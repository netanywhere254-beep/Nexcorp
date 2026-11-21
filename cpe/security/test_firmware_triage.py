import datetime as dt
import hashlib
import json
import tempfile
import unittest
from pathlib import Path

from firmware_triage import ScopeError, inspect_firmware, validate_scope


class FirmwareTriageTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.root = Path(self.temp.name)
        self.firmware = self.root / "sample.bin"
        self.payload = b"\x7fELF" + (b"router firmware sample string " * 64)
        self.firmware.write_bytes(self.payload)
        self.scope = self.root / "scope.json"
        self.scope.write_text(json.dumps({
            "engagement_id": "NP-TEST-001",
            "tenant_id": "tenant-test",
            "asset_owner": "lab-owner",
            "targets": [{"asset_id": "lab-router-01", "manufacturer": "Test", "model": "Router", "hardware_revision": "A1", "firmware_hash_sha256": hashlib.sha256(self.payload).hexdigest()}],
            "authorisation_reference": "CHANGE-1001",
            "starts_at": (dt.datetime.now(dt.timezone.utc) - dt.timedelta(minutes=5)).isoformat(),
            "operator_id": "operator-1",
            "reviewer_id": "reviewer-1",
            "stop_conditions": ["unexpected outage"],
            "approval_status": "approved",
            "expires_at": (dt.datetime.now(dt.timezone.utc) + dt.timedelta(hours=1)).isoformat(),
            "allowed_methods": ["static_analysis"],
        }), encoding="utf-8")

    def tearDown(self):
        self.temp.cleanup()

    def test_valid_scope_produces_read_only_report(self):
        report = inspect_firmware(self.firmware, self.scope, "lab-router-01")
        self.assertEqual(report["mode"], "offline_static_read_only")
        self.assertIn("ELF executable", report["firmware"]["detected_signatures"])
        self.assertEqual(report["firmware"]["sha256"], hashlib.sha256(self.payload).hexdigest())

    def test_hash_mismatch_is_rejected(self):
        scope = json.loads(self.scope.read_text())
        scope["targets"][0]["firmware_hash_sha256"] = "0" * 64
        self.scope.write_text(json.dumps(scope))
        with self.assertRaisesRegex(ScopeError, "exact asset and firmware"):
            validate_scope(self.scope, hashlib.sha256(self.payload).hexdigest(), "lab-router-01")

    def test_expired_scope_is_rejected(self):
        scope = json.loads(self.scope.read_text())
        scope["expires_at"] = "2000-01-01T00:00:00+00:00"
        self.scope.write_text(json.dumps(scope))
        with self.assertRaisesRegex(ScopeError, "expired"):
            validate_scope(self.scope, hashlib.sha256(self.payload).hexdigest(), "lab-router-01")

    def test_scope_without_triage_permission_is_rejected(self):
        scope = json.loads(self.scope.read_text())
        scope["allowed_methods"] = ["read_only_inventory"]
        self.scope.write_text(json.dumps(scope))
        with self.assertRaisesRegex(ScopeError, "does not permit"):
            validate_scope(self.scope, hashlib.sha256(self.payload).hexdigest())

    def test_secret_like_strings_are_redacted(self):
        from firmware_triage import printable_strings
        values = printable_strings(b"password=do-not-print-this-value")
        self.assertTrue(values)
        self.assertTrue(values[0].startswith("[REDACTED"))

    def test_empty_firmware_is_rejected(self):
        self.firmware.write_bytes(b"")
        with self.assertRaisesRegex(ValueError, "size"):
            inspect_firmware(self.firmware, self.scope, "lab-router-01")


if __name__ == "__main__":
    unittest.main()
