/**
 * NetPulse CPE firmware eligibility policy.
 *
 * This is a policy gate, not a flasher and not a cryptographic verifier.
 * The caller must obtain signature/checksum verification results from a
 * trusted build/release verification service, never from browser input.
 */

export type DeviceStatus =
  | "identification_required"
  | "research"
  | "porting"
  | "lab_only"
  | "supported"
  | "blocked"
  | "retired";

export interface CpeDeviceProfile {
  manufacturer: string;
  model: string;
  hardwareRevision: string;
  boardId: string;
  openwrtTarget: string | null;
  firmwarePartitionBytes: number | null;
  status: DeviceStatus;
  recoveryVerified: boolean;
}

export interface FirmwareArtifact {
  manufacturer: string;
  model: string;
  hardwareRevision: string;
  boardId: string;
  openwrtTarget: string;
  imageBytes: number;
  sha256: string;
  signatureVerifiedByTrustedPipeline: boolean;
  checksumVerifiedByTrustedPipeline: boolean;
  releaseApproved: boolean;
}

export interface EligibilityResult {
  eligible: boolean;
  reasons: string[];
}

const isKnown = (value: string | null | undefined): boolean =>
  typeof value === "string" &&
  value.trim().length > 0 &&
  value.trim().toUpperCase() !== "UNKNOWN";

export function evaluateFirmwareEligibility(
  device: CpeDeviceProfile,
  artifact: FirmwareArtifact,
): EligibilityResult {
  const reasons: string[] = [];

  if (device.status !== "supported") {
    reasons.push("Device profile is not in supported status.");
  }

  if (!device.recoveryVerified) {
    reasons.push("Recovery procedure has not been verified.");
  }

  if (
    !isKnown(device.hardwareRevision) ||
    !isKnown(device.boardId) ||
    !isKnown(device.openwrtTarget)
  ) {
    reasons.push("Device hardware identity or target is unknown.");
  }

  if (
    device.manufacturer !== artifact.manufacturer ||
    device.model !== artifact.model ||
    device.hardwareRevision !== artifact.hardwareRevision ||
    device.boardId !== artifact.boardId
  ) {
    reasons.push("Exact manufacturer, model, hardware revision and board ID must match.");
  }

  if (
    !device.openwrtTarget ||
    device.openwrtTarget !== artifact.openwrtTarget
  ) {
    reasons.push("Firmware target does not match the registered device target.");
  }

  if (
    !Number.isSafeInteger(artifact.imageBytes) ||
    artifact.imageBytes <= 0
  ) {
    reasons.push("Firmware image size is invalid.");
  }

  if (
    !device.firmwarePartitionBytes ||
    !Number.isSafeInteger(device.firmwarePartitionBytes) ||
    device.firmwarePartitionBytes <= 0 ||
    artifact.imageBytes > device.firmwarePartitionBytes
  ) {
    reasons.push("Image exceeds or cannot be checked against the firmware partition.");
  }

  if (!/^[a-f0-9]{64}$/i.test(artifact.sha256)) {
    reasons.push("A valid SHA-256 digest is required.");
  }

  if (!artifact.checksumVerifiedByTrustedPipeline) {
    reasons.push("Checksum has not been verified by the trusted release pipeline.");
  }

  if (!artifact.signatureVerifiedByTrustedPipeline) {
    reasons.push("Firmware signature has not been verified by the trusted release pipeline.");
  }

  if (!artifact.releaseApproved) {
    reasons.push("Firmware release has not been approved.");
  }

  return {
    eligible: reasons.length === 0,
    reasons,
  };
}
