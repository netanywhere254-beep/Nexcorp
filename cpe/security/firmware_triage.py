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


def validate_scope(scope_path: Path, firmware_sha256: str) -> dict[str, Any]:
    try:
        scope = json.loads(scope_path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError) as exc:
        raise ScopeError(f"Cannot read valid engagement scope: {exc}") from exc

    required = ("engagement_id", "target", "approved_by", "approval_reference",
                "expires_at", "firmware_sha256", "permitted_actions")
    missing = [key for key in required if not scope.get(key)]
    if missing:
        raise ScopeError("Missing scope fields: " + ", ".join(missing))
    if scope["firmware_sha256"].lower() != firmware_sha256:
        raise ScopeError("Scope hash does not match this exact firmware file")
    if "static_firmware_triage" not in scope["permitted_actions"]:
        raise ScopeError("Scope does not permit static_firmware_triage")

    try:
        expires = dt.datetime.fromisoformat(scope["expires_at"].replace("Z", "+00:00"))
    except (TypeError, ValueError) as exc:
        raise ScopeError("expires_at must be an ISO-8601 timestamp") from exc
    if expires.tzinfo is None:
        raise ScopeError("expires_at must include a timezone")
    if expires <= dt.datetime.now(dt.timezone.utc):
        raise ScopeError("Engagement scope has expired")
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


def inspect_firmware(path: Path, scope_path: Path) -> dict[str, Any]:
    if not path.is_file():
        raise ValueError("Firmware path must point to a regular file")
    size = path.stat().st_size
    if size <= 0 or size > MAX_FIRMWARE_BYTES:
        raise ValueError(f"Firmware size must be between 1 byte and {MAX_FIRMWARE_BYTES} bytes")

    digest = sha256_file(path)
    scope = validate_scope(scope_path, digest)
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
            "target": scope["target"],
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
    parser.add_argument("--output", type=Path, help="Optional JSON report path")
    args = parser.parse_args()
    try:
        report = inspect_firmware(args.firmware, args.scope)
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
