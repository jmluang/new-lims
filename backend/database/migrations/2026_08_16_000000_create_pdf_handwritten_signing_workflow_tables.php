<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pdf_documents', function (Blueprint $table): void {
            $table->id();
            $table->uuid('document_uuid')->unique();
            $table->string('document_public_id', 64)->unique();
            $table->string('organization_scope', 128);
            $table->string('authoritative_report_number', 128);
            $table->string('normalized_report_number', 128);
            $table->unsignedBigInteger('active_workflow_id')->nullable()->index();
            $table->unsignedBigInteger('active_operation_id')->nullable()->index();
            $table->unsignedBigInteger('published_revision_id')->nullable()->index();
            $table->unsignedBigInteger('publication_version')->default(0);
            $table->unsignedBigInteger('integrity_version')->default(0);
            $table->unsignedBigInteger('integrity_hold_mask')->default(0);
            $table->string('integrity_state', 16)->default('ok');
            $table->timestamp('integrity_hold_started_at')->nullable();
            $table->timestamp('integrity_hold_released_at')->nullable();
            $table->unsignedBigInteger('evidence_hold_mask')->default(0);
            $table->string('evidence_hold_state', 16)->default('none');
            $table->timestamp('evidence_hold_started_at')->nullable();
            $table->timestamp('evidence_hold_released_at')->nullable();
            $table->timestamp('legal_hold_until')->nullable();
            $table->unsignedInteger('next_revision_number')->default(1);
            $table->string('status', 16)->default('draft');
            $table->foreignId('created_by_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['organization_scope', 'normalized_report_number'], 'pdf_documents_org_report_unique');
        });

        Schema::create('pdf_source_uploads', function (Blueprint $table): void {
            $table->id();
            $table->uuid('source_uuid')->unique();
            $table->foreignId('document_id')->nullable()->constrained('pdf_documents')->restrictOnDelete();
            $table->string('stored_path', 1024);
            $table->char('sha256', 64)->unique();
            $table->unsignedBigInteger('file_size');
            $table->unsignedInteger('page_count')->nullable();
            $table->json('inspection_manifest')->nullable();
            $table->char('inspection_manifest_hash', 64)->nullable();
            $table->foreignId('created_by_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->softDeletes();
            $table->string('status', 16)->default('uploaded');
            $table->timestamps();
            $table->index(['status', 'expires_at']);
        });

        Schema::table('pdf_files', function (Blueprint $table): void {
            $table->timestamp('signed_at')->nullable()->change();
            $table->foreignId('document_id')->nullable()->after('id')->constrained('pdf_documents')->restrictOnDelete();
            $table->uuid('revision_uuid')->nullable()->unique()->after('document_id');
            $table->foreignId('parent_pdf_file_id')->nullable()->after('revision_uuid')->constrained('pdf_files')->restrictOnDelete();
            $table->unsignedInteger('revision_number')->nullable()->after('parent_pdf_file_id');
            $table->string('revision_role', 32)->nullable()->after('revision_number');
            $table->timestamp('revision_created_at')->nullable()->after('revision_role');
            $table->json('revision_manifest')->nullable()->after('metadata');
            $table->char('revision_manifest_hash', 64)->nullable()->after('revision_manifest');
            $table->string('integrity_state', 16)->default('ready')->after('revision_manifest_hash');
            $table->string('disposition', 16)->default('active')->after('integrity_state');
            $table->timestamp('first_published_at')->nullable()->after('disposition');
            $table->unique(['document_id', 'revision_number'], 'pdf_files_document_revision_unique');
        });

        Schema::create('pdf_signing_policy_versions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('version_uuid')->unique();
            $table->char('policy_hash', 64)->unique();
            $table->timestamp('immutable_at');
            $table->string('pades_profile', 8)->default('B-T');
            $table->string('digest_algorithm_oid', 64);
            $table->string('signature_algorithm_oid', 64);
            $table->json('organization_certificate_fingerprints');
            $table->string('signing_material_version', 128);
            $table->string('key_locator', 255);
            $table->json('tsa_url_set');
            $table->string('tsa_policy_oid', 128)->nullable();
            $table->unsignedInteger('tsa_timeout_seconds');
            $table->char('trust_bundle_hash', 64);
            $table->json('revocation_policy');
            $table->unsignedInteger('reserved_size');
            $table->unsignedTinyInteger('pre_private_key_max_attempts')->default(3);
            $table->json('pre_private_key_retry_backoff_seconds');
            $table->json('pre_private_key_retryable_error_codes');
            $table->unsignedInteger('java_execution_registration_timeout_seconds');
            $table->unsignedInteger('java_execution_timeout_seconds');
            $table->json('java_status_poll_policy');
            $table->unsignedInteger('java_result_min_bytes_per_second');
            $table->unsignedInteger('java_result_read_timeout_seconds');
            $table->unsignedBigInteger('source_max_bytes')->default(20971520);
            $table->unsignedBigInteger('generated_revision_max_bytes');
            $table->unsignedBigInteger('max_signature_increment_bytes');
            $table->unsignedInteger('retirement_authorization_ttl_seconds')->default(300);
            $table->unsignedInteger('evidence_retirement_grace_seconds')->default(86400);
            $table->json('policy_manifest');
            $table->char('config_bundle_hash', 64);
            $table->timestamps();
        });

        Schema::create('pdf_signing_workflows', function (Blueprint $table): void {
            $table->id();
            $table->uuid('workflow_uuid')->unique();
            $table->foreignId('document_id')->constrained('pdf_documents')->restrictOnDelete();
            $table->unsignedInteger('workflow_generation');
            $table->foreignId('base_revision_id')->nullable()->constrained('pdf_files')->restrictOnDelete();
            $table->foreignId('planning_revision_id')->nullable()->constrained('pdf_files')->restrictOnDelete();
            $table->foreignId('prepared_revision_id')->nullable()->constrained('pdf_files')->restrictOnDelete();
            $table->foreignId('current_revision_id')->nullable()->constrained('pdf_files')->restrictOnDelete();
            $table->foreignId('publication_base_revision_id')->nullable()->constrained('pdf_files')->restrictOnDelete();
            $table->unsignedBigInteger('expected_publication_version')->default(0);
            $table->json('placement_plan')->nullable();
            $table->char('placement_plan_hash', 64)->nullable();
            $table->char('field_plan_hash', 64)->nullable();
            $table->unsignedBigInteger('active_operation_id')->nullable()->index();
            $table->string('status', 20)->default('draft');
            $table->foreignId('created_by_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['document_id', 'workflow_generation']);
        });

        Schema::create('pdf_signing_acts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('logical_act_uuid')->unique();
            $table->foreignId('document_id')->constrained('pdf_documents')->restrictOnDelete();
            $table->unsignedInteger('plan_generation');
            $table->string('semantic_role', 24);
            $table->string('pdf_signature_role', 24);
            $table->unsignedInteger('sequence');
            $table->string('field_name', 128);
            $table->string('status', 24)->default('planned');
            $table->foreignId('completed_revision_id')->nullable()->constrained('pdf_files')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['document_id', 'plan_generation', 'semantic_role', 'sequence'], 'pdf_signing_acts_plan_unique');
        });

        Schema::create('pdf_signing_requests', function (Blueprint $table): void {
            $table->id();
            $table->uuid('request_uuid')->unique();
            $table->foreignId('workflow_id')->constrained('pdf_signing_workflows')->restrictOnDelete();
            $table->foreignId('signing_act_id')->constrained('pdf_signing_acts')->restrictOnDelete();
            $table->unsignedInteger('sequence');
            $table->unsignedBigInteger('predecessor_request_id')->nullable();
            $table->string('request_type', 24);
            $table->foreignId('assigned_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('signing_policy_version_id')->constrained('pdf_signing_policy_versions')->restrictOnDelete();
            $table->string('status', 20)->default('pending');
            $table->foreignId('expected_source_revision_id')->nullable()->constrained('pdf_files')->restrictOnDelete();
            $table->char('expected_source_sha256', 64)->nullable();
            $table->foreignId('completed_revision_id')->nullable()->constrained('pdf_files')->restrictOnDelete();
            $table->string('rejection_reason_code', 96)->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->foreignId('rejected_by_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['id', 'workflow_id'], 'pdf_signing_requests_id_workflow_unique');
            $table->unique(
                ['id', 'workflow_id', 'signing_act_id'],
                'pdf_signing_requests_id_workflow_act_unique',
            );
            $table->unique(['workflow_id', 'sequence']);
            $table->unique(['workflow_id', 'signing_act_id']);
            $table->foreign(
                ['predecessor_request_id', 'workflow_id'],
                'pdf_signing_requests_predecessor_workflow_fk',
            )->references(['id', 'workflow_id'])
                ->on('pdf_signing_requests')
                ->restrictOnDelete();
        });

        Schema::create('pdf_signing_fields', function (Blueprint $table): void {
            $table->id();
            $table->uuid('field_uuid')->unique();
            $table->foreignId('workflow_id')->constrained('pdf_signing_workflows')->restrictOnDelete();
            $table->foreignId('signing_act_id')->constrained('pdf_signing_acts')->restrictOnDelete();
            $table->unsignedBigInteger('request_id')->nullable();
            $table->unsignedBigInteger('source_field_id')->nullable();
            $table->string('field_name', 128);
            $table->string('field_type', 24);
            $table->string('activation_mode', 16);
            $table->string('binding_mode', 40);
            $table->string('lock_policy', 32)->default('include_self_only');
            $table->foreignId('prepared_revision_id')->nullable()->constrained('pdf_files')->restrictOnDelete();
            $table->string('prepared_object_ref', 64)->nullable();
            $table->string('status', 16)->default('planned');
            $table->timestamps();
            $table->unique(['id', 'signing_act_id'], 'pdf_signing_fields_id_act_unique');
            $table->unique(['workflow_id', 'signing_act_id']);
            $table->unique(['workflow_id', 'field_name']);
            $table->foreign(
                ['request_id', 'workflow_id', 'signing_act_id'],
                'pdf_signing_fields_request_scope_fk',
            )->references(['id', 'workflow_id', 'signing_act_id'])
                ->on('pdf_signing_requests')
                ->restrictOnDelete();
            $table->foreign(
                ['source_field_id', 'signing_act_id'],
                'pdf_signing_fields_source_act_fk',
            )->references(['id', 'signing_act_id'])
                ->on('pdf_signing_fields')
                ->restrictOnDelete();
        });

        Schema::create('pdf_signing_slots', function (Blueprint $table): void {
            $table->id();
            $table->uuid('slot_uuid')->unique();
            $table->foreignId('field_id')->constrained('pdf_signing_fields')->restrictOnDelete();
            $table->unsignedInteger('page_index');
            $table->unsignedInteger('widget_index')->default(0);
            $table->string('placement_type', 24);
            $table->json('normalized_rect');
            $table->char('geometry_hash', 64);
            $table->string('prepared_widget_object_ref', 64)->nullable();
            $table->json('prepared_appearance_object_refs')->nullable();
            $table->string('status', 16)->default('planned');
            $table->timestamps();
            $table->unique(['field_id', 'widget_index']);
        });

        Schema::create('pdf_signature_appearance_artifacts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('appearance_uuid')->unique();
            $table->foreignId('request_id')->constrained('pdf_signing_requests')->restrictOnDelete();
            $table->foreignId('created_by_id')->constrained('users')->restrictOnDelete();
            $table->string('artifact_type', 24);
            $table->char('canonical_image_sha256', 64);
            $table->char('appearance_manifest_hash', 64);
            $table->json('slot_manifest');
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->json('crop_box');
            $table->string('renderer_version', 64);
            $table->string('state', 16)->default('available');
            $table->unsignedBigInteger('evidence_hold_mask')->default(0);
            $table->string('evidence_hold_state', 16)->default('none');
            $table->string('retirement_state', 20)->default('none');
            $table->unsignedBigInteger('retirement_epoch')->default(0);
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->unsignedBigInteger('claimed_by_operation_id')->nullable()->index();
            $table->timestamp('retention_until')->nullable();
            $table->timestamp('legal_hold_until')->nullable();
            $table->timestamp('hold_started_at')->nullable();
            $table->timestamp('hold_released_at')->nullable();
            $table->string('retirement_staged_path', 1024)->nullable();
            $table->timestamp('retirement_staged_at')->nullable();
            $table->timestamp('retirement_purge_not_before')->nullable();
            $table->string('file_path', 1024)->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('pdf_signing_challenges', function (Blueprint $table): void {
            $table->id();
            $table->uuid('challenge_uuid')->unique();
            $table->foreignId('request_id')->constrained('pdf_signing_requests')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('source_revision_id')->constrained('pdf_files')->restrictOnDelete();
            $table->char('source_sha256', 64);
            $table->char('field_plan_hash', 64);
            $table->foreignId('appearance_artifact_id')->constrained('pdf_signature_appearance_artifacts')->restrictOnDelete();
            $table->char('appearance_manifest_hash', 64);
            $table->string('intent', 255);
            $table->foreignId('signing_policy_version_id')->constrained('pdf_signing_policy_versions')->restrictOnDelete();
            $table->char('policy_hash', 64);
            $table->char('expected_certificate_fingerprint', 64);
            $table->string('auth_context_id', 128);
            $table->timestamp('password_changed_at_snapshot')->nullable();
            $table->timestamp('reauthenticated_at');
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->index(['request_id', 'expires_at']);
        });

        Schema::create('pdf_signing_operations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('operation_uuid')->unique();
            $table->string('idempotency_key', 128);
            $table->string('idempotency_scope_key', 255);
            $table->string('scope_type', 16);
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('document_id')->constrained('pdf_documents')->restrictOnDelete();
            $table->foreignId('workflow_id')->nullable()->constrained('pdf_signing_workflows')->restrictOnDelete();
            $table->foreignId('request_id')->nullable()->constrained('pdf_signing_requests')->restrictOnDelete();
            $table->foreignId('challenge_id')->nullable()->unique()->constrained('pdf_signing_challenges')->restrictOnDelete();
            $table->string('action', 32);
            $table->char('input_fingerprint', 64);
            $table->char('operation_input_manifest_hash', 64);
            $table->foreignId('expected_source_revision_id')->nullable()->constrained('pdf_files')->restrictOnDelete();
            $table->char('expected_source_sha256', 64)->nullable();
            $table->foreignId('signing_policy_version_id')->nullable()->constrained('pdf_signing_policy_versions')->restrictOnDelete();
            $table->char('policy_hash', 64)->nullable();
            $table->char('config_bundle_hash', 64)->nullable();
            $table->char('expected_certificate_fingerprint', 64)->nullable();
            $table->char('appearance_manifest_hash', 64)->nullable();
            $table->char('appearance_sha256', 64)->nullable();
            $table->string('pdf_signature_role', 24)->nullable();
            $table->string('target_field_name', 128)->nullable();
            $table->char('field_lock_policy_hash', 64)->nullable();
            $table->uuid('result_revision_uuid')->nullable();
            $table->foreignId('result_revision_id')->nullable()->constrained('pdf_files')->restrictOnDelete();
            $table->string('state', 24)->default('claimed');
            $table->string('stage', 24)->default('awaiting_dispatch');
            $table->uuid('lease_owner')->nullable();
            $table->unsignedBigInteger('lease_epoch')->default(0);
            $table->timestamp('lease_expires_at')->nullable();
            $table->timestamp('heartbeat_at')->nullable();
            $table->unsignedBigInteger('java_gate_version')->default(0);
            $table->unsignedBigInteger('document_evidence_hold_mask')->default(0);
            $table->timestamp('java_request_started_at')->nullable();
            $table->timestamp('java_execution_registration_deadline_at')->nullable();
            $table->string('java_execution_state', 40)->nullable();
            $table->timestamp('java_execution_deadline_at')->nullable();
            $table->timestamp('next_java_poll_at')->nullable();
            $table->unsignedInteger('java_poll_count')->default(0);
            $table->string('promoted_file_path', 1024)->nullable();
            $table->char('result_sha256', 64)->nullable();
            $table->unsignedBigInteger('result_size')->nullable();
            $table->char('response_fingerprint', 64)->nullable();
            $table->string('error_code', 96)->nullable();
            $table->string('error_retryability', 32)->nullable();
            $table->timestamp('cancellation_requested_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason_code', 96)->nullable();
            $table->foreignId('cancelled_by_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('result_retirement_not_before')->nullable();
            $table->timestamp('result_retirement_authorized_at')->nullable();
            $table->timestamp('result_retirement_authorization_expires_at')->nullable();
            $table->json('result_retirement_authorization_manifest')->nullable();
            $table->char('result_retirement_authorization_hash', 64)->nullable();
            $table->json('audit_context');
            $table->char('audit_context_hash', 64);
            $table->timestamps();
            $table->unique(['idempotency_scope_key', 'idempotency_key'], 'pdf_signing_operations_idempotency_unique');
        });

        Schema::create('pdf_operation_outbox', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('operation_id')->unique()->constrained('pdf_signing_operations')->restrictOnDelete();
            $table->string('job_type', 64);
            $table->char('payload_hash', 64);
            $table->string('state', 16)->default('pending');
            $table->unsignedInteger('attempt_count')->default(0);
            $table->timestamp('available_at');
            $table->timestamp('dispatched_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->index(['state', 'available_at']);
        });

        Schema::create('pdf_signing_operation_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('event_uuid')->unique();
            $table->foreignId('operation_id')->constrained('pdf_signing_operations')->restrictOnDelete();
            $table->string('event_type', 64);
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('reason_code', 96)->nullable();
            $table->char('resolution_fingerprint', 64)->nullable();
            $table->json('event_payload');
            $table->char('previous_event_hash', 64)->nullable();
            $table->char('event_hash', 64);
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->index(['operation_id', 'occurred_at']);
        });

        Schema::create('pdf_java_signing_executions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('operation_uuid')->unique();
            $table->char('operation_input_manifest_hash', 64);
            $table->char('input_fingerprint', 64);
            $table->char('policy_hash', 64);
            $table->unsignedInteger('attempt_number')->default(0);
            $table->unsignedInteger('attempt_count')->default(0);
            $table->unsignedInteger('max_attempts')->default(3);
            $table->string('state', 48)->default('claimed');
            $table->string('retryability', 32)->nullable();
            $table->unsignedBigInteger('authorized_lease_epoch');
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->timestamp('claimed_at');
            $table->timestamp('execution_started_at')->nullable();
            $table->timestamp('private_key_started_at')->nullable();
            $table->timestamp('execution_deadline_at')->nullable();
            $table->timestamp('next_retry_at')->nullable();
            $table->timestamp('retry_exhausted_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('terminal_at')->nullable();
            $table->string('error_code', 96)->nullable();
            $table->string('result_path', 1024)->nullable();
            $table->char('result_sha256', 64)->nullable();
            $table->unsignedBigInteger('result_size')->nullable();
            $table->string('result_file_key', 255)->nullable();
            $table->char('validation_report_hash', 64)->nullable();
            $table->string('result_integrity_state', 24)->default('not_applicable');
            $table->timestamp('result_last_verified_at')->nullable();
            $table->string('result_integrity_error_code', 96)->nullable();
            $table->timestamp('retention_until')->nullable();
            $table->string('retirement_phase', 24)->default('none');
            $table->unsignedBigInteger('retirement_epoch')->default(0);
            $table->string('retirement_staged_path', 1024)->nullable();
            $table->timestamp('retirement_started_at')->nullable();
            $table->timestamp('retirement_purge_not_before')->nullable();
            $table->unsignedBigInteger('evidence_hold_mask')->default(0);
            $table->string('evidence_hold_state', 16)->default('none');
            $table->timestamp('legal_hold_until')->nullable();
            $table->timestamp('bytes_deleted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('pdf_java_signing_execution_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('operation_uuid');
            $table->unsignedInteger('attempt_number');
            $table->string('event_type', 64);
            $table->string('old_state', 48)->nullable();
            $table->string('new_state', 48);
            $table->string('old_retirement_phase', 24)->nullable();
            $table->string('new_retirement_phase', 24)->nullable();
            $table->unsignedBigInteger('old_lock_version');
            $table->unsignedBigInteger('new_lock_version');
            $table->unsignedBigInteger('authorized_lease_epoch')->nullable();
            $table->unsignedBigInteger('retirement_epoch')->nullable();
            $table->string('error_code', 96)->nullable();
            $table->timestamp('event_at');
            $table->char('event_hash', 64);
            $table->unique(['operation_uuid', 'attempt_number', 'event_type', 'new_lock_version'], 'pdf_java_execution_events_unique');
        });

        Schema::create('pdf_document_evidence_holds', function (Blueprint $table): void {
            $table->id();
            $table->uuid('hold_uuid')->unique();
            $table->foreignId('document_id')->constrained('pdf_documents')->restrictOnDelete();
            $table->unsignedBigInteger('reason_bit');
            $table->string('reason_code', 96);
            $table->string('state', 16)->default('active');
            $table->string('active_scope_key', 160)->nullable()->unique();
            $table->json('target_manifest');
            $table->char('target_manifest_hash', 64);
            $table->timestamp('legal_hold_until')->nullable();
            $table->foreignId('installed_by_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('installed_at');
            $table->foreignId('released_by_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('release_reason_code', 96)->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();
            $table->index(['document_id', 'state']);
        });

        Schema::create('pdf_document_publication_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('event_uuid')->unique();
            $table->foreignId('document_id')->constrained('pdf_documents')->restrictOnDelete();
            $table->foreignId('revision_id')->nullable()->constrained('pdf_files')->restrictOnDelete();
            $table->string('event_type', 32);
            $table->string('reason_code', 96)->nullable();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('occurred_at');
            $table->char('audit_context_hash', 64);
            $table->unsignedBigInteger('previous_published_revision_id')->nullable();
            $table->foreign('previous_published_revision_id', 'pdf_pub_events_previous_revision_fk')
                ->references('id')->on('pdf_files')->restrictOnDelete();
            $table->foreignId('related_event_id')->nullable()->constrained('pdf_document_publication_events')->restrictOnDelete();
            $table->unsignedBigInteger('old_integrity_version')->nullable();
            $table->unsignedBigInteger('new_integrity_version')->nullable();
            $table->unsignedBigInteger('old_evidence_hold_mask')->nullable();
            $table->unsignedBigInteger('new_evidence_hold_mask')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pdf_document_publication_events');
        Schema::dropIfExists('pdf_document_evidence_holds');
        Schema::dropIfExists('pdf_java_signing_execution_events');
        Schema::dropIfExists('pdf_java_signing_executions');
        Schema::dropIfExists('pdf_signing_operation_events');
        Schema::dropIfExists('pdf_operation_outbox');
        Schema::dropIfExists('pdf_signing_operations');
        Schema::dropIfExists('pdf_signing_challenges');
        Schema::dropIfExists('pdf_signature_appearance_artifacts');
        Schema::dropIfExists('pdf_signing_slots');
        Schema::dropIfExists('pdf_signing_fields');
        Schema::dropIfExists('pdf_signing_requests');
        Schema::dropIfExists('pdf_signing_acts');
        Schema::dropIfExists('pdf_signing_workflows');
        Schema::dropIfExists('pdf_signing_policy_versions');

        Schema::table('pdf_files', function (Blueprint $table): void {
            $table->dropUnique('pdf_files_document_revision_unique');
            $table->dropForeign(['document_id']);
            $table->dropForeign(['parent_pdf_file_id']);
            $table->dropColumn([
                'document_id', 'revision_uuid', 'parent_pdf_file_id', 'revision_number',
                'revision_role', 'revision_created_at', 'revision_manifest',
                'revision_manifest_hash', 'integrity_state', 'disposition', 'first_published_at',
            ]);
            $table->timestamp('signed_at')->nullable(false)->change();
        });
        Schema::dropIfExists('pdf_source_uploads');
        Schema::dropIfExists('pdf_documents');
    }
};
