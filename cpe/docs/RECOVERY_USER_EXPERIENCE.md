# NetPulse recovery experience

## Before an operation

The customer or authorised operator sees a plain-language summary:

- What is about to change?
- What settings and services may be interrupted?
- Has the current configuration been backed up and verified?
- Is original firmware available and verified?
- Is automatic rollback supported by this exact hardware revision?
- Does recovery require someone physically at the router?
- What happens if power or internet connectivity is lost?

The operation cannot proceed while recovery readiness is BLOCKED. CONDITIONAL readiness requires explicit reviewer approval and a local recovery plan.

## Recovery centre

The device page offers only validated actions:

| Action | Purpose |
| --- | --- |
| Restore configuration | Return network settings to a saved checkpoint |
| Revert firmware | Boot a previously verified firmware slot |
| Restore vendor firmware | Use an exact verified original vendor image |
| Guided recovery | Show tested model-specific physical recovery steps |
| Use backup gateway | Restore customer connectivity through an external gateway |
| Factory reset | Last resort, with data-loss warning and re-provisioning plan |

## Automatic recovery

When an A/B-capable router fails its trial boot or health gate, the bootloader watchdog/boot counter should select the previous known-good slot. The agent reports the event when connectivity returns. The agent must not be the only mechanism responsible for boot rollback because it may not start when the new firmware fails to boot.

## Customer protection

- Customer must be informed before a planned outage.
- No factory reset without explicit approval.
- Do not expose stored Wi-Fi keys, PPPoE passwords or ACS credentials in reports.
- Backups are encrypted and visible only to authorised tenant operators.
- Restore must preserve or deliberately re-provision the correct ISP service credentials.
- A customer may request restoration to the original vendor state, subject to hardware support and verified firmware availability.
