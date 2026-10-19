"""Immutable-file maintenance checks against disposable synthetic hosting trees."""

from __future__ import annotations

import copy
import json
import os
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "cpanel"))
import deduplicate_immutable as dedupe


class ImmutableAssetsTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.base = Path(self.temp.name).resolve()
        self.stage = self.base / "private-maintenance"
        self.stage.mkdir()
        self.identity = dict(base=str(self.base), stage=str(self.stage), expected_release="release-example",
                             config_sha256="a" * 64, env_sha256="b" * 64)
        for relative in ["public_html"] + ["public_html/masal/portals/" + role for role in dedupe.ROLES]:
            root = self.base / relative
            (root / "assets").mkdir(parents=True)
            (root / "index.html").write_text('<script src="/assets/index-12345678.js"></script>', encoding="utf-8")
            (root / "assets/index-12345678.js").write_text('import("./route-12345678.js")', encoding="utf-8")
            (root / "assets/route-12345678.js").write_text("export default 1", encoding="utf-8")
            (root / "assets/style-12345678.css").write_text("body{}", encoding="utf-8")
            (root / ".htaccess").write_text("Options -Indexes", encoding="utf-8")
            (root / ".vite").mkdir()
            (root / ".vite/manifest.json").write_text(json.dumps({
                "index": {"file": "assets/index-12345678.js", "css": ["assets/style-12345678.css"], "dynamicImports": ["route"]},
                "route": {"file": "assets/route-12345678.js"},
            }), encoding="utf-8")
        for relative in ["public_html/downloads", "public_html/masal/portals/pos/downloads"]:
            root = self.base / relative
            root.mkdir()
            (root / "example-pos-2.0.0.apk").write_bytes(b"signed-package-fixture")
        self.unique = self.base / "public_html/masal/portals/pos/assets/unique-12345678.js"
        self.unique.write_text("export default 99", encoding="utf-8")
        for path in self.base.rglob("*"):
            if path.is_file():
                os.utime(path, ns=(1800000000123456789, 1800000000123456789))

    def plan(self):
        return dedupe.build_plan(self.base, self.identity)

    def backup(self, plan):
        path = self.stage / "plan.json"
        dedupe.write_private(path, plan)
        pin = dedupe.file_sha(path)
        dedupe.archive_backup(self.base, self.stage, plan, pin)
        return pin, dedupe.load_json(self.stage / "backup-manifest.json")

    def test_every_url_and_unique_file_survives_with_exact_original_payload(self):
        plan = self.plan()
        self.assertEqual(plan["summary"]["groups"], 4)
        self.assertEqual(plan["summary"]["shared_paths"], 10)
        before = {name: row["sha256"] for name, row in plan["inventory"].items()}
        unique_inode = self.unique.stat().st_ino
        result = dedupe.apply_plan(self.base, plan)
        self.assertTrue(result["passed"])
        self.assertEqual(result["public_urls_removed"], 0)
        self.assertEqual(before, {name: row["sha256"] for name, row in dedupe.candidates(self.base).items()})
        self.assertEqual(self.unique.stat().st_ino, unique_inode)

    def test_different_http_timestamp_is_not_merged(self):
        path = self.base / "public_html/masal/portals/pos/assets/style-12345678.css"
        os.utime(path, ns=(1800000001123456789, 1800000001123456789))
        self.assertEqual(self.plan()["summary"]["shared_paths"], 9)

    def test_subsecond_timestamp_difference_keeps_http_time(self):
        path = self.base / "public_html/masal/portals/pos/assets/style-12345678.css"
        os.utime(path, ns=(1800000000999999999, 1800000000999999999))
        result = dedupe.apply_plan(self.base, self.plan())
        self.assertTrue(result["all_payloads_and_http_last_modified_preserved"])
        self.assertEqual(path.stat().st_mtime_ns // 1000000000, 1800000000)

    def test_owner_group_and_permissions_must_all_match(self):
        inventory = dedupe.candidates(self.base)
        first = copy.deepcopy(next(iter(inventory.values())))
        other = copy.deepcopy(first)
        other.update(path="public_html/assets/another-12345678.js", inode=first["inode"] + 1)
        for key in ("uid", "gid", "mode", "dev"):
            changed = copy.deepcopy(other)
            changed[key] += 1
            self.assertEqual(dedupe.groups_for({first["path"]: first, changed["path"]: changed}), [])

    def test_existing_link_to_unreviewed_path_is_skipped(self):
        source = self.base / "public_html/assets/style-12345678.css"
        os.link(source, self.base / "unreviewed-copy.css")
        self.assertEqual(self.plan()["summary"]["groups"], 3)

    def test_mutable_and_private_files_are_rejected(self):
        inventory = dedupe.candidates(self.base)
        row = copy.deepcopy(next(iter(inventory.values())))
        row["path"] = "public_html/.env"
        with self.assertRaisesRegex(dedupe.DedupeError, "NON_IMMUTABLE"):
            dedupe.groups_for({row["path"]: row})
        (self.base / "public_html/downloads/app-release.apk").write_bytes(b"mutable")
        self.assertNotIn("public_html/downloads/app-release.apk", dedupe.candidates(self.base))

    def test_tampered_payload_blocks_all_changes(self):
        plan = self.plan()
        self.unique.write_text("new unrelated content", encoding="utf-8")
        with self.assertRaisesRegex(dedupe.DedupeError, "PUBLIC_PAYLOAD_OR_METADATA_CHANGED"):
            dedupe.apply_plan(self.base, plan)
        first = plan["groups"][0]
        self.assertNotEqual((self.base / first["canonical"]).stat().st_ino, (self.base / first["targets"][0]).stat().st_ino)

    def test_removed_unique_url_blocks_changes(self):
        plan = self.plan()
        self.unique.unlink()
        with self.assertRaisesRegex(dedupe.DedupeError, "PUBLIC_URL_SET_CHANGED"):
            dedupe.apply_plan(self.base, plan)

    def test_changed_service_worker_blocks_changes(self):
        path = self.base / "public_html/masal/portals/pos/sw.js"
        path.write_text("const CACHE='first'", encoding="utf-8")
        plan = self.plan()
        path.write_text("const CACHE='second'", encoding="utf-8")
        with self.assertRaisesRegex(dedupe.DedupeError, "CURRENT_WEB_CONTENT_CHANGED"):
            dedupe.apply_plan(self.base, plan)

    def test_missing_dynamic_import_blocks_planning(self):
        (self.base / "public_html/masal/portals/pos/assets/route-12345678.js").unlink()
        with self.assertRaisesRegex(dedupe.DedupeError, "CURRENT_TRANSITIVE_ASSET_MISSING"):
            self.plan()

    def test_archive_contains_full_copies_and_restores_original_metadata(self):
        plan = self.plan()
        pin, manifest = self.backup(plan)
        self.assertTrue(dedupe.verify_backup(self.stage / "before.tar.gz", manifest, plan, pin)["passed"])
        dedupe.apply_plan(self.base, plan)
        restored = dedupe.restore_backup(self.base, self.stage / "before.tar.gz", manifest, plan, pin)
        self.assertTrue(restored["original_bytes_and_metadata_restored"])
        for name, row in manifest["files"].items():
            value = dedupe.describe(self.base, name)
            for key in ("sha256", "bytes", "mode", "uid", "gid", "mtime_ns"):
                self.assertEqual(value[key], row[key])
        self.assertTrue(self.unique.is_file())

    def test_archive_corruption_is_rejected(self):
        plan = self.plan()
        pin, manifest = self.backup(plan)
        with (self.stage / "before.tar.gz").open("ab") as output:
            output.write(b"bad CRC or trailing data")
        with self.assertRaisesRegex(dedupe.DedupeError, "BACKUP_ARCHIVE_PIN"):
            dedupe.verify_backup(self.stage / "before.tar.gz", manifest, plan, pin)

    def test_unapproved_archive_membership_is_rejected(self):
        plan = self.plan()
        pin, manifest = self.backup(plan)
        manifest["files"].pop(next(iter(manifest["files"])))
        with self.assertRaisesRegex(dedupe.DedupeError, "BACKUP_MEMBERSHIP_MISMATCH"):
            dedupe.verify_backup(self.stage / "before.tar.gz", manifest, plan, pin)

    def test_restore_will_not_overwrite_a_later_public_change(self):
        plan = self.plan()
        pin, manifest = self.backup(plan)
        dedupe.apply_plan(self.base, plan)
        self.unique.write_text("a later deployment", encoding="utf-8")
        with self.assertRaisesRegex(dedupe.DedupeError, "PUBLIC_PAYLOAD_OR_METADATA_CHANGED"):
            dedupe.restore_backup(self.base, self.stage / "before.tar.gz", manifest, plan, pin)

    def test_local_verify_backup_cli_creates_pinned_receipt_without_server(self):
        plan = self.plan()
        pin, _ = self.backup(plan)
        archive, manifest = self.stage / "before.tar.gz", self.stage / "backup-manifest.json"
        receipt = self.base / "desktop-receipt.json"
        result = subprocess.run([sys.executable, str(Path(dedupe.__file__)), "verify-backup",
                                 "--plan", str(self.stage / "plan.json"), "--plan-sha256", pin,
                                 "--archive", str(archive), "--archive-sha256", dedupe.file_sha(archive),
                                 "--backup-manifest", str(manifest), "--backup-manifest-sha256", dedupe.file_sha(manifest),
                                 "--receipt-output", str(receipt)], capture_output=True, text=True)
        self.assertEqual(result.returncode, 0, result.stderr)
        evidence = json.loads(result.stdout)
        self.assertEqual(evidence["receipt_sha256"], dedupe.file_sha(receipt))
        self.assertTrue(dedupe.load_json(receipt)["complete_member_payload_and_gzip_crc_verified"])
        self.assertFalse(evidence["database_accessed"])

    def test_parent_traversal_is_rejected(self):
        with self.assertRaisesRegex(dedupe.DedupeError, "UNSAFE_RELATIVE_PATH"):
            dedupe.checked(self.base, "../private")


if __name__ == "__main__":
    unittest.main()
