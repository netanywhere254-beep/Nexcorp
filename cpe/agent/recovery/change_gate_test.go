package recovery

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"testing"
	"time"
)

type fakeBackupSource struct {
	capture ConfigurationCapture
	err     error
	calls   int
}

func newFakeBackupSource(config []byte) *fakeBackupSource {
	return &fakeBackupSource{capture: ConfigurationCapture{
		Configuration: config, Schema: "openwrt-uci-v1", Complete: true,
	}}
}

func (f *fakeBackupSource) CaptureConfiguration(_ context.Context, _ Metadata) (ConfigurationCapture, error) {
	f.calls++
	return f.capture, f.err
}

type fakeCheckpointStore struct {
	err      error
	verified bool
	calls    int
}

func (f *fakeCheckpointStore) SaveAndVerify(_ context.Context, _ Metadata, snapshot []byte) (CheckpointReceipt, error) {
	f.calls++
	if f.err != nil {
		return CheckpointReceipt{}, f.err
	}
	sum := sha256.Sum256(snapshot)
	return CheckpointReceipt{SnapshotID: "checkpoint-001", SHA256: hex.EncodeToString(sum[:]), Verified: f.verified}, nil
}

func gateTestMetadata() Metadata {
	return Metadata{
		TenantID: "tenant-a", AssetID: "router-1",
		HardwareFingerprint: "sha256:hardware", ConfigSchema: "openwrt-uci-v1",
		CreatedAt: time.Date(2026, 11, 21, 0, 0, 0, 0, time.UTC),
	}
}

func TestApplyWithCheckpointBacksUpBeforeChange(t *testing.T) {
	source := newFakeBackupSource([]byte(`{"network":{"wan":"pppoe"}}`))
	store := &fakeCheckpointStore{verified: true}
	applied := false

	receipt, err := ApplyWithCheckpoint(context.Background(), gateTestMetadata(), make([]byte, 32), source, store, func(context.Context) error {
		if source.calls != 1 || store.calls != 1 {
			t.Fatal("change ran before backup capture and verification")
		}
		applied = true
		return nil
	})
	if err != nil {
		t.Fatalf("ApplyWithCheckpoint() error = %v", err)
	}
	if !applied || !receipt.Verified {
		t.Fatal("expected verified backup and change execution")
	}
}

func TestApplyWithCheckpointBlocksWhenCaptureFails(t *testing.T) {
	source := &fakeBackupSource{err: errors.New("router API unavailable")}
	store := &fakeCheckpointStore{verified: true}
	applied := false

	_, err := ApplyWithCheckpoint(context.Background(), gateTestMetadata(), make([]byte, 32), source, store, func(context.Context) error {
		applied = true
		return nil
	})
	if err == nil || applied || store.calls != 0 {
		t.Fatal("change must be blocked when configuration capture fails")
	}
}

func TestApplyWithCheckpointBlocksIncompleteCapture(t *testing.T) {
	source := newFakeBackupSource([]byte("partial-config"))
	source.capture.Complete = false
	source.capture.Missing = []string{"firewall", "wireless"}
	store := &fakeCheckpointStore{verified: true}
	applied := false

	_, err := ApplyWithCheckpoint(context.Background(), gateTestMetadata(), make([]byte, 32), source, store, func(context.Context) error {
		applied = true
		return nil
	})
	if !errors.Is(err, ErrCheckpointNotVerified) || applied || store.calls != 0 {
		t.Fatal("incomplete configuration export must block the change")
	}
}

func TestApplyWithCheckpointBlocksSchemaMismatch(t *testing.T) {
	source := newFakeBackupSource([]byte("config"))
	source.capture.Schema = "unknown-schema"
	store := &fakeCheckpointStore{verified: true}
	applied := false

	_, err := ApplyWithCheckpoint(context.Background(), gateTestMetadata(), make([]byte, 32), source, store, func(context.Context) error {
		applied = true
		return nil
	})
	if !errors.Is(err, ErrCheckpointNotVerified) || applied || store.calls != 0 {
		t.Fatal("configuration schema mismatch must block the change")
	}
}

func TestApplyWithCheckpointBlocksWhenStoreFails(t *testing.T) {
	source := newFakeBackupSource([]byte("config"))
	store := &fakeCheckpointStore{err: errors.New("disk full")}
	applied := false

	_, err := ApplyWithCheckpoint(context.Background(), gateTestMetadata(), make([]byte, 32), source, store, func(context.Context) error {
		applied = true
		return nil
	})
	if err == nil || applied {
		t.Fatal("change must be blocked when durable checkpoint storage fails")
	}
}

func TestApplyWithCheckpointBlocksUnverifiedReceipt(t *testing.T) {
	source := &fakeBackupSource{config: []byte("config")}
	store := &fakeCheckpointStore{verified: false}
	applied := false

	_, err := ApplyWithCheckpoint(context.Background(), gateTestMetadata(), make([]byte, 32), source, store, func(context.Context) error {
		applied = true
		return nil
	})
	if !errors.Is(err, ErrCheckpointNotVerified) || applied {
		t.Fatal("change must be blocked when store does not verify the checkpoint")
	}
}

func TestApplyWithCheckpointBlocksInvalidKey(t *testing.T) {
	source := &fakeBackupSource{config: []byte("config")}
	store := &fakeCheckpointStore{verified: true}
	applied := false

	_, err := ApplyWithCheckpoint(context.Background(), gateTestMetadata(), []byte("weak"), source, store, func(context.Context) error {
		applied = true
		return nil
	})
	if err == nil || applied || store.calls != 0 {
		t.Fatal("invalid encryption key must block the change")
	}
}

func TestApplyWithCheckpointDoesNotRunAfterContextCancellation(t *testing.T) {
	ctx, cancel := context.WithCancel(context.Background())
	cancel()
	source := &fakeBackupSource{config: []byte("config")}
	store := &fakeCheckpointStore{verified: true}
	applied := false

	_, err := ApplyWithCheckpoint(ctx, gateTestMetadata(), make([]byte, 32), source, store, func(context.Context) error {
		applied = true
		return nil
	})
	if !errors.Is(err, context.Canceled) || applied || source.calls != 0 {
		t.Fatal("cancelled operation must not capture or apply a change")
	}
}
