package engineer

import (
	"crypto/rand"
	"encoding/hex"
	"errors"
	"fmt"
	"sync"
	"time"
)

const DeactivateCommand = "GOLD MODE DEACTIVATE"

var (
	ErrNoSession       = errors.New("no active engineer session")
	ErrWrongActor      = errors.New("session belongs to a different engineer")
	ErrInvalidCommand  = errors.New("deactivation command not recognised")
	ErrSessionExpired  = errors.New("engineer session expired")
	ErrAuditUnavailable = errors.New("audit sink unavailable")
)

type AuditEvent struct {
	SessionID string    `json:"session_id"`
	ActorID   string    `json:"actor_id"`
	Action    string    `json:"action"`
	At        time.Time `json:"at"`
	Reason    string    `json:"reason,omitempty"`
}

type AuditSink interface {
	Append(AuditEvent) error
}

type Session struct {
	ID        string    `json:"id"`
	ActorID   string    `json:"actor_id"`
	StartedAt time.Time `json:"started_at"`
	LastSeen  time.Time `json:"last_seen"`
	ExpiresAt time.Time `json:"expires_at"`
}

type Manager struct {
	mu          sync.Mutex
	active      *Session
	idleTimeout time.Duration
	maxDuration time.Duration
	now         func() time.Time
	audit       AuditSink
}

func NewManager(idleTimeout, maxDuration time.Duration, audit AuditSink) (*Manager, error) {
	if idleTimeout <= 0 || maxDuration <= 0 || idleTimeout > maxDuration {
		return nil, errors.New("timeouts must be positive and idle timeout cannot exceed maximum duration")
	}
	if audit == nil {
		return nil, ErrAuditUnavailable
	}
	return &Manager{idleTimeout: idleTimeout, maxDuration: maxDuration, now: time.Now, audit: audit}, nil
}

func newID() (string, error) {
	var raw [24]byte
	if _, err := rand.Read(raw[:]); err != nil {
		return "", fmt.Errorf("generate session identifier: %w", err)
	}
	return hex.EncodeToString(raw[:]), nil
}

func (m *Manager) append(event AuditEvent) error {
	if err := m.audit.Append(event); err != nil {
		return fmt.Errorf("write engineer audit event: %w", err)
	}
	return nil
}

// Start assumes actorID was authenticated and authorised by the calling API.
// This package is a lifecycle primitive, not an authentication mechanism.
func (m *Manager) Start(actorID string) (Session, error) {
	if actorID == "" {
		return Session{}, errors.New("authenticated actor ID is required")
	}
	m.mu.Lock()
	defer m.mu.Unlock()

	now := m.now().UTC()
	if m.active != nil {
		if now.Before(m.deadline(*m.active)) && now.Before(m.active.ExpiresAt) {
			return Session{}, errors.New("an engineer session is already active")
		}
		expired := *m.active
		m.active = nil // Always revoke locally, even if audit storage is unavailable.
		_ = m.append(AuditEvent{SessionID: expired.ID, ActorID: expired.ActorID, Action: "session_expired", At: now, Reason: "idle_or_max_duration"})
	}

	id, err := newID()
	if err != nil {
		return Session{}, err
	}
	session := Session{ID: id, ActorID: actorID, StartedAt: now, LastSeen: now, ExpiresAt: now.Add(m.maxDuration)}
	if err := m.append(AuditEvent{SessionID: id, ActorID: actorID, Action: "session_started", At: now}); err != nil {
		return Session{}, err // Fail closed: do not grant an unaudited session.
	}
	m.active = &session
	return session, nil
}

func (m *Manager) deadline(s Session) time.Time {
	idle := s.LastSeen.Add(m.idleTimeout)
	if idle.Before(s.ExpiresAt) {
		return idle
	}
	return s.ExpiresAt
}

func (m *Manager) expireLocked(now time.Time, reason string) error {
	if m.active == nil {
		return ErrNoSession
	}
	s := *m.active
	m.active = nil
	return m.append(AuditEvent{SessionID: s.ID, ActorID: s.ActorID, Action: "session_expired", At: now.UTC(), Reason: reason})
}

// Touch renews the idle window but never extends the absolute maximum lifetime.
func (m *Manager) Touch(actorID string) (Session, error) {
	m.mu.Lock()
	defer m.mu.Unlock()
	if m.active == nil {
		return Session{}, ErrNoSession
	}
	now := m.now().UTC()
	if !now.Before(m.deadline(*m.active)) {
		_ = m.expireLocked(now, "idle_or_max_duration")
		return Session{}, ErrSessionExpired
	}
	if m.active.ActorID != actorID {
		return Session{}, ErrWrongActor
	}
	updated := *m.active
	updated.LastSeen = now
	if err := m.append(AuditEvent{SessionID: updated.ID, ActorID: actorID, Action: "session_activity", At: now}); err != nil {
		return Session{}, err
	}
	m.active = &updated
	return updated, nil
}

// Deactivate requires both the authenticated owner and the explicit command.
// The command is not a password and must only be accepted over an authenticated,
// authorised control channel.
func (m *Manager) Deactivate(actorID, command string) error {
	m.mu.Lock()
	defer m.mu.Unlock()
	if m.active == nil {
		return ErrNoSession
	}
	if m.active.ActorID != actorID {
		return ErrWrongActor
	}
	if command != DeactivateCommand {
		return ErrInvalidCommand
	}
	s := *m.active
	m.active = nil
	return m.append(AuditEvent{SessionID: s.ID, ActorID: actorID, Action: "session_deactivated", At: m.now().UTC(), Reason: "operator_command"})
}

// Active returns no session after either the idle timeout or absolute expiry.
func (m *Manager) Active() (*Session, error) {
	m.mu.Lock()
	defer m.mu.Unlock()
	if m.active == nil {
		return nil, ErrNoSession
	}
	now := m.now().UTC()
	if !now.Before(m.deadline(*m.active)) {
		_ = m.expireLocked(now, "idle_or_max_duration")
		return nil, ErrSessionExpired
	}
	copy := *m.active
	return &copy, nil
}
