<?php

namespace App\Services;

final class CpeFirmwareEligibilityService
{
    /**
     * Fail-closed policy check. Cryptographic verification must happen in the
     * trusted release pipeline; caller-provided browser values are not trusted.
     *
     * @return array{eligible: bool, reasons: list<string>}
     */
    public function evaluate(array $device, array $artifact): array
    {
        $reasons = [];
        $known = static fn ($value): bool =>
            is_string($value)
            && trim($value) !== ''
            && strtoupper(trim($value)) !== 'UNKNOWN';

        if (($device['status'] ?? null) !== 'supported') {
            $reasons[] = 'Device profile is not in supported status.';
        }

        if (($device['recovery_verified'] ?? false) !== true) {
            $reasons[] = 'Recovery procedure has not been verified.';
        }

        foreach (['hardware_revision', 'board_id', 'openwrt_target'] as $field) {
            if (! $known($device[$field] ?? null)) {
                $reasons[] = "Device {$field} is unknown.";
            }
        }

        foreach (['manufacturer', 'model', 'hardware_revision', 'board_id'] as $field) {
            if (! isset($device[$field], $artifact[$field]) || $device[$field] !== $artifact[$field]) {
                $reasons[] = "Firmware {$field} does not exactly match the device profile.";
            }
        }

        if (
            ! $known($device['openwrt_target'] ?? null)
            || ($device['openwrt_target'] ?? null) !== ($artifact['openwrt_target'] ?? null)
        ) {
            $reasons[] = 'Firmware target does not match the registered device target.';
        }

        $imageBytes = $artifact['image_bytes'] ?? null;
        $partitionBytes = $device['firmware_partition_bytes'] ?? null;

        if (! is_int($imageBytes) || $imageBytes <= 0) {
            $reasons[] = 'Firmware image size is invalid.';
        }

        if (
            ! is_int($partitionBytes)
            || $partitionBytes <= 0
            || ! is_int($imageBytes)
            || $imageBytes > $partitionBytes
        ) {
            $reasons[] = 'Image exceeds or cannot be checked against the firmware partition.';
        }

        if (! is_string($artifact['sha256'] ?? null) || ! preg_match('/\A[a-f0-9]{64}\z/i', $artifact['sha256'])) {
            $reasons[] = 'A valid SHA-256 digest is required.';
        }

        if (($artifact['checksum_verified_by_trusted_pipeline'] ?? false) !== true) {
            $reasons[] = 'Checksum has not been verified by the trusted release pipeline.';
        }

        if (($artifact['signature_verified_by_trusted_pipeline'] ?? false) !== true) {
            $reasons[] = 'Firmware signature has not been verified by the trusted release pipeline.';
        }

        if (($artifact['release_approved'] ?? false) !== true) {
            $reasons[] = 'Firmware release has not been approved.';
        }

        return [
            'eligible' => $reasons === [],
            'reasons' => $reasons,
        ];
    }
}
