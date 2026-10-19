#!/usr/bin/env python3
"""Share identical immutable files while retaining every public URL."""

from __future__ import annotations

import argparse
import collections
import datetime
import gzip
import hashlib
import json
import os
import re
import stat
import sys
import tarfile
import uuid
from contextlib import ExitStack
from pathlib import Path, PurePosixPath

try:
    import fcntl
except ImportError:
    fcntl = None

ROLES = ("admin", "agents", "pos")
ASSET = re.compile(r"public_html/(?:masal/portals/(?:admin|agents|pos)/)?assets/[^/]+-[A-Za-z0-9_-]{8,64}\.(?:js|css|ttf|woff2?|png|jpe?g|gif|webp|svg|ico)$")
APK = re.compile(r"public_html/(?:masal/portals/pos/)?downloads/[A-Za-z0-9][A-Za-z0-9._-]*-\d+\.\d+\.\d+(?:-[A-Za-z0-9._-]+)?\.apk$")
SHA = re.compile(r"[a-f0-9]{64}")
MAX_FILES = 100000
MAX_BYTES = 20 * 1024 ** 3


class DedupeError(RuntimeError):
    pass


def require(value, code):
    if not value:
        raise DedupeError(code)


def file_sha(path):
    before = path.lstat()
    require(stat.S_ISREG(before.st_mode), "HASH_REQUIRES_REGULAR_FILE")
    result = hashlib.sha256()
    descriptor = os.open(path, os.O_RDONLY | getattr(os, "O_NOFOLLOW", 0))
    with os.fdopen(descriptor, "rb") as source:
        opened = os.fstat(source.fileno())
        require((opened.st_dev, opened.st_ino) == (before.st_dev, before.st_ino), "HASH_SOURCE_CHANGED")
        for block in iter(lambda: source.read(1048576), b""):
            result.update(block)
    after = path.lstat()
    require((before.st_dev, before.st_ino, before.st_size, before.st_mtime_ns) ==
            (after.st_dev, after.st_ino, after.st_size, after.st_mtime_ns), "HASH_SOURCE_CHANGED")
    return result.hexdigest()


def load_json(path):
    def pairs(items):
        value = {}
        for key, item in items:
            require(key not in value, "DUPLICATE_JSON_KEY")
            value[key] = item
        return value
    return json.loads(path.read_text(encoding="utf-8-sig"), object_pairs_hook=pairs)


def write_private(path, value):
    with path.open("x", encoding="utf-8", newline="\n") as output:
        os.chmod(path, 0o600)
        json.dump(value, output, indent=2, sort_keys=True)
        output.write("\n")
        output.flush()
        os.fsync(output.fileno())


def safe_relative(value):
    require(isinstance(value, str) and value and not any(c in value for c in ("\\", ":", "\0", "\r", "\n")), "UNSAFE_RELATIVE_PATH")
    path = PurePosixPath(value)
    require(str(path) == value and not path.is_absolute() and all(part not in {".", ".."} for part in path.parts), "UNSAFE_RELATIVE_PATH")
    return path


def regular_directory(path):
    require(path.is_absolute() and path.resolve() == path and not path.is_symlink() and stat.S_ISDIR(path.lstat().st_mode), "UNSAFE_DIRECTORY")


def checked(base, relative):
    path = base
    parts = safe_relative(relative).parts
    regular_directory(base)
    for index, part in enumerate(parts):
        path /= part
        mode = path.lstat().st_mode
        require(not stat.S_ISLNK(mode), "SYMLINK_REJECTED")
        require(stat.S_ISREG(mode) if index == len(parts) - 1 else stat.S_ISDIR(mode), "SPECIAL_FILE_REJECTED")
    return path


def describe(base, relative):
    path = checked(base, relative)
    value = path.stat()
    digest = file_sha(path)
    after = path.stat()
    require((value.st_dev, value.st_ino, value.st_size, value.st_mtime_ns) ==
            (after.st_dev, after.st_ino, after.st_size, after.st_mtime_ns), "HASH_SOURCE_CHANGED")
    return dict(path=relative, bytes=value.st_size, sha256=digest, dev=value.st_dev, inode=value.st_ino,
                links=value.st_nlink, mode=stat.S_IMODE(value.st_mode), uid=value.st_uid, gid=value.st_gid,
                mtime_ns=value.st_mtime_ns, atime_ns=value.st_atime_ns,
                blocks=getattr(value, "st_blocks", (value.st_size + 511) // 512))


def candidates(base):
    roots = [base / "public_html/assets"]
    roots += [base / ("public_html/masal/portals/" + role + "/assets") for role in ROLES]
    roots += [base / "public_html/downloads", base / "public_html/masal/portals/pos/downloads"]
    result = {}
    for root in roots:
        if not root.exists():
            continue
        regular_directory(root)
        for path in sorted(root.iterdir()):
            relative = path.relative_to(base).as_posix()
            if ASSET.fullmatch(relative) or APK.fullmatch(relative):
                require(relative not in result, "DUPLICATE_CANDIDATE")
                result[relative] = describe(base, relative)
                require(len(result) <= MAX_FILES, "TOO_MANY_CANDIDATES")
    require(sum(row["bytes"] for row in result.values()) <= MAX_BYTES, "CANDIDATES_TOO_LARGE")
    return result


def restorable_metadata(row):
    if os.name != "posix" or os.geteuid() == 0:
        return True
    return row["uid"] == os.geteuid() and row["gid"] in set(os.getgroups()) | {os.getegid()}


def groups_for(inventory):
    by_content = collections.defaultdict(list)
    for row in inventory.values():
        require(ASSET.fullmatch(row["path"]) or APK.fullmatch(row["path"]), "NON_IMMUTABLE_FILE_REJECTED")
        key = tuple(row[k] for k in ("sha256", "bytes", "dev", "mode", "uid", "gid")) + (row["mtime_ns"] // 1000000000,)
        by_content[key].append(row)
    groups = []
    for items in by_content.values():
        inodes = collections.defaultdict(list)
        for row in items:
            inodes[row["inode"]].append(row)
        if len(inodes) <= 1 or not all(restorable_metadata(row) for row in items):
            continue
        # Unknown existing links may share data outside the approved inventory.
        if any(len(rows) != rows[0]["links"] for rows in inodes.values()):
            continue
        items.sort(key=lambda row: row["path"])
        canonical = items[0]
        groups.append(dict(canonical=canonical["path"], targets=[row["path"] for row in items if row["inode"] != canonical["inode"]],
                           allocated_bytes_saved=sum(rows[0]["blocks"] * 512 for inode, rows in inodes.items() if inode != canonical["inode"])))
    return sorted(groups, key=lambda group: group["canonical"])


def protected_web(base):
    protected = {}
    pattern = re.compile(r"[A-Za-z0-9_.-]+-[A-Za-z0-9_-]{8,64}\.(?:js|css|ttf|woff2?|png|jpe?g|gif|webp|svg|ico)")
    for relative in ["public_html"] + ["public_html/masal/portals/" + role for role in ROLES]:
        root = base / relative
        regular_directory(root)
        index = checked(base, relative + "/index.html")
        protected[relative + "/index.html"] = file_sha(index)
        assets = root / "assets"
        regular_directory(assets)
        names = {path.name: path for path in assets.iterdir() if path.is_file() and not path.is_symlink()}
        queue = list(pattern.findall(index.read_text(encoding="utf-8")))
        manifest_path = root / ".vite/manifest.json"
        if manifest_path.exists():
            manifest = load_json(checked(base, relative + "/.vite/manifest.json"))
            protected[relative + "/.vite/manifest.json"] = file_sha(manifest_path)
            for row in manifest.values():
                for value in [row["file"]] + row.get("css", []) + row.get("assets", []):
                    require(str(safe_relative(value)).startswith("assets/") and len(PurePosixPath(value).parts) == 2, "MANIFEST_ASSET_PATH")
                    queue.append(PurePosixPath(value).name)
                require(all(key in manifest for key in row.get("imports", []) + row.get("dynamicImports", [])), "MANIFEST_IMPORT_MISSING")
        visited = set()
        while queue:
            name = queue.pop()
            if name in visited:
                continue
            require(name in names, "CURRENT_TRANSITIVE_ASSET_MISSING")
            path = checked(base, relative + "/assets/" + name)
            protected[relative + "/assets/" + name] = file_sha(path)
            visited.add(name)
            if path.suffix in {".js", ".css"}:
                queue.extend(set(pattern.findall(path.read_text(encoding="utf-8"))) - visited)
        # These files include the service worker, web manifest and routing rules.
        for path in sorted(root.iterdir()):
            if path.is_file() and not path.is_symlink():
                protected[path.relative_to(base).as_posix()] = file_sha(path)
        for path in sorted((root / "icons").glob("*")):
            if path.is_file() and not path.is_symlink():
                protected[path.relative_to(base).as_posix()] = file_sha(path)
    return protected


def build_plan(base, identity):
    inventory = candidates(base)
    groups = groups_for(inventory)
    return dict(format="immutable-assets-plan-v1", identity=identity, inventory=inventory, groups=groups,
                protected=protected_web(base), created_at_utc=datetime.datetime.now(datetime.timezone.utc).isoformat(),
                summary=dict(candidate_urls=len(inventory), groups=len(groups),
                             shared_paths=sum(len(group["targets"]) for group in groups),
                             allocated_bytes_saved=sum(group["allocated_bytes_saved"] for group in groups)),
                public_urls_removed=0, database_accessed=False)


def plan_rows(plan):
    require(plan["format"] == "immutable-assets-plan-v1" and plan["public_urls_removed"] == 0 and plan["database_accessed"] is False, "PLAN_FORMAT")
    inventory = plan["inventory"]
    require(len(inventory) <= MAX_FILES and sum(row["bytes"] for row in inventory.values()) <= MAX_BYTES, "PLAN_LIMIT")
    affected = {}
    for group in plan["groups"]:
        names = [group["canonical"]] + group["targets"]
        require(len(names) > 1, "EMPTY_GROUP")
        canonical = inventory[group["canonical"]]
        for name in names:
            require(name not in affected and name in inventory, "DUPLICATE_OR_UNKNOWN_PLAN_PATH")
            require(ASSET.fullmatch(name) or APK.fullmatch(name), "NON_IMMUTABLE_FILE_REJECTED")
            row = inventory[name]
            require(row["path"] == name and all(canonical[k] == row[k] for k in ("sha256", "bytes", "dev", "mode", "uid", "gid")), "INCOMPATIBLE_GROUP")
            require(canonical["mtime_ns"] // 1000000000 == row["mtime_ns"] // 1000000000, "HTTP_LAST_MODIFIED_MISMATCH")
            affected[name] = row
    return affected


def validate_contents(base, plan, original_inodes=False):
    plan_rows(plan)
    current = candidates(base)
    require(set(current) == set(plan["inventory"]), "PUBLIC_URL_SET_CHANGED")
    target_names = {name for group in plan["groups"] for name in group["targets"]}
    for name, row in plan["inventory"].items():
        value = current[name]
        require(all(value[k] == row[k] for k in ("path", "bytes", "sha256", "dev", "mode", "uid", "gid")), "PUBLIC_PAYLOAD_OR_METADATA_CHANGED")
        require(value["mtime_ns"] // 1000000000 == row["mtime_ns"] // 1000000000, "HTTP_LAST_MODIFIED_CHANGED")
        if original_inodes or name not in target_names:
            require(value["inode"] == row["inode"] and value["mtime_ns"] == row["mtime_ns"], "ORIGINAL_FILE_CHANGED")
    require(protected_web(base) == plan["protected"], "CURRENT_WEB_CONTENT_CHANGED")
    return current


def archive_backup(base, stage, plan, plan_sha):
    validate_contents(base, plan, original_inodes=True)
    affected = plan_rows(plan)
    archive = stage / "before.tar.gz"
    require(not archive.exists() and not (stage / "backup-manifest.json").exists(), "BACKUP_ALREADY_EXISTS")
    with archive.open("xb") as raw:
        os.chmod(archive, 0o600)
        with gzip.GzipFile(fileobj=raw, mode="wb", mtime=0) as compressed:
            with tarfile.open(fileobj=compressed, mode="w", format=tarfile.USTAR_FORMAT) as output:
                for name, row in sorted(affected.items()):
                    source = checked(base, name)
                    require(file_sha(source) == row["sha256"], "ARCHIVE_SOURCE_CHANGED")
                    info = tarfile.TarInfo(name)
                    info.size, info.mode, info.uid, info.gid, info.mtime = row["bytes"], 0o600, 0, 0, 0
                    with source.open("rb") as handle:
                        output.addfile(info, handle)
    validate_contents(base, plan, original_inodes=True)
    manifest = dict(format="immutable-assets-backup-v1", plan_sha256=plan_sha, archive_sha256=file_sha(archive),
                    archive_bytes=archive.stat().st_size, files=affected)
    write_private(stage / "backup-manifest.json", manifest)
    return dict(passed=True, archive_sha256=manifest["archive_sha256"], backup_manifest_sha256=file_sha(stage / "backup-manifest.json"), members=len(affected))


def verify_backup(archive, manifest, plan, plan_sha):
    require(manifest["format"] == "immutable-assets-backup-v1" and manifest["plan_sha256"] == plan_sha, "BACKUP_PLAN_PIN")
    require(manifest["files"] == plan_rows(plan), "BACKUP_MEMBERSHIP_MISMATCH")
    require(archive.stat().st_size == manifest["archive_bytes"] and file_sha(archive) == manifest["archive_sha256"], "BACKUP_ARCHIVE_PIN")
    seen = set()
    with tarfile.open(archive, "r:gz") as source:
        for member in source:
            safe_relative(member.name)
            require(member.isfile() and member.name in manifest["files"] and member.name not in seen and
                    member.mode == 0o600 and member.uid == member.gid == member.mtime == 0 and not member.pax_headers, "BACKUP_MEMBER_REJECTED")
            row = manifest["files"][member.name]
            require(member.size == row["bytes"], "BACKUP_SIZE_MISMATCH")
            value = hashlib.sha256()
            payload = source.extractfile(member)
            for block in iter(lambda: payload.read(1048576), b""):
                value.update(block)
            require(value.hexdigest() == row["sha256"], "BACKUP_PAYLOAD_MISMATCH")
            seen.add(member.name)
            source.members.clear()
    require(seen == set(manifest["files"]), "BACKUP_INCOMPLETE")
    with gzip.open(archive, "rb") as source:
        while source.read(1048576):
            pass
    return dict(passed=True, complete_member_payload_and_gzip_crc_verified=True, members=len(seen))


def verify_result(base, plan):
    current = validate_contents(base, plan)
    for group in plan["groups"]:
        canonical = current[group["canonical"]]
        require(all((current[name]["dev"], current[name]["inode"]) == (canonical["dev"], canonical["inode"]) for name in group["targets"]), "FILES_NOT_SHARED")
    before = {(row["dev"], row["inode"]): row for row in plan["inventory"].values()}
    after = {(row["dev"], row["inode"]): row for row in current.values()}
    saved = sum(row["blocks"] * 512 for row in before.values()) - sum(row["blocks"] * 512 for row in after.values())
    require(saved == plan["summary"]["allocated_bytes_saved"], "ALLOCATED_BYTES_MISMATCH")
    return dict(passed=True, verified_public_urls=len(current), shared_paths=plan["summary"]["shared_paths"],
                allocated_bytes_saved=saved, public_urls_removed=0, all_payloads_and_http_last_modified_preserved=True, database_accessed=False)


def apply_plan(base, plan):
    validate_contents(base, plan, original_inodes=True)
    for group in plan["groups"]:
        canonical = checked(base, group["canonical"])
        for name in group["targets"]:
            row = plan["inventory"][name]
            target = checked(base, name)
            require(describe(base, name)["inode"] == row["inode"] and file_sha(target) == row["sha256"], "TARGET_CHANGED_BEFORE_LINK")
            temporary = target.parent / (".immutable-link-" + uuid.uuid4().hex)
            try:
                os.link(canonical, temporary, follow_symlinks=False)
                require(temporary.stat().st_ino == canonical.stat().st_ino and file_sha(temporary) == row["sha256"], "NEW_LINK_REJECTED")
                os.replace(temporary, target)
            finally:
                if temporary.exists():
                    temporary.unlink()
    return verify_result(base, plan)


def restore_backup(base, archive, manifest, plan, plan_sha):
    verify_backup(archive, manifest, plan, plan_sha)
    validate_contents(base, plan)
    with tarfile.open(archive, "r:gz") as source:
        for member in source:
            row = manifest["files"][member.name]
            target = checked(base, member.name)
            temporary = target.parent / (".immutable-restore-" + uuid.uuid4().hex)
            try:
                with temporary.open("xb") as output:
                    os.chmod(temporary, 0o600)
                    payload = source.extractfile(member)
                    for block in iter(lambda: payload.read(1048576), b""):
                        output.write(block)
                    output.flush()
                    os.fsync(output.fileno())
                require(file_sha(temporary) == row["sha256"], "RESTORE_PAYLOAD_MISMATCH")
                if hasattr(os, "chown"):
                    os.chown(temporary, row["uid"], row["gid"], follow_symlinks=False)
                os.chmod(temporary, row["mode"])
                if os.utime in os.supports_follow_symlinks:
                    os.utime(temporary, ns=(row["atime_ns"], row["mtime_ns"]), follow_symlinks=False)
                else:
                    os.utime(temporary, ns=(row["atime_ns"], row["mtime_ns"]))
                os.replace(temporary, target)
            finally:
                if temporary.exists():
                    temporary.unlink()
            source.members.clear()
    current = candidates(base)
    for name, row in manifest["files"].items():
        require(all(current[name][key] == row[key] for key in ("bytes", "sha256", "mode", "uid", "gid", "mtime_ns")), "RESTORE_METADATA_MISMATCH")
    require(protected_web(base) == plan["protected"], "CURRENT_WEB_CONTENT_CHANGED")
    return dict(passed=True, restored_files=len(manifest["files"]), original_bytes_and_metadata_restored=True, public_urls_removed=0, database_accessed=False)


def identity_for(args):
    require(args.base is not None and args.stage is not None and args.expected_release, "SERVER_ARGUMENTS_REQUIRED")
    require(re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9._-]{0,127}", args.expected_release) is not None and args.expected_release not in {".", ".."}, "RELEASE_NAME_REJECTED")
    require(args.config_sha256 and SHA.fullmatch(args.config_sha256) and args.env_sha256 and SHA.fullmatch(args.env_sha256), "CONFIG_AND_ENV_PINS_REQUIRED")
    return dict(base=str(args.base), stage=str(args.stage), expected_release=args.expected_release,
                config_sha256=args.config_sha256, env_sha256=args.env_sha256)


def live_guard(base, stage, identity):
    require(os.name == "posix" and fcntl is not None, "SERVER_REQUIRES_POSIX")
    regular_directory(base)
    regular_directory(stage)
    require(stage.parent == base and stage.name.startswith("private-") and stat.S_IMODE(stage.stat().st_mode) == 0o700, "PRIVATE_STAGE_MUST_BE_DIRECT_BASE_CHILD_WITH_MODE_0700")
    backend = base / "masal-backend"
    regular_directory(backend)
    release = backend / "releases" / identity["expected_release"]
    regular_directory(release)
    current = backend / "current"
    require(current.is_symlink() and current.resolve() == release, "CURRENT_RELEASE_CHANGED")
    require(file_sha(checked(base, "masal-backend/releases/" + identity["expected_release"] + "/bootstrap/cache/config.php")) == identity["config_sha256"], "CONFIG_PIN_CHANGED")
    require(file_sha(checked(base, "masal-backend/shared/.env")) == identity["env_sha256"], "ENV_PIN_CHANGED")


def pinned_plan(stage, pin):
    require(pin and SHA.fullmatch(pin), "EXTERNAL_PLAN_PIN_REQUIRED")
    path = checked(stage, "plan.json")
    require(stat.S_IMODE(path.stat().st_mode) == 0o600 and file_sha(path) == pin, "PLAN_PIN_OR_MODE_CHANGED")
    return load_json(path)


def checked_backup(stage, plan, plan_sha, receipt_sha):
    require(receipt_sha and SHA.fullmatch(receipt_sha), "EXTERNAL_RECEIPT_PIN_REQUIRED")
    receipt_path = checked(stage, "desktop-receipt.json")
    require(stat.S_IMODE(receipt_path.stat().st_mode) == 0o600 and file_sha(receipt_path) == receipt_sha, "DESKTOP_RECEIPT_PIN_OR_MODE")
    receipt = load_json(receipt_path)
    manifest_path = checked(stage, "backup-manifest.json")
    archive = checked(stage, "before.tar.gz")
    manifest = load_json(manifest_path)
    require(receipt["format"] == "immutable-assets-desktop-v1" and receipt["complete_member_payload_and_gzip_crc_verified"] is True and
            receipt["plan_sha256"] == plan_sha and receipt["archive_sha256"] == file_sha(archive) and
            receipt["backup_manifest_sha256"] == file_sha(manifest_path), "DESKTOP_BACKUP_NOT_VERIFIED")
    verify_backup(archive, manifest, plan, plan_sha)
    return archive, manifest


def main():
    os.umask(0o077)
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("phase", choices=["plan", "archive", "apply", "verify", "restore", "verify-backup"])
    parser.add_argument("--base", type=Path, help="Absolute hosting account home")
    parser.add_argument("--stage", type=Path, help="Existing private-* directory directly under base, mode 0700")
    parser.add_argument("--expected-release")
    parser.add_argument("--config-sha256")
    parser.add_argument("--env-sha256")
    parser.add_argument("--plan-sha256")
    parser.add_argument("--receipt-sha256")
    parser.add_argument("--plan", type=Path, help="Downloaded plan for local verify-backup")
    parser.add_argument("--archive", type=Path, help="Downloaded before.tar.gz for local verify-backup")
    parser.add_argument("--backup-manifest", type=Path)
    parser.add_argument("--archive-sha256")
    parser.add_argument("--backup-manifest-sha256")
    parser.add_argument("--receipt-output", type=Path, help="New private receipt file for local verify-backup")
    args = parser.parse_args()
    try:
        if args.phase == "verify-backup":
            require(all((args.plan, args.archive, args.backup_manifest, args.receipt_output)), "LOCAL_BACKUP_PATHS_REQUIRED")
            for pin in (args.plan_sha256, args.archive_sha256, args.backup_manifest_sha256):
                require(pin and SHA.fullmatch(pin), "EXTERNAL_BACKUP_PINS_REQUIRED")
            require(file_sha(args.plan) == args.plan_sha256 and file_sha(args.archive) == args.archive_sha256 and file_sha(args.backup_manifest) == args.backup_manifest_sha256, "DOWNLOADED_BACKUP_PIN_CHANGED")
            result = verify_backup(args.archive, load_json(args.backup_manifest), load_json(args.plan), args.plan_sha256)
            receipt = dict(format="immutable-assets-desktop-v1", plan_sha256=args.plan_sha256,
                           archive_sha256=args.archive_sha256, backup_manifest_sha256=args.backup_manifest_sha256,
                           verified_at_utc=datetime.datetime.now(datetime.timezone.utc).isoformat(), **result)
            write_private(args.receipt_output, receipt)
            print(json.dumps(dict(passed=True, receipt_sha256=file_sha(args.receipt_output), members=result["members"], database_accessed=False)))
            return 0
        identity = identity_for(args)
        base, stage = args.base, args.stage
        live_guard(base, stage, identity)
        with ExitStack() as stack:
            for name in ("masal-backend/.local/deployment.lock", "masal-backend/shared/storage/framework/backup-coordination.lock"):
                handle = stack.enter_context(checked(base, name).open("r+"))
                fcntl.flock(handle, fcntl.LOCK_EX | fcntl.LOCK_NB)
            live_guard(base, stage, identity)
            if args.phase == "plan":
                plan = build_plan(base, identity)
                write_private(stage / "plan.json", plan)
                result = dict(passed=True, plan_sha256=file_sha(stage / "plan.json"), **plan["summary"])
            else:
                plan = pinned_plan(stage, args.plan_sha256)
                require(plan["identity"] == identity, "PLAN_IDENTITY_CHANGED")
                if args.phase == "archive":
                    result = archive_backup(base, stage, plan, args.plan_sha256)
                elif args.phase == "verify":
                    result = verify_result(base, plan)
                else:
                    archive, manifest = checked_backup(stage, plan, args.plan_sha256, args.receipt_sha256)
                    result = apply_plan(base, plan) if args.phase == "apply" else restore_backup(base, archive, manifest, plan, args.plan_sha256)
            live_guard(base, stage, identity)
            print(json.dumps({**result, "database_accessed": False}))
            return 0
    except (DedupeError, OSError, ValueError, KeyError, TypeError, tarfile.TarError, EOFError) as error:
        print(json.dumps(dict(passed=False, error=str(error), database_accessed=False)), file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
