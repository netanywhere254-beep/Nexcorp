<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\GovernanceApproval;
use App\Models\GroupAuthority;
use App\Models\User;
use App\Services\GovernanceApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class GovernanceApprovalServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_authority_is_activated_only_after_all_approval_stages(): void
    {
        [$requester, $subject, $director, $ceo] = $this->users();
        $this->authority($director, 'DEPARTMENT_HEAD');
        $this->authority($ceo, 'GROUP_DIRECTOR');

        $service = app(GovernanceApprovalService::class);
        $request = $service->openRequest(
            $requester, $subject, 'DEPARTMENT_HEAD', 'group', 'nexora',
            'Appointment for the technology department',
            [
                ['approver_user_id' => $director->id, 'approval_level' => 1, 'required_authority' => 'DEPARTMENT_HEAD'],
                ['approver_user_id' => $ceo->id, 'approval_level' => 2, 'required_authority' => 'GROUP_DIRECTOR'],
            ],
        );

        $service->decide($director, $request->approvals[0]->id, 'approved');
        $this->assertDatabaseMissing('group_authorities', [
            'user_id' => $subject->id,
            'authority' => 'DEPARTMENT_HEAD',
        ]);

        $service->decide($ceo, $request->approvals[1]->id, 'approved');
        $this->assertDatabaseHas('group_authorities', [
            'user_id' => $subject->id,
            'authority' => 'DEPARTMENT_HEAD',
            'scope_type' => 'group',
            'scope_key' => 'nexora',
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('governance_requests', [
            'id' => $request->id,
            'status' => 'approved',
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'governance.authority_activated']);
    }

    public function test_unassigned_user_cannot_approve_even_if_they_have_same_authority(): void
    {
        [$requester, $subject, $assigned, $other] = $this->users();
        $this->authority($assigned, 'GROUP_DIRECTOR');
        $this->authority($other, 'GROUP_DIRECTOR');

        $request = app(GovernanceApprovalService::class)->openRequest(
            $requester, $subject, 'DEPARTMENT_HEAD', 'group', 'nexora',
            'Appointment reason',
            [['approver_user_id' => $assigned->id, 'approval_level' => 1, 'required_authority' => 'GROUP_DIRECTOR']],
        );

        $this->expectException(AuthorizationException::class);
        app(GovernanceApprovalService::class)->decide($other, $request->approvals[0]->id, 'approved');
    }

    public function test_requester_or_subject_cannot_be_assigned_as_approver(): void
    {
        [$requester, $subject] = $this->users();

        $this->expectException(ValidationException::class);
        app(GovernanceApprovalService::class)->openRequest(
            $requester, $subject, 'DEPARTMENT_HEAD', 'group', 'nexora',
            'Appointment reason',
            [['approver_user_id' => $requester->id, 'approval_level' => 1, 'required_authority' => 'GROUP_DIRECTOR']],
        );
    }

    public function test_rejection_closes_request_without_activating_authority(): void
    {
        [$requester, $subject, $director] = $this->users();
        $this->authority($director, 'GROUP_DIRECTOR');

        $service = app(GovernanceApprovalService::class);
        $request = $service->openRequest(
            $requester, $subject, 'DEPARTMENT_HEAD', 'group', 'nexora',
            'Appointment reason',
            [['approver_user_id' => $director->id, 'approval_level' => 1, 'required_authority' => 'GROUP_DIRECTOR']],
        );

        $service->decide($director, $request->approvals[0]->id, 'rejected', 'Not approved');
        $this->assertDatabaseHas('governance_requests', ['id' => $request->id, 'status' => 'rejected']);
        $this->assertSame(0, GroupAuthority::where('user_id', $subject->id)->count());
    }

    public function test_ceo_appointment_is_reserved_for_separate_bootstrap(): void
    {
        [$requester, $subject] = $this->users();

        $this->expectException(ValidationException::class);
        app(GovernanceApprovalService::class)->openRequest(
            $requester, $subject, 'GROUP_CEO', 'group', 'nexora',
            'CEO succession',
            [['approver_user_id' => 999, 'approval_level' => 1, 'required_authority' => 'GROUP_DIRECTOR']],
        );
    }

    private function users(): array
    {
        return array_map(fn ($name) => User::create([
            'name' => $name,
            'email' => strtolower(str_replace(' ', '-', $name)).'-'.uniqid().'@example.test',
            'password' => 'Correct-Horse-42!',
            'status' => 'active',
        ]), ['Requester', 'Appointee', 'Director', 'Executive']);
    }

    private function authority(User $user, string $authority): void
    {
        GroupAuthority::create([
            'user_id' => $user->id,
            'authority' => $authority,
            'scope_type' => 'group',
            'scope_key' => 'nexora',
            'status' => 'active',
            'starts_at' => Carbon::now()->subMinute(),
        ]);
    }
}
