package com.luang.pdfsigner.execution;

import com.fasterxml.jackson.databind.ObjectMapper;
import com.luang.pdfsigner.execution.SigningExecutionRepository.ClaimResult;
import com.luang.pdfsigner.execution.SigningExecutionRepository.OperationClaim;
import com.luang.pdfsigner.signature.IncrementalSigningService;
import com.luang.pdfsigner.signature.PdfSignatureVerifier;
import java.net.http.HttpTimeoutException;
import java.security.MessageDigest;
import java.time.Duration;
import java.time.Instant;
import java.util.HexFormat;
import java.util.UUID;
import org.slf4j.Logger;
import org.slf4j.LoggerFactory;
import org.springframework.stereotype.Service;

@Service
public final class SigningExecutionService {
    private static final Logger LOG = LoggerFactory.getLogger(SigningExecutionService.class);

    private static final HexFormat HEX = HexFormat.of();
    private final SigningExecutionRepository repository;
    private final ExecutionStorage storage;
    private final IncrementalSigningService signingService;
    private final PdfSignatureVerifier verifier;
    private final ObjectMapper objectMapper;

    public SigningExecutionService(
            SigningExecutionRepository repository,
            ExecutionStorage storage,
            IncrementalSigningService signingService,
            PdfSignatureVerifier verifier,
            ObjectMapper objectMapper
    ) {
        this.repository = repository;
        this.storage = storage;
        this.signingService = signingService;
        this.verifier = verifier;
        this.objectMapper = objectMapper;
    }

    public ExecutionRecord execute(
            OperationClaim claim,
            byte[] sourcePdf,
            byte[] appearancePng,
            IncrementalSigningService.SignCommand command
    ) throws Exception {
        ClaimResult claimed = repository.claim(claim);
        if (!claimed.newlyClaimed()) {
            if (!"failed_before_private_key".equals(claimed.execution().state())
                    || !"same_operation".equals(claimed.execution().retryability())) {
                return claimed.execution();
            }
            repository.authorizeRetry(claim, claimed.execution().lockVersion());
        }

        boolean privateKeyStarted = false;
        try {
            ExecutionRecord executing = repository.markExecuting(
                    claim.operationUuid(),
                    Duration.ofSeconds(claimed.policy().executionTimeoutSeconds())
            );
            if (!"executing".equals(executing.state())) {
                return executing;
            }
            preflight(claim, claimed, sourcePdf, appearancePng, command);
            repository.markPrivateKeyStarted(claim);
            privateKeyStarted = true;
            byte[] signed = signingService.signExistingField(
                    sourcePdf,
                    appearancePng,
                    command,
                    claim.expectedCertificateFingerprint()
            );
            PdfSignatureVerifier.VerificationReport verification = verifier.verify(signed);
            if (!isAcceptableGeneratedRevision(verification)) {
                throw new KnownPostPrivateKeyFailure("Generated revision failed layered PAdES-B-T verification");
            }
            long maximumBytes = Math.min(
                    claimed.policy().maximumGeneratedRevisionBytes(),
                    (long) sourcePdf.length + claimed.policy().maximumSignatureIncrementBytes()
            );
            ExecutionStorage.StoredResult stored = storage.persist(claim.operationUuid(), signed, maximumBytes);
            String validationReportHash = sha256(objectMapper.writeValueAsBytes(verification));
            return repository.markCompleted(claim.operationUuid(), stored, validationReportHash);
        } catch (Exception exception) {
            if (!privateKeyStarted) {
                String errorCode = stableErrorCode(exception, "PRE_KEY_VALIDATION_FAILED");
                // Refusing before the private key is the safe outcome, but the
                // ledger only records a code. Without the cause, diagnosing why
                // a signature was refused means guessing.
                LOG.warn("Signing refused before the private key for {}: {} ({})",
                        claim.operationUuid(), errorCode, exception.toString(), exception);
                return repository.markPrePrivateKeyFailure(
                        claim.operationUuid(),
                        errorCode,
                        claimed.policy()
                );
            }
            if (isOutcomeUncertain(exception)) {
                // Leave the execution in `executing`. Deadline recovery is the
                // only authority allowed to classify a post-key ambiguous outcome.
                throw exception;
            }
            LOG.error("Signing failed after the private key for {}: {}",
                    claim.operationUuid(), exception.toString(), exception);
            return repository.markFailure(
                    claim.operationUuid(),
                    "failed_after_private_key_known",
                    "none",
                    stableErrorCode(exception, "POST_KEY_KNOWN_FAILURE")
            );
        }
    }

    public ExecutionRecord status(SigningExecutionRepository.ExecutionReadClaim claim) throws Exception {
        UUID operationUuid = claim.operationUuid();
        ExecutionRecord execution;
        try {
            execution = repository.requireMatchingExecution(claim);
        } catch (SigningExecutionRepository.ExecutionGateException exception) {
            if (repository.find(operationUuid).isEmpty()) {
                throw new ExecutionNotFoundException(operationUuid);
            }
            throw exception;
        }
        if ("executing".equals(execution.state())
                && execution.executionDeadlineAt() != null
                && !Instant.now().isBefore(execution.executionDeadlineAt())) {
            if (execution.privateKeyStartedAt() == null) {
                return repository.markFailure(
                        operationUuid,
                        "failed_before_private_key",
                        "none",
                        "EXECUTION_DEADLINE_BEFORE_PRIVATE_KEY"
                );
            }
            return recoverDeadlineResult(operationUuid);
        }
        return execution;
    }

    public ExecutionStorage.OpenResult openResult(SigningExecutionRepository.ExecutionReadClaim claim) throws Exception {
        ExecutionRecord execution = status(claim);
        if (!"completed".equals(execution.state())) {
            throw new ExecutionResultUnavailableException("Execution is not completed");
        }
        if (!"available".equals(execution.resultIntegrityState())) {
            throw new ExecutionResultUnavailableException("Execution result integrity state is not available");
        }
        return repository.openVerifiedResult(claim, storage);
    }

    private void preflight(
            OperationClaim claim,
            ClaimResult claimed,
            byte[] sourcePdf,
            byte[] appearancePng,
            IncrementalSigningService.SignCommand command
    ) throws Exception {
        // Cheapest thing that can fail, so it goes first — and it has to be here
        // rather than inside the signer, because markPrivateKeyStarted runs
        // between preflight and signing. A TSA misconfiguration caught there
        // would already be classified as a post-key failure.
        signingService.requireReadySigningConfiguration(claim.expectedCertificateFingerprint());
        if (sourcePdf.length == 0 || sourcePdf.length > 20L * 1024 * 1024) {
            throw new IllegalArgumentException("Source PDF exceeds the V1 input boundary");
        }
        if (!sha256(sourcePdf).equals(claim.expectedSourceSha256())) {
            throw new IllegalArgumentException("Source PDF SHA-256 does not match the frozen operation");
        }
        if (!sha256(appearancePng).equals(claim.appearanceSha256())) {
            throw new IllegalArgumentException("Appearance SHA-256 does not match the frozen operation");
        }
        if (!claim.targetFieldName().equals(command.fieldName())
                || !claim.pdfSignatureRole().equals(command.signatureRole())) {
            throw new IllegalArgumentException("Signature command does not match the frozen target field and role");
        }
        if (sourcePdf.length + claimed.policy().maximumSignatureIncrementBytes()
                > claimed.policy().maximumGeneratedRevisionBytes()) {
            throw new IllegalArgumentException("Projected signature revision exceeds the frozen size budget");
        }
        signingService.validateSignTarget(sourcePdf, command);
        IncrementalSigningService.Inspection inspection = signingService.inspect(sourcePdf);
        if ("certification_p2".equals(command.signatureRole())) {
            if (inspection.signatureCount() != 0 || inspection.docMdpPermission() != null) {
                throw new IllegalArgumentException("Certification input already contains a signature");
            }
        } else {
            PdfSignatureVerifier.VerificationReport verification = verifier.verify(sourcePdf);
            if (!"valid".equals(verification.documentCurrentState())) {
                throw new IllegalArgumentException("Approval input historical signatures are not fully valid");
            }
        }
    }

    private ExecutionRecord recoverDeadlineResult(UUID operationUuid) throws Exception {
        ExecutionStorage.RecoveryCandidate candidate;
        try {
            candidate = storage.findRecoveryCandidate(
                    operationUuid,
                    repository.recoveryMaximumBytes(operationUuid)
            );
        } catch (ExecutionStorage.ResultBreachedException exception) {
            return repository.markFailure(
                    operationUuid,
                    "outcome_unknown",
                    "none",
                    "RECOVERY_RESULT_IDENTITY_AMBIGUOUS"
            );
        }
        if (candidate == null) {
            return repository.markFailure(
                    operationUuid,
                    "outcome_unknown",
                    "none",
                    "EXECUTION_DEADLINE_OUTCOME_UNKNOWN"
            );
        }
        PdfSignatureVerifier.VerificationReport verification = verifier.verify(candidate.bytes());
        if (!isAcceptableGeneratedRevision(verification)) {
            return repository.markFailure(
                    operationUuid,
                    "failed_after_private_key_known",
                    "none",
                    "RECOVERY_RESULT_VERIFICATION_FAILED"
            );
        }
        try {
            ExecutionStorage.StoredResult stored = storage.promoteRecoveryCandidate(candidate);
            String validationReportHash = sha256(objectMapper.writeValueAsBytes(verification));
            return repository.markCompleted(operationUuid, stored, validationReportHash);
        } catch (ExecutionStorage.ResultBreachedException exception) {
            return repository.markFailure(
                    operationUuid,
                    "outcome_unknown",
                    "none",
                    "RECOVERY_RESULT_PROMOTION_AMBIGUOUS"
            );
        }
    }

    private static boolean isAcceptableGeneratedRevision(
            PdfSignatureVerifier.VerificationReport verification
    ) {
        return "valid".equals(verification.documentCurrentState())
                && !verification.signatures().isEmpty();
    }

    private static boolean isOutcomeUncertain(Throwable exception) {
        Throwable current = exception;
        while (current != null) {
            if (current instanceof HttpTimeoutException
                    || current instanceof java.net.ConnectException
                    || current instanceof java.net.SocketException) {
                return true;
            }
            current = current.getCause();
        }
        return false;
    }

    private static String stableErrorCode(Throwable exception, String fallback) {
        if (exception instanceof KnownPostPrivateKeyFailure) {
            return "GENERATED_REVISION_VERIFICATION_FAILED";
        }
        if (exception instanceof SigningExecutionRepository.ExecutionGateException) {
            return "EXECUTION_GATE_REJECTED";
        }
        return fallback;
    }

    private static String sha256(byte[] bytes) throws Exception {
        return HEX.formatHex(MessageDigest.getInstance("SHA-256").digest(bytes));
    }

    private static final class KnownPostPrivateKeyFailure extends IllegalStateException {
        KnownPostPrivateKeyFailure(String message) {
            super(message);
        }
    }

    public static final class ExecutionNotFoundException extends IllegalArgumentException {
        ExecutionNotFoundException(UUID operationUuid) {
            super("Execution does not exist: " + operationUuid);
        }
    }

    public static final class ExecutionResultUnavailableException extends IllegalStateException {
        ExecutionResultUnavailableException(String message) {
            super(message);
        }
    }
}
