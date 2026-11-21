package recovery

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"fmt"
)

// ConfigurationBackupSource must capture the live configuration from the exact
// target device without changing its state.
type ConfigurationBackupSource interface {
	CaptureConfiguration(context.Context, Metadata) ([]byte, error)
}

// CheckpointReceipt is returned only after the storage adapter has read the
// encrypted checkpoint back and verified its digest.
type CheckpointReceipt struct {
	SnapshotID string
	SHA256     string
	Verified   bool
}

// DurableCheckpointStore must durably persist the encrypted snapshot, read it
// back, and verify its SHA-256 before returning a successful receipt.
type DurableCheckpointStore interface {
	SaveAndVerify(context.Context, Metadata, []byte) (CheckpointReceipt, error)
}

// ProtectedChange is any operation that can alter router state: configuration,
// network, firewall, wireless, management, package or firmware changes.
type ProtectedChange func(context.Context) error

var ErrCheckpointNotVerified = errors.New("recovery: verified pre-change checkpoint is required")

// ApplyWithCheckpoint is the mandatory fail-closed gate for router mutations.
// It captures the live configuration, encrypts it, authenticates/decrypts it
// against the exact tenant/asset/hardware/schema identity, persists and verifies
// the checkpoint, and only then invokes apply. No adapter in this package can
// bypass the ordering by supplying a caller-asserted "backup complete" flag.
func ApplyWithCheckpoint(
	ctx context.Context,
	meta Metadata,
	key []byte,
	source ConfigurationBackupSource,
	store DurableCheckpointStore,
	apply ProtectedChange,
) (CheckpointReceipt, error) {
	if source == nil || store == nil || apply == nil {
		return CheckpointReceipt{}, errors.New("recovery: backup source, durable store and change callback are required")
	}
	if err := ctx.Err(); err != nil {
		return CheckpointReceipt{}, err
	}

	configuration, err := source.CaptureConfiguration(ctx, meta)
	if err != nil {
		return CheckpointReceipt{}, fmt.Errorf("recovery: pre-change configuration capture failed; change blocked: %w", err)
	}
	snapshot, err := EncodeSnapshot(meta, configuration, key)
	if err != nil {
		return CheckpointReceipt{}, fmt.Errorf("recovery: pre-change checkpoint creation failed; change blocked: %w", err)
	}
	// Verify that the just-created envelope is authentic and bound to this exact
	// device before handing it to durable storage.
	if _, _, err := DecodeSnapshot(snapshot, key, meta); err != nil {
		return CheckpointReceipt{}, fmt.Errorf("recovery: pre-change checkpoint validation failed; change blocked: %w", err)
	}

	receipt, err := store.SaveAndVerify(ctx, meta, snapshot)
	if err != nil {
		return CheckpointReceipt{}, fmt.Errorf("recovery: checkpoint persistence/verification failed; change blocked: %w", err)
	}
	sum := sha256.Sum256(snapshot)
	expectedDigest := hex.EncodeToString(sum[:])
	if !receipt.Verified || receipt.SnapshotID == "" || receipt.SHA256 != expectedDigest {
		return CheckpointReceipt{}, ErrCheckpointNotVerified
	}
	if err := ctx.Err(); err != nil {
		return receipt, err
	}
	if err := apply(ctx); err != nil {
		return receipt, fmt.Errorf("recovery: change failed after a verified checkpoint was saved: %w", err)
	}
	return receipt, nil
}
