<?php

namespace Tests\Feature;

use App\Services\CaseReferenceNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CaseReferenceNumberTest extends TestCase
{
    use RefreshDatabase;

    public function test_nexora_and_loopza_have_separate_registration_sequences(): void
    {
        $references = app(CaseReferenceNumber::class);

        $this->assertSame('NEX-2026-000001', $references->next('nexora', 2026));
        $this->assertSame('LTF-2026-000001', $references->next('loopza', 2026));
        $this->assertSame('NEX-2026-000002', $references->next('nexora', 2026));
        $this->assertSame('LTF-2026-000002', $references->next('loopza', 2026));
    }

    public function test_registration_sequence_is_independent_between_years(): void
    {
        $references = app(CaseReferenceNumber::class);

        $this->assertSame('LTF-2026-000001', $references->next('loopza', 2026));
        $this->assertSame('LTF-2027-000001', $references->next('loopza', 2027));
        $this->assertSame('LTF-2026-000002', $references->next('loopza', 2026));
    }

    public function test_unknown_systems_are_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(CaseReferenceNumber::class)->next('unknown', 2026);
    }
}
