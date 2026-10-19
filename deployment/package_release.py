#!/usr/bin/env python3
"""Build a manifest-verified release package. Never deploy or access a database."""

from __future__ import annotations

import argparse
import gzip
import hashlib
import io
import json
import os
import tarfile
from datetime import datetime, timezone
from pathlib import Path

from verify_release import DATABASES, FORMAT, MAX_FILE_BYTES, MAX_FILES, ReleaseError, allowed_backend, allowed_member, checked_file, fail, file_sha, regular_directory, regular_file, required_members, safe_name, verify_package


def walk_files(root: Path, prefix: str = "") -> list[str]:
    regular_directory(root)
    result = []
    for child in sorted(root.iterdir(), key=lambda entry: entry.name):
        relative = f"{prefix}/{child.name}" if prefix else child.name
        if child.is_symlink() or getattr(child, "is_junction", lambda: False)():
            fail("Source links are not allowed")
        if child.is_dir():
            result.extend(walk_files(child, relative))
        else:
            regular_file(child)
            if child.name != ".gitignore":
                result.append(relative)
        if len(result) > MAX_FILES:
            fail("Too many source files")
    return result


def backend_files(source: Path) -> dict[str, Path]:
    root = source / "backend"
    selected = {name: checked_file(root, name) for name in ("artisan", "composer.json", "composer.lock", "bootstrap/app.php", "bootstrap/providers.php")}
    for folder in ("app", "config", "routes", "resources", "database/migrations", "database/seeders", "database/factories", "public"):
        for relative in walk_files(root / folder):
            name = f"{folder}/{relative}"
            if not allowed_backend(name):
                fail(f"Disallowed backend source file: {name}")
            selected[name] = checked_file(root, name)
    return selected


def web_files(source: Path, include_public: bool) -> dict[str, dict[str, Path]]:
    result = {"portals": {}}
    for portal in ("admin", "agents", "pos", *(("public",) if include_public else ())):
        kind = "public" if portal == "public" else "portals"
        selected = result.setdefault(kind, {})
        root = source / "frontend/dist" / portal
        for relative in walk_files(root):
            name = relative if kind == "public" else f"{portal}/{relative}"
            if relative in {"masal-api.php", ".htaccess"} or not allowed_member(kind, name):
                fail(f"Disallowed or conflicting web build file: {portal}/{relative}")
            selected[name] = checked_file(root, relative)
        for name, template in (("masal-api.php", "masal-api.php"), (".htaccess", "public.htaccess" if kind == "public" else "portal.htaccess")):
            target = name if kind == "public" else f"{portal}/{name}"
            selected[target] = checked_file(source / "deployment/cpanel", template)
    return result


def archive_files(path: Path, kind: str, selected: dict[str, Path]) -> dict:
    if not selected or len(selected) > MAX_FILES:
        fail("Invalid source file count")
    folded = [name.casefold() for name in selected]
    if len(set(folded)) != len(folded):
        fail("Case-insensitive duplicate source paths")
    required_members(kind, set(selected))
    records = []
    with path.open("xb") as target:
        with gzip.GzipFile(fileobj=target, filename="", mode="wb", mtime=0) as compressed:
            with tarfile.open(fileobj=compressed, mode="w", format=tarfile.PAX_FORMAT) as archive:
                for name, source in sorted(selected.items()):
                    if not allowed_member(kind, name):
                        fail("Source file is outside its artifact policy")
                    regular_file(source)
                    before = source.stat()
                    if before.st_size > MAX_FILE_BYTES:
                        fail("Source file exceeds the size limit")
                    contents = source.read_bytes()
                    after = source.stat()
                    if (before.st_size, before.st_mtime_ns, before.st_ino) != (after.st_size, after.st_mtime_ns, after.st_ino) or len(contents) != before.st_size:
                        fail("Source changed while packaging")
                    info = tarfile.TarInfo(name)
                    info.size = len(contents)
                    info.mode = 0o755 if name == "artisan" else 0o644
                    info.uid = info.gid = info.mtime = 0
                    info.uname = info.gname = ""
                    archive.addfile(info, io.BytesIO(contents))
                    records.append({"path": name, "bytes": len(contents), "sha256": hashlib.sha256(contents).hexdigest()})
    return {"kind": kind, "bytes": path.stat().st_size, "sha256": file_sha(path), "members": records}


def build_package(source: Path, output: Path, release: str, include_public: bool = False, database: str = "dananiriq_masalprod") -> dict:
    safe_name(release)
    if database not in DATABASES:
        fail("Database is outside the release allowlist")
    regular_directory(source)
    source = source.resolve()
    output = output.absolute()
    if output.exists() or output.is_symlink():
        fail("Output must be a new directory")
    regular_directory(output.parent)
    if output.parent.resolve().is_relative_to(source):
        fail("Package output must be outside the source tree")
    files = {"backend": backend_files(source), **web_files(source, include_public)}
    for kind, selected in files.items():
        if len({name.casefold() for name in selected}) != len(selected):
            fail("Duplicate source paths")
        required_members(kind, set(selected))
    output.mkdir(mode=0o700)
    archives = {"backend": "backend.tar.gz", "portals": "portals.tar.gz", "public": "public-site.tar.gz"}
    artifacts = {archives[kind]: archive_files(output / archives[kind], kind, selected) for kind, selected in files.items()}
    manifest = {"format": FORMAT, "release": release, "database": database, "created_utc": datetime.now(timezone.utc).isoformat(), "artifacts": artifacts}
    with (output / "manifest.json").open("x", encoding="utf-8", newline="\n") as target:
        json.dump(manifest, target, ensure_ascii=False, indent=2)
        target.write("\n")
    return verify_package(output)


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("release", help="Reviewed release identifier")
    parser.add_argument("--output", required=True, type=Path, help="New output directory outside the source tree")
    parser.add_argument("--include-public", action="store_true", help="Include the separately built public company site")
    parser.add_argument("--database", default="dananiriq_masalprod", choices=sorted(DATABASES), help="Expected database policy only; no database connection is made")
    args = parser.parse_args()
    try:
        print(json.dumps(build_package(Path(__file__).resolve().parent.parent, args.output, args.release, args.include_public, args.database), ensure_ascii=False))
        return 0
    except (ReleaseError, OSError) as error:
        parser.exit(1, json.dumps({"passed": False, "error": str(error)}, ensure_ascii=False) + os.linesep)


if __name__ == "__main__":
    raise SystemExit(main())
