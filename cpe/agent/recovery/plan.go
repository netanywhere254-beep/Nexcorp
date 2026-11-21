package recovery

import (
	"bytes"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"sort"
	"strings"
	"time"
)

var (
	ErrRestoreNotEligible = errors.New("recovery: device is not eligible for configuration restore")
	ErrApprovalInvalid    = errors.New("recovery: restore approval is invalid")
	ErrConfigInvalid      = errors.New("recovery: configuration document is invalid")
)

var allowedSections = map[string]bool{
	"wan": true, "lan": true, "wireless": true, "dhcp": true,
	"firewall": true, "routes": true, "dns": true, "management": true,
}

type DeviceProfile struct {
	Manufacturer         string    `json:"manufacturer"`
	Model                string    `json:"model"`
	HardwareRevision     string    `json:"hardware_revision"`
	BoardID              string    `json:"board_id"`
	Status               string    `json:"status"`
	RecoveryVerified     bool      `json:"recovery_verified"`
	ConfigurationRestore bool      `json:"configuration_restore"`
	SupportLevel         string    `json:"support_level"`
	LastLabTest          time.Time `json:"last_lab_test"`
	EvidenceReference    string    `json:"evidence_reference"`
}

type RestoreApproval struct {
	TenantID         string    `json:"tenant_id"`
	AssetID          string    `json:"asset_id"`
	AuthorisationRef string    `json:"authorisation_reference"`
	OperatorID       string    `json:"operator_id"`
	ReviewerID       string    `json:"reviewer_id"`
	Approved         bool      `json:"approved"`
	ExpiresAt        time.Time `json:"expires_at"`
}

type RestorePlan struct {
	PlanVersion         int       `json:"plan_version"`
	Status              string    `json:"status"`
	TenantID            string    `json:"tenant_id"`
	AssetID             string    `json:"asset_id"`
	HardwareFingerprint string    `json:"hardware_fingerprint"`
	Device              string    `json:"device"`
	CurrentSHA256       string    `json:"current_configuration_sha256"`
	ProposedSHA256      string    `json:"proposed_configuration_sha256"`
	ChangedSections     []string  `json:"changed_sections"`
	ApprovalReference   string    `json:"approval_reference"`
	OperatorID          string    `json:"operator_id"`
	ReviewerID          string    `json:"reviewer_id"`
	RecoveryEvidence    string    `json:"recovery_evidence_reference"`
	GeneratedAt         time.Time `json:"generated_at"`
	ExecutionEnabled    bool      `json:"execution_enabled"`
	Notice              string    `json:"notice"`
}

type configDocument struct {
	SchemaVersion string                     `json:"schema_version"`
	Sections      map[string]json.RawMessage `json:"sections"`
}

func parseConfig(raw []byte) (configDocument, error) {
	var doc configDocument
	if len(raw) == 0 || len(raw) > MaxConfigBytes {
		return doc, ErrConfigInvalid
	}
	dec := json.NewDecoder(bytes.NewReader(raw))
	dec.DisallowUnknownFields()
	if err := dec.Decode(&doc); err != nil {
		return doc, fmt.Errorf("%w: %v", ErrConfigInvalid, err)
	}
	if err := dec.Decode(new(any)); err != nil && !errors.Is(err, errors.New("EOF")) {
		// A second JSON value is not permitted. The decoder's EOF is handled below
		// using the standard library sentinel.
		if err.Error() != "EOF" {
			return doc, fmt.Errorf("%w: trailing JSON data", ErrConfigInvalid)
		}
	}
	if doc.SchemaVersion == "" || len(doc.Sections) == 0 {
		return doc, ErrConfigInvalid
	}
	for name, value := range doc.Sections {
		if !allowedSections[name] || len(value) == 0 || !json.Valid(value) {
			return doc, fmt.Errorf("%w: unsupported or malformed section %q", ErrConfigInvalid, name)
		}
		var decoded any
		if err := json.Unmarshal(value, &decoded); err != nil {
			return doc, fmt.Errorf("%w: malformed section %q", ErrConfigInvalid, name)
		}
		canonical, err := json.Marshal(decoded)
		if err != nil {
			return doc, fmt.Errorf("%w: cannot canonicalise section %q", ErrConfigInvalid, name)
		}
		doc.Sections[name] = canonical
	}
	return doc, nil
}

func digest(raw []byte) string {
	sum := sha256.Sum256(raw)
	return hex.EncodeToString(sum[:])
}

func changedSections(current, proposed configDocument) []string {
	set := make(map[string]bool)
	for key := range current.Sections {
		set[key] = true
	}
	for key := range proposed.Sections {
		set[key] = true
	}
	changed := make([]string, 0)
	for key := range set {
		if string(current.Sections[key]) != string(proposed.Sections[key]) {
			changed = append(changed, key)
		}
	}
	sort.Strings(changed)
	return changed
}

// BuildRestorePlan performs an offline dry-run. It does not execute commands,
// connect to a router, or return configuration contents or credentials.
func BuildRestorePlan(meta Metadata, profile DeviceProfile, approval RestoreApproval, currentConfig, proposedConfig []byte, now time.Time) (RestorePlan, error) {
	if meta.TenantID == "" || meta.AssetID == "" || meta.HardwareFingerprint == "" ||
		meta.ConfigSchema == "" || profile.Manufacturer == "" || profile.Model == "" ||
		profile.HardwareRevision == "" || profile.BoardID == "" {
		return RestorePlan{}, ErrRestoreNotEligible
	}
	if !profile.RecoveryVerified || !profile.ConfigurationRestore ||
		(profile.SupportLevel != "R1" && profile.SupportLevel != "R2" && profile.SupportLevel != "R3" && profile.SupportLevel != "R4") ||
		(profile.Status != "supported" && profile.Status != "lab_only") ||
		profile.EvidenceReference == "" || profile.LastLabTest.IsZero() ||
		profile.LastLabTest.After(now) || now.Sub(profile.LastLabTest) > 365*24*time.Hour {
		return RestorePlan{}, ErrRestoreNotEligible
	}
	if !approval.Approved || approval.TenantID != meta.TenantID ||
		approval.AssetID != meta.AssetID || approval.AuthorisationRef == "" ||
		approval.OperatorID == "" || approval.ReviewerID == "" ||
		approval.OperatorID == approval.ReviewerID || !approval.ExpiresAt.After(now) {
		return RestorePlan{}, ErrApprovalInvalid
	}
	current, err := parseConfig(currentConfig)
	if err != nil {
		return RestorePlan{}, err
	}
	proposed, err := parseConfig(proposedConfig)
	if err != nil {
		return RestorePlan{}, err
	}
	if current.SchemaVersion != proposed.SchemaVersion || current.SchemaVersion != meta.ConfigSchema {
		return RestorePlan{}, fmt.Errorf("%w: configuration schema mismatch", ErrConfigInvalid)
	}
	sections := changedSections(current, proposed)
	if len(sections) == 0 {
		return RestorePlan{}, errors.New("recovery: no configuration changes are required")
	}
	return RestorePlan{
		PlanVersion: 1, Status: "dry_run_ready_for_lab_review",
		TenantID: meta.TenantID, AssetID: meta.AssetID,
		HardwareFingerprint: meta.HardwareFingerprint,
		Device: profile.Manufacturer + " " + profile.Model + " " + profile.HardwareRevision + " (" + profile.BoardID + ")",
		CurrentSHA256: digest(currentConfig), ProposedSHA256: digest(proposedConfig),
		ChangedSections: sections, ApprovalReference: approval.AuthorisationRef,
		OperatorID: approval.OperatorID, ReviewerID: approval.ReviewerID,
		RecoveryEvidence: profile.EvidenceReference, GeneratedAt: now.UTC(),
		ExecutionEnabled: false,
		Notice: "Dry-run only. No router connection or configuration change was performed. A device-specific restore adapter and separate execution approval are still required.",
	}, nil
}

var _ = strings.Builder{}
