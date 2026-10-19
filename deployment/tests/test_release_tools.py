"""Release boundary tests using synthetic files; no live service is contacted."""

from __future__ import annotations

import gzip
import hashlib
import io
import json
import sys
import tarfile
import tempfile
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from hosting_guard import audit_hosting
from package_release import build_package
from verify_release import ReleaseError, manifest_data, safe_path, verify_package


class ReleaseBoundaryTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.source = self.root / "source"
        self.source.mkdir()
        for name in ("artisan", "composer.json", "composer.lock", "bootstrap/app.php", "bootstrap/providers.php", "public/index.php", "routes/web.php", "app/Http/Example.php", "config/app.php", "database/migrations/example.php", "database/seeders/DatabaseSeeder.php", "database/factories/ExampleFactory.php", "resources/views/example.blade.php"):
            self.write(self.source / "backend" / name, f"source:{name}")
        for portal in ("admin", "agents", "pos", "public"):
            for name in ("index.html", "version.json", ".vite/manifest.json", "assets/index-12345678.js", "assets/style-12345678.css"):
                self.write(self.source / "frontend/dist" / portal / name, f"{portal}:{name}")
        for name in ("masal-api.php", "portal.htaccess", "public.htaccess"):
            self.write(self.source / "deployment/cpanel" / name, f"template:{name}")
        self.package = self.root / "release"

    @staticmethod
    def write(path, content):
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(content, encoding="utf-8")

    def package_now(self, public=False):
        return build_package(self.source, self.package, "example-139", public)

    def change_manifest(self, action):
        path = self.package / "manifest.json"
        data = json.loads(path.read_text(encoding="utf-8"))
        action(data)
        path.write_text(json.dumps(data), encoding="utf-8")

    def replace_archive(self, name, members, trailing=b""):
        buffer = io.BytesIO()
        with tarfile.open(fileobj=buffer, mode="w") as archive:
            for member, content, kind in members:
                info = tarfile.TarInfo(member)
                info.type = kind
                info.size = len(content) if kind == tarfile.REGTYPE else 0
                if kind == tarfile.SYMTYPE:
                    info.linkname = "../../outside"
                archive.addfile(info, io.BytesIO(content) if info.size else None)
        payload = gzip.compress(buffer.getvalue() + trailing)
        (self.package / name).write_bytes(payload)
        self.change_manifest(lambda data: data["artifacts"][name].update(bytes=len(payload), sha256=hashlib.sha256(payload).hexdigest()))

    def archive_members(self, name):
        with tarfile.open(self.package / name, "r:gz") as archive:
            return [(entry.name, archive.extractfile(entry).read(), tarfile.REGTYPE) for entry in archive]

    def test_valid_package_verifies_both_artifacts_and_has_no_database_access(self):
        result = self.package_now()
        self.assertTrue(result["passed"])
        self.assertFalse(result["database_checked"])
        self.assertEqual(set(result["artifacts"]), {"backend.tar.gz", "portals.tar.gz"})

    def test_public_build_is_only_included_explicitly(self):
        self.package_now(True)
        self.assertIn("public-site.tar.gz", verify_package(self.package)["artifacts"])

    def test_environment_dependencies_and_local_database_are_not_packaged(self):
        self.write(self.source / "backend/.env", "SECRET-NOT-FOR-RELEASE")
        self.write(self.source / "backend/database/database.sqlite", "PRIVATE")
        self.write(self.source / "backend/vendor/library.php", "DEPENDENCY")
        self.package_now()
        with tarfile.open(self.package / "backend.tar.gz", "r:gz") as archive:
            self.assertFalse(any(".env" in entry.name or "vendor" in entry.name or "sqlite" in entry.name for entry in archive))

    def test_hidden_secret_inside_selected_source_is_rejected(self):
        self.write(self.source / "backend/app/.env.production", "SECRET")
        with self.assertRaises(ReleaseError):
            self.package_now()

    def test_database_dump_inside_selected_source_is_rejected(self):
        self.write(self.source / "backend/resources/backup.sql", "PRIVATE")
        with self.assertRaises(ReleaseError):
            self.package_now()

    def test_executable_disguised_as_web_asset_is_rejected(self):
        self.write(self.source / "frontend/dist/pos/assets/credentials.php", "PRIVATE")
        with self.assertRaises(ReleaseError):
            self.package_now()

    def test_existing_output_is_never_overwritten(self):
        self.package.mkdir()
        marker = self.package / "keep.txt"
        marker.write_text("preserve")
        with self.assertRaises(ReleaseError):
            self.package_now()
        self.assertEqual(marker.read_text(), "preserve")

    def test_output_inside_source_is_rejected(self):
        with self.assertRaises(ReleaseError):
            build_package(self.source, self.source / "bundle", "example-139")

    def test_missing_bootstrap_provider_registration_fails_closed(self):
        (self.source / "backend/bootstrap/providers.php").unlink()
        with self.assertRaises(OSError):
            self.package_now()

    def test_source_symlink_does_not_copy_external_content(self):
        external = self.root / "outside-secret"
        external.write_text("SECRET")
        try:
            (self.source / "backend/app/link.php").symlink_to(external)
        except OSError:
            self.skipTest("Symlink creation is unavailable for this user")
        with self.assertRaises(ReleaseError):
            self.package_now()

    def test_non_production_database_policy_is_rejected(self):
        with self.assertRaises(ReleaseError):
            build_package(self.source, self.package, "example-139", database="dananiriq_masalstage")

    def test_portable_unsafe_paths_are_rejected(self):
        for name in ("../secret", "/absolute", "C:/private", "a\\b", "a//b", "a/./b", "a/../b", "a/file. ", "a\x00b", "a/CON.txt", "LPT1/data"):
            with self.subTest(name=name), self.assertRaises(ReleaseError):
                safe_path(name)

    def test_modified_archive_is_rejected_before_reading_members(self):
        self.package_now()
        with (self.package / "backend.tar.gz").open("ab") as target:
            target.write(b"tampered")
        with self.assertRaises(ReleaseError):
            verify_package(self.package)

    def test_gzip_crc_is_checked_even_when_compressed_hash_is_updated(self):
        self.package_now()
        path = self.package / "backend.tar.gz"
        payload = bytearray(path.read_bytes())
        payload[-8] ^= 1
        path.write_bytes(payload)
        self.change_manifest(lambda data: data["artifacts"]["backend.tar.gz"].update(sha256=hashlib.sha256(payload).hexdigest()))
        with self.assertRaisesRegex(ReleaseError, "Gzip"):
            verify_package(self.package)

    def test_duplicate_archive_members_are_rejected_even_if_equal(self):
        self.package_now()
        members = self.archive_members("backend.tar.gz")
        self.replace_archive("backend.tar.gz", [*members, members[0]])
        with self.assertRaisesRegex(ReleaseError, "Duplicate"):
            verify_package(self.package)

    def test_tar_traversal_and_link_are_never_extracted(self):
        for name, kind in (("../outside", tarfile.REGTYPE), ("app/link.php", tarfile.SYMTYPE)):
            with self.subTest(kind=kind):
                self.package_now()
                self.replace_archive("backend.tar.gz", [*self.archive_members("backend.tar.gz"), (name, b"secret", kind)])
                with self.assertRaises(ReleaseError):
                    verify_package(self.package)
                self.assertFalse((self.root / "outside").exists())
                for item in self.package.iterdir():
                    item.unlink()
                self.package.rmdir()

    def test_payload_after_tar_end_markers_is_rejected(self):
        self.package_now()
        self.replace_archive("backend.tar.gz", self.archive_members("backend.tar.gz"), b"unexpected-after-tar")
        with self.assertRaisesRegex(ReleaseError, "follows"):
            verify_package(self.package)

    def test_member_content_change_is_detected_with_correct_archive_hash(self):
        self.package_now()
        members = self.archive_members("backend.tar.gz")
        name, content, kind = members[0]
        members[0] = (name, content[:-1] + b"X", kind)
        self.replace_archive("backend.tar.gz", members)
        with self.assertRaisesRegex(ReleaseError, "checksum"):
            verify_package(self.package)

    def test_old_code_copies_inside_selected_source_are_rejected(self):
        self.write(self.source / "backend/app/Controller.php.bak", "OLD-CODE")
        with self.assertRaises(ReleaseError):
            self.package_now()

    def test_missing_manifest_member_and_extra_package_files_are_rejected(self):
        self.package_now()
        (self.package / "notes.txt").write_text("not part of the release")
        with self.assertRaises(ReleaseError):
            verify_package(self.package)

    def test_duplicate_json_keys_and_manifest_paths_are_rejected(self):
        self.package_now()
        self.change_manifest(lambda data: data["artifacts"]["backend.tar.gz"]["members"].append(data["artifacts"]["backend.tar.gz"]["members"][0]))
        with self.assertRaisesRegex(ReleaseError, "Duplicate"):
            verify_package(self.package)
        manifest = self.package / "manifest.json"
        manifest.write_text('{"format":"one","format":"two"}')
        with self.assertRaisesRegex(ReleaseError, "Duplicate JSON"):
            manifest_data(manifest)

    def test_trusted_manifest_hash_cannot_be_replaced_by_new_manifest(self):
        self.package_now()
        trusted = hashlib.sha256((self.package / "manifest.json").read_bytes()).hexdigest()
        self.change_manifest(lambda data: data.update(release="other-139"))
        with self.assertRaisesRegex(ReleaseError, "trusted"):
            verify_package(self.package, trusted)

    def prepare_host(self):
        self.package_now()
        backend = self.root / "host/backend"
        release = backend / "releases/example-139"
        web = self.root / "host/web"
        for archive_name, target in (("backend.tar.gz", release), ("portals.tar.gz", web)):
            for name, contents, unused in self.archive_members(archive_name):
                destination = target / name
                destination.parent.mkdir(parents=True, exist_ok=True)
                destination.write_bytes(contents)
        self.write(release / "bootstrap/cache/config.php", "<?php return ['do_not_execute' => 'secret'];")
        (release / "resources").mkdir(exist_ok=True)
        try:
            (backend / "current").symlink_to(release, target_is_directory=True)
        except OSError:
            self.skipTest("Directory symlink creation is unavailable for this user")
        return backend, web, release

    def test_hosting_audit_reads_files_without_executing_config_or_changing_them(self):
        backend, web, release = self.prepare_host()
        before = {str(path): path.read_bytes() for path in self.root.rglob("*") if path.is_file()}
        result = audit_hosting(backend, web, "example-139", self.package / "manifest.json")
        self.assertTrue(result["passed"])
        self.assertTrue(result["read_only"])
        self.assertFalse(result["database_checked"])
        after = {str(path): path.read_bytes() for path in self.root.rglob("*") if path.is_file()}
        self.assertEqual(before, after)
        self.assertNotIn("secret", json.dumps(result))

    def test_hosting_audit_rejects_wrong_current_pointer_and_database(self):
        backend, web, release = self.prepare_host()
        with self.assertRaises(ReleaseError):
            audit_hosting(backend, web, "example-139", self.package / "manifest.json", "other_database")
        (backend / "current").unlink()
        (backend / "current").symlink_to(web, target_is_directory=True)
        with self.assertRaisesRegex(ReleaseError, "pointer"):
            audit_hosting(backend, web, "example-139", self.package / "manifest.json")

    def test_hosting_inventory_reports_extra_assets_without_deleting_them(self):
        backend, web, release = self.prepare_host()
        extra = web / "pos/assets/old-12345678.js"
        self.write(extra, "old-client-asset")
        result = audit_hosting(backend, web, "example-139", self.package / "manifest.json")
        self.assertIn("pos/assets/old-12345678.js", result["results"]["web_inventory"]["additional_files"])
        self.assertTrue(extra.is_file())
        self.assertFalse(result["results"]["web_inventory"]["cleanup_performed"])

    def test_hosting_inventory_rejects_exposed_private_files_without_removing_them(self):
        backend, web, release = self.prepare_host()
        extra = web / "admin/old-backup.sql"
        self.write(extra, "PRIVATE")
        with self.assertRaisesRegex(ReleaseError, "private or executable"):
            audit_hosting(backend, web, "example-139", self.package / "manifest.json")
        self.assertEqual(extra.read_text(), "PRIVATE")


if __name__ == "__main__":
    unittest.main()
