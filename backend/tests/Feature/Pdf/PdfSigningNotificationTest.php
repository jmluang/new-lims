<?php

namespace Tests\Feature\Pdf;

use App\Models\PdfDocument;
use App\Models\PdfSigningAct;
use App\Models\PdfSigningPolicyVersion;
use App\Models\PdfSigningRequest;
use App\Models\PdfSigningWorkflow;
use App\Models\User;
use App\Models\UserMessage;
use App\Services\Pdf\CanonicalJson;
use App\Services\Pdf\PdfSigningNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PdfSigningNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_the_signer_whose_turn_it_is_hears_about_it(): void
    {
        [$workflow, $requests] = $this->workflow();

        app(PdfSigningNotifier::class)->notifyNextInWorkflow($workflow->id);

        // The other two cannot sign until the signature in front of them lands,
        // so telling them now would be telling them to do nothing.
        $this->assertSame(1, UserMessage::query()->count());
        $this->assertSame($requests[0]->assigned_user_id, UserMessage::query()->sole()->recipient_user_id);
    }

    public function test_the_message_says_which_report_and_which_role(): void
    {
        [, $requests] = $this->workflow();

        app(PdfSigningNotifier::class)->notifyAvailable($requests[0]);

        $message = UserMessage::query()->sole();
        $this->assertSame('手写签名待处理', $message->title);
        $this->assertStringContainsString('XDP-NOTIFY-1', $message->content);
        $this->assertStringContainsString('主检', $message->content);
        // The task id lives in the link now, not in the sentence.
        $this->assertStringContainsString($requests[0]->request_uuid, (string) $message->link_path);
    }

    public function test_the_message_links_straight_to_the_task(): void
    {
        [, $requests] = $this->workflow();

        app(PdfSigningNotifier::class)->notifyAvailable($requests[0]);

        $this->assertSame(
            "/pdf/handwritten-signing?request={$requests[0]->request_uuid}#sign",
            UserMessage::query()->sole()->link_path,
        );
    }

    public function test_the_api_refuses_to_hand_out_an_offsite_link(): void
    {
        [, $requests] = $this->workflow();
        app(PdfSigningNotifier::class)->notifyAvailable($requests[0]);
        $recipient = User::query()->findOrFail($requests[0]->assigned_user_id);
        // Whatever put it there, the client navigates with this value.
        UserMessage::query()->sole()->update(['link_path' => '//evil.example/phish']);
        Sanctum::actingAs($recipient);

        $this->getJson('/api/messages')
            ->assertOk()
            ->assertJsonPath('data.0.link_path', null);
    }

    public function test_a_replayed_transition_does_not_notify_twice(): void
    {
        [, $requests] = $this->workflow();
        $notifier = app(PdfSigningNotifier::class);

        // The reconciler can drive the same transition again after a crash.
        $notifier->notifyAvailable($requests[0]);
        $notifier->notifyAvailable($requests[0]);

        $this->assertSame(1, UserMessage::query()->count());
    }

    public function test_the_next_signer_is_told_when_the_turn_moves_on(): void
    {
        [$workflow, $requests] = $this->workflow();
        $notifier = app(PdfSigningNotifier::class);
        $notifier->notifyNextInWorkflow($workflow->id);

        $requests[0]->update(['status' => 'signed']);
        $requests[1]->update(['status' => 'available']);
        $notifier->notifyNextInWorkflow($workflow->id);

        $this->assertSame(2, UserMessage::query()->count());
        $this->assertSame(
            [$requests[0]->assigned_user_id, $requests[1]->assigned_user_id],
            UserMessage::query()->orderBy('id')->pluck('recipient_user_id')->all(),
        );
    }

    public function test_a_rejection_reaches_whoever_planned_the_workflow(): void
    {
        [, $requests] = $this->workflow();

        app(PdfSigningNotifier::class)->notifyRejected($requests[1], 'SCAN_UNREADABLE');

        $message = UserMessage::query()->sole();
        $this->assertSame('手写签名被拒绝', $message->title);
        $this->assertStringContainsString('SCAN_UNREADABLE', $message->content);
        $this->assertStringContainsString('审核', $message->content);
        $this->assertSame(
            PdfDocument::query()->sole()->created_by_id,
            $message->recipient_user_id,
        );
    }

    /** @return array{0: PdfSigningWorkflow, 1: list<PdfSigningRequest>} */
    private function workflow(): array
    {
        $planner = User::factory()->create();
        $document = PdfDocument::query()->create([
            'document_uuid' => (string) Str::uuid(),
            'document_public_id' => Str::random(48),
            'organization_scope' => 'default',
            'authoritative_report_number' => 'XDP-NOTIFY-1',
            'normalized_report_number' => 'XDP-NOTIFY-1',
            'status' => 'draft',
            'created_by_id' => $planner->id,
        ]);
        $workflow = PdfSigningWorkflow::query()->create([
            'workflow_uuid' => (string) Str::uuid(),
            'document_id' => $document->id,
            'workflow_generation' => 1,
            'placement_plan' => [],
            'placement_plan_hash' => str_repeat('a', 64),
            'status' => 'ready',
            'created_by_id' => $planner->id,
        ]);
        $policy = $this->policy();
        $requests = [];

        foreach ([['inspector', 1], ['reviewer', 2], ['issuer', 3]] as [$role, $sequence]) {
            $act = PdfSigningAct::query()->create([
                'logical_act_uuid' => (string) Str::uuid(),
                'document_id' => $document->id,
                'plan_generation' => 1,
                'semantic_role' => $role,
                'pdf_signature_role' => $sequence === 1 ? 'certification_p2' : 'approval',
                'sequence' => $sequence,
                'field_name' => "lims_{$role}_g1",
                'status' => 'planned',
            ]);
            $requests[] = PdfSigningRequest::query()->create([
                'request_uuid' => (string) Str::uuid(),
                'workflow_id' => $workflow->id,
                'signing_act_id' => $act->id,
                'sequence' => $sequence,
                'request_type' => 'handwritten',
                'assigned_user_id' => User::factory()->create()->id,
                'signing_policy_version_id' => $policy->id,
                'status' => $sequence === 1 ? 'available' : 'pending',
            ]);
        }

        return [$workflow, $requests];
    }

    private function policy(): PdfSigningPolicyVersion
    {
        $manifest = ['profile' => 'B-T', 'version' => 1];

        return PdfSigningPolicyVersion::query()->create([
            'version_uuid' => (string) Str::uuid(),
            'policy_hash' => hash('sha256', CanonicalJson::encode($manifest)),
            'immutable_at' => now(),
            'pades_profile' => 'B-T',
            'digest_algorithm_oid' => '2.16.840.1.101.3.4.2.1',
            'signature_algorithm_oid' => '1.2.840.113549.1.1.11',
            'organization_certificate_fingerprints' => [str_repeat('b', 64)],
            'signing_material_version' => 'test-v1',
            'key_locator' => 'test',
            'tsa_url_set' => ['http://tsa.invalid'],
            'tsa_policy_oid' => '1.2.3.4',
            'tsa_timeout_seconds' => 10,
            'trust_bundle_hash' => str_repeat('c', 64),
            'revocation_policy' => ['mode' => 'required'],
            'reserved_size' => 32768,
            'pre_private_key_retry_backoff_seconds' => [2, 5],
            'pre_private_key_retryable_error_codes' => ['DB_UNAVAILABLE'],
            'java_execution_registration_timeout_seconds' => 15,
            'java_execution_timeout_seconds' => 90,
            'java_status_poll_policy' => ['initial' => 2, 'max' => 10],
            'java_result_min_bytes_per_second' => 1048576,
            'java_result_read_timeout_seconds' => 60,
            'generated_revision_max_bytes' => 33554432,
            'max_signature_increment_bytes' => 2097152,
            'policy_manifest' => $manifest,
            'config_bundle_hash' => hash('sha256', CanonicalJson::encode($manifest)),
        ]);
    }
}
