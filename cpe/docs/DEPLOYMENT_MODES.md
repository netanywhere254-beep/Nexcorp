# NetPulse CPE OS deployment modes

| Mode | What is installed | Controls | Hardware requirements |
|---|---|---|---|
| Native firmware | Complete router image | Full controls exposed by supported drivers | Exact supported board and tested recovery |
| Router program | Agent package/service | Only host APIs and drivers already exposed | Supported Linux/OpenWrt OS, package access and privileges |
| External gateway | NetPulse software on separate router/mini-PC | Routing, firewall, DHCP, VPN and ACS for downstream network | Separate supported gateway; existing router may act as AP |
| Cloud-only management | ACS/NexISP configuration | Only functions exposed by existing TR-069/USP/vendor API | Existing router must expose a supported management interface |

## Choosing the mode
1. Identify the exact hardware and installed operating system.
2. Check for a supported package manager and documented network-management API.
3. Probe capabilities without modifying the router.
4. Select the highest safe mode available.
5. Never claim Wi-Fi, PPPoE, firewall or firmware-update support unless the adapter detects and validates that capability.

## Key limitation
An installable agent is an application/service, not a bootable firmware image. It runs on top of an existing operating system. It cannot replace the kernel, bootloader or hardware drivers. Native firmware is required when those components must be replaced.
