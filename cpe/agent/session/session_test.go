package session

import (
	"errors"
	"testing"
	"time"
)

func testSession(t *testing.T) (*Session, time.Time) {
	t.Helper()
	start := time.Date(2026, 11, 21, 10, 0, 0, 0, time.UTC)
	s, err := New("session-1", "engineer-1", DefaultPolicy(), start)
	if err != nil {
		t.Fatal(err)
	}
	return s, start
}

func TestDefaultPolicy(t *testing.T) {
	p := DefaultPolicy()
	if p.WarnAfter != 10*time.Minute || p.IdleTimeout != 15*time.Minute || p.MaxLifetime != 8*time.Hour {
		t.Fatalf("unexpected defaults: %+v", p)
	}
}

func TestIdleWarningThenAutomaticLogout(t *testing.T) {
	s, start := testSession(t)
	eval, err := s.Evaluate(start.Add(10 * time.Minute))
	if err != nil || !eval.Warning || eval.LockNow {
		t.Fatalf("warning expected: %+v %v", eval, err)
	}
	eval, err = s.Evaluate(start.Add(15 * time.Minute))
	if err != nil || !eval.LockNow || !eval.CloseReady || eval.Reason != CloseIdleTimeout {
		t.Fatalf("idle timeout expected: %+v %v", eval, err)
	}
	if got := s.Snapshot(start.Add(15 * time.Minute)); got.State != StateClosing || !got.Locked {
		t.Fatalf("session should be locked pending close: %+v", got)
	}
	if err := s.CompleteSafeBoundary(start.Add(15 * time.Minute)); err != nil {
		t.Fatal(err)
	}
	got := s.Snapshot(start.Add(16 * time.Minute))
	if got.State != StateClosed || got.EndedAtUTC == nil || got.Elapsed != 15*time.Minute || got.CloseReason != CloseIdleTimeout {
		t.Fatalf("unexpected closed session: %+v", got)
	}
	if err := s.RecordHumanActivity(start.Add(16 * time.Minute)); !errors.Is(err, ErrSessionClosed) {
		t.Fatalf("expected closed-session error, got %v", err)
	}
}

func TestMeaningfulHumanActivityResetsIdleTimer(t *testing.T) {
	s, start := testSession(t)
	if err := s.RecordHumanActivity(start.Add(9 * time.Minute)); err != nil {
		t.Fatal(err)
	}
	eval, err := s.Evaluate(start.Add(20 * time.Minute))
	if err != nil || eval.LockNow || !eval.Warning {
		t.Fatalf("expected warning, not logout: %+v %v", eval, err)
	}
}

func TestInFlightOperationLocksImmediatelyButWaitsForSafeBoundary(t *testing.T) {
	s, start := testSession(t)
	if err := s.SetOperationInFlight(true); err != nil {
		t.Fatal(err)
	}
	eval, err := s.Evaluate(start.Add(15 * time.Minute))
	if err != nil || !eval.LockNow || eval.CloseReady {
		t.Fatalf("expected locked pending transaction: %+v %v", eval, err)
	}
	if err := s.RecordHumanActivity(start.Add(15 * time.Minute)); !errors.Is(err, ErrSessionClosed) {
		t.Fatalf("locked session accepted activity: %v", err)
	}
	if err := s.SetOperationInFlight(true); !errors.Is(err, ErrSessionClosed) {
		t.Fatalf("closing session must reject new work: %v", err)
	}
	if err := s.SetOperationInFlight(false); err != nil {
		t.Fatal(err)
	}
	if err := s.CompleteSafeBoundary(start.Add(16 * time.Minute)); err != nil {
		t.Fatal(err)
	}
	if got := s.Snapshot(start.Add(16 * time.Minute)); got.State != StateClosed || got.Elapsed != 16*time.Minute {
		t.Fatalf("unexpected safe close: %+v", got)
	}
}

func TestMaximumLifetimeForcesNewSession(t *testing.T) {
	s, start := testSession(t)
	eval, err := s.Evaluate(start.Add(8 * time.Hour))
	if err != nil || !eval.LockNow || eval.Reason != CloseMaxLifetime {
		t.Fatalf("max lifetime expected: %+v %v", eval, err)
	}
	if err := s.CompleteSafeBoundary(start.Add(8 * time.Hour)); err != nil {
		t.Fatal(err)
	}
}

func TestBackgroundWorkCannotRefreshIdleTimer(t *testing.T) {
	s, start := testSession(t)
	// There is intentionally no background-job activity API. Merely evaluating
	// the session after telemetry/polling leaves the original idle timestamp.
	eval, err := s.Evaluate(start.Add(15 * time.Minute))
	if err != nil || !eval.LockNow || eval.Reason != CloseIdleTimeout {
		t.Fatalf("background activity must not keep session alive: %+v %v", eval, err)
	}
}

func TestManualDeactivationAndClockRollback(t *testing.T) {
	s, start := testSession(t)
	if err := s.Deactivate(start.Add(2*time.Minute), CloseManual); err != nil {
		t.Fatal(err)
	}
	if err := s.Evaluate(start.Add(time.Minute)); !errors.Is(err, ErrSessionClosed) {
		t.Fatalf("expected closed state: %v", err)
	}

	s2, _ := testSession(t)
	if err := s2.RecordHumanActivity(start.Add(3 * time.Minute)); err != nil {
		t.Fatal(err)
	}
	if _, err := s2.Evaluate(start.Add(2 * time.Minute)); !errors.Is(err, ErrInvalidTransition) {
		t.Fatalf("expected backwards-time rejection, got %v", err)
	}
}

func TestPolicyValidation(t *testing.T) {
	p := DefaultPolicy()
	p.WarnAfter = p.IdleTimeout
	if err := p.Validate(); !errors.Is(err, ErrInvalidPolicy) {
		t.Fatalf("expected invalid policy: %v", err)
	}
}
