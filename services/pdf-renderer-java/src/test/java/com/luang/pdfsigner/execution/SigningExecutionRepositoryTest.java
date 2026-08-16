package com.luang.pdfsigner.execution;

import static org.assertj.core.api.Assertions.assertThat;
import static org.assertj.core.api.Assertions.assertThatThrownBy;

import com.luang.pdfsigner.execution.SigningExecutionRepository.OperationClaim;
import com.fasterxml.jackson.databind.ObjectMapper;
import com.fasterxml.jackson.databind.SerializationFeature;
import java.nio.file.Files;
import java.nio.file.Path;
import java.nio.charset.StandardCharsets;
import java.security.MessageDigest;
import java.sql.Connection;
import java.sql.DriverManager;
import java.sql.Statement;
import java.time.Duration;
import java.time.Instant;
import java.util.LinkedHashMap;
import java.util.HexFormat;
import java.util.Map;
import java.util.UUID;
import java.util.concurrent.TimeUnit;
import org.junit.jupiter.api.BeforeEach;
import org.junit.jupiter.api.Test;
import org.junit.jupiter.api.io.TempDir;

class SigningExecutionRepositoryTest {
    @TempDir
    Path temporaryDirectory;

    private String jdbcUrl;
    private SigningExecutionRepository repository;
    private ExecutionStorage storage;
    private ExecutionLedgerProperties properties;
    private UUID operationUuid;
    private OperationClaim claim;

    @BeforeEach
    void setUp() throws Exception {
        jdbcUrl = "jdbc:h2:mem:" + UUID.randomUUID() + ";MODE=MySQL;DB_CLOSE_DELAY=-1";
        properties = new ExecutionLedgerProperties(
                true,
                jdbcUrl,
                "sa",
                "test",
                temporaryDirectory.toString()
        );
        repository = new SigningExecutionRepository(properties);
        storage = new ExecutionStorage(properties);
        createSchema();
        operationUuid = UUID.randomUUID();
        claim = new OperationClaim(
                operationUuid,
                0,
                7,
                "0".repeat(64),
                "1".repeat(64),
                "a".repeat(64),
                1,
                UUID.fromString("12345678-1234-4234-8234-123456789abc"),
                "b".repeat(64),
                "c".repeat(64),
                "d".repeat(64),
                "e".repeat(64),
                "certification_p2",
                "lims_inspector_g1",
                "f".repeat(64),
                "8".repeat(64)
        );
        insertPolicyAndOperation();
    }

    @Test
    void operationGateCreatesExactlyOneExecutionAndReturnsItForDuplicateDelivery() throws Exception {
        var first = repository.claim(claim);
        var duplicate = repository.claim(claim);

        assertThat(first.newlyClaimed()).isTrue();
        assertThat(duplicate.newlyClaimed()).isFalse();
        assertThat(duplicate.execution().operationUuid()).isEqualTo(operationUuid);
        assertThat(count("pdf_java_signing_executions")).isEqualTo(1);
        assertThat(count("pdf_java_signing_execution_events")).isEqualTo(1);
        assertThat(scalarLong("SELECT java_gate_version FROM pdf_signing_operations")).isEqualTo(1);
    }

    @Test
    void completedResultIsDurableAndReadableFromTheSameVerifiedIdentity() throws Exception {
        repository.claim(claim);
        repository.markExecuting(operationUuid, Duration.ofSeconds(60));
        repository.markPrivateKeyStarted(claim);
        byte[] resultBytes = "%PDF-1.7 immutable signed execution".getBytes(java.nio.charset.StandardCharsets.US_ASCII);
        ExecutionStorage.StoredResult stored = storage.persist(operationUuid, resultBytes, 1024 * 1024);
        ExecutionRecord completed = repository.markCompleted(operationUuid, stored, "7".repeat(64));

        assertThat(completed.state()).isEqualTo("completed");
        assertThat(completed.resultIntegrityState()).isEqualTo("available");
        SigningExecutionRepository.ExecutionReadClaim readClaim = new SigningExecutionRepository.ExecutionReadClaim(
                operationUuid,
                claim.leaseEpoch(),
                claim.operationInputManifestHash(),
                claim.inputFingerprint(),
                claim.policyHash()
        );
        try (var opened = repository.openVerifiedResult(readClaim, storage).input()) {
            assertThat(opened.readAllBytes()).isEqualTo(resultBytes);
        }
        assertThat(repository.find(operationUuid).orElseThrow().resultLastVerifiedAt()).isNotNull();
        assertThat(count("pdf_java_signing_execution_events")).isEqualTo(5);
    }

    @Test
    void completedResultUsesTheCurrentOperationLeaseWithoutRewritingItsAuthorizationHistory() throws Exception {
        repository.claim(claim);
        repository.markExecuting(operationUuid, Duration.ofSeconds(60));
        repository.markPrivateKeyStarted(claim);
        byte[] resultBytes = "%PDF-1.7 adopted completed result"
                .getBytes(java.nio.charset.StandardCharsets.US_ASCII);
        ExecutionStorage.StoredResult stored = storage.persist(operationUuid, resultBytes, 1024 * 1024);
        repository.markCompleted(operationUuid, stored, "7".repeat(64));
        execute("""
                UPDATE pdf_signing_operations
                   SET lease_epoch=8, lease_owner='22222222-2222-2222-2222-222222222222',
                       lease_expires_at=DATEADD('HOUR', 1, CURRENT_TIMESTAMP)
                """);
        var adoptedLease = new SigningExecutionRepository.ExecutionReadClaim(
                operationUuid,
                8,
                claim.operationInputManifestHash(),
                claim.inputFingerprint(),
                claim.policyHash()
        );
        try (var opened = repository.openVerifiedResult(adoptedLease, storage).input()) {
            assertThat(opened.readAllBytes()).isEqualTo(resultBytes);
        }
        assertThat(repository.find(operationUuid).orElseThrow().authorizedLeaseEpoch()).isEqualTo(7);

        var staleLease = new SigningExecutionRepository.ExecutionReadClaim(
                operationUuid,
                7,
                claim.operationInputManifestHash(),
                claim.inputFingerprint(),
                claim.policyHash()
        );
        assertThatThrownBy(() -> repository.requireMatchingExecution(staleLease))
                .isInstanceOf(SigningExecutionRepository.ExecutionGateException.class)
                .hasMessageContaining("lease");

        execute("UPDATE pdf_signing_operations SET lease_expires_at=DATEADD('SECOND', -1, CURRENT_TIMESTAMP)");
        assertThatThrownBy(() -> repository.requireMatchingExecution(adoptedLease))
                .isInstanceOf(SigningExecutionRepository.ExecutionGateException.class)
                .hasMessageContaining("lease");
    }

    @Test
    void takeoverLeaseCanReadExecutingStatusButCannotCrossTheOriginalPrivateKeyBoundary() throws Exception {
        repository.claim(claim);
        repository.markExecuting(operationUuid, Duration.ofSeconds(60));
        execute("""
                UPDATE pdf_signing_operations
                   SET lease_epoch=8, lease_owner='22222222-2222-2222-2222-222222222222',
                       lease_expires_at=DATEADD('HOUR', 1, CURRENT_TIMESTAMP)
                """);
        var readClaim = new SigningExecutionRepository.ExecutionReadClaim(
                operationUuid,
                8,
                claim.operationInputManifestHash(),
                claim.inputFingerprint(),
                claim.policyHash()
        );

        assertThat(repository.requireMatchingExecution(readClaim).state()).isEqualTo("executing");

        var takeoverClaim = new OperationClaim(
                operationUuid,
                0,
                8,
                claim.operationInputManifestHash(),
                claim.inputFingerprint(),
                claim.expectedSourceSha256(),
                claim.policyVersionId(),
                claim.policyVersionUuid(),
                claim.policyHash(),
                claim.configBundleHash(),
                claim.appearanceManifestHash(),
                claim.appearanceSha256(),
                claim.pdfSignatureRole(),
                claim.targetFieldName(),
                claim.expectedCertificateFingerprint(),
                claim.fieldLockPolicyHash()
        );
        assertThatThrownBy(() -> repository.markPrivateKeyStarted(takeoverClaim))
                .isInstanceOf(SigningExecutionRepository.ExecutionGateException.class)
                .hasMessageContaining("Private-key boundary");
        assertThat(repository.find(operationUuid).orElseThrow().authorizedLeaseEpoch()).isEqualTo(7);
        assertThat(repository.find(operationUuid).orElseThrow().privateKeyStartedAt()).isNull();
        assertThat(scalarLong("SELECT java_gate_version FROM pdf_signing_operations")).isEqualTo(1);
    }

    @Test
    void completedOperationResultReadStillRequiresItsFinalLeaseEpoch() throws Exception {
        repository.claim(claim);
        repository.markExecuting(operationUuid, Duration.ofSeconds(60));
        repository.markPrivateKeyStarted(claim);
        byte[] resultBytes = "%PDF-1.7 completed final lease"
                .getBytes(java.nio.charset.StandardCharsets.US_ASCII);
        ExecutionStorage.StoredResult stored = storage.persist(operationUuid, resultBytes, 1024 * 1024);
        repository.markCompleted(operationUuid, stored, "7".repeat(64));
        execute("UPDATE pdf_signing_operations SET state='completed', lease_owner=NULL, lease_expires_at=NULL");

        var finalLease = new SigningExecutionRepository.ExecutionReadClaim(
                operationUuid,
                7,
                claim.operationInputManifestHash(),
                claim.inputFingerprint(),
                claim.policyHash()
        );
        assertThat(repository.requireMatchingExecution(finalLease).state()).isEqualTo("completed");

        var inventedLease = new SigningExecutionRepository.ExecutionReadClaim(
                operationUuid,
                8,
                claim.operationInputManifestHash(),
                claim.inputFingerprint(),
                claim.policyHash()
        );
        assertThatThrownBy(() -> repository.requireMatchingExecution(inventedLease))
                .isInstanceOf(SigningExecutionRepository.ExecutionGateException.class)
                .hasMessageContaining("lease");
    }

    @Test
    void authorizedRetirementStagesThenPurgesWithoutReopeningTheSigningBoundary() throws Exception {
        repository.claim(claim);
        repository.markExecuting(operationUuid, Duration.ofSeconds(60));
        repository.markPrivateKeyStarted(claim);
        byte[] resultBytes = "%PDF-1.7 retirement evidence".getBytes(java.nio.charset.StandardCharsets.US_ASCII);
        ExecutionStorage.StoredResult stored = storage.persist(operationUuid, resultBytes, 1024 * 1024);
        ExecutionRecord completed = repository.markCompleted(operationUuid, stored, "7".repeat(64));
        execute("UPDATE pdf_java_signing_executions SET retention_until=DATEADD('SECOND', -1, CURRENT_TIMESTAMP)");
        authorizeRetirement(completed);

        ResultRetirementService retirement = new ResultRetirementService(properties, repository, storage);
        assertThat(retirement.sweep(10)).isEqualTo(2);
        ExecutionRecord staged = repository.find(operationUuid).orElseThrow();
        assertThat(staged.resultIntegrityState()).isEqualTo("retiring");
        assertThat(staged.retirementPhase()).isEqualTo("staged");
        assertThat(Files.exists(Path.of(staged.resultPath()))).isFalse();
        assertThat(Files.exists(Path.of(staged.retirementStagedPath()))).isTrue();

        execute("UPDATE pdf_java_signing_executions SET retirement_purge_not_before=DATEADD('SECOND', -1, CURRENT_TIMESTAMP)");
        assertThat(retirement.sweep(10)).isEqualTo(2);
        ExecutionRecord retired = repository.find(operationUuid).orElseThrow();
        assertThat(retired.resultIntegrityState()).isEqualTo("retired");
        assertThat(retired.retirementPhase()).isEqualTo("retired");
        assertThat(retired.bytesDeletedAt()).isNotNull();
        assertThat(Files.exists(Path.of(staged.retirementStagedPath()))).isFalse();
    }

    @Test
    void verifiedResultDescriptorSurvivesRetirementRenameAndUnlink() throws Exception {
        repository.claim(claim);
        repository.markExecuting(operationUuid, Duration.ofSeconds(60));
        repository.markPrivateKeyStarted(claim);
        byte[] resultBytes = "%PDF-1.7 descriptor survives retirement"
                .getBytes(java.nio.charset.StandardCharsets.US_ASCII);
        ExecutionStorage.StoredResult stored = storage.persist(operationUuid, resultBytes, 1024 * 1024);
        ExecutionRecord completed = repository.markCompleted(operationUuid, stored, "7".repeat(64));
        SigningExecutionRepository.ExecutionReadClaim readClaim = new SigningExecutionRepository.ExecutionReadClaim(
                operationUuid,
                claim.leaseEpoch(),
                claim.operationInputManifestHash(),
                claim.inputFingerprint(),
                claim.policyHash()
        );

        try (var descriptor = repository.openVerifiedResult(readClaim, storage).input()) {
            execute("UPDATE pdf_java_signing_executions SET retention_until=DATEADD('SECOND', -1, CURRENT_TIMESTAMP)");
            authorizeRetirement(completed);
            ResultRetirementService retirement = new ResultRetirementService(properties, repository, storage);
            assertThat(retirement.sweep(10)).isEqualTo(2);
            execute("UPDATE pdf_java_signing_executions "
                    + "SET retirement_purge_not_before=DATEADD('SECOND', -1, CURRENT_TIMESTAMP)");
            assertThat(retirement.sweep(10)).isEqualTo(2);
            assertThat(repository.find(operationUuid).orElseThrow().retirementPhase()).isEqualTo("retired");
            assertThat(descriptor.readAllBytes()).isEqualTo(resultBytes);
        }
    }

    @Test
    void retirementRecoversFileActionsThatWonBeforeTheirDatabaseCommits() throws Exception {
        repository.claim(claim);
        repository.markExecuting(operationUuid, Duration.ofSeconds(60));
        repository.markPrivateKeyStarted(claim);
        byte[] resultBytes = "%PDF-1.7 crash recovery evidence"
                .getBytes(java.nio.charset.StandardCharsets.US_ASCII);
        ExecutionStorage.StoredResult stored = storage.persist(operationUuid, resultBytes, 1024 * 1024);
        ExecutionRecord completed = repository.markCompleted(operationUuid, stored, "7".repeat(64));
        execute("UPDATE pdf_java_signing_executions SET retention_until=DATEADD('SECOND', -1, CURRENT_TIMESTAMP)");
        authorizeRetirement(completed);

        ExecutionRecord stageIntent = repository.beginRetirement(operationUuid);
        Files.copy(Path.of(stageIntent.resultPath()), Path.of(stageIntent.retirementStagedPath()));
        assertThatThrownBy(() -> repository.applyRetirementStage(
                operationUuid,
                stageIntent.retirementEpoch(),
                storage
        )).isInstanceOf(SigningExecutionRepository.ExecutionGateException.class)
                .hasMessageContaining("duplicate evidence");
        assertThat(repository.find(operationUuid).orElseThrow().retirementPhase()).isEqualTo("stage_intent");
        Files.delete(Path.of(stageIntent.retirementStagedPath()));

        runCrashWorker(
                "move",
                Path.of(stageIntent.resultPath()),
                Path.of(stageIntent.retirementStagedPath())
        );
        assertThat(Files.exists(Path.of(stageIntent.resultPath()))).isFalse();
        assertThat(Files.exists(Path.of(stageIntent.retirementStagedPath()))).isTrue();

        ExecutionRecord staged = repository.applyRetirementStage(
                operationUuid,
                stageIntent.retirementEpoch(),
                storage
        );
        assertThat(staged.retirementPhase()).isEqualTo("staged");

        execute("UPDATE pdf_java_signing_executions "
                + "SET retirement_purge_not_before=DATEADD('SECOND', -1, CURRENT_TIMESTAMP)");
        ExecutionRecord purgeIntent = repository.beginRetirementPurge(
                operationUuid,
                staged.retirementEpoch()
        );
        Files.copy(Path.of(purgeIntent.retirementStagedPath()), Path.of(purgeIntent.resultPath()));
        assertThatThrownBy(() -> repository.applyRetirementPurge(
                operationUuid,
                purgeIntent.retirementEpoch(),
                storage
        )).isInstanceOf(SigningExecutionRepository.ExecutionGateException.class)
                .hasMessageContaining("unexpected canonical");
        assertThat(repository.find(operationUuid).orElseThrow().retirementPhase()).isEqualTo("purge_intent");
        Files.delete(Path.of(purgeIntent.resultPath()));

        runCrashWorker("unlink", Path.of(purgeIntent.retirementStagedPath()), null);
        assertThat(Files.exists(Path.of(purgeIntent.retirementStagedPath()))).isFalse();

        ExecutionRecord retired = repository.applyRetirementPurge(
                operationUuid,
                purgeIntent.retirementEpoch(),
                storage
        );
        assertThat(retired.resultIntegrityState()).isEqualTo("retired");
        assertThat(retired.retirementPhase()).isEqualTo("retired");
        assertThat(retired.bytesDeletedAt()).isNotNull();
    }

    @Test
    void evidenceHoldRestoresStagedBytesBeforeRetirementCanContinue() throws Exception {
        repository.claim(claim);
        repository.markExecuting(operationUuid, Duration.ofSeconds(60));
        repository.markPrivateKeyStarted(claim);
        byte[] resultBytes = "%PDF-1.7 held retirement evidence".getBytes(java.nio.charset.StandardCharsets.US_ASCII);
        ExecutionStorage.StoredResult stored = storage.persist(operationUuid, resultBytes, 1024 * 1024);
        ExecutionRecord completed = repository.markCompleted(operationUuid, stored, "7".repeat(64));
        execute("UPDATE pdf_java_signing_executions SET retention_until=DATEADD('SECOND', -1, CURRENT_TIMESTAMP)");
        authorizeRetirement(completed);

        ResultRetirementService retirement = new ResultRetirementService(properties, repository, storage);
        assertThat(retirement.sweep(10)).isEqualTo(2);
        ExecutionRecord staged = repository.find(operationUuid).orElseThrow();
        assertThat(staged.retirementPhase()).isEqualTo("staged");
        assertThat(Files.exists(Path.of(staged.resultPath()))).isFalse();
        assertThat(Files.exists(Path.of(staged.retirementStagedPath()))).isTrue();

        execute("""
                UPDATE pdf_java_signing_executions
                   SET evidence_hold_mask=1, evidence_hold_state='active'
                 WHERE operation_uuid='%s'
                """.formatted(operationUuid));

        runCrashWorker(
                "move",
                Path.of(staged.retirementStagedPath()),
                Path.of(staged.resultPath())
        );
        assertThat(Files.exists(Path.of(staged.resultPath()))).isTrue();
        assertThat(Files.exists(Path.of(staged.retirementStagedPath()))).isFalse();
        assertThat(repository.find(operationUuid).orElseThrow().retirementPhase()).isEqualTo("staged");

        assertThat(retirement.sweep(10)).isEqualTo(1);
        ExecutionRecord restored = repository.find(operationUuid).orElseThrow();
        assertThat(restored.resultIntegrityState()).isEqualTo("available");
        assertThat(restored.retirementPhase()).isEqualTo("none");
        assertThat(restored.retirementEpoch()).isEqualTo(staged.retirementEpoch() + 1);
        assertThat(restored.evidenceHoldMask()).isEqualTo(1);
        assertThat(restored.evidenceHoldState()).isEqualTo("active");
        assertThat(Files.readAllBytes(Path.of(restored.resultPath()))).isEqualTo(resultBytes);
        assertThat(Files.exists(Path.of(staged.retirementStagedPath()))).isFalse();
    }

    @Test
    void cancellationBeforePrivateKeyWinsAndPreventsTheBoundary() throws Exception {
        repository.claim(claim);
        repository.markExecuting(operationUuid, Duration.ofSeconds(60));
        execute("UPDATE pdf_signing_operations SET state='cancelled' WHERE operation_uuid='" + operationUuid + "'");

        assertThatThrownBy(() -> repository.markPrivateKeyStarted(claim))
                .isInstanceOf(SigningExecutionRepository.ExecutionGateException.class)
                .hasMessageContaining("SIGN_AUTHORIZE");
        assertThat(repository.find(operationUuid).orElseThrow().privateKeyStartedAt()).isNull();
    }

    @Test
    void documentEvidenceHoldBeforeClaimRejectsTheOperationGate() throws Exception {
        execute("UPDATE pdf_signing_operations SET document_evidence_hold_mask=1 WHERE operation_uuid='"
                + operationUuid + "'");

        assertThatThrownBy(() -> repository.claim(claim))
                .isInstanceOf(SigningExecutionRepository.ExecutionGateException.class)
                .hasMessageContaining("SIGN_AUTHORIZE");
        assertThat(count("pdf_java_signing_executions")).isZero();
    }

    @Test
    void executionEvidenceHoldBeforePrivateKeyPreventsTheBoundary() throws Exception {
        repository.claim(claim);
        repository.markExecuting(operationUuid, Duration.ofSeconds(60));
        execute("""
                UPDATE pdf_java_signing_executions
                   SET evidence_hold_mask=1, evidence_hold_state='active'
                 WHERE operation_uuid='%s'
                """.formatted(operationUuid));

        assertThatThrownBy(() -> repository.markPrivateKeyStarted(claim))
                .isInstanceOf(SigningExecutionRepository.ExecutionGateException.class)
                .hasMessageContaining("Private-key boundary");
        assertThat(repository.find(operationUuid).orElseThrow().privateKeyStartedAt()).isNull();
    }

    @Test
    void modifiedSnapshotCannotClaimAnUnregisteredOperation() throws Exception {
        OperationClaim tampered = new OperationClaim(
                operationUuid,
                0,
                7,
                claim.operationInputManifestHash(),
                claim.inputFingerprint(),
                claim.expectedSourceSha256(),
                1,
                UUID.fromString("ffffffff-eeee-4ddd-8ccc-bbbbbbbbbbbb"),
                claim.policyHash(),
                claim.configBundleHash(),
                claim.appearanceManifestHash(),
                claim.appearanceSha256(),
                claim.pdfSignatureRole(),
                claim.targetFieldName(),
                claim.expectedCertificateFingerprint(),
                claim.fieldLockPolicyHash()
        );

        assertThatThrownBy(() -> repository.claim(tampered))
                .isInstanceOf(SigningExecutionRepository.ExecutionGateException.class);
        assertThat(count("pdf_java_signing_executions")).isZero();
    }

    @Test
    void retryablePreKeyFailureRequiresNewLeaseAndExactRetryCas() throws Exception {
        var initial = repository.claim(claim);
        repository.markExecuting(operationUuid, Duration.ofSeconds(60));
        ExecutionRecord failed = repository.markPrePrivateKeyFailure(
                operationUuid,
                "DB_UNAVAILABLE",
                initial.policy()
        );
        assertThat(failed.retryability()).isEqualTo("same_operation");
        execute("UPDATE pdf_java_signing_executions SET next_retry_at=DATEADD('SECOND', -1, CURRENT_TIMESTAMP)");
        execute("UPDATE pdf_signing_operations SET lease_epoch=8, lease_expires_at=DATEADD('HOUR', 1, CURRENT_TIMESTAMP)");
        OperationClaim retry = new OperationClaim(
                operationUuid,
                1,
                8,
                claim.operationInputManifestHash(),
                claim.inputFingerprint(),
                claim.expectedSourceSha256(),
                claim.policyVersionId(),
                claim.policyVersionUuid(),
                claim.policyHash(),
                claim.configBundleHash(),
                claim.appearanceManifestHash(),
                claim.appearanceSha256(),
                claim.pdfSignatureRole(),
                claim.targetFieldName(),
                claim.expectedCertificateFingerprint(),
                claim.fieldLockPolicyHash()
        );

        repository.authorizeRetry(retry, failed.lockVersion());
        ExecutionRecord secondAttempt = repository.markExecuting(operationUuid, Duration.ofSeconds(60));

        assertThat(secondAttempt.attemptCount()).isEqualTo(2);
        assertThat(secondAttempt.attemptNumber()).isEqualTo(2);
        assertThat(secondAttempt.authorizedLeaseEpoch()).isEqualTo(8);
        assertThat(count("pdf_java_signing_executions")).isEqualTo(1);
    }

    private void createSchema() throws Exception {
        execute("""
                CREATE TABLE pdf_signing_operations (
                    operation_uuid VARCHAR(36) PRIMARY KEY,
                    java_gate_version BIGINT NOT NULL,
                    document_evidence_hold_mask BIGINT NOT NULL DEFAULT 0,
                    lease_epoch BIGINT NOT NULL,
                    lease_owner VARCHAR(36) NULL,
                    state VARCHAR(32) NOT NULL,
                    stage VARCHAR(32) NOT NULL,
                    action VARCHAR(32) NOT NULL,
                    lease_expires_at TIMESTAMP NULL,
                    cancellation_requested_at TIMESTAMP NULL,
                    operation_input_manifest_hash CHAR(64),
                    input_fingerprint CHAR(64),
                    expected_source_sha256 CHAR(64),
                    signing_policy_version_id BIGINT,
                    policy_hash CHAR(64),
                    config_bundle_hash CHAR(64),
                    appearance_manifest_hash CHAR(64),
                    appearance_sha256 CHAR(64),
                    pdf_signature_role VARCHAR(24),
                    target_field_name VARCHAR(128),
                    expected_certificate_fingerprint CHAR(64),
                    field_lock_policy_hash CHAR(64),
                    result_retirement_not_before TIMESTAMP NULL,
                    result_retirement_authorization_expires_at TIMESTAMP NULL,
                    result_retirement_authorization_manifest VARCHAR(4096) NULL,
                    result_retirement_authorization_hash CHAR(64) NULL
                )
                """);
        execute("""
                CREATE TABLE pdf_signing_policy_versions (
                    id BIGINT PRIMARY KEY,
                    version_uuid VARCHAR(36),
                    policy_hash CHAR(64),
                    config_bundle_hash CHAR(64),
                    pades_profile VARCHAR(8),
                    immutable_at TIMESTAMP,
                    java_execution_timeout_seconds INT,
                    pre_private_key_max_attempts INT,
                    pre_private_key_retry_backoff_seconds VARCHAR(255),
                    pre_private_key_retryable_error_codes VARCHAR(255),
                    generated_revision_max_bytes BIGINT,
                    max_signature_increment_bytes BIGINT
                )
                """);
        execute("""
                CREATE TABLE pdf_java_signing_executions (
                    id BIGINT AUTO_INCREMENT PRIMARY KEY,
                    operation_uuid VARCHAR(36) UNIQUE,
                    operation_input_manifest_hash CHAR(64), input_fingerprint CHAR(64), policy_hash CHAR(64),
                    attempt_number INT, attempt_count INT, max_attempts INT,
                    state VARCHAR(48), retryability VARCHAR(32),
                    authorized_lease_epoch BIGINT, lock_version BIGINT,
                    claimed_at TIMESTAMP, execution_started_at TIMESTAMP,
                    private_key_started_at TIMESTAMP, execution_deadline_at TIMESTAMP,
                    next_retry_at TIMESTAMP, retry_exhausted_at TIMESTAMP,
                    completed_at TIMESTAMP, terminal_at TIMESTAMP,
                    error_code VARCHAR(96), result_path VARCHAR(1024),
                    result_sha256 CHAR(64), result_size BIGINT, result_file_key VARCHAR(255),
                    validation_report_hash CHAR(64), result_integrity_state VARCHAR(24),
                    result_last_verified_at TIMESTAMP, result_integrity_error_code VARCHAR(96),
                    retention_until TIMESTAMP, retirement_phase VARCHAR(24),
                    retirement_epoch BIGINT, retirement_staged_path VARCHAR(1024),
                    retirement_started_at TIMESTAMP, retirement_purge_not_before TIMESTAMP,
                    evidence_hold_mask BIGINT, evidence_hold_state VARCHAR(16),
                    legal_hold_until TIMESTAMP, bytes_deleted_at TIMESTAMP,
                    created_at TIMESTAMP, updated_at TIMESTAMP
                )
                """);
        execute("""
                CREATE TABLE pdf_java_signing_execution_events (
                    id BIGINT AUTO_INCREMENT PRIMARY KEY,
                    operation_uuid VARCHAR(36), attempt_number INT, event_type VARCHAR(64),
                    old_state VARCHAR(48), new_state VARCHAR(48),
                    old_retirement_phase VARCHAR(24), new_retirement_phase VARCHAR(24),
                    old_lock_version BIGINT, new_lock_version BIGINT,
                    authorized_lease_epoch BIGINT, retirement_epoch BIGINT,
                    error_code VARCHAR(96), event_at TIMESTAMP, event_hash CHAR(64),
                    UNIQUE(operation_uuid, attempt_number, event_type, new_lock_version)
                )
                """);
    }

    private void insertPolicyAndOperation() throws Exception {
        execute("""
                INSERT INTO pdf_signing_policy_versions VALUES (
                    1, '%s', '%s', '%s', 'B-T', CURRENT_TIMESTAMP, 90, 3,
                    '[1,2]', '["DB_UNAVAILABLE"]', 33554432, 2097152
                )
                """.formatted(claim.policyVersionUuid(), claim.policyHash(), claim.configBundleHash()));
        execute("""
                INSERT INTO pdf_signing_operations (
                    operation_uuid, java_gate_version, lease_epoch, lease_owner, state, stage, action,
                    lease_expires_at, cancellation_requested_at, operation_input_manifest_hash,
                    input_fingerprint, expected_source_sha256, signing_policy_version_id,
                    policy_hash, config_bundle_hash, appearance_manifest_hash, appearance_sha256,
                    pdf_signature_role, target_field_name, expected_certificate_fingerprint,
                    field_lock_policy_hash
                ) VALUES (
                    '%s', 0, 7, '11111111-1111-1111-1111-111111111111',
                    'processing', 'java_call', 'fill_signature_field',
                    DATEADD('HOUR', 1, CURRENT_TIMESTAMP), NULL, '%s', '%s',
                    '%s', 1, '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s'
                )
                """.formatted(
                operationUuid,
                claim.operationInputManifestHash(),
                claim.inputFingerprint(),
                claim.expectedSourceSha256(),
                claim.policyHash(),
                claim.configBundleHash(),
                claim.appearanceManifestHash(),
                claim.appearanceSha256(),
                claim.pdfSignatureRole(),
                claim.targetFieldName(),
                claim.expectedCertificateFingerprint(),
                claim.fieldLockPolicyHash()
        ));
    }

    private void authorizeRetirement(ExecutionRecord completed) throws Exception {
        Instant notBefore = Instant.now().minusSeconds(1);
        Instant expiresAt = Instant.now().plusSeconds(300);
        Map<String, Object> manifest = new LinkedHashMap<>();
        manifest.put("operation_uuid", operationUuid.toString());
        manifest.put("execution_result_path", completed.resultPath());
        manifest.put("execution_result_sha256", completed.resultSha256());
        manifest.put("execution_result_size", completed.resultSize());
        manifest.put("formal_revision_uuid", UUID.randomUUID().toString());
        manifest.put("formal_revision_sha256", completed.resultSha256());
        manifest.put("grace_seconds", 60);
        manifest.put("not_before", notBefore.toString());
        manifest.put("expires_at", expiresAt.toString());
        ObjectMapper canonical = new ObjectMapper().configure(SerializationFeature.ORDER_MAP_ENTRIES_BY_KEYS, true);
        String manifestJson = canonical.writeValueAsString(manifest);
        String authorizationHash = HexFormat.of().formatHex(
                MessageDigest.getInstance("SHA-256").digest(canonical.writeValueAsBytes(manifest))
        );
        try (Connection connection = DriverManager.getConnection(jdbcUrl, "sa", "test");
             var update = connection.prepareStatement("""
                     UPDATE pdf_signing_operations
                        SET state='completed', result_retirement_not_before=?,
                            result_retirement_authorization_expires_at=?,
                            result_retirement_authorization_manifest=?,
                            result_retirement_authorization_hash=?
                      WHERE operation_uuid=?
                     """)) {
            update.setTimestamp(1, java.sql.Timestamp.from(notBefore));
            update.setTimestamp(2, java.sql.Timestamp.from(expiresAt));
            update.setString(3, manifestJson);
            update.setString(4, authorizationHash);
            update.setString(5, operationUuid.toString());
            update.executeUpdate();
        }
    }

    private void execute(String sql) throws Exception {
        try (Connection connection = DriverManager.getConnection(jdbcUrl, "sa", "test");
             Statement statement = connection.createStatement()) {
            statement.execute(sql);
        }
    }

    private void runCrashWorker(String action, Path source, Path target) throws Exception {
        Process process = new ProcessBuilder(
                Path.of(System.getProperty("java.home"), "bin", "java").toString(),
                "-cp",
                System.getProperty("java.class.path"),
                RetirementCrashWorker.class.getName(),
                action,
                source.toString(),
                target == null ? "-" : target.toString()
        ).redirectErrorStream(true).start();
        if (!process.waitFor(10, TimeUnit.SECONDS)) {
            process.destroyForcibly();
            throw new AssertionError("Retirement crash worker did not terminate in time");
        }
        String output = new String(process.getInputStream().readAllBytes(), StandardCharsets.UTF_8);
        assertThat(process.exitValue()).as(output).isEqualTo(137);
        assertThat(output).contains("retirement-file-action-durable");
    }

    private long count(String table) throws Exception {
        return scalarLong("SELECT COUNT(*) FROM " + table);
    }

    private long scalarLong(String sql) throws Exception {
        try (Connection connection = DriverManager.getConnection(jdbcUrl, "sa", "test");
             Statement statement = connection.createStatement();
             var result = statement.executeQuery(sql)) {
            result.next();
            return result.getLong(1);
        }
    }
}
