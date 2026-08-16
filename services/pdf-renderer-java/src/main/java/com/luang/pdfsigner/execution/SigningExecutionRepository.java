package com.luang.pdfsigner.execution;

import java.nio.charset.StandardCharsets;
import java.security.MessageDigest;
import java.sql.Connection;
import java.sql.DriverManager;
import java.sql.PreparedStatement;
import java.sql.ResultSet;
import java.sql.SQLException;
import java.sql.Timestamp;
import java.time.Duration;
import java.time.Instant;
import java.util.HexFormat;
import java.util.Optional;
import java.util.List;
import java.util.Map;
import java.util.Set;
import com.fasterxml.jackson.core.type.TypeReference;
import com.fasterxml.jackson.databind.ObjectMapper;
import com.fasterxml.jackson.databind.SerializationFeature;
import java.util.UUID;
import org.springframework.stereotype.Repository;

@Repository
public class SigningExecutionRepository {
    private static final HexFormat HEX = HexFormat.of();
    private static final ObjectMapper JSON = new ObjectMapper();
    private final ExecutionLedgerProperties properties;

    public SigningExecutionRepository(ExecutionLedgerProperties properties) {
        this.properties = properties;
    }

    public ClaimResult claim(OperationClaim claim) throws Exception {
        properties.requireReadyConfiguration();
        try (Connection connection = connection()) {
            connection.setAutoCommit(false);
            try {
                Optional<ExecutionRecord> existing = find(connection, claim.operationUuid());
                if (existing.isPresent()) {
                    validateExistingClaim(connection, existing.get(), claim);
                    connection.commit();
                    return new ClaimResult(existing.get(), false, policy(connection, claim));
                }
                PolicySnapshot policy = policy(connection, claim);
                int gateUpdated;
                try (PreparedStatement statement = connection.prepareStatement("""
                        UPDATE pdf_signing_operations
                           SET java_gate_version = java_gate_version + 1
                         WHERE operation_uuid = ?
                           AND java_gate_version = ?
                           AND lease_epoch = ?
                           AND state = 'processing'
                           AND stage IN ('java_call', 'java_polling')
                           AND action = 'fill_signature_field'
                           AND document_evidence_hold_mask = 0
                           AND lease_expires_at > CURRENT_TIMESTAMP
                           AND operation_input_manifest_hash = ?
                           AND input_fingerprint = ?
                           AND expected_source_sha256 = ?
                           AND signing_policy_version_id = ?
                           AND policy_hash = ?
                           AND config_bundle_hash = ?
                           AND appearance_manifest_hash = ?
                           AND appearance_sha256 = ?
                           AND pdf_signature_role = ?
                           AND target_field_name = ?
                           AND expected_certificate_fingerprint = ?
                           AND field_lock_policy_hash = ?
                        """)) {
                    statement.setString(1, claim.operationUuid().toString());
                    statement.setLong(2, claim.javaGateVersion());
                    statement.setLong(3, claim.leaseEpoch());
                    statement.setString(4, claim.operationInputManifestHash());
                    statement.setString(5, claim.inputFingerprint());
                    statement.setString(6, claim.expectedSourceSha256());
                    statement.setLong(7, claim.policyVersionId());
                    statement.setString(8, claim.policyHash());
                    statement.setString(9, claim.configBundleHash());
                    statement.setString(10, claim.appearanceManifestHash());
                    statement.setString(11, claim.appearanceSha256());
                    statement.setString(12, claim.pdfSignatureRole());
                    statement.setString(13, claim.targetFieldName());
                    statement.setString(14, claim.expectedCertificateFingerprint());
                    statement.setString(15, claim.fieldLockPolicyHash());
                    gateUpdated = statement.executeUpdate();
                }
                if (gateUpdated != 1) {
                    Optional<ExecutionRecord> raced = find(connection, claim.operationUuid());
                    connection.rollback();
                    if (raced.isPresent()) {
                        return new ClaimResult(raced.get(), false, policy);
                    }
                    throw new ExecutionGateException("SIGN_AUTHORIZE gate rejected the operation snapshot");
                }
                Instant now = Instant.now();
                try (PreparedStatement statement = connection.prepareStatement("""
                        INSERT INTO pdf_java_signing_executions (
                            operation_uuid, operation_input_manifest_hash, input_fingerprint, policy_hash,
                            attempt_number, attempt_count, max_attempts,
                            state, authorized_lease_epoch, lock_version, claimed_at,
                            result_integrity_state, retirement_phase, retirement_epoch,
                            evidence_hold_mask, evidence_hold_state, created_at, updated_at
                        ) VALUES (?, ?, ?, ?, 0, 0, ?, 'claimed', ?, 0, ?,
                                  'not_applicable', 'none', 0, 0, 'none', ?, ?)
                        """)) {
                    statement.setString(1, claim.operationUuid().toString());
                    statement.setString(2, claim.operationInputManifestHash());
                    statement.setString(3, claim.inputFingerprint());
                    statement.setString(4, claim.policyHash());
                    statement.setInt(5, policy.maximumAttempts());
                    statement.setLong(6, claim.leaseEpoch());
                    statement.setTimestamp(7, Timestamp.from(now));
                    statement.setTimestamp(8, Timestamp.from(now));
                    statement.setTimestamp(9, Timestamp.from(now));
                    statement.executeUpdate();
                }
                appendEvent(connection, claim.operationUuid(), 0, "CLAIMED", null, "claimed", 0, 0, null);
                ExecutionRecord created = find(connection, claim.operationUuid())
                        .orElseThrow(() -> new SQLException("Execution claim disappeared before commit"));
                connection.commit();
                return new ClaimResult(created, true, policy);
            } catch (Exception exception) {
                connection.rollback();
                throw exception;
            }
        }
    }

    public ExecutionRecord markExecuting(UUID operationUuid, Duration timeout) throws Exception {
        Instant now = Instant.now();
        Instant deadline = now.plus(timeout);
        return transition(
                operationUuid,
                "claimed",
                "executing",
                "EXECUTION_STARTED",
                null,
                statement -> {
                    statement.setTimestamp(1, Timestamp.from(now));
                    statement.setTimestamp(2, Timestamp.from(deadline));
                    statement.setString(3, operationUuid.toString());
                },
                "UPDATE pdf_java_signing_executions SET state='executing', execution_started_at=?, "
                        + "execution_deadline_at=?, attempt_count=attempt_count+1, "
                        + "attempt_number=attempt_number+1, lock_version=lock_version+1, "
                        + "updated_at=CURRENT_TIMESTAMP WHERE operation_uuid=? AND state='claimed' "
                        + "AND attempt_count < max_attempts"
        );
    }

    public ExecutionRecord markPrivateKeyStarted(OperationClaim claim) throws Exception {
        UUID operationUuid = claim.operationUuid();
        try (Connection connection = connection()) {
            connection.setAutoCommit(false);
            try {
                signAuthorizeGate(connection, claim);
                ExecutionRecord before = findForUpdate(connection, operationUuid);
                if (!"executing".equals(before.state())
                        || before.privateKeyStartedAt() != null
                        || before.evidenceHoldMask() != 0
                        || !"none".equals(before.evidenceHoldState())
                        || (before.legalHoldUntil() != null && Instant.now().isBefore(before.legalHoldUntil()))
                        || !claim.inputFingerprint().equals(before.inputFingerprint())
                        || !claim.policyHash().equals(before.policyHash())
                        || before.authorizedLeaseEpoch() != claim.leaseEpoch()
                        || before.executionDeadlineAt() == null
                        || !Instant.now().isBefore(before.executionDeadlineAt())) {
                    throw new ExecutionGateException("Private-key boundary is not available for this execution state");
                }
                Instant now = Instant.now();
                try (PreparedStatement statement = connection.prepareStatement("""
                        UPDATE pdf_java_signing_executions
                           SET private_key_started_at=?, lock_version=lock_version+1, updated_at=?
                         WHERE operation_uuid=? AND state='executing' AND private_key_started_at IS NULL
                           AND execution_deadline_at > CURRENT_TIMESTAMP
                           AND evidence_hold_mask=0 AND evidence_hold_state='none'
                           AND (legal_hold_until IS NULL OR legal_hold_until<=CURRENT_TIMESTAMP)
                           AND input_fingerprint=? AND policy_hash=? AND authorized_lease_epoch=?
                        """)) {
                    statement.setTimestamp(1, Timestamp.from(now));
                    statement.setTimestamp(2, Timestamp.from(now));
                    statement.setString(3, operationUuid.toString());
                    statement.setString(4, claim.inputFingerprint());
                    statement.setString(5, claim.policyHash());
                    statement.setLong(6, claim.leaseEpoch());
                    if (statement.executeUpdate() != 1) {
                        throw new ExecutionGateException("Private-key boundary CAS lost");
                    }
                }
                ExecutionRecord after = find(connection, operationUuid).orElseThrow();
                appendEvent(connection, operationUuid, after.attemptNumber(), "PRIVATE_KEY_STARTED",
                        before.state(), after.state(), before.lockVersion(), after.lockVersion(), null);
                connection.commit();
                return after;
            } catch (Exception exception) {
                connection.rollback();
                throw exception;
            }
        }
    }

    public ExecutionRecord markCompleted(
            UUID operationUuid,
            ExecutionStorage.StoredResult stored,
            String validationReportHash
    ) throws Exception {
        try (Connection connection = connection()) {
            connection.setAutoCommit(false);
            try {
                ExecutionRecord before = findForUpdate(connection, operationUuid);
                if ("completed".equals(before.state())) {
                    if (stored.path().equals(before.resultPath())
                            && stored.sha256().equals(before.resultSha256())
                            && stored.size() == before.resultSize()
                            && stored.fileKey().equals(before.resultFileKey())
                            && validationReportHash.equals(before.validationReportHash())) {
                        connection.commit();
                        return before;
                    }
                    throw new ExecutionGateException("Completed execution result identity is immutable");
                }
                if (!"executing".equals(before.state()) || before.privateKeyStartedAt() == null) {
                    throw new ExecutionGateException("Completed execution must have crossed its private-key boundary");
                }
                Instant now = Instant.now();
                try (PreparedStatement statement = connection.prepareStatement("""
                        UPDATE pdf_java_signing_executions
                           SET state='completed', completed_at=?, terminal_at=?, result_path=?, result_sha256=?,
                               result_size=?, result_file_key=?, result_integrity_state='available',
                               validation_report_hash=?, retention_until=?,
                               lock_version=lock_version+1, updated_at=?
                         WHERE operation_uuid=? AND state='executing' AND private_key_started_at IS NOT NULL
                           AND input_fingerprint=? AND policy_hash=? AND lock_version=?
                        """)) {
                    statement.setTimestamp(1, Timestamp.from(now));
                    statement.setTimestamp(2, Timestamp.from(now));
                    statement.setString(3, stored.path());
                    statement.setString(4, stored.sha256());
                    statement.setLong(5, stored.size());
                    statement.setString(6, stored.fileKey());
                    statement.setString(7, validationReportHash);
                    statement.setTimestamp(8, Timestamp.from(now.plus(Duration.ofDays(7))));
                    statement.setTimestamp(9, Timestamp.from(now));
                    statement.setString(10, operationUuid.toString());
                    statement.setString(11, before.inputFingerprint());
                    statement.setString(12, before.policyHash());
                    statement.setLong(13, before.lockVersion());
                    if (statement.executeUpdate() != 1) {
                        throw new ExecutionGateException("Completed execution CAS lost");
                    }
                }
                ExecutionRecord after = find(connection, operationUuid).orElseThrow();
                appendEvent(connection, operationUuid, after.attemptNumber(), "COMPLETED",
                        before.state(), after.state(), before.lockVersion(), after.lockVersion(), null);
                connection.commit();
                return after;
            } catch (Exception exception) {
                connection.rollback();
                throw exception;
            }
        }
    }

    public ExecutionRecord markFailure(UUID operationUuid, String state, String retryability, String errorCode)
            throws Exception {
        if (!("failed_before_private_key".equals(state)
                || "failed_after_private_key_known".equals(state)
                || "outcome_unknown".equals(state))) {
            throw new IllegalArgumentException("Unsupported execution failure state");
        }
        try (Connection connection = connection()) {
            connection.setAutoCommit(false);
            try {
                ExecutionRecord before = findForUpdate(connection, operationUuid);
                if (!("claimed".equals(before.state()) || "executing".equals(before.state()))) {
                    connection.commit();
                    return before;
                }
                boolean needsPrivateKey = !"failed_before_private_key".equals(state);
                if (needsPrivateKey != (before.privateKeyStartedAt() != null)) {
                    throw new ExecutionGateException("Failure classification contradicts the private-key boundary");
                }
                Instant now = Instant.now();
                try (PreparedStatement statement = connection.prepareStatement("""
                        UPDATE pdf_java_signing_executions
                           SET state=?, retryability=?, error_code=?, terminal_at=?,
                               next_retry_at=NULL,
                               retry_exhausted_at=CASE WHEN ?='failed_before_private_key'
                                   AND attempt_count >= max_attempts THEN ? ELSE retry_exhausted_at END,
                               lock_version=lock_version+1, updated_at=?
                         WHERE operation_uuid=? AND state IN ('claimed', 'executing')
                           AND input_fingerprint=? AND policy_hash=? AND lock_version=?
                        """)) {
                    statement.setString(1, state);
                    statement.setString(2, retryability);
                    statement.setString(3, errorCode);
                    statement.setTimestamp(4, Timestamp.from(now));
                    statement.setString(5, state);
                    statement.setTimestamp(6, Timestamp.from(now));
                    statement.setTimestamp(7, Timestamp.from(now));
                    statement.setString(8, operationUuid.toString());
                    statement.setString(9, before.inputFingerprint());
                    statement.setString(10, before.policyHash());
                    statement.setLong(11, before.lockVersion());
                    statement.executeUpdate();
                }
                ExecutionRecord after = find(connection, operationUuid).orElseThrow();
                appendEvent(connection, operationUuid, after.attemptNumber(), state.toUpperCase(),
                        before.state(), after.state(), before.lockVersion(), after.lockVersion(), errorCode);
                connection.commit();
                return after;
            } catch (Exception exception) {
                connection.rollback();
                throw exception;
            }
        }
    }

    public ExecutionRecord markPrePrivateKeyFailure(
            UUID operationUuid,
            String errorCode,
            PolicySnapshot policy
    ) throws Exception {
        try (Connection connection = connection()) {
            connection.setAutoCommit(false);
            try {
                ExecutionRecord before = findForUpdate(connection, operationUuid);
                if (!"executing".equals(before.state()) || before.privateKeyStartedAt() != null) {
                    throw new ExecutionGateException("Pre-key failure classification contradicts execution state");
                }
                boolean retryable = policy.retryableErrorCodes().contains(errorCode)
                        && before.attemptCount() < before.maxAttempts();
                int backoffIndex = Math.max(0, before.attemptCount() - 1);
                Instant now = Instant.now();
                Instant nextRetry = retryable
                        ? now.plusSeconds(policy.retryBackoffSeconds().get(
                                Math.min(backoffIndex, policy.retryBackoffSeconds().size() - 1)))
                        : null;
                try (PreparedStatement statement = connection.prepareStatement("""
                        UPDATE pdf_java_signing_executions
                           SET state='failed_before_private_key', retryability=?, error_code=?, terminal_at=?,
                               next_retry_at=?, retry_exhausted_at=?, lock_version=lock_version+1, updated_at=?
                         WHERE operation_uuid=? AND state='executing' AND private_key_started_at IS NULL
                           AND input_fingerprint=? AND policy_hash=? AND lock_version=?
                        """)) {
                    statement.setString(1, retryable ? "same_operation" : "none");
                    statement.setString(2, errorCode);
                    statement.setTimestamp(3, Timestamp.from(now));
                    statement.setTimestamp(4, nextRetry == null ? null : Timestamp.from(nextRetry));
                    statement.setTimestamp(5, !retryable && before.attemptCount() >= before.maxAttempts()
                            ? Timestamp.from(now) : null);
                    statement.setTimestamp(6, Timestamp.from(now));
                    statement.setString(7, operationUuid.toString());
                    statement.setString(8, before.inputFingerprint());
                    statement.setString(9, before.policyHash());
                    statement.setLong(10, before.lockVersion());
                    if (statement.executeUpdate() != 1) {
                        throw new ExecutionGateException("Pre-key failure CAS lost");
                    }
                }
                ExecutionRecord after = find(connection, operationUuid).orElseThrow();
                appendEvent(connection, operationUuid, after.attemptNumber(), "FAILED_BEFORE_PRIVATE_KEY",
                        before.state(), after.state(), before.lockVersion(), after.lockVersion(), errorCode);
                connection.commit();
                return after;
            } catch (Exception exception) {
                connection.rollback();
                throw exception;
            }
        }
    }

    public ExecutionRecord authorizeRetry(OperationClaim claim, long expectedLockVersion) throws Exception {
        try (Connection connection = connection()) {
            connection.setAutoCommit(false);
            try {
                signAuthorizeGateAtVersion(connection, claim, claim.javaGateVersion());
                ExecutionRecord before = findForUpdate(connection, claim.operationUuid());
                Instant now = Instant.now();
                if (!"failed_before_private_key".equals(before.state())
                        || !"same_operation".equals(before.retryability())
                        || before.privateKeyStartedAt() != null
                        || before.nextRetryAt() == null || now.isBefore(before.nextRetryAt())
                        || before.attemptCount() >= before.maxAttempts()
                        || before.lockVersion() != expectedLockVersion
                        || !before.inputFingerprint().equals(claim.inputFingerprint())
                        || !before.policyHash().equals(claim.policyHash())) {
                    throw new ExecutionGateException("Pre-key retry is not authorized by the execution ledger");
                }
                try (PreparedStatement statement = connection.prepareStatement("""
                        UPDATE pdf_java_signing_executions
                           SET state='claimed', authorized_lease_epoch=?, next_retry_at=NULL,
                               retry_exhausted_at=NULL, error_code=NULL, terminal_at=NULL,
                               lock_version=lock_version+1, updated_at=?
                         WHERE operation_uuid=? AND input_fingerprint=? AND policy_hash=?
                           AND state='failed_before_private_key' AND retryability='same_operation'
                           AND lock_version=? AND private_key_started_at IS NULL
                           AND attempt_count < max_attempts AND next_retry_at<=CURRENT_TIMESTAMP
                        """)) {
                    statement.setLong(1, claim.leaseEpoch());
                    statement.setTimestamp(2, Timestamp.from(now));
                    statement.setString(3, claim.operationUuid().toString());
                    statement.setString(4, claim.inputFingerprint());
                    statement.setString(5, claim.policyHash());
                    statement.setLong(6, expectedLockVersion);
                    if (statement.executeUpdate() != 1) {
                        throw new ExecutionGateException("Pre-key retry CAS lost");
                    }
                }
                ExecutionRecord after = find(connection, claim.operationUuid()).orElseThrow();
                appendEvent(connection, claim.operationUuid(), after.attemptNumber(), "RETRY_AUTHORIZED",
                        before.state(), after.state(), before.lockVersion(), after.lockVersion(), null);
                connection.commit();
                return after;
            } catch (Exception exception) {
                connection.rollback();
                throw exception;
            }
        }
    }

    public Optional<ExecutionRecord> find(UUID operationUuid) throws Exception {
        properties.requireReadyConfiguration();
        try (Connection connection = connection()) {
            return find(connection, operationUuid);
        }
    }

    public long recoveryMaximumBytes(UUID operationUuid) throws Exception {
        properties.requireReadyConfiguration();
        try (Connection connection = connection();
             PreparedStatement statement = connection.prepareStatement("""
                     SELECT policy.generated_revision_max_bytes
                       FROM pdf_java_signing_executions execution
                       JOIN pdf_signing_operations operation
                         ON operation.operation_uuid=execution.operation_uuid
                       JOIN pdf_signing_policy_versions policy
                         ON policy.id=operation.signing_policy_version_id
                        AND policy.policy_hash=execution.policy_hash
                      WHERE execution.operation_uuid=? AND policy.immutable_at IS NOT NULL
                     """)) {
            statement.setString(1, operationUuid.toString());
            try (ResultSet result = statement.executeQuery()) {
                if (!result.next()) {
                    throw new ExecutionGateException("Recovery policy snapshot does not exist");
                }
                long maximumBytes = result.getLong(1);
                if (maximumBytes < 1) {
                    throw new ExecutionGateException("Recovery policy budget is invalid");
                }
                return maximumBytes;
            }
        }
    }

    public List<ExecutionRecord> retirementCandidates(int limit) throws Exception {
        properties.requireReadyConfiguration();
        try (Connection connection = connection();
             PreparedStatement statement = connection.prepareStatement("""
                     SELECT * FROM pdf_java_signing_executions
                      WHERE state='completed'
                        AND result_integrity_state IN ('available','retiring')
                        AND retirement_phase IN ('none','stage_intent','staged','purge_intent')
                      ORDER BY id
                      LIMIT ?
                     """)) {
            statement.setInt(1, Math.max(1, Math.min(limit, 500)));
            try (ResultSet result = statement.executeQuery()) {
                java.util.ArrayList<ExecutionRecord> records = new java.util.ArrayList<>();
                while (result.next()) records.add(map(result));
                return List.copyOf(records);
            }
        }
    }

    public ExecutionRecord beginRetirement(UUID operationUuid) throws Exception {
        try (Connection connection = connection()) {
            connection.setAutoCommit(false);
            try {
                RetirementAuthorization authorization = retirementAuthorization(connection, operationUuid);
                ExecutionRecord before = findForUpdate(connection, operationUuid);
                Instant now = Instant.now();
                if (!"completed".equals(before.state())
                        || !"available".equals(before.resultIntegrityState())
                        || !"none".equals(before.retirementPhase())
                        || before.retentionUntil() == null || now.isBefore(before.retentionUntil())
                        || before.evidenceHoldMask() != 0 || !"none".equals(before.evidenceHoldState())
                        || (before.legalHoldUntil() != null && now.isBefore(before.legalHoldUntil()))) {
                    throw new ExecutionGateException("RESULT_RETIRE_STAGE execution is not eligible");
                }
                validateRetirementAuthorization(authorization, before, now);
                retirementGate(connection, operationUuid, authorization);
                long epoch = before.retirementEpoch() + 1;
                String stagedPath = before.resultPath() + ".retirement-" + epoch;
                Instant purgeNotBefore = now.plusSeconds(authorization.graceSeconds());
                try (PreparedStatement update = connection.prepareStatement("""
                        UPDATE pdf_java_signing_executions
                           SET result_integrity_state='retiring', retirement_phase='stage_intent',
                               retirement_epoch=?, retirement_staged_path=?, retirement_started_at=?,
                               retirement_purge_not_before=?, lock_version=lock_version+1, updated_at=?
                         WHERE operation_uuid=? AND state='completed'
                           AND result_integrity_state='available' AND retirement_phase='none'
                           AND lock_version=? AND evidence_hold_mask=0 AND evidence_hold_state='none'
                        """)) {
                    update.setLong(1, epoch);
                    update.setString(2, stagedPath);
                    update.setTimestamp(3, Timestamp.from(now));
                    update.setTimestamp(4, Timestamp.from(purgeNotBefore));
                    update.setTimestamp(5, Timestamp.from(now));
                    update.setString(6, operationUuid.toString());
                    update.setLong(7, before.lockVersion());
                    if (update.executeUpdate() != 1) throw new ExecutionGateException("RESULT_RETIRE_STAGE CAS lost");
                }
                ExecutionRecord after = find(connection, operationUuid).orElseThrow();
                appendRetirementEvent(connection, before, after, "RESULT_RETIRE_STAGE_INTENT", null);
                connection.commit();
                return after;
            } catch (Exception exception) {
                connection.rollback();
                throw exception;
            }
        }
    }

    public ExecutionRecord applyRetirementStage(
            UUID operationUuid,
            long epoch,
            ExecutionStorage storage
    ) throws Exception {
        try (Connection connection = connection()) {
            connection.setAutoCommit(false);
            try {
                lockCompletedOperation(connection, operationUuid);
                ExecutionRecord before = findForUpdate(connection, operationUuid);
                Instant now = Instant.now();
                if (!"stage_intent".equals(before.retirementPhase()) || before.retirementEpoch() != epoch
                        || before.evidenceHoldMask() != 0 || !"none".equals(before.evidenceHoldState())
                        || (before.legalHoldUntil() != null && now.isBefore(before.legalHoldUntil()))) {
                    throw new ExecutionGateException("RESULT_RETIRE_STAGE_APPLY execution is not eligible");
                }
                boolean canonicalExists = storage.canonicalExists(before);
                boolean stagedExists = storage.stagedExists(before);
                if (canonicalExists && stagedExists) {
                    throw new ExecutionGateException("RESULT_RETIRE_STAGE_APPLY found duplicate evidence copies");
                }
                if (stagedExists) {
                    storage.verifyStaged(before);
                } else if (canonicalExists) {
                    String staged = storage.stageForRetirement(before, epoch);
                    if (!staged.equals(before.retirementStagedPath())) {
                        throw new ExecutionGateException("RESULT_RETIRE_STAGE_APPLY path differs from the frozen ledger");
                    }
                } else {
                    throw new ExecutionGateException("RESULT_RETIRE_STAGE_APPLY cannot locate exact result bytes");
                }
                try (PreparedStatement update = connection.prepareStatement("""
                        UPDATE pdf_java_signing_executions
                           SET retirement_phase='staged', lock_version=lock_version+1, updated_at=?
                         WHERE operation_uuid=? AND retirement_phase='stage_intent'
                           AND retirement_epoch=? AND lock_version=?
                           AND evidence_hold_mask=0 AND evidence_hold_state='none'
                        """)) {
                    update.setTimestamp(1, Timestamp.from(now));
                    update.setString(2, operationUuid.toString());
                    update.setLong(3, epoch);
                    update.setLong(4, before.lockVersion());
                    if (update.executeUpdate() != 1) throw new ExecutionGateException("RESULT_RETIRE_STAGE_APPLY CAS lost");
                }
                ExecutionRecord after = find(connection, operationUuid).orElseThrow();
                appendRetirementEvent(connection, before, after, "RESULT_RETIRE_STAGED", null);
                connection.commit();
                return after;
            } catch (Exception exception) {
                connection.rollback();
                throw exception;
            }
        }
    }

    public ExecutionRecord beginRetirementPurge(UUID operationUuid, long epoch) throws Exception {
        return retirementTransition(operationUuid, epoch, "staged", "purge_intent", "RESULT_RETIRE_PURGE_INTENT", true);
    }

    public ExecutionRecord applyRetirementPurge(
            UUID operationUuid,
            long epoch,
            ExecutionStorage storage
    ) throws Exception {
        try (Connection connection = connection()) {
            connection.setAutoCommit(false);
            try {
                lockCompletedOperation(connection, operationUuid);
                ExecutionRecord before = findForUpdate(connection, operationUuid);
                Instant now = Instant.now();
                if (!"purge_intent".equals(before.retirementPhase()) || before.retirementEpoch() != epoch
                        || before.retirementPurgeNotBefore() == null || now.isBefore(before.retirementPurgeNotBefore())
                        || before.evidenceHoldMask() != 0 || !"none".equals(before.evidenceHoldState())
                        || (before.legalHoldUntil() != null && now.isBefore(before.legalHoldUntil()))) {
                    throw new ExecutionGateException("RESULT_RETIRE_PURGE_APPLY execution is not eligible");
                }
                if (storage.canonicalExists(before)) {
                    throw new ExecutionGateException("RESULT_RETIRE_PURGE_APPLY found unexpected canonical evidence");
                }
                if (storage.stagedExists(before)) storage.purgeStaged(before);
                try (PreparedStatement update = connection.prepareStatement("""
                        UPDATE pdf_java_signing_executions
                           SET result_integrity_state='retired', retirement_phase='retired', bytes_deleted_at=?,
                               lock_version=lock_version+1, updated_at=?
                         WHERE operation_uuid=? AND retirement_phase='purge_intent'
                           AND retirement_epoch=? AND lock_version=?
                           AND evidence_hold_mask=0 AND evidence_hold_state='none'
                        """)) {
                    update.setTimestamp(1, Timestamp.from(now));
                    update.setTimestamp(2, Timestamp.from(now));
                    update.setString(3, operationUuid.toString());
                    update.setLong(4, epoch);
                    update.setLong(5, before.lockVersion());
                    if (update.executeUpdate() != 1) throw new ExecutionGateException("RESULT_RETIRE_PURGE_APPLY CAS lost");
                }
                ExecutionRecord after = find(connection, operationUuid).orElseThrow();
                appendRetirementEvent(connection, before, after, "RESULT_RETIRED", null);
                connection.commit();
                return after;
            } catch (Exception exception) {
                connection.rollback();
                throw exception;
            }
        }
    }

    public ExecutionRecord applyRetirementRestore(
            UUID operationUuid,
            long epoch,
            ExecutionStorage storage
    ) throws Exception {
        try (Connection connection = connection()) {
            connection.setAutoCommit(false);
            try {
                lockCompletedOperation(connection, operationUuid);
                ExecutionRecord before = findForUpdate(connection, operationUuid);
                if (!Set.of("stage_intent", "staged", "purge_intent").contains(before.retirementPhase())
                        || before.retirementEpoch() != epoch
                        || before.evidenceHoldMask() == 0 || !"active".equals(before.evidenceHoldState())) {
                    throw new ExecutionGateException("RESULT_RETIRE_RESTORE execution is not eligible");
                }
                boolean canonical = storage.canonicalExists(before);
                boolean staged = storage.stagedExists(before);
                if (canonical && staged) {
                    throw new ExecutionGateException("RESULT_RETIRE_RESTORE found two evidence copies");
                }
                if (!canonical && staged) {
                    storage.restoreStaged(before);
                    canonical = true;
                }
                if (!canonical) {
                    throw new ExecutionGateException("RESULT_RETIRE_RESTORE cannot locate exact result bytes");
                }
                try (var input = storage.openVerified(before).input()) {
                    // The descriptor verification proves the restored canonical identity.
                }
                Instant now = Instant.now();
                try (PreparedStatement update = connection.prepareStatement("""
                        UPDATE pdf_java_signing_executions
                           SET result_integrity_state='available', retirement_phase='none',
                               retirement_epoch=retirement_epoch+1, retirement_staged_path=NULL,
                               retirement_started_at=NULL, retirement_purge_not_before=NULL,
                               lock_version=lock_version+1, updated_at=?
                         WHERE operation_uuid=? AND retirement_epoch=?
                           AND retirement_phase IN ('stage_intent','staged','purge_intent')
                           AND lock_version=? AND evidence_hold_mask<>0 AND evidence_hold_state='active'
                        """)) {
                    update.setTimestamp(1, Timestamp.from(now));
                    update.setString(2, operationUuid.toString());
                    update.setLong(3, epoch);
                    update.setLong(4, before.lockVersion());
                    if (update.executeUpdate() != 1) throw new ExecutionGateException("RESULT_RETIRE_RESTORE CAS lost");
                }
                ExecutionRecord after = find(connection, operationUuid).orElseThrow();
                appendRetirementEvent(connection, before, after, "RESULT_RETIRE_RESTORED", null);
                connection.commit();
                return after;
            } catch (Exception exception) {
                connection.rollback();
                throw exception;
            }
        }
    }

    private ExecutionRecord retirementTransition(
            UUID operationUuid,
            long epoch,
            String oldPhase,
            String newPhase,
            String eventType,
            boolean requireGrace
    ) throws Exception {
        try (Connection connection = connection()) {
            connection.setAutoCommit(false);
            try {
                lockCompletedOperation(connection, operationUuid);
                ExecutionRecord before = findForUpdate(connection, operationUuid);
                Instant now = Instant.now();
                if (!oldPhase.equals(before.retirementPhase()) || before.retirementEpoch() != epoch
                        || before.evidenceHoldMask() != 0 || !"none".equals(before.evidenceHoldState())
                        || (before.legalHoldUntil() != null && now.isBefore(before.legalHoldUntil()))
                        || (requireGrace && (before.retirementPurgeNotBefore() == null
                        || now.isBefore(before.retirementPurgeNotBefore())))) {
                    throw new ExecutionGateException(eventType + " execution is not eligible");
                }
                try (PreparedStatement update = connection.prepareStatement("""
                        UPDATE pdf_java_signing_executions
                           SET retirement_phase=?, lock_version=lock_version+1, updated_at=?
                         WHERE operation_uuid=? AND retirement_phase=? AND retirement_epoch=?
                           AND lock_version=? AND evidence_hold_mask=0 AND evidence_hold_state='none'
                        """)) {
                    update.setString(1, newPhase);
                    update.setTimestamp(2, Timestamp.from(now));
                    update.setString(3, operationUuid.toString());
                    update.setString(4, oldPhase);
                    update.setLong(5, epoch);
                    update.setLong(6, before.lockVersion());
                    if (update.executeUpdate() != 1) throw new ExecutionGateException(eventType + " CAS lost");
                }
                ExecutionRecord after = find(connection, operationUuid).orElseThrow();
                appendRetirementEvent(connection, before, after, eventType, null);
                connection.commit();
                return after;
            } catch (Exception exception) {
                connection.rollback();
                throw exception;
            }
        }
    }

    private void lockCompletedOperation(Connection connection, UUID operationUuid) throws Exception {
        try (PreparedStatement statement = connection.prepareStatement(
                "SELECT operation_uuid FROM pdf_signing_operations WHERE operation_uuid=? AND state='completed' FOR UPDATE")) {
            statement.setString(1, operationUuid.toString());
            try (ResultSet result = statement.executeQuery()) {
                if (!result.next()) throw new ExecutionGateException("RESULT_RETIRE owning operation is not completed");
            }
        }
    }

    private RetirementAuthorization retirementAuthorization(Connection connection, UUID operationUuid) throws Exception {
        try (PreparedStatement statement = connection.prepareStatement("""
                SELECT java_gate_version, state, result_retirement_not_before,
                       result_retirement_authorization_expires_at,
                       result_retirement_authorization_manifest,
                       result_retirement_authorization_hash
                  FROM pdf_signing_operations WHERE operation_uuid=? FOR UPDATE
                """)) {
            statement.setString(1, operationUuid.toString());
            try (ResultSet result = statement.executeQuery()) {
                if (!result.next() || !"completed".equals(result.getString("state"))) {
                    throw new ExecutionGateException("RESULT_RETIRE operation is not completed");
                }
                String manifestJson = result.getString("result_retirement_authorization_manifest");
                if (manifestJson == null) throw new ExecutionGateException("RESULT_RETIRE authorization is missing");
                Map<String, Object> manifest = JSON.readValue(manifestJson, new TypeReference<Map<String, Object>>() {});
                return new RetirementAuthorization(
                        result.getLong("java_gate_version"),
                        instant(result, "result_retirement_not_before"),
                        instant(result, "result_retirement_authorization_expires_at"),
                        manifestJson,
                        result.getString("result_retirement_authorization_hash"),
                        manifest
                );
            }
        }
    }

    private void validateRetirementAuthorization(
            RetirementAuthorization authorization,
            ExecutionRecord execution,
            Instant now
    ) throws Exception {
        ObjectMapper canonical = JSON.copy().configure(SerializationFeature.ORDER_MAP_ENTRIES_BY_KEYS, true);
        String calculated = HEX.formatHex(MessageDigest.getInstance("SHA-256")
                .digest(canonical.writeValueAsBytes(authorization.manifest())));
        Map<String, Object> manifest = authorization.manifest();
        if (authorization.hash() == null || !authorization.hash().equals(calculated)
                || authorization.notBefore() == null || now.isBefore(authorization.notBefore())
                || authorization.expiresAt() == null || !now.isBefore(authorization.expiresAt())
                || !execution.operationUuid().toString().equals(manifest.get("operation_uuid"))
                || !execution.resultPath().equals(manifest.get("execution_result_path"))
                || !execution.resultSha256().equals(manifest.get("execution_result_sha256"))
                || execution.resultSize() != ((Number) manifest.getOrDefault("execution_result_size", -1)).longValue()
                || ((Number) manifest.getOrDefault("grace_seconds", 0)).longValue() < 60) {
            throw new ExecutionGateException("RESULT_RETIRE authorization snapshot is invalid");
        }
    }

    private void retirementGate(Connection connection, UUID operationUuid, RetirementAuthorization authorization)
            throws Exception {
        try (PreparedStatement gate = connection.prepareStatement("""
                UPDATE pdf_signing_operations SET java_gate_version=java_gate_version+1
                 WHERE operation_uuid=? AND java_gate_version=? AND state='completed'
                   AND result_retirement_authorization_hash=?
                   AND result_retirement_not_before<=CURRENT_TIMESTAMP
                   AND result_retirement_authorization_expires_at>CURRENT_TIMESTAMP
                """)) {
            gate.setString(1, operationUuid.toString());
            gate.setLong(2, authorization.gateVersion());
            gate.setString(3, authorization.hash());
            if (gate.executeUpdate() != 1) throw new ExecutionGateException("RESULT_RETIRE gate rejected authorization");
        }
    }

    private void appendRetirementEvent(
            Connection connection,
            ExecutionRecord before,
            ExecutionRecord after,
            String eventType,
            String errorCode
    ) throws Exception {
        Instant now = Instant.now();
        String material = String.join("|", before.operationUuid().toString(), eventType,
                before.retirementPhase(), after.retirementPhase(), Long.toString(after.retirementEpoch()),
                Long.toString(before.lockVersion()), Long.toString(after.lockVersion()), now.toString());
        try (PreparedStatement statement = connection.prepareStatement("""
                INSERT INTO pdf_java_signing_execution_events (
                    operation_uuid, attempt_number, event_type, old_state, new_state,
                    old_retirement_phase, new_retirement_phase, old_lock_version, new_lock_version,
                    authorized_lease_epoch, retirement_epoch, error_code, event_at, event_hash
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                """)) {
            statement.setString(1, before.operationUuid().toString());
            statement.setInt(2, after.attemptNumber());
            statement.setString(3, eventType);
            statement.setString(4, before.state());
            statement.setString(5, after.state());
            statement.setString(6, before.retirementPhase());
            statement.setString(7, after.retirementPhase());
            statement.setLong(8, before.lockVersion());
            statement.setLong(9, after.lockVersion());
            statement.setLong(10, after.authorizedLeaseEpoch());
            statement.setLong(11, after.retirementEpoch());
            statement.setString(12, errorCode);
            statement.setTimestamp(13, Timestamp.from(now));
            statement.setString(14, HEX.formatHex(MessageDigest.getInstance("SHA-256")
                    .digest(material.getBytes(StandardCharsets.UTF_8))));
            statement.executeUpdate();
        }
    }

    private record RetirementAuthorization(
            long gateVersion,
            Instant notBefore,
            Instant expiresAt,
            String manifestJson,
            String hash,
            Map<String, Object> manifest
    ) {
        long graceSeconds() {
            return ((Number) manifest.get("grace_seconds")).longValue();
        }
    }

    public boolean readinessProbe() {
        try {
            properties.requireReadyConfiguration();
            try (Connection connection = connection();
                 PreparedStatement statement = connection.prepareStatement(
                         "SELECT COUNT(*) FROM pdf_signing_policy_versions")) {
                statement.executeQuery().close();
                return true;
            }
        } catch (Exception exception) {
            return false;
        }
    }

    public ExecutionRecord requireMatchingExecution(ExecutionReadClaim claim) throws Exception {
        properties.requireReadyConfiguration();
        try (Connection connection = connection()) {
            ExecutionRecord execution = find(connection, claim.operationUuid())
                    .orElseThrow(() -> new ExecutionGateException("Execution does not exist"));
            if (!execution.operationInputManifestHash().equals(claim.operationInputManifestHash())
                    || !execution.inputFingerprint().equals(claim.inputFingerprint())
                    || !execution.policyHash().equals(claim.policyHash())) {
                throw new ExecutionGateException("Execution read metadata does not match the immutable ledger");
            }
            validateExecutionReadLease(connection, claim, execution);
            return execution;
        }
    }

    public ExecutionStorage.OpenResult openVerifiedResult(
            ExecutionReadClaim claim,
            ExecutionStorage storage
    ) throws Exception {
        properties.requireReadyConfiguration();
        try (Connection connection = connection()) {
            connection.setAutoCommit(false);
            try {
                long gateVersion;
                try (PreparedStatement select = connection.prepareStatement(
                        "SELECT java_gate_version FROM pdf_signing_operations WHERE operation_uuid=?")) {
                    select.setString(1, claim.operationUuid().toString());
                    try (ResultSet result = select.executeQuery()) {
                        if (!result.next()) {
                            throw new ExecutionGateException("RESULT_READ operation does not exist");
                        }
                        gateVersion = result.getLong(1);
                    }
                }
                try (PreparedStatement gate = connection.prepareStatement("""
                        UPDATE pdf_signing_operations
                           SET java_gate_version=java_gate_version+1
                         WHERE operation_uuid=? AND java_gate_version=?
                           AND action='fill_signature_field'
                           AND (
                               state='completed'
                               OR (state='manual_review' AND lease_owner IS NULL)
                               OR (state IN ('processing','promoted') AND lease_epoch=?
                                   AND lease_owner IS NOT NULL AND lease_expires_at>CURRENT_TIMESTAMP)
                           )
                           AND operation_input_manifest_hash=? AND input_fingerprint=? AND policy_hash=?
                        """)) {
                    gate.setString(1, claim.operationUuid().toString());
                    gate.setLong(2, gateVersion);
                    gate.setLong(3, claim.leaseEpoch());
                    gate.setString(4, claim.operationInputManifestHash());
                    gate.setString(5, claim.inputFingerprint());
                    gate.setString(6, claim.policyHash());
                    if (gate.executeUpdate() != 1) {
                        throw new ExecutionGateException("RESULT_READ gate rejected the operation snapshot");
                    }
                }
                ExecutionRecord before = findForUpdate(connection, claim.operationUuid());
                if (!"completed".equals(before.state())
                        || !"available".equals(before.resultIntegrityState())
                        || !before.operationInputManifestHash().equals(claim.operationInputManifestHash())
                        || !before.inputFingerprint().equals(claim.inputFingerprint())
                        || !before.policyHash().equals(claim.policyHash())) {
                    throw new ExecutionGateException("RESULT_READ execution is not available");
                }
                validateExecutionReadLease(connection, claim, before);

                ExecutionStorage.OpenResult opened;
                try {
                    opened = storage.openVerified(before);
                } catch (ExecutionStorage.ResultMissingException exception) {
                    markResultIntegrity(connection, before, "missing", "RESULT_BYTES_MISSING");
                    connection.commit();
                    throw exception;
                } catch (ExecutionStorage.ResultBreachedException exception) {
                    markResultIntegrity(connection, before, "breached", "RESULT_BYTES_BREACHED");
                    connection.commit();
                    throw exception;
                }

                Instant now = Instant.now();
                try (PreparedStatement update = connection.prepareStatement("""
                        UPDATE pdf_java_signing_executions
                           SET result_last_verified_at=?, lock_version=lock_version+1, updated_at=?
                         WHERE operation_uuid=? AND state='completed' AND result_integrity_state='available'
                           AND input_fingerprint=? AND policy_hash=? AND lock_version=?
                        """)) {
                    update.setTimestamp(1, Timestamp.from(now));
                    update.setTimestamp(2, Timestamp.from(now));
                    update.setString(3, claim.operationUuid().toString());
                    update.setString(4, claim.inputFingerprint());
                    update.setString(5, claim.policyHash());
                    update.setLong(6, before.lockVersion());
                    if (update.executeUpdate() != 1) {
                        opened.input().close();
                        throw new ExecutionGateException("RESULT_READ verification CAS lost");
                    }
                }
                ExecutionRecord after = find(connection, claim.operationUuid()).orElseThrow();
                appendEvent(connection, claim.operationUuid(), after.attemptNumber(), "RESULT_VERIFIED",
                        before.state(), after.state(), before.lockVersion(), after.lockVersion(), null);
                connection.commit();
                return opened;
            } catch (Exception exception) {
                if (!connection.getAutoCommit()) {
                    try {
                        connection.rollback();
                    } catch (SQLException ignored) {
                        // The original failure is authoritative.
                    }
                }
                throw exception;
            }
        }
    }

    private void validateExecutionReadLease(
            Connection connection,
            ExecutionReadClaim claim,
            ExecutionRecord execution
    ) throws Exception {
        try (PreparedStatement statement = connection.prepareStatement("""
                SELECT state, lease_epoch, lease_owner, lease_expires_at
                  FROM pdf_signing_operations
                 WHERE operation_uuid=?
                """)) {
            statement.setString(1, claim.operationUuid().toString());
            try (ResultSet result = statement.executeQuery()) {
                if (!result.next()) {
                    throw new ExecutionGateException("Execution read operation does not exist");
                }
                String operationState = result.getString("state");
                long operationLeaseEpoch = result.getLong("lease_epoch");
                String operationLeaseOwner = result.getString("lease_owner");
                Timestamp operationLeaseExpiresAt = result.getTimestamp("lease_expires_at");
                boolean completedOperation = "completed".equals(operationState)
                        && operationLeaseEpoch == claim.leaseEpoch();
                boolean forensicManualReview = "manual_review".equals(operationState)
                        && operationLeaseOwner == null
                        && execution.authorizedLeaseEpoch() == claim.leaseEpoch();
                boolean currentWorkerLease = Set.of("processing", "promoted").contains(operationState)
                        && operationLeaseEpoch == claim.leaseEpoch()
                        && operationLeaseOwner != null
                        && operationLeaseExpiresAt != null
                        && Instant.now().isBefore(operationLeaseExpiresAt.toInstant());
                if (!completedOperation && !forensicManualReview && !currentWorkerLease) {
                    throw new ExecutionGateException("Execution read lease is not current");
                }
            }
        }
    }

    private void markResultIntegrity(
            Connection connection,
            ExecutionRecord before,
            String integrityState,
            String errorCode
    ) throws Exception {
        try (PreparedStatement update = connection.prepareStatement("""
                UPDATE pdf_java_signing_executions
                   SET result_integrity_state=?, result_integrity_error_code=?,
                       lock_version=lock_version+1, updated_at=CURRENT_TIMESTAMP
                 WHERE operation_uuid=? AND state='completed' AND result_integrity_state='available'
                   AND input_fingerprint=? AND policy_hash=? AND lock_version=?
                """)) {
            update.setString(1, integrityState);
            update.setString(2, errorCode);
            update.setString(3, before.operationUuid().toString());
            update.setString(4, before.inputFingerprint());
            update.setString(5, before.policyHash());
            update.setLong(6, before.lockVersion());
            if (update.executeUpdate() != 1) {
                throw new ExecutionGateException("RESULT_INTEGRITY CAS lost");
            }
        }
        ExecutionRecord after = find(connection, before.operationUuid()).orElseThrow();
        appendEvent(connection, before.operationUuid(), after.attemptNumber(),
                "RESULT_" + integrityState.toUpperCase(), before.state(), after.state(),
                before.lockVersion(), after.lockVersion(), errorCode);
    }

    private ExecutionRecord transition(
            UUID operationUuid,
            String oldState,
            String newState,
            String eventType,
            String errorCode,
            StatementBinder binder,
            String sql
    ) throws Exception {
        try (Connection connection = connection()) {
            connection.setAutoCommit(false);
            try {
                ExecutionRecord before = findForUpdate(connection, operationUuid);
                if (!oldState.equals(before.state())) {
                    connection.commit();
                    return before;
                }
                try (PreparedStatement statement = connection.prepareStatement(sql)) {
                    binder.bind(statement);
                    if (statement.executeUpdate() != 1) {
                        throw new ExecutionGateException(eventType + " CAS lost");
                    }
                }
                ExecutionRecord after = find(connection, operationUuid).orElseThrow();
                appendEvent(connection, operationUuid, after.attemptNumber(), eventType,
                        oldState, newState, before.lockVersion(), after.lockVersion(), errorCode);
                connection.commit();
                return after;
            } catch (Exception exception) {
                connection.rollback();
                throw exception;
            }
        }
    }

    private PolicySnapshot policy(Connection connection, OperationClaim claim) throws Exception {
        try (PreparedStatement statement = connection.prepareStatement("""
                SELECT id, version_uuid, policy_hash, config_bundle_hash, pades_profile,
                       java_execution_timeout_seconds, pre_private_key_max_attempts,
                       pre_private_key_retry_backoff_seconds, pre_private_key_retryable_error_codes,
                       generated_revision_max_bytes, max_signature_increment_bytes
                  FROM pdf_signing_policy_versions
                 WHERE id=? AND immutable_at IS NOT NULL
                """)) {
            statement.setLong(1, claim.policyVersionId());
            try (ResultSet result = statement.executeQuery()) {
                if (!result.next()
                        || !claim.policyVersionUuid().toString().equals(result.getString("version_uuid"))
                        || !claim.policyHash().equals(result.getString("policy_hash"))
                        || !claim.configBundleHash().equals(result.getString("config_bundle_hash"))
                        || !"B-T".equals(result.getString("pades_profile"))) {
                    throw new ExecutionGateException("The immutable PAdES-B-T policy snapshot does not match");
                }
                List<Integer> backoff = JSON.readValue(
                        result.getString("pre_private_key_retry_backoff_seconds"),
                        new TypeReference<List<Integer>>() {}
                );
                Set<String> retryableCodes = JSON.readValue(
                        result.getString("pre_private_key_retryable_error_codes"),
                        new TypeReference<Set<String>>() {}
                );
                if (backoff.isEmpty() || backoff.stream().anyMatch(value -> value == null || value < 1)) {
                    throw new ExecutionGateException("Pre-key retry backoff policy is invalid");
                }
                return new PolicySnapshot(
                        result.getLong("id"),
                        UUID.fromString(result.getString("version_uuid")),
                        result.getString("policy_hash"),
                        result.getString("config_bundle_hash"),
                        result.getInt("java_execution_timeout_seconds"),
                        result.getInt("pre_private_key_max_attempts"),
                        List.copyOf(backoff),
                        Set.copyOf(retryableCodes),
                        result.getLong("generated_revision_max_bytes"),
                        result.getLong("max_signature_increment_bytes")
                );
            }
        }
    }

    private void validateExistingClaim(
            Connection connection,
            ExecutionRecord execution,
            OperationClaim claim
    ) throws Exception {
        boolean retryCandidate = "failed_before_private_key".equals(execution.state())
                && "same_operation".equals(execution.retryability());
        if ((!retryCandidate && execution.authorizedLeaseEpoch() != claim.leaseEpoch())
                || !execution.operationInputManifestHash().equals(claim.operationInputManifestHash())
                || !execution.inputFingerprint().equals(claim.inputFingerprint())
                || !execution.policyHash().equals(claim.policyHash())) {
            throw new ExecutionGateException("Existing execution does not match the immutable operation claim");
        }
        try (PreparedStatement statement = connection.prepareStatement("""
                SELECT 1
                  FROM pdf_signing_operations
                 WHERE operation_uuid=?
                   AND action='fill_signature_field'
                   AND operation_input_manifest_hash=? AND input_fingerprint=?
                   AND expected_source_sha256=? AND signing_policy_version_id=?
                   AND policy_hash=? AND config_bundle_hash=?
                   AND appearance_manifest_hash=? AND appearance_sha256=?
                   AND pdf_signature_role=? AND target_field_name=?
                   AND expected_certificate_fingerprint=? AND field_lock_policy_hash=?
                """)) {
            bindImmutableOperationSnapshot(statement, claim, 1);
            try (ResultSet result = statement.executeQuery()) {
                if (!result.next()) {
                    throw new ExecutionGateException("Existing execution operation snapshot was modified");
                }
            }
        }
    }

    private void signAuthorizeGate(Connection connection, OperationClaim claim) throws Exception {
        signAuthorizeGateAtVersion(connection, claim, claim.javaGateVersion() + 1);
    }

    private void signAuthorizeGateAtVersion(
            Connection connection,
            OperationClaim claim,
            long expectedGateVersion
    ) throws Exception {
        try (PreparedStatement statement = connection.prepareStatement("""
                UPDATE pdf_signing_operations
                   SET java_gate_version=java_gate_version+1
                 WHERE operation_uuid=? AND java_gate_version=? AND lease_epoch=?
                   AND state='processing' AND stage IN ('java_call','java_polling')
                   AND action='fill_signature_field' AND lease_expires_at>CURRENT_TIMESTAMP
                   AND document_evidence_hold_mask=0
                   AND cancellation_requested_at IS NULL
                   AND operation_input_manifest_hash=? AND input_fingerprint=?
                   AND expected_source_sha256=? AND signing_policy_version_id=?
                   AND policy_hash=? AND config_bundle_hash=?
                   AND appearance_manifest_hash=? AND appearance_sha256=?
                   AND pdf_signature_role=? AND target_field_name=?
                   AND expected_certificate_fingerprint=? AND field_lock_policy_hash=?
                """)) {
            statement.setString(1, claim.operationUuid().toString());
            statement.setLong(2, expectedGateVersion);
            statement.setLong(3, claim.leaseEpoch());
            bindImmutableOperationSnapshotWithoutUuid(statement, claim, 4);
            if (statement.executeUpdate() != 1) {
                throw new ExecutionGateException("SIGN_AUTHORIZE private-key gate rejected the operation snapshot");
            }
        }
    }

    private static void bindImmutableOperationSnapshot(
            PreparedStatement statement,
            OperationClaim claim,
            int start
    ) throws SQLException {
        statement.setString(start, claim.operationUuid().toString());
        bindImmutableOperationSnapshotWithoutUuid(statement, claim, start + 1);
    }

    private static void bindImmutableOperationSnapshotWithoutUuid(
            PreparedStatement statement,
            OperationClaim claim,
            int start
    ) throws SQLException {
        statement.setString(start, claim.operationInputManifestHash());
        statement.setString(start + 1, claim.inputFingerprint());
        statement.setString(start + 2, claim.expectedSourceSha256());
        statement.setLong(start + 3, claim.policyVersionId());
        statement.setString(start + 4, claim.policyHash());
        statement.setString(start + 5, claim.configBundleHash());
        statement.setString(start + 6, claim.appearanceManifestHash());
        statement.setString(start + 7, claim.appearanceSha256());
        statement.setString(start + 8, claim.pdfSignatureRole());
        statement.setString(start + 9, claim.targetFieldName());
        statement.setString(start + 10, claim.expectedCertificateFingerprint());
        statement.setString(start + 11, claim.fieldLockPolicyHash());
    }

    private Optional<ExecutionRecord> find(Connection connection, UUID operationUuid) throws SQLException {
        try (PreparedStatement statement = connection.prepareStatement(
                "SELECT * FROM pdf_java_signing_executions WHERE operation_uuid=?")) {
            statement.setString(1, operationUuid.toString());
            try (ResultSet result = statement.executeQuery()) {
                return result.next() ? Optional.of(map(result)) : Optional.empty();
            }
        }
    }

    private ExecutionRecord findForUpdate(Connection connection, UUID operationUuid) throws SQLException {
        try (PreparedStatement statement = connection.prepareStatement(
                "SELECT * FROM pdf_java_signing_executions WHERE operation_uuid=? FOR UPDATE")) {
            statement.setString(1, operationUuid.toString());
            try (ResultSet result = statement.executeQuery()) {
                if (!result.next()) {
                    throw new SQLException("Execution does not exist: " + operationUuid);
                }
                return map(result);
            }
        }
    }

    private static ExecutionRecord map(ResultSet result) throws SQLException {
        return new ExecutionRecord(
                UUID.fromString(result.getString("operation_uuid")),
                result.getString("operation_input_manifest_hash"),
                result.getString("input_fingerprint"),
                result.getString("policy_hash"),
                result.getInt("attempt_number"),
                result.getInt("attempt_count"),
                result.getInt("max_attempts"),
                result.getString("state"),
                result.getString("retryability"),
                result.getLong("authorized_lease_epoch"),
                result.getLong("lock_version"),
                instant(result, "claimed_at"),
                instant(result, "execution_started_at"),
                instant(result, "private_key_started_at"),
                instant(result, "execution_deadline_at"),
                instant(result, "next_retry_at"),
                instant(result, "retry_exhausted_at"),
                instant(result, "completed_at"),
                instant(result, "terminal_at"),
                result.getString("error_code"),
                result.getString("result_path"),
                result.getString("result_sha256"),
                result.getLong("result_size"),
                result.getString("result_file_key"),
                result.getString("validation_report_hash"),
                result.getString("result_integrity_state"),
                instant(result, "result_last_verified_at"),
                result.getString("result_integrity_error_code"),
                instant(result, "retention_until"),
                result.getString("retirement_phase"),
                result.getLong("retirement_epoch"),
                result.getString("retirement_staged_path"),
                instant(result, "retirement_started_at"),
                instant(result, "retirement_purge_not_before"),
                result.getLong("evidence_hold_mask"),
                result.getString("evidence_hold_state"),
                instant(result, "legal_hold_until"),
                instant(result, "bytes_deleted_at")
        );
    }

    private void appendEvent(
            Connection connection,
            UUID operationUuid,
            int attemptNumber,
            String eventType,
            String oldState,
            String newState,
            long oldLockVersion,
            long newLockVersion,
            String errorCode
    ) throws Exception {
        Instant now = Instant.now();
        String eventMaterial = String.join("|",
                operationUuid.toString(),
                Integer.toString(attemptNumber),
                eventType,
                String.valueOf(oldState),
                String.valueOf(newState),
                Long.toString(oldLockVersion),
                Long.toString(newLockVersion),
                String.valueOf(errorCode),
                now.toString()
        );
        String eventHash = HEX.formatHex(MessageDigest.getInstance("SHA-256")
                .digest(eventMaterial.getBytes(StandardCharsets.UTF_8)));
        try (PreparedStatement statement = connection.prepareStatement("""
                INSERT INTO pdf_java_signing_execution_events (
                    operation_uuid, attempt_number, event_type, old_state, new_state,
                    old_lock_version, new_lock_version, error_code, event_at, event_hash
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                """)) {
            statement.setString(1, operationUuid.toString());
            statement.setInt(2, attemptNumber);
            statement.setString(3, eventType);
            statement.setString(4, oldState);
            statement.setString(5, newState);
            statement.setLong(6, oldLockVersion);
            statement.setLong(7, newLockVersion);
            statement.setString(8, errorCode);
            statement.setTimestamp(9, Timestamp.from(now));
            statement.setString(10, eventHash);
            statement.executeUpdate();
        }
    }

    private Connection connection() throws SQLException {
        return DriverManager.getConnection(properties.jdbcUrl(), properties.username(), properties.password());
    }

    private static Instant instant(ResultSet result, String column) throws SQLException {
        Timestamp timestamp = result.getTimestamp(column);
        return timestamp == null ? null : timestamp.toInstant();
    }

    @FunctionalInterface
    private interface StatementBinder {
        void bind(PreparedStatement statement) throws SQLException;
    }

    public record OperationClaim(
            UUID operationUuid,
            long javaGateVersion,
            long leaseEpoch,
            String operationInputManifestHash,
            String inputFingerprint,
            String expectedSourceSha256,
            long policyVersionId,
            UUID policyVersionUuid,
            String policyHash,
            String configBundleHash,
            String appearanceManifestHash,
            String appearanceSha256,
            String pdfSignatureRole,
            String targetFieldName,
            String expectedCertificateFingerprint,
            String fieldLockPolicyHash
    ) {}

    public record ExecutionReadClaim(
            UUID operationUuid,
            long leaseEpoch,
            String operationInputManifestHash,
            String inputFingerprint,
            String policyHash
    ) {}

    public record PolicySnapshot(
            long id,
            UUID versionUuid,
            String policyHash,
            String configBundleHash,
            int executionTimeoutSeconds,
            int maximumAttempts,
            List<Integer> retryBackoffSeconds,
            Set<String> retryableErrorCodes,
            long maximumGeneratedRevisionBytes,
            long maximumSignatureIncrementBytes
    ) {}

    public record ClaimResult(ExecutionRecord execution, boolean newlyClaimed, PolicySnapshot policy) {}

    public static final class ExecutionGateException extends IllegalStateException {
        public ExecutionGateException(String message) {
            super(message);
        }
    }
}
