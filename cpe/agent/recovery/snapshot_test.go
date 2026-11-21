package recovery

import (
	"bytes"
	"crypto/rand"
	"encoding/json"
	"errors"
	"testing"
	"time"
)

func testKey(t *testing.T) []byte {
	t.Helper()
	key := make([]byte, 32)
	if _, err := rand.Read(key); err != nil {
		t.Fatal(err)
	}
	return key
}

func testMetadata() Metadata {
	return Metadata{
		TenantID: "tenant-1", AssetID: "router-9",
		HardwareFingerprint: "board:rev-a:serial-hash",
		ConfigSchema: "netpulse-config-v1",
		CreatedAt: time.Date(2026, 11, 21, 12, 0, 0, 0, time.UTC),
	}
}

func TestSnapshotRoundTrip(t *testing.T) {
	key, meta := testKey(t), testMetadata()
	config := []byte("fixture-config-with-sensitive-settings")
	encoded, err := EncodeSnapshot(meta, config, key)
	if err != nil {
		t.Fatal(err)
	}
	if bytes.Contains(encoded, config) {
		t.Fatal("plaintext configuration leaked into snapshot")
	}
	got, gotMeta, err := DecodeSnapshot(encoded, key, meta)
	if err != nil {
		t.Fatal(err)
	}
	if !bytes.Equal(got, config) {
		t.Fatal("restored configuration differs")
	}
	if gotMeta.AssetID != meta.AssetID {
		t.Fatal("metadata was not preserved")
	}
}

func TestSnapshotRejectsWrongAsset(t *testing.T) {
	key, meta := testKey(t), testMetadata()
	encoded, err := EncodeSnapshot(meta, []byte("config"), key)
	if err != nil {
		t.Fatal(err)
	}
	other := meta
	other.AssetID = "different-router"
	_, _, err = DecodeSnapshot(encoded, key, other)
	if !errors.Is(err, ErrIdentityMismatch) {
		t.Fatalf("expected identity mismatch, got %v", err)
	}
}

func TestSnapshotRejectsTampering(t *testing.T) {
	key, meta := testKey(t), testMetadata()
	encoded, err := EncodeSnapshot(meta, []byte("config"), key)
	if err != nil {
		t.Fatal(err)
	}
	var env envelope
	if err := json.Unmarshal(encoded, &env); err != nil {
		t.Fatal(err)
	}
	env.Metadata.AssetID = "tampered"
	tampered, _ := json.Marshal(env)
	_, _, err = DecodeSnapshot(tampered, key, meta)
	if !errors.Is(err, ErrIdentityMismatch) {
		t.Fatalf("expected identity mismatch, got %v", err)
	}
}

func TestSnapshotRejectsWrongKey(t *testing.T) {
	key, meta := testKey(t), testMetadata()
	encoded, err := EncodeSnapshot(meta, []byte("config"), key)
	if err != nil {
		t.Fatal(err)
	}
	_, _, err = DecodeSnapshot(encoded, testKey(t), meta)
	if err == nil {
		t.Fatal("expected authentication failure")
	}
}

func TestSnapshotRejectsInvalidKeyAndEmptyConfig(t *testing.T) {
	meta := testMetadata()
	if _, err := EncodeSnapshot(meta, []byte("config"), []byte("short")); !errors.Is(err, ErrInvalidKey) {
		t.Fatalf("expected invalid key, got %v", err)
	}
	if _, err := EncodeSnapshot(meta, nil, testKey(t)); !errors.Is(err, ErrConfigTooLarge) {
		t.Fatalf("expected size error, got %v", err)
	}
}

func TestSnapshotRejectsUnsupportedVersion(t *testing.T) {
	key, meta := testKey(t), testMetadata()
	encoded, err := EncodeSnapshot(meta, []byte("config"), key)
	if err != nil {
		t.Fatal(err)
	}
	var env envelope
	if err := json.Unmarshal(encoded, &env); err != nil {
		t.Fatal(err)
	}
	env.Version++
	encoded, _ = json.Marshal(env)
	_, _, err = DecodeSnapshot(encoded, key, meta)
	if !errors.Is(err, ErrUnsupportedVersion) {
		t.Fatalf("expected unsupported version, got %v", err)
	}
}
