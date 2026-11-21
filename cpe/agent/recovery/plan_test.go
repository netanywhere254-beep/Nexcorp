package recovery

import (
	"encoding/json"
	"errors"
	"strings"
	"testing"
	"time"
)

func planFixtures() (Metadata, DeviceProfile, RestoreApproval, []byte, []byte, time.Time) {
	now := time.Date(2026, 11, 21, 14, 0, 0, 0, time.UTC)
	meta := Metadata{
		TenantID: "tenant-a", AssetID: "cpe-01",
		HardwareFingerprint: "board-x-rev2", ConfigSchema: "netpulse-config-v1",
	}
	profile := DeviceProfile{
		Manufacturer: "LabVendor", Model: "Router-X", HardwareRevision: "2",
		BoardID: "board-x", Status: "lab_only", RecoveryVerified: true,
		ConfigurationRestore: true, SupportLevel: "R1",
		LastLabTest: now.Add(-24 * time.Hour), EvidenceReference: "LAB-RECOVERY-TEST-2026-004",
	}
	approval := RestoreApproval{
		TenantID: "tenant-a", AssetID: "cpe-01", AuthorisationRef: "CHANGE-104",
		OperatorID: "operator-1", ReviewerID: "reviewer-2", Approved: true,
		ExpiresAt: now.Add(time.Hour),
	}
	current := []byte(`{"schema_version":"netpulse-config-v1","sections":{"lan":{"address":"192.168.1.1"},"wireless":{"ssid":"old-name"}}}`)
	proposed := []byte(`{"schema_version":"netpulse-config-v1","sections":{"lan":{"address":"192.168.1.1"},"wireless":{"ssid":"new-name"},"dns":{"mode":"auto"}}}`)
	return meta, profile, approval, current, proposed, now
}

func TestBuildRestorePlanDryRun(t *testing.T) {
	meta, profile, approval, current, proposed, now := planFixtures()
	plan, err := BuildRestorePlan(meta, profile, approval, current, proposed, now)
	if err != nil {
		t.Fatal(err)
	}
	if plan.ExecutionEnabled {
		t.Fatal("dry-run plan must never enable execution")
	}
	if plan.Status != "dry_run_ready_for_lab_review" {
		t.Fatalf("unexpected status %q", plan.Status)
	}
	want := []string{"dns", "wireless"}
	if len(plan.ChangedSections) != len(want) {
		t.Fatalf("changed sections: %v", plan.ChangedSections)
	}
	for i := range want {
		if plan.ChangedSections[i] != want[i] {
			t.Fatalf("changed sections: %v", plan.ChangedSections)
		}
	}
	encoded, err := json.Marshal(plan)
	if err != nil {
		t.Fatal(err)
	}
	if containsAny(string(encoded), "new-name", "192.168.1.1") {
		t.Fatal("plan leaked configuration values")
	}
}

func TestBuildRestorePlanRejectsUnverifiedRecovery(t *testing.T) {
	meta, profile, approval, current, proposed, now := planFixtures()
	profile.RecoveryVerified = false
	_, err := BuildRestorePlan(meta, profile, approval, current, proposed, now)
	if !errors.Is(err, ErrRestoreNotEligible) {
		t.Fatalf("expected eligibility error, got %v", err)
	}
}

func TestBuildRestorePlanRejectsWrongAssetApproval(t *testing.T) {
	meta, profile, approval, current, proposed, now := planFixtures()
	approval.AssetID = "other-cpe"
	_, err := BuildRestorePlan(meta, profile, approval, current, proposed, now)
	if !errors.Is(err, ErrApprovalInvalid) {
		t.Fatalf("expected approval error, got %v", err)
	}
}

func TestBuildRestorePlanRejectsSameOperatorAndReviewer(t *testing.T) {
	meta, profile, approval, current, proposed, now := planFixtures()
	approval.ReviewerID = approval.OperatorID
	_, err := BuildRestorePlan(meta, profile, approval, current, proposed, now)
	if !errors.Is(err, ErrApprovalInvalid) {
		t.Fatalf("expected approval error, got %v", err)
	}
}

func TestBuildRestorePlanRejectsExpiredLabEvidence(t *testing.T) {
	meta, profile, approval, current, proposed, now := planFixtures()
	profile.LastLabTest = now.Add(-366 * 24 * time.Hour)
	_, err := BuildRestorePlan(meta, profile, approval, current, proposed, now)
	if !errors.Is(err, ErrRestoreNotEligible) {
		t.Fatalf("expected eligibility error, got %v", err)
	}
}

func TestBuildRestorePlanRejectsSchemaMismatch(t *testing.T) {
	meta, profile, approval, current, proposed, now := planFixtures()
	proposed = []byte(`{"schema_version":"different","sections":{"lan":{"address":"10.0.0.1"}}}`)
	_, err := BuildRestorePlan(meta, profile, approval, current, proposed, now)
	if !errors.Is(err, ErrConfigInvalid) {
		t.Fatalf("expected config error, got %v", err)
	}
}

func TestBuildRestorePlanRejectsNoOp(t *testing.T) {
	meta, profile, approval, current, _, now := planFixtures()
	_, err := BuildRestorePlan(meta, profile, approval, current, current, now)
	if err == nil {
		t.Fatal("expected no-op plan to be rejected")
	}
}

func TestBuildRestorePlanCanonicalisesJSON(t *testing.T) {
	meta, profile, approval, current, _, now := planFixtures()
	reformatted := []byte(`{
		"schema_version": "netpulse-config-v1",
		"sections": {"lan": { "address" : "192.168.1.1" }, "wireless": {"ssid":"old-name"}}
	}`)
	_, err := BuildRestorePlan(meta, profile, approval, current, reformatted, now)
	if err == nil {
		t.Fatal("canonical equivalent configuration should be treated as no change")
	}
}

func containsAny(value string, needles ...string) bool {
	for _, needle := range needles {
		if strings.Contains(value, needle) {
			return true
		}
	}
	return false
}
