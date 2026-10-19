#!/usr/bin/env python3
"""Verify a release without extracting files or executing application code."""

from __future__ import annotations

import argparse
import gzip
import hashlib
import json
import re
import stat
import sys
import tarfile
import tempfile
import zlib
from pathlib import Path, PurePosixPath

FORMAT = "dananeer.release.v1"
DATABASES = {"dananiriq_masalprod"}
PORTALS = ("admin", "agents", "pos")
MAX_FILES = 20000
MAX_FILE_BYTES = 128 * 1024 * 1024
MAX_ARCHIVE_BYTES = 1024 * 1024 * 1024
MAX_UNPACKED_BYTES = 2 * 1024 * 1024 * 1024
ASSET_SUFFIXES = {".js", ".css", ".woff", ".woff2", ".ttf", ".png", ".jpg", ".jpeg", ".svg", ".gif", ".webp", ".ico"}
BLOCKED_PARTS = {"vendor", "node_modules", "storage", "tests", ".git", ".github", ".local"}
BLOCKED_SUFFIXES = {".sqlite", ".sqlite3", ".db", ".sql", ".zip", ".gz", ".log", ".key", ".pem", ".p12", ".jks", ".keystore", ".bak", ".old", ".orig", ".tmp", ".swp"}
RESERVED_NAMES = {"con", "prn", "aux", "nul", *(f"com{number}" for number in range(1, 10)), *(f"lpt{number}" for number in range(1, 10))}


class ReleaseError(ValueError):
    """A release does not meet the packaging policy."""


def fail(message: str) -> None:
    raise ReleaseError(message)


def json_object(pairs: list[tuple[str, object]]) -> dict:
    result = {}
    for key, value in pairs:
        if key in result:
            fail("Duplicate JSON key")
        result[key] = value
    return result


def load_json(path: Path) -> dict:
    regular_file(path)
    if path.stat().st_size > 16 * 1024 * 1024:
        fail("Manifest exceeds the size limit")
    try:
        value = json.loads(path.read_text(encoding="utf-8"), object_pairs_hook=json_object)
    except (UnicodeError, json.JSONDecodeError) as error:
        raise ReleaseError("Invalid UTF-8 JSON manifest") from error
    if not isinstance(value, dict):
        fail("Manifest must be an object")
    return value


def safe_name(value: object) -> str:
    if not isinstance(value, str) or not re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9._-]{0,95}", value):
        fail("Invalid release name")
    if value in {".", ".."}:
        fail("Invalid release name")
    return value


def safe_path(value: object) -> str:
    if not isinstance(value, str) or not value or len(value) > 1024 or "\\" in value or ":" in value:
        fail("Unsafe archive path")
    if any(ord(char) < 32 or ord(char) == 127 for char in value):
        fail("Unsafe archive path")
    parts = value.split("/")
    if any(part in {"", ".", ".."} or part.endswith((".", " ")) for part in parts):
        fail("Unsafe archive path")
    if any(part.split(".", 1)[0].casefold() in RESERVED_NAMES for part in parts):
        fail("Reserved device name in archive path")
    if PurePosixPath(value).is_absolute():
        fail("Unsafe archive path")
    return value


def regular_file(path: Path) -> None:
    info = path.lstat()
    if not stat.S_ISREG(info.st_mode) or getattr(info, "st_file_attributes", 0) & getattr(stat, "FILE_ATTRIBUTE_REPARSE_POINT", 0):
        fail("Expected a regular file without links")


def regular_directory(path: Path) -> None:
    info = path.lstat()
    if not stat.S_ISDIR(info.st_mode) or getattr(info, "st_file_attributes", 0) & getattr(stat, "FILE_ATTRIBUTE_REPARSE_POINT", 0):
        fail("Expected a directory without links")


def checked_file(root: Path, relative: str) -> Path:
    relative = safe_path(relative)
    regular_directory(root)
    target = root
    parts = relative.split("/")
    for part in parts[:-1]:
        target /= part
        regular_directory(target)
    target /= parts[-1]
    regular_file(target)
    if not target.resolve().is_relative_to(root.resolve()):
        fail("File escaped its root")
    return target


def file_sha(path: Path) -> str:
    regular_file(path)
    digest = hashlib.sha256()
    with path.open("rb") as stream:
        for block in iter(lambda: stream.read(1024 * 1024), b""):
            digest.update(block)
    return digest.hexdigest()


def allowed_backend(name: str) -> bool:
    parts = safe_path(name).split("/")
    if any(part.casefold() in BLOCKED_PARTS or part.casefold().startswith(".env") for part in parts):
        return False
    if Path(name).suffix.casefold() in BLOCKED_SUFFIXES or name.endswith("~"):
        return False
    if any(part.startswith(".") for part in parts) and name != "public/.htaccess":
        return False
    if name in {"artisan", "composer.json", "composer.lock", "bootstrap/app.php", "bootstrap/providers.php"}:
        return True
    suffix = Path(name).suffix.casefold()
    if parts[0] in {"app", "config", "routes"} and len(parts) > 1:
        return suffix in {".php", ".json"}
    if parts[0] == "resources" and len(parts) > 1:
        return suffix in ASSET_SUFFIXES | {".php", ".json", ".html", ".txt", ".xml"}
    if len(parts) > 2 and parts[0] == "database" and parts[1] in {"migrations", "seeders", "factories"}:
        return suffix in {".php", ".json"}
    return name in {"public/index.php", "public/.htaccess", "public/robots.txt", "public/favicon.ico"}


def allowed_web(name: str, portal: str) -> bool:
    parts = safe_path(name).split("/")
    if name in {"index.html", "version.json", ".vite/manifest.json", "masal-api.php", ".htaccess"}:
        return True
    if portal == "pos" and name in {"manifest.webmanifest", "sw.js"}:
        return True
    if any(part.startswith(".") or part.casefold().startswith(".env") for part in parts):
        return False
    if len(parts) > 1 and parts[0] == "assets":
        return Path(name).suffix.casefold() in ASSET_SUFFIXES
    if portal == "pos" and len(parts) == 2 and parts[0] == "icons":
        return Path(name).suffix.casefold() in {".png", ".svg", ".ico"}
    return False


def allowed_member(kind: str, name: str) -> bool:
    if kind == "backend":
        return allowed_backend(name)
    if kind == "public":
        return allowed_web(name, "public")
    if kind == "portals":
        portal, separator, relative = safe_path(name).partition("/")
        return bool(separator) and portal in PORTALS and allowed_web(relative, portal)
    return False


def manifest_data(path: Path) -> dict:
    value = load_json(path)
    if value.get("format") != FORMAT:
        fail("Unsupported manifest format")
    safe_name(value.get("release"))
    if value.get("database") not in DATABASES:
        fail("Database is outside the release allowlist")
    artifacts = value.get("artifacts")
    if not isinstance(artifacts, dict) or set(artifacts) not in ({"backend.tar.gz", "portals.tar.gz"}, {"backend.tar.gz", "portals.tar.gz", "public-site.tar.gz"}):
        fail("Unexpected release artifacts")
    expected_kinds = {"backend.tar.gz": "backend", "portals.tar.gz": "portals", "public-site.tar.gz": "public"}
    for archive, artifact in artifacts.items():
        if not isinstance(artifact, dict) or artifact.get("kind") != expected_kinds[archive]:
            fail("Invalid artifact kind")
        if type(artifact.get("bytes")) is not int or not 0 < artifact["bytes"] <= MAX_ARCHIVE_BYTES:
            fail("Invalid artifact size")
        check_sha(artifact.get("sha256"))
        members = artifact.get("members")
        if not isinstance(members, list) or not 0 < len(members) <= MAX_FILES:
            fail("Invalid artifact member list")
        seen = set()
        for member in members:
            if not isinstance(member, dict):
                fail("Invalid member record")
            name = safe_path(member.get("path"))
            if name.casefold() in seen or not allowed_member(artifact["kind"], name):
                fail("Duplicate or disallowed member path")
            seen.add(name.casefold())
            if type(member.get("bytes")) is not int or not 0 <= member["bytes"] <= MAX_FILE_BYTES:
                fail("Invalid member size")
            check_sha(member.get("sha256"))
        required_members(artifact["kind"], {member["path"] for member in members})
    return value


def check_sha(value: object) -> None:
    if not isinstance(value, str) or not re.fullmatch(r"[0-9a-f]{64}", value):
        fail("Invalid SHA-256 value")


def required_members(kind: str, names: set[str]) -> None:
    if kind == "backend":
        required = {"artisan", "composer.json", "composer.lock", "bootstrap/app.php", "bootstrap/providers.php", "public/index.php", "routes/web.php"}
    else:
        entry = {"index.html", "version.json", ".vite/manifest.json", "masal-api.php", ".htaccess"}
        required = {f"{portal}/{name}" for portal in PORTALS for name in entry} if kind == "portals" else entry
    if not required.issubset(names):
        fail("Required release files are missing")


def verify_archive(path: Path, artifact: dict) -> dict:
    regular_file(path)
    if path.stat().st_size != artifact["bytes"] or file_sha(path) != artifact["sha256"]:
        fail("Archive checksum or size mismatch")
    expected = {member["path"]: member for member in artifact["members"]}
    with tempfile.TemporaryFile() as raw:
        total = 0
        try:
            with gzip.open(path, "rb") as compressed:
                while block := compressed.read(1024 * 1024):
                    total += len(block)
                    if total > MAX_UNPACKED_BYTES:
                        fail("Unpacked archive exceeds the size limit")
                    raw.write(block)
        except (OSError, EOFError, zlib.error) as error:
            raise ReleaseError("Gzip integrity check failed") from error
        raw.seek(0)
        seen = set()
        final_end = 0
        try:
            with tarfile.open(fileobj=raw, mode="r:") as archive:
                for member in archive:
                    name = safe_path(member.name)
                    if len(seen) >= MAX_FILES or name.casefold() in seen:
                        fail("Duplicate archive member")
                    seen.add(name.casefold())
                    if not member.isfile() or member.issym() or member.islnk() or member.sparse or set(member.pax_headers) - {"path"}:
                        fail("Only regular archive members are allowed")
                    if member.mode not in {0o644, 0o755} or member.uid != 0 or member.gid != 0:
                        fail("Unsafe archive ownership or permissions")
                    record = expected.get(name)
                    if record is None or not allowed_member(artifact["kind"], name) or member.size != record["bytes"]:
                        fail("Archive member differs from its manifest")
                    stream = archive.extractfile(member)
                    if stream is None:
                        fail("Archive member cannot be read")
                    digest = hashlib.sha256()
                    count = 0
                    with stream:
                        for block in iter(lambda: stream.read(1024 * 1024), b""):
                            count += len(block)
                            digest.update(block)
                    if count != record["bytes"] or digest.hexdigest() != record["sha256"]:
                        fail("Member checksum mismatch")
                    final_end = member.offset_data + ((member.size + 511) // 512) * 512
        except (tarfile.TarError, OSError) as error:
            raise ReleaseError("Invalid tar archive") from error
        if seen != {name.casefold() for name in expected}:
            fail("Archive and manifest member lists differ")
        if total - final_end < 1024:
            fail("Tar end markers are missing")
        raw.seek(final_end)
        for block in iter(lambda: raw.read(1024 * 1024), b""):
            if block.strip(b"\0"):
                fail("Unexpected data follows the tar payload")
    return {"files": len(seen), "unpacked_bytes": total, "sha256": artifact["sha256"]}


def verify_package(directory: Path, expected_manifest_sha: str | None = None) -> dict:
    regular_directory(directory)
    manifest = checked_file(directory, "manifest.json")
    if expected_manifest_sha is not None:
        check_sha(expected_manifest_sha)
        if file_sha(manifest) != expected_manifest_sha:
            fail("Manifest checksum differs from the trusted value")
    data = manifest_data(manifest)
    if {child.name for child in directory.iterdir()} != {"manifest.json", *data["artifacts"]}:
        fail("Package contains files outside the manifest")
    results = {name: verify_archive(checked_file(directory, name), artifact) for name, artifact in data["artifacts"].items()}
    return {"passed": True, "release": data["release"], "database_policy": data["database"], "database_checked": False, "manifest_sha256": file_sha(manifest), "artifacts": results}


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("directory", type=Path, help="Package directory; no files are extracted")
    parser.add_argument("--manifest-sha256", help="Trusted manifest SHA-256, obtained separately")
    args = parser.parse_args()
    try:
        print(json.dumps(verify_package(args.directory, args.manifest_sha256), ensure_ascii=False))
        return 0
    except (ReleaseError, OSError) as error:
        print(json.dumps({"passed": False, "error": str(error)}, ensure_ascii=False), file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
