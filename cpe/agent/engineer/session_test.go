package engineer

import (
	"errors"
	"testing"
	"time"
)

type memoryAudit struct {
	events []AuditEvent
	fail   bool
}

func (a *memoryAudit) Append(event AuditEvent) error {
	if a.fail {
		return errors.New("audit store unavailable")
	}
	a.events = append(a.events, event)
	return nil
}

func newTestManager(t *testing.T, idle, max time.Duration, audit *memoryAudit) (*Manager, *time.Time) {
	t.Helper()
	m, err := NewManager(idle, max, audit)
	if err != nil {
		t.Fatal(err)
	}
	now := time.Date(2026, 11, 22, 10, 0, 0, 0, time.UTC)
	m.now = func() time.Time { return now }
	return m, &now
}

func TestStartRequiresActorAndAudit(t *testing.T) {
	audit := &memoryAudit{}
	m, _ := newTestManager(t, time.Minute, 10*time.Minute, audit)
	if _, err := m.Start(""); err == nil {
		t.Fatal("expected empty actor to be rejected")
	}
	audit.fail = true
	if _, err := m.Start("engineer-1"); err == nil {
		t.Fatal("expected audit failure to prevent session creation")
	}
	if _, err := m.Active(); !errors.Is(err, ErrNoSession) {
		t.Fatalf("expected no session, got %v", err)
	}
}

func TestIdleTimeoutExpiresSession(t *testing.T) {
	audit := &memoryAudit{}
	m, now := newTestManager(t, time.Minute, 10*time.Minute, audit)
	if _, err := m.Start("engineer-1"); err != nil {
		t.Fatal(err)
	}
	*now = now.Add(time.Minute)
	if _, err := m.Active(); !errors.Is(err, ErrSessionExpired) {
		t.Fatalf("expected expiry, got %v", err)
	}
	if got := audit.events[len(audit.events)-1].Action; got != "session_expired" {
		t.Fatalf("expected expiry audit event, got %q", got)
	}
}

func TestActivityRenewsIdleButNotMaximumLifetime(t *testing.T) {
	audit := &memoryAudit{}
	m, now := newTestManager(t, 2*time.Minute, 3*time.Minute, audit)
	if _, err := m.Start("engineer-1"); err != nil {
		t.Fatal(err)
	}
	*now = now.Add(90 * time.Second)
	if _, err := m.Touch("engineer-1"); err != nil {
		t.Fatal(err)
	}
	*now = now.Add(90 * time.Second)
	if _, err := m.Active(); err != nil {
		t.Fatalf("idle renewal should keep session alive before absolute expiry: %v", err)
	}
	*now = now.Add(time.Minute)
	if _, err := m.Active(); !errors.Is(err, ErrSessionExpired) {
		t.Fatalf("absolute lifetime must expire session: %v", err)
	}
}

func TestDeactivateRequiresOwnerAndExactCommand(t *testing.T) {
	audit := &memoryAudit{}
	m, _ := newTestManager(t, time.Minute, 10*time.Minute, audit)
	if _, err := m.Start("engineer-1"); err != nil {
		t.Fatal(err)
	}
	if err := m.Deactivate("engineer-2", DeactivateCommand); !errors.Is(err, ErrWrongActor) {
		t.Fatalf("expected owner check, got %v", err)
	}
	if err := m.Deactivate("engineer-1", "GOLD MODE"); !errors.Is(err, ErrInvalidCommand) {
		t.Fatalf("expected exact command check, got %v", err)
	}
	if err := m.Deactivate("engineer-1", DeactivateCommand); err != nil {
		t.Fatal(err)
	}
	if _, err := m.Active(); !errors.Is(err, ErrNoSession) {
		t.Fatalf("session should be closed, got %v", err)
	}
	if got := audit.events[len(audit.events)-1].Action; got != "session_deactivated" {
		t.Fatalf("expected deactivation audit, got %q", got)
	}
}

func TestConcurrentSessionCannotBeStarted(t *testing.T) {
	audit := &memoryAudit{}
	m, _ := newTestManager(t, time.Minute, 10*time.Minute, audit)
	if _, err := m.Start("engineer-1"); err != nil {
		t.Fatal(err)
	}
	if _, err := m.Start("engineer-2"); err == nil {
		t.Fatal("expected concurrent privileged session to be rejected")
	}
}

func TestInvalidTimeoutConfigurationRejected(t *testing.T) {
	audit := &memoryAudit{}
	if _, err := NewManager(0, time.Minute, audit); err == nil {
		t.Fatal("expected zero idle timeout to be rejected")
	}
	if _, err := NewManager(time.Minute, 0, audit); err == nil {
		t.Fatal("expected zero max duration to be rejected")
	}
}
