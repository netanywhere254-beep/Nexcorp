package recovery

import (
	"bytes"
	"crypto/aes"
	"crypto/cipher"
	"crypto/rand"
	"crypto/sha256"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"time"
)

const (
	FormatVersion  = 1
	MaxConfigBytes = 8 << 20
	keyBytes       = 32
)

var (
	ErrInvalidKey         = errors.New("recovery: key must be exactly 32 bytes")
	ErrIdentityMismatch   = errors.New("recovery: snapshot identity does not match target")
	ErrUnsupportedVersion = errors.New("recovery: unsupported snapshot format")
	ErrConfigTooLarge     = errors.New("recovery: configuration exceeds size limit")
)

// Metadata identifies the exact customer asset and hardware that produced a checkpoint.
// Do not put credentials or other secrets in these fields.
type Metadata struct {
	TenantID            string    `json:"tenant_id"`
	AssetID             string    `json:"asset_id"`
	HardwareFingerprint string    `json:"hardware_fingerprint"`
	ConfigSchema        string    `json:"config_schema"`
	CreatedAt           time.Time `json:"created_at"`
}

type envelope struct {
	Version    int      `json:"version"`
	Metadata   Metadata `json:"metadata"`
	Nonce      string   `json:"nonce"`
	Ciphertext string   `json:"ciphertext"`
}

type encryptedPayload struct {
	SHA256        string `json:"sha256"`
	Configuration []byte `json:"configuration"`
}

// EncodeSnapshot creates an authenticated, encrypted checkpoint. The caller must
// supply a randomly generated, protected 32-byte key; this package does not manage keys.
func EncodeSnapshot(meta Metadata, configuration, key []byte) ([]byte, error) {
	if len(key) != keyBytes {
		return nil, ErrInvalidKey
	}
	if len(configuration) == 0 || len(configuration) > MaxConfigBytes {
		return nil, ErrConfigTooLarge
	}
	if meta.TenantID == "" || meta.AssetID == "" || meta.HardwareFingerprint == "" || meta.ConfigSchema == "" {
		return nil, errors.New("recovery: tenant, asset, hardware fingerprint and config schema are required")
	}
	if meta.CreatedAt.IsZero() {
		meta.CreatedAt = time.Now().UTC()
	} else {
		meta.CreatedAt = meta.CreatedAt.UTC()
	}

	block, err := aes.NewCipher(key)
	if err != nil {
		return nil, fmt.Errorf("recovery: initialise cipher: %w", err)
	}
	aead, err := cipher.NewGCM(block)
	if err != nil {
		return nil, fmt.Errorf("recovery: initialise authenticated encryption: %w", err)
	}
	nonce := make([]byte, aead.NonceSize())
	if _, err := io.ReadFull(rand.Reader, nonce); err != nil {
		return nil, fmt.Errorf("recovery: generate nonce: %w", err)
	}

	sum := sha256.Sum256(configuration)
	payload, err := json.Marshal(encryptedPayload{
		SHA256: hex.EncodeToString(sum[:]), Configuration: configuration,
	})
	if err != nil {
		return nil, fmt.Errorf("recovery: encode payload: %w", err)
	}
	aad, err := json.Marshal(meta)
	if err != nil {
		return nil, fmt.Errorf("recovery: encode authenticated metadata: %w", err)
	}
	ciphertext := aead.Seal(nil, nonce, payload, aad)
	out, err := json.Marshal(envelope{
		Version: FormatVersion, Metadata: meta,
		Nonce:      base64.StdEncoding.EncodeToString(nonce),
		Ciphertext: base64.StdEncoding.EncodeToString(ciphertext),
	})
	if err != nil {
		return nil, fmt.Errorf("recovery: encode snapshot: %w", err)
	}
	return out, nil
}

// DecodeSnapshot authenticates and decrypts a checkpoint only when all identity
// fields match expected. It returns bytes for inspection or a separately approved
// restore adapter; it never applies configuration to a device.
func DecodeSnapshot(snapshot, key []byte, expected Metadata) ([]byte, Metadata, error) {
	if len(key) != keyBytes {
		return nil, Metadata{}, ErrInvalidKey
	}
	if len(snapshot) == 0 || len(snapshot) > MaxConfigBytes*2 {
		return nil, Metadata{}, errors.New("recovery: invalid snapshot size")
	}
	dec := json.NewDecoder(bytes.NewReader(snapshot))
	dec.DisallowUnknownFields()
	var env envelope
	if err := dec.Decode(&env); err != nil {
		return nil, Metadata{}, fmt.Errorf("recovery: decode snapshot: %w", err)
	}
	if err := dec.Decode(new(any)); err != io.EOF {
		return nil, Metadata{}, errors.New("recovery: trailing snapshot data")
	}
	if env.Version != FormatVersion {
		return nil, Metadata{}, ErrUnsupportedVersion
	}
	if env.Metadata.TenantID != expected.TenantID ||
		env.Metadata.AssetID != expected.AssetID ||
		env.Metadata.HardwareFingerprint != expected.HardwareFingerprint ||
		env.Metadata.ConfigSchema != expected.ConfigSchema {
		return nil, Metadata{}, ErrIdentityMismatch
	}
	nonce, err := base64.StdEncoding.DecodeString(env.Nonce)
	if err != nil {
		return nil, Metadata{}, errors.New("recovery: invalid nonce encoding")
	}
	ciphertext, err := base64.StdEncoding.DecodeString(env.Ciphertext)
	if err != nil {
		return nil, Metadata{}, errors.New("recovery: invalid ciphertext encoding")
	}
	block, err := aes.NewCipher(key)
	if err != nil {
		return nil, Metadata{}, fmt.Errorf("recovery: initialise cipher: %w", err)
	}
	aead, err := cipher.NewGCM(block)
	if err != nil {
		return nil, Metadata{}, fmt.Errorf("recovery: initialise authenticated encryption: %w", err)
	}
	if len(nonce) != aead.NonceSize() {
		return nil, Metadata{}, errors.New("recovery: invalid nonce length")
	}
	aad, err := json.Marshal(env.Metadata)
	if err != nil {
		return nil, Metadata{}, fmt.Errorf("recovery: encode authenticated metadata: %w", err)
	}
	plaintext, err := aead.Open(nil, nonce, ciphertext, aad)
	if err != nil {
		return nil, Metadata{}, errors.New("recovery: authentication failed; key or snapshot may be invalid or modified")
	}
	var payload encryptedPayload
	if err := json.Unmarshal(plaintext, &payload); err != nil {
		return nil, Metadata{}, fmt.Errorf("recovery: decode authenticated payload: %w", err)
	}
	if len(payload.Configuration) == 0 || len(payload.Configuration) > MaxConfigBytes {
		return nil, Metadata{}, ErrConfigTooLarge
	}
	sum := sha256.Sum256(payload.Configuration)
	if payload.SHA256 != hex.EncodeToString(sum[:]) {
		return nil, Metadata{}, errors.New("recovery: configuration digest mismatch")
	}
	return payload.Configuration, env.Metadata, nil
}
