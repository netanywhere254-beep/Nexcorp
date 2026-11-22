# Engineer privileged-session lifecycle

This package implements the in-process lifecycle primitive for the NetPulse
engineering console. It is intentionally not an authentication system and is
not wired to any remote HTTP route in this milestone.

## Enforced behavior

- A session requires a non-empty actor ID supplied by an authenticated and
  authorised caller.
- Only one privileged session may be active per Manager instance.
- Session IDs are generated from 192 bits of cryptographic randomness.
- Both an idle timeout and an absolute maximum duration are enforced.
- Activity renews the idle deadline but never the absolute maximum lifetime.
- `GOLD MODE DEACTIVATE` closes the session only when issued by its owning
  authenticated actor through the authorised control channel.
- Session start is fail-closed if the audit sink is unavailable.
- Expiry and deactivation revoke the session locally even if audit storage
  fails; the caller receives the audit error where applicable.
- Session activity is audited. Do not log secrets, passwords, tokens or
  customer configuration values in audit metadata.

## Integration requirements before production

1. Implement an authenticated API / local IPC boundary. Never trust an actor
   ID supplied directly by an unauthenticated request body.
2. Use MFA or equivalent strong authentication for privileged entry.
3. Authorise the actor against tenant, device and engagement scope on every
   privileged operation, not only at session start.
4. Implement a durable append-only audit sink with restricted write access,
   integrity protection, retention policy and monitoring.
5. Persist/recover session revocation safely across process restarts; do not
   restore an active privileged session after restart.
6. Add rate limits, CSRF protection for browser control surfaces, request
   binding and replay protection where applicable.
7. Wire explicit session status, start, activity and deactivate endpoints only
   after the boundary and audit sink are implemented and tested.
8. Add an independent operator-visible emergency revoke control.

## Example

```go
manager, err := engineer.NewManager(10*time.Minute, 60*time.Minute, auditSink)
if err != nil {
    return err
}

session, err := manager.Start(authenticatedActor.ID)
if err != nil {
    return err
}

// On authenticated activity:
_, err = manager.Touch(authenticatedActor.ID)

// Explicit operator deactivation:
err = manager.Deactivate(authenticatedActor.ID, engineer.DeactivateCommand)
```

The timeout values above are examples, not a production policy. Production
values must be configurable by policy and constrained by server-side maxima.
