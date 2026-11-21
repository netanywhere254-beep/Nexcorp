# NetPulse CPE OS — Firmware-like Program Mode

## Product decision
NetPulse CPE OS will have two deployment modes sharing one management plane:

1. **Native firmware image**: a complete OpenWrt-derived image for an exact supported board. It controls the kernel, drivers, routing, firewall, wireless and boot lifecycle.
2. **Program mode**: an installable NetPulse Router Agent package/service on a compatible Linux/OpenWrt host. It provides the management experience and orchestrates host networking services through supported APIs.

Program mode is not a universal substitute for firmware. It cannot add missing kernel drivers, unlock vendor-restricted radios, replace a bootloader, or guarantee access to WAN/LAN/Wi-Fi controls on locked stock firmware. It must verify host capabilities and show unsupported controls as unavailable rather than pretending they work.

## Intended user experience
- WAN and PPPoE configuration where the host exposes a supported network API
- LAN/DHCP and firewall management where supported
- Wi-Fi configuration through hostapd/netifd or a vendor-supported API
- device status, uptime, clients and diagnostics where available
- TR-069/USP connectivity through a separately maintained CWMP/USP adapter
- software updates with signature verification and rollback controls

Feature parity is capability-based, not assumed. A native image can expose more controls than a package installed on locked manufacturer firmware.

## Program-mode runtime
- netpulse-cpe-agent: unprivileged control-plane service where possible.
- Host adapter: OpenWrt ubus/uci/netifd, or a separately validated Linux NetworkManager/systemd-networkd/nftables adapter.
- CWMP adapter: communicates with the selected CWMP client; do not implement a partial/unsafe CWMP stack inside the management API.
- Local control API: Unix domain socket, owner/group restricted; no unauthenticated public HTTP administration endpoint.
- Management plane: outbound TLS connection to NexISP; device-specific credentials.
- Update manager: signed package manifests, compatibility checks, staged update and rollback only when the host package manager and filesystem support it.

## Security boundaries
- No shell command assembled from user-supplied values.
- Use fixed executable paths and structured argument arrays if subprocesses are required.
- Validate all configuration against typed schemas and explicit allow-lists.
- Require authorization for every state-changing action.
- Tenant scope comes from authenticated NexISP server context, never a request-supplied organization identifier.
- Never expose local admin API directly to the public internet.
- Keep PPPoE passwords, Wi-Fi keys and ACS secrets out of logs and diagnostics.
- Updates must be signed and checked against exact device/OS/package compatibility.
- The agent must fail closed when the host adapter cannot prove an operation is safe.

## Installable deliverables
- OpenWrt .ipk package for explicitly supported OpenWrt releases and architectures.
- Debian .deb package for validated Debian-based router hosts.
- RPM package only if a target distribution is validated.
- Native firmware image remains a separate per-board artifact.

A package is not automatically installable on arbitrary Huawei, TP-Link, Tenda or Xiaomi stock firmware. Those vendors often ship closed systems with no supported third-party package manager or management API.

## WS318n decision
The Huawei WS318n remains blocked pending exact hardware revision, board ID, SoC, flash layout, RAM, Wi-Fi chipset and recovery path. If its stock firmware has no supported package installation/API route, program mode on the router itself may not be possible. An external NetPulse gateway can still provide routing and TR-069 control while the WS318n operates as a Wi-Fi access point, if its configuration permits.
