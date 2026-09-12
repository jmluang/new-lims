package com.luang.pdfsigner.execution;

import static org.assertj.core.api.Assertions.assertThat;
import static org.mockito.ArgumentMatchers.anyString;
import static org.mockito.ArgumentMatchers.any;
import static org.mockito.ArgumentMatchers.eq;
import static org.mockito.Mockito.mock;
import static org.mockito.Mockito.never;
import static org.mockito.Mockito.verify;
import static org.mockito.Mockito.when;

import com.fasterxml.jackson.databind.ObjectMapper;
import com.luang.pdfsigner.service.Pkcs12SigningKeyProvider;
import com.luang.pdfsigner.signature.DocumentSigningCertificatePolicy;
import com.luang.pdfsigner.signature.IncrementalSigningService;
import com.luang.pdfsigner.signature.PdfSignatureVerifier;
import java.time.Instant;
import java.util.List;
import java.util.UUID;
import org.junit.jupiter.api.BeforeEach;
import org.junit.jupiter.api.Test;
import org.junit.jupiter.api.io.TempDir;

class SigningExecutionServiceTest {
    @TempDir
    java.nio.file.Path temporaryDirectory;

    private SigningExecutionRepository repository;
    private ExecutionStorage storage;
    private PdfSignatureVerifier verifier;
    private SigningExecutionService service;
    private SigningExecutionRepository.ExecutionReadClaim claim;
    private ExecutionRecord executing;
    private UUID operationUuid;
    private com.luang.pdfsigner.service.PdfWorkLimiter limiter;

    @BeforeEach
    void setUp() throws Exception {
        repository = mock(SigningExecutionRepository.class);
        storage = new ExecutionStorage(new ExecutionLedgerProperties(
                true,
                "jdbc:h2:mem:unused",
                "sa",
                "test",
                temporaryDirectory.toString()
        ));
        verifier = mock(PdfSignatureVerifier.class);
        limiter = new com.luang.pdfsigner.service.PdfWorkLimiter(1);
        service = new SigningExecutionService(
                repository,
                storage,
                new IncrementalSigningService(
                        new Pkcs12SigningKeyProvider(),
                        null,
                        new DocumentSigningCertificatePolicy("")
                ),
                verifier,
                new ObjectMapper(),
                limiter
        );
        operationUuid = UUID.randomUUID();
        claim = new SigningExecutionRepository.ExecutionReadClaim(
                operationUuid,
                7,
                "a".repeat(64),
                "b".repeat(64),
                "c".repeat(64)
        );
        executing = execution("executing");
        when(repository.requireMatchingExecution(claim)).thenReturn(executing);
        when(repository.recoveryMaximumBytes(operationUuid)).thenReturn(1024L * 1024L);
    }

    @Test
    void deadlineRecoveryPromotesVerifiedPersistentResultAndCompletesLedger() throws Exception {
        byte[] bytes = "%PDF-1.7 recovered signed revision".getBytes(java.nio.charset.StandardCharsets.US_ASCII);
        java.nio.file.Path temporary = temporaryDirectory.resolve("." + operationUuid + ".tmp-recovery");
        java.nio.file.Files.write(temporary, bytes);
        ExecutionStorage.RecoveryCandidate candidate = storage.findRecoveryCandidate(operationUuid, 1024L * 1024L);
        assertThat(candidate.canonical()).isFalse();
        PdfSignatureVerifier.VerificationReport report = validSignedReport();
        ExecutionRecord completed = execution("completed");
        when(verifier.verify(bytes)).thenReturn(report);
        when(repository.markCompleted(
                eq(operationUuid),
                any(ExecutionStorage.StoredResult.class),
                anyString()
        )).thenReturn(completed);

        assertThat(service.status(claim)).isSameAs(completed);
        assertThat(java.nio.file.Files.exists(temporary)).isFalse();
        assertThat(java.nio.file.Files.readAllBytes(temporaryDirectory.resolve(operationUuid + ".pdf")))
                .isEqualTo(bytes);
        verify(repository, never()).markFailure(eq(operationUuid), eq("outcome_unknown"), anyString(), anyString());
    }

    @Test
    void deadlineRecoveryRejectsUnsignedPdfAsKnownPostKeyFailure() throws Exception {
        byte[] bytes = "%PDF-1.7 unsigned".getBytes(java.nio.charset.StandardCharsets.US_ASCII);
        storage.persist(operationUuid, bytes, 1024L * 1024L);
        ExecutionStorage.RecoveryCandidate candidate = storage.findRecoveryCandidate(operationUuid, 1024L * 1024L);
        ExecutionRecord failed = execution("failed_after_private_key_known");
        when(verifier.verify(bytes)).thenReturn(new PdfSignatureVerifier.VerificationReport(
                "valid", null, List.of(), null
        ));
        when(repository.markFailure(
                operationUuid,
                "failed_after_private_key_known",
                "none",
                "RECOVERY_RESULT_VERIFICATION_FAILED"
        )).thenReturn(failed);

        assertThat(service.status(claim)).isSameAs(failed);
        assertThat(java.nio.file.Files.exists(candidate.path())).isTrue();
    }

    @Test
    void deadlineWithoutPersistentResultRemainsOutcomeUnknown() throws Exception {
        ExecutionRecord unknown = execution("outcome_unknown");
        when(repository.markFailure(
                operationUuid,
                "outcome_unknown",
                "none",
                "EXECUTION_DEADLINE_OUTCOME_UNKNOWN"
        )).thenReturn(unknown);

        assertThat(service.status(claim)).isSameAs(unknown);
    }

    @Test
    void busySigningRecordsPreKeyFailureWithoutReadingUploadsOrCallingTheKey() throws Exception {
        var operation = operationClaim();
        var policy = policy();
        var failed = execution("failed_before_private_key");
        when(repository.claim(operation)).thenReturn(new SigningExecutionRepository.ClaimResult(executing, true, policy));
        when(repository.markExecuting(eq(operationUuid), any())).thenReturn(executing);
        when(repository.markPrePrivateKeyFailure(operationUuid, "PDF_BUSY", policy)).thenReturn(failed);
        try (var permit = limiter.acquire()) {
            assertThat(service.execute(operation,
                    () -> { throw new AssertionError("PDF must remain on disk while busy"); },
                    () -> { throw new AssertionError("Appearance must remain on disk while busy"); },
                    null)).isSameAs(failed);
        }
        verify(repository, never()).markPrivateKeyStarted(any());
        verify(repository).markPrePrivateKeyFailure(operationUuid, "PDF_BUSY", policy);
    }

    @Test
    void uploadFailureReleasesAdmissionAndKeepsThePreKeyLedgerBoundary() throws Exception {
        var operation = operationClaim();
        var policy = policy();
        var failed = execution("failed_before_private_key");
        when(repository.claim(operation)).thenReturn(new SigningExecutionRepository.ClaimResult(executing, true, policy));
        when(repository.markExecuting(eq(operationUuid), any())).thenReturn(executing);
        when(repository.markPrePrivateKeyFailure(operationUuid, "PRE_KEY_VALIDATION_FAILED", policy)).thenReturn(failed);
        assertThat(service.execute(operation,
                () -> { throw new java.io.IOException("Upload unavailable"); }, () -> new byte[0], null)).isSameAs(failed);
        verify(repository, never()).markPrivateKeyStarted(any());
        try (var permit = limiter.acquire()) {
            assertThat(permit).isNotNull();
        }
    }

    @Test
    void duplicateCompletedExecutionDoesNotReadUploadsOrRequireAdmission() throws Exception {
        var operation = operationClaim();
        var completed = execution("completed");
        when(repository.claim(operation)).thenReturn(new SigningExecutionRepository.ClaimResult(completed, false, policy()));
        try (var permit = limiter.acquire()) {
            assertThat(service.execute(operation, () -> { throw new AssertionError("Duplicate read"); },
                    () -> { throw new AssertionError("Duplicate read"); }, null)).isSameAs(completed);
        }
        verify(repository, never()).markExecuting(any(), any());
    }

    @Test
    void busyDeadlineRecoveryDoesNotReclassifyOrDeleteAnUncertainResult() throws Exception {
        try (var permit = limiter.acquire()) {
            org.assertj.core.api.Assertions.assertThatThrownBy(() -> service.status(claim))
                    .isInstanceOf(org.springframework.web.server.ResponseStatusException.class)
                    .hasMessageContaining("PDF_BUSY");
        }
        verify(repository, never()).markFailure(any(), anyString(), anyString(), anyString());
        verify(repository, never()).recoveryMaximumBytes(any());
    }

    private SigningExecutionRepository.OperationClaim operationClaim() {
        return new SigningExecutionRepository.OperationClaim(operationUuid, 1, 7, "a".repeat(64),
                "b".repeat(64), "c".repeat(64), 1, UUID.randomUUID(), "d".repeat(64), "e".repeat(64),
                "f".repeat(64), "a".repeat(64), "certification_p2", "inspector", "b".repeat(64), "c".repeat(64));
    }

    private SigningExecutionRepository.PolicySnapshot policy() {
        return new SigningExecutionRepository.PolicySnapshot(1, UUID.randomUUID(), "d".repeat(64),
                "e".repeat(64), 60, 3, List.of(1, 2), java.util.Set.of("PDF_BUSY"), 24L * 1024 * 1024, 1024 * 1024);
    }

    private PdfSignatureVerifier.VerificationReport validSignedReport() {
        return new PdfSignatureVerifier.VerificationReport(
                "valid",
                2,
                List.of(new PdfSignatureVerifier.SignatureVerification(
                        0,
                        "Signer",
                        "ETSI.CAdES.detached",
                        "valid",
                        "valid",
                        "valid",
                        "valid",
                        "valid",
                        "valid",
                        null
                )),
                null
        );
    }

    private ExecutionRecord execution(String state) {
        Instant now = Instant.now();
        return new ExecutionRecord(
                operationUuid,
                claim == null ? "a".repeat(64) : claim.operationInputManifestHash(),
                claim == null ? "b".repeat(64) : claim.inputFingerprint(),
                claim == null ? "c".repeat(64) : claim.policyHash(),
                1,
                1,
                3,
                state,
                "none",
                7,
                3,
                now.minusSeconds(10),
                now.minusSeconds(5),
                now.minusSeconds(2),
                now.minusSeconds(1),
                null,
                null,
                "completed".equals(state) ? now : null,
                "executing".equals(state) ? null : now,
                null,
                null,
                null,
                0,
                null,
                null,
                "completed".equals(state) ? "available" : "not_applicable",
                null,
                null,
                null,
                "none",
                0,
                null,
                null,
                null,
                0,
                "none",
                null,
                null
        );
    }
}
