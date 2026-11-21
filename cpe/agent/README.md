# NetPulse CPE Router Agent

This directory is the starting point for a small cross-platform agent intended to run as a service on validated Linux/OpenWrt hosts.

## Planned packaging
- OpenWrt .ipk, built separately for each supported architecture/release.
- Debian .deb, after validating the target OS and service integration.
- Native router firmware: separate board-specific image, not this package.

## Current state
This is an architecture starter, not a production agent. The first implementation uses a local Unix socket, capability discovery and read-only health reporting. Network configuration changes, remote administration and package updates must not be enabled until authentication, authorization, audit logging, schema validation and rollback are implemented and tested.

See ../docs/PROGRAM_MODE_ARCHITECTURE.md.
