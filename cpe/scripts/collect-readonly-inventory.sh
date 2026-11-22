#!/bin/sh
# Read-only hardware inventory for OpenWrt/Linux-compatible router shells.
# This script does not write flash, change settings, or enable remote access.
set -eu

section() { printf '\n===== %s =====\n' "$1"; }

section "CPU"
cat /proc/cpuinfo 2>/dev/null || true

section "MEMORY"
cat /proc/meminfo 2>/dev/null || true

section "FLASH PARTITIONS (READ ONLY)"
cat /proc/mtd 2>/dev/null || true

section "KERNEL COMMAND LINE"
cat /proc/cmdline 2>/dev/null || true

section "BOARD IDENTIFICATION"
if command -v ubus >/dev/null 2>&1; then
  ubus call system board 2>/dev/null || true
fi

section "KERNEL LOG (FIRST 160 LINES)"
dmesg 2>/dev/null | head -160 || true

printf '\nInventory complete. Redact serial numbers, MAC addresses, Wi-Fi keys, tokens, and public IPs before sharing.\n'
