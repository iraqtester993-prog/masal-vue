#!/usr/bin/env python3
"""Read-only checks of an installed release against a trusted package manifest."""

from __future__ import annotations

import argparse
import json
import os
import sys
from pathlib import Path

from verify_release import BLOCKED_SUFFIXES, DATABASES, MAX_FILES, ReleaseError, checked_file, fail, file_sha, manifest_data, regular_directory, regular_file, safe_name


def installed_files(root: Path, records: list[dict]) -> dict:
    for record in records:
        path = checked_file(root, record["path"])
        if path.stat().st_size != record["bytes"] or file_sha(path) != record["sha256"]:
            fail(f"Installed checksum mismatch: {record['path']}")
    return {"files": len(records), "matched": True}


def inventory(root: Path, expected: set[str]) -> dict:
    regular_directory(root)
    extras = []
    duplicates = {}
    total = 0
    file_count = 0
    for directory, subdirs, names in os.walk(root, followlinks=False):
        current = Path(directory)
        regular_directory(current)
        for name in subdirs:
            regular_directory(current / name)
        for name in names:
            file_count += 1
            if file_count > MAX_FILES * 3:
                fail("Installed inventory exceeds the file limit")
            path = current / name
            regular_file(path)
            relative = path.relative_to(root).as_posix()
            total += path.stat().st_size
            if relative not in expected:
                if any(part.casefold().startswith(".env") for part in Path(relative).parts) or path.suffix.casefold() in BLOCKED_SUFFIXES | {".php", ".phar", ".phtml", ".tar"}:
                    fail(f"Unapproved private or executable web file: {relative}")
                extras.append(relative)
            digest = file_sha(path)
            duplicates.setdefault(digest, []).append(relative)
    duplicate_groups = [paths for paths in duplicates.values() if len(paths) > 1]
    return {"files": file_count, "bytes": total, "additional_files": sorted(extras), "identical_content_groups": duplicate_groups, "cleanup_performed": False}


def backend_inventory(root: Path, records: list[dict]) -> dict:
    expected = {record["path"] for record in records}
    actual = set()
    for folder in ("app", "config", "routes", "resources", "database/migrations", "database/seeders", "database/factories", "public"):
        base = root / folder
        regular_directory(base)
        for directory, subdirs, names in os.walk(base, followlinks=False):
            current = Path(directory)
            regular_directory(current)
            for name in subdirs:
                regular_directory(current / name)
            for name in names:
                regular_file(current / name)
                if name != ".gitignore":
                    actual.add((current / name).relative_to(root).as_posix())
                if len(actual) > MAX_FILES:
                    fail("Installed backend inventory exceeds the file limit")
    return {"source_files": len(actual), "additional_source_files": sorted(actual - expected), "runtime_and_dependencies_excluded": True, "cleanup_performed": False}


def audit_hosting(backend_root: Path, web_root: Path, expected_release: str, manifest_path: Path, expected_database: str = "dananiriq_masalprod", public_root: Path | None = None, manifest_sha256: str | None = None) -> dict:
    safe_name(expected_release)
    if expected_database not in DATABASES:
        fail("Database is outside the release allowlist")
    if not backend_root.is_absolute() or not web_root.is_absolute():
        fail("Hosting roots must be absolute paths")
    regular_directory(backend_root)
    regular_directory(backend_root / "releases")
    regular_directory(web_root)
    data = manifest_data(manifest_path)
    manifest_sha = file_sha(manifest_path)
    if manifest_sha256 is not None and manifest_sha != manifest_sha256:
        fail("Manifest checksum differs from the trusted value")
    if data["release"] != expected_release or data["database"] != expected_database:
        fail("Expected release or database policy differs from the manifest")
    current = backend_root / "current"
    expected = backend_root / "releases" / expected_release
    regular_directory(expected)
    if not current.is_symlink() or current.resolve(strict=True) != expected.resolve(strict=True):
        fail("The current pointer differs from the expected release")
    if expected.resolve() != expected.absolute():
        fail("Release path contains a link")
    backend_records = data["artifacts"]["backend.tar.gz"]["members"]
    results = {"backend": installed_files(expected, backend_records), "backend_inventory": backend_inventory(expected, backend_records)}
    web_records = data["artifacts"]["portals.tar.gz"]["members"]
    results["portals"] = installed_files(web_root, web_records)
    results["web_inventory"] = inventory(web_root, {record["path"] for record in web_records})
    if "public-site.tar.gz" in data["artifacts"]:
        if public_root is None or not public_root.is_absolute():
            fail("A public root is required for the public-site artifact")
        regular_directory(public_root)
        results["public"] = installed_files(public_root, data["artifacts"]["public-site.tar.gz"]["members"])
    cache = checked_file(expected, "bootstrap/cache/config.php")
    if not current.is_symlink() or current.resolve(strict=True) != expected.resolve(strict=True):
        fail("The current pointer changed during the audit")
    return {"passed": True, "release": expected_release, "manifest_sha256": manifest_sha, "cached_config_sha256": file_sha(cache), "database_policy": expected_database, "database_policy_matches": True, "database_checked": False, "database_check_tool": "deployment/cpanel/audit_database.php (run separately with the pinned config hash)", "read_only": True, "results": results}


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--backend-root", required=True, type=Path)
    parser.add_argument("--web-root", required=True, type=Path, help="Root containing the admin, agents and pos directories")
    parser.add_argument("--expected-release", required=True)
    parser.add_argument("--expected-database", default="dananiriq_masalprod", choices=sorted(DATABASES), help="Policy comparison only; no SQL is executed")
    parser.add_argument("--manifest", required=True, type=Path, help="Trusted release manifest")
    parser.add_argument("--manifest-sha256", help="Manifest SHA-256 received separately")
    parser.add_argument("--public-root", type=Path, help="Required when the manifest includes the public site")
    args = parser.parse_args()
    try:
        print(json.dumps(audit_hosting(args.backend_root, args.web_root, args.expected_release, args.manifest, args.expected_database, args.public_root, args.manifest_sha256), ensure_ascii=False))
        return 0
    except (ReleaseError, OSError) as error:
        print(json.dumps({"passed": False, "error": str(error), "read_only": True, "database_checked": False}, ensure_ascii=False), file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
