// Package session implements the local GOD MODE session lifecycle.
// It deliberately contains no router-operation APIs: callers must route all
// mutations through the separate checkpoint and recovery execution gateway.
package session

import (
	"errors"
	"sync"
	"time"
)

var (
	ErrSessionClosed = errors.New("god mode session is not active")
	ErrInvalidPolicy = errors.New("invalid god mode session policy")
	ErrInvalidTransition = errors.New("invalid god mode session transition")
)

type Policy struct {
	WarnAfter time.Duration
	IdleTimeout time.Duration
	MaxLifetime time.Duration
}

func DefaultPolicy() Policy {
	return Policy{
		WarnAfter: 10 * time.Minute,
		IdleTimeout: 15 * time.Minute,
		MaxLifetime: 8 * time.Hour,
	}
}

func (p Policy) Validate() error {
	if p.WarnAfter <= 0 || p.IdleTimeout <= 0 || p.MaxLifetime <= 0 ||
		p.WarnAfter >= p.IdleTimeout || p.IdleTimeout > p.MaxLifetime {
		return ErrInvalidPolicy
	}
	return nil
}

type CloseReason string

const (
	CloseManual CloseReason = "engineer_requested"
	CloseIdleTimeout CloseReason = "idle_timeout"
	CloseMaxLifetime CloseReason = "maximum_lifetime"
	CloseRevoked CloseReason = "revoked"
	ClosePolicyViolation CloseReason = "policy_violation"
	CloseInterrupted CloseReason = "interrupted"
)

type State string

const (
	StateActive State = "active"
	StateClosing State = "closing"
	StateClosed State = "closed"
)

type Snapshot struct {
	SessionID string
	EngineerID string
	State State
	StartedAtUTC time.Time
	LastActivityAtUTC time.Time
	EndedAtUTC *time.Time
	Elapsed time.Duration
	IdleFor time.Duration
	Warning bool
	Locked bool
	CloseReason CloseReason
}

// Session tracks authenticated human interaction only. Background jobs,
// telemetry and agent polling have no method to refresh the idle timer.
type Session struct {
	mu sync.Mutex
	id string
	engineerID string
	policy Policy
	started time.Time
	lastActivity time.Time
	state State
	locked bool
	inFlight bool
	closeReason CloseReason
	ended *time.Time
}

func New(sessionID, engineerID string, policy Policy, now time.Time) (*Session, error) {
	if sessionID == "" || engineerID == "" || policy.Validate() != nil || now.IsZero() {
		return nil, ErrInvalidPolicy
	}
	return &Session{
		id: sessionID, engineerID: engineerID, policy: policy,
		started: now, lastActivity: now, state: StateActive,
	}, nil
}

// RecordHumanActivity must only be called after the caller has authenticated
// and classified an intentional engineer interaction with the privileged UI.
func (s *Session) RecordHumanActivity(now time.Time) error {
	s.mu.Lock()
	defer s.mu.Unlock()
	if s.state != StateActive || s.locked {
		return ErrSessionClosed
	}
	if now.Before(s.lastActivity) || now.Before(s.started) {
		return ErrInvalidTransition
	}
	s.lastActivity = now
	return nil
}

// SetOperationInFlight reports whether a transaction is between safe
// boundaries. It does not execute or cancel the transaction.
func (s *Session) SetOperationInFlight(inFlight bool) error {
	s.mu.Lock()
	defer s.mu.Unlock()
	if s.state == StateClosed {
		return ErrSessionClosed
	}
	s.inFlight = inFlight
	return nil
}

type Evaluation struct {
	Warning bool
	LockNow bool
	CloseReady bool
	Reason CloseReason
}

// Evaluate enforces idle and maximum lifetime. At timeout it locks the console
// immediately. If a transaction is in flight, final logout waits for the
// caller to report a safe boundary; no new privileged command should be
// accepted once LockNow is true.
func (s *Session) Evaluate(now time.Time) (Evaluation, error) {
	s.mu.Lock()
	defer s.mu.Unlock()
	if s.state == StateClosed {
		return Evaluation{}, ErrSessionClosed
	}
	if now.Before(s.started) || now.Before(s.lastActivity) {
		return Evaluation{}, ErrInvalidTransition
	}
	if s.state == StateClosing {
		return Evaluation{LockNow: true, CloseReady: !s.inFlight, Reason: s.closeReason}, nil
	}
	elapsed := now.Sub(s.started)
	idle := now.Sub(s.lastActivity)
	reason := CloseReason("")
	if elapsed >= s.policy.MaxLifetime {
		reason = CloseMaxLifetime
	} else if idle >= s.policy.IdleTimeout {
		reason = CloseIdleTimeout
	}
	if reason != "" {
		s.locked = true
		s.state = StateClosing
		s.closeReason = reason
		return Evaluation{LockNow: true, CloseReady: !s.inFlight, Reason: reason}, nil
	}
	return Evaluation{Warning: idle >= s.policy.WarnAfter}, nil
}

// CompleteSafeBoundary closes a pending session after the operation owner has
// completed or safely cancelled the in-flight transaction.
func (s *Session) CompleteSafeBoundary(now time.Time) error {
	s.mu.Lock()
	defer s.mu.Unlock()
	if s.state != StateClosing || s.inFlight || now.Before(s.started) {
		return ErrInvalidTransition
	}
	s.finish(now)
	return nil
}

func (s *Session) Deactivate(now time.Time, reason CloseReason) error {
	s.mu.Lock()
	defer s.mu.Unlock()
	if s.state != StateActive || now.Before(s.started) || reason == "" {
		return ErrInvalidTransition
	}
	s.locked = true
	s.state = StateClosing
	s.closeReason = reason
	if !s.inFlight {
		s.finish(now)
	}
	return nil
}

func (s *Session) finish(now time.Time) {
	ended := now
	s.ended = &ended
	s.state = StateClosed
	s.inFlight = false
}

func (s *Session) Snapshot(now time.Time) Snapshot {
	s.mu.Lock()
	defer s.mu.Unlock()
	elapsed := now.Sub(s.started)
	if elapsed < 0 {
		elapsed = 0
	}
	idle := now.Sub(s.lastActivity)
	if idle < 0 {
		idle = 0
	}
	if s.ended != nil {
		elapsed = s.ended.Sub(s.started)
		if elapsed < 0 { elapsed = 0 }
		idle = s.ended.Sub(s.lastActivity)
		if idle < 0 { idle = 0 }
	}
	return Snapshot{
		SessionID: s.id, EngineerID: s.engineerID, State: s.state,
		StartedAtUTC: s.started.UTC(), LastActivityAtUTC: s.lastActivity.UTC(),
		EndedAtUTC: s.ended, Elapsed: elapsed, IdleFor: idle,
		Warning: s.state == StateActive && idle >= s.policy.WarnAfter,
		Locked: s.locked, CloseReason: s.closeReason,
	}
}
