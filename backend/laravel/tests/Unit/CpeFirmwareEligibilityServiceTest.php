<?php

namespace Tests\Unit;

use App\Services\CpeFirmwareEligibilityService;
use PHPUnit\Framework\TestCase;

class CpeFirmwareEligibilityServiceTest extends TestCase
{
    private function validDevice(): array
    {
        return [
            'manufacturer' => 'Example',
            'model' => 'Router-1',
            'hardware_revision' => 'A1',
            'board_id' => 'example,router-1',
            'openwrt_target' => 'ramips/mt7621',
            'firmware_partition_bytes' => 8_000_000,
            'status' => 'supported',
            'recovery_verified' => true,
        ];
    }

    private function validArtifact(): array
    {
        return [
            'manufacturer' => 'Example',
            'model' => 'Router-1',
            'hardware_revision' => 'A1',
            'board_id' => 'example,router-1',
            'openwrt_target' => 'ramips/mt7621',
            'image_bytes' => 4_000_000,
            'sha256' => str_repeat('a', 64),
            'checksum_verified_by_trusted_pipeline' => true,
            'signature_verified_by_trusted_pipeline' => true,
            'release_approved' => true,
        ];
    }

    public function test_matching_supported_device_and_approved_image_are_eligible(): void
    {
        $result = (new CpeFirmwareEligibilityService())->evaluate(
            $this->validDevice() + [],
            $this->validArtifact() + ['firmware_partition_bytes' => 8_000_000],
        );

        self::assertTrue($result['eligible']);
        self::assertSame([], $result['reasons']);
    }

    public function test_unknown_hardware_revision_is_blocked(): void
    {
        $device = $this->validDevice();
        $device['hardware_revision'] = 'UNKNOWN';

        $result = (new CpeFirmwareEligibilityService())->evaluate($device, $this->validArtifact());

        self::assertFalse($result['eligible']);
        self::assertNotSame([], $result['reasons']);
    }

    public function test_wrong_hardware_revision_is_blocked(): void
    {
        $artifact = $this->validArtifact();
        $artifact['hardware_revision'] = 'B2';

        $result = (new CpeFirmwareEligibilityService())->evaluate($this->validDevice(), $artifact);

        self::assertFalse($result['eligible']);
    }

    public function test_missing_signature_verification_is_blocked(): void
    {
        $artifact = $this->validArtifact();
        $artifact['signature_verified_by_trusted_pipeline'] = false;

        $result = (new CpeFirmwareEligibilityService())->evaluate($this->validDevice(), $artifact);

        self::assertFalse($result['eligible']);
        self::assertStringContainsString(
            'Firmware signature',
            implode(' ', $result['reasons'])
        );
    }

    public function test_image_larger_than_partition_is_blocked(): void
    {
        $artifact = $this->validArtifact();
        $artifact['image_bytes'] = 9_000_000;

        $result = (new CpeFirmwareEligibilityService())->evaluate($this->validDevice(), $artifact);

        self::assertFalse($result['eligible']);
    }
}
