#!/usr/bin/env python3
"""Offline, non-executing firmware triage for authorised NetPulse assessments.

This tool never flashes, boots, extracts, decrypts, brute-forces, or modifies firmware.
It reads a bounded sample and emits a JSON report.
"""
from __future__ import annotations

import argparse
import datetime as dt
import hashlib
import json
import math
import re
import sys
from pathlib import Path
from typing import Any

MAX_FIRMWARE_BYTES = 512 * 1024 * 1024
SAMPLE_BYTES = 8 * 1024 * 1024
MAX_STRINGS = 80
MIN_STRING_LENGTH = 8

MAGIC = (
    (b"\x27\x05\x19\x56", "u-boot legacy image"),
    (b"\xd0\x0d\xfe\xed", "device tree blob"),
    (b"hsqs", "SquashFS filesystem"),
    (b"sqsh", "possible SquashFS filesystem"),
    (b"\x1f\x8b", "gzip stream"),
    (b"PK\x03\x04", "ZIP archive"),
    (b"ustar", "tar archive marker"),
    (b"\x7fELF", "ELF executable"),
    (b"\x68\x73\x71\x73", "SquashFS filesystem"),
)

SECRET_HINTS = re.compile(
    r"(password|passwd|secret|token|private.?key|api.?key|credential)",
    re.IGNORECASE,
)


class ScopeError(ValueError):
    pass


def sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as stream:
        for chunk in iter(lambda: stream.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def validate_scope(scope_path: Path, firmware_sha256: str, asset_id: str) -> dict[str, Any]:
    try:
        scope = json.loads(scope_path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError) as exc:
        raise ScopeError(f"Cannot read valid engagement scope: {exc}") from exc

    required = ("engagement_id", "tenant_id", "asset_owner", "authorisation_reference",
                "targets", "allowed_methods", "starts_at", "expires_at",
                "operator_id", "reviewer_id", "stop_conditions", "approval_status")
    missing = [key for key in required if not scope.get(key)]
    if missing:
        raise ScopeError("Missing scope fields: " + ", ".join(missing))
    if scope["approval_status"] != "approved":
        raise ScopeError("Engagement must have approved status")
    if "static_analysis" not in scope["allowed_methods"]:
        raise ScopeError("Scope does not permit static_analysis")

    now = dt.datetime.now(dt.timezone.utc)
    try:
        starts = dt.datetime.fromisoformat(scope["starts_at"].replace("Z", "+00:00"))
        expires = dt.datetime.fromisoformat(scope["expires_at"].replace("Z", "+00:00"))
    except (TypeError, ValueError) as exc:
        raise ScopeError("starts_at and expires_at must be ISO-8601 timestamps") from exc
    if starts.tzinfo is None or expires.tzinfo is None:
        raise ScopeError("Scope timestamps must include a timezone")
    if starts > now:
        raise ScopeError("Engagement scope is not active yet")
    if expires <= now:
        raise ScopeError("Engagement scope has expired")
    if expires <= starts:
        raise ScopeError("Engagement expiry must be later than its start")

    matches = [
        target for target in scope["targets"]
        if target.get("asset_id") == asset_id
        and target.get("firmware_hash_sha256", "").lower() == firmware_sha256
    ]
    if len(matches) != 1:
        raise ScopeError("Scope must identify this exact asset and firmware SHA-256 once")
    return scope

def entropy(data: bytes) -> float:
    if not data:
        return 0.0
    counts = [0] * 256
    for byte in data:
        counts[byte] += 1
    length = len(data)
    return round(-sum((count / length) * math.log2(count / length)
                      for count in counts if count), 4)


def printable_strings(data: bytes) -> list[str]:
    found = re.findall(rb"[ -~]{%d,}" % MIN_STRING_LENGTH, data)
    output: list[str] = []
    for raw in found:
        value = raw.decode("ascii", errors="replace")
        # Do not disclose likely secrets in the report.
        if SECRET_HINTS.search(value):
            output.append("[REDACTED: possible sensitive configuration string]")
        else:
            output.append(value[:240])
        if len(output) >= MAX_STRINGS:
            break
    return output


def inspect_firmware(path: Path, scope_path: Path, asset_id: str) -> dict[str, Any]:
    if not path.is_file():
        raise ValueError("Firmware path must point to a regular file")
    size = path.stat().st_size
    if size <= 0 or size > MAX_FIRMWARE_BYTES:
        raise ValueError(f"Firmware size must be between 1 byte and {MAX_FIRMWARE_BYTES} bytes")

    digest = sha256_file(path)
    scope = validate_scope(scope_path, digest, asset_id)
    with path.open("rb") as stream:
        sample = stream.read(SAMPLE_BYTES)
        header = sample[:64]

    signatures = [label for magic, label in MAGIC if magic in header]
    if not signatures:
        signatures = ["unknown format; requires manual identification"]

    return {
        "schema_version": "1.0",
        "tool": "NetPulse Firmware Triage",
        "mode": "offline_static_read_only",
        "engagement": {
            "engagement_id": scope["engagement_id"],
            "tenant_id": scope["tenant_id"],
            "asset_id": asset_id,
            "asset_owner": scope["asset_owner"],
            "operator_id": scope["operator_id"],
            "reviewer_id": scope["reviewer_id"],
            "approval_reference": scope["approval_reference"],
            "expires_at": scope["expires_at"],
        },
        "firmware": {
            "filename": path.name,
            "size_bytes": size,
            "sha256": digest,
            "detected_signatures": signatures,
            "sample_bytes_analysed": len(sample),
            "sample_entropy": entropy(sample),
            "readable_strings": printable_strings(sample),
        },
        "limitations": [
            "Signature matches are preliminary and are not proof of a complete firmware layout.",
            "Only the first bounded sample is inspected for signatures, entropy and strings.",
            "No extraction, emulation, decryption, password guessing, exploitation or device modification is performed.",
            "A human reviewer must verify vendor, exact model, hardware revision, image integrity and recovery path.",
        ],
        "next_review": "manual_format_and_hardware_validation",
    }


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--firmware", required=True, type=Path)
    parser.add_argument("--scope", required=True, type=Path)
    parser.add_argument("--asset-id", required=True, help="Exact asset_id registered in the engagement scope")
    parser.add_argument("--output", type=Path, help="Optional JSON report path")
    args = parser.parse_args()
    try:
        report = inspect_firmware(args.firmware, args.scope, args.asset_id)
    except (ValueError, OSError) as exc:
        print(json.dumps({"error": str(exc), "status": "rejected"}), file=sys.stderr)
        return 2

    rendered = json.dumps(report, indent=2)
    if args.output:
        args.output.write_text(rendered + "\n", encoding="utf-8")
    else:
        print(rendered)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
