package com.luang.pdfsigner.web;

import com.fasterxml.jackson.core.type.TypeReference;
import com.fasterxml.jackson.databind.ObjectMapper;
import com.luang.pdfsigner.signature.IncrementalSigningService;
import com.luang.pdfsigner.signature.PdfSignatureVerifier;
import com.luang.pdfsigner.execution.ExecutionRecord;
import com.luang.pdfsigner.execution.ExecutionStorage;
import com.luang.pdfsigner.execution.SigningExecutionRepository;
import com.luang.pdfsigner.execution.SigningExecutionService;
import jakarta.servlet.http.HttpServletRequest;
import java.security.MessageDigest;
import java.util.HexFormat;
import java.util.List;
import org.springframework.core.io.ByteArrayResource;
import org.springframework.http.HttpHeaders;
import org.springframework.http.HttpStatus;
import org.springframework.http.MediaType;
import org.springframework.http.ResponseEntity;
import org.springframework.web.bind.annotation.PostMapping;
import org.springframework.web.bind.annotation.GetMapping;
import org.springframework.web.bind.annotation.PathVariable;
import org.springframework.web.bind.annotation.RequestMapping;
import org.springframework.web.bind.annotation.RequestBody;
import org.springframework.web.bind.annotation.RequestPart;
import org.springframework.web.bind.annotation.RestController;
import org.springframework.web.multipart.MultipartFile;
import org.springframework.web.server.ResponseStatusException;
import org.springframework.core.io.InputStreamResource;
import java.util.Map;
import java.util.UUID;

@RestController
@RequestMapping("/internal/pdf/signatures")
public final class PdfSignatureController {
    private final IncrementalSigningService signingService;
    private final PdfSignatureVerifier signatureVerifier;
    private final ObjectMapper objectMapper;
    private final SigningExecutionService executionService;
    private final ExecutionStorage executionStorage;

    public PdfSignatureController(
            IncrementalSigningService signingService,
            PdfSignatureVerifier signatureVerifier,
            ObjectMapper objectMapper,
            SigningExecutionService executionService,
            ExecutionStorage executionStorage
    ) {
        this.signingService = signingService;
        this.signatureVerifier = signatureVerifier;
        this.objectMapper = objectMapper;
        this.executionService = executionService;
        this.executionStorage = executionStorage;
    }

    @PostMapping(value = "/inspect", consumes = MediaType.MULTIPART_FORM_DATA_VALUE)
    public IncrementalSigningService.Inspection inspect(
            @RequestPart("pdf") MultipartFile pdf,
            HttpServletRequest request
    ) throws Exception {
        return signingService.inspect(pdf.getBytes());
    }

    @PostMapping(value = "/prepare", consumes = MediaType.MULTIPART_FORM_DATA_VALUE)
    public ResponseEntity<ByteArrayResource> prepare(
            @RequestPart("pdf") MultipartFile pdf,
            @RequestPart("field_plan") String fieldPlanJson,
            HttpServletRequest request
    ) throws Exception {
        List<IncrementalSigningService.FieldPlan> plan = objectMapper.readValue(
                fieldPlanJson,
                new TypeReference<>() {}
        );
        return pdfResponse(signingService.prepareFields(pdf.getBytes(), plan));
    }

    @PostMapping(value = "/finalize-unsigned", consumes = MediaType.MULTIPART_FORM_DATA_VALUE)
    public ResponseEntity<ByteArrayResource> finalizeUnsigned(
            @RequestPart("pdf") MultipartFile pdf,
            HttpServletRequest request
    ) throws Exception {
        byte[] bytes = pdf.getBytes();
        IncrementalSigningService.Inspection inspection = signingService.inspect(bytes);
        if (inspection.encrypted()
                || inspection.pageCount() < 1
                || inspection.signatureCount() != 0
                || inspection.docMdpPermission() != null) {
            throw new ResponseStatusException(HttpStatus.UNPROCESSABLE_ENTITY, "PDF_SOURCE_NOT_UNSIGNED");
        }
        return pdfResponse(bytes);
    }

    @PostMapping(value = "/sign-existing-field", consumes = MediaType.MULTIPART_FORM_DATA_VALUE)
    public ResponseEntity<Map<String, Object>> signExistingField(
            @RequestPart("pdf") MultipartFile pdf,
            @RequestPart("appearance") MultipartFile appearance,
            @RequestPart("operation") String operationJson,
            @RequestPart("command") String commandJson,
            HttpServletRequest request
    ) throws Exception {
        ExecutionOperation operation = objectMapper.readValue(operationJson, ExecutionOperation.class);
        validateExecutionHeaders(request, operation);
        IncrementalSigningService.SignCommand command = objectMapper.readValue(
                commandJson,
                IncrementalSigningService.SignCommand.class
        );
        try {
            ExecutionRecord execution = executionService.execute(
                    operation.toClaim(),
                    pdf.getBytes(),
                    appearance.getBytes(),
                    command
            );
            HttpStatus status = isTerminal(execution.state()) ? HttpStatus.OK : HttpStatus.ACCEPTED;
            return ResponseEntity.status(status).body(executionResponse(execution));
        } catch (SigningExecutionRepository.ExecutionGateException exception) {
            throw new ResponseStatusException(HttpStatus.CONFLICT, "EXECUTION_GATE_REJECTED", exception);
        } catch (IllegalStateException exception) {
            if (exception.getMessage() != null && exception.getMessage().contains("not fully configured")) {
                throw new ResponseStatusException(HttpStatus.SERVICE_UNAVAILABLE, "EXECUTION_LEDGER_NOT_READY", exception);
            }
            throw exception;
        }
    }

    @GetMapping("/executions/{operationUuid}")
    public ResponseEntity<Map<String, Object>> execution(
            @PathVariable UUID operationUuid,
            HttpServletRequest request
    ) throws Exception {
        try {
            SigningExecutionRepository.ExecutionReadClaim readClaim = readClaim(request, operationUuid);
            ExecutionRecord execution = executionService.status(readClaim);
            HttpStatus status = isTerminal(execution.state()) ? HttpStatus.OK : HttpStatus.ACCEPTED;
            return ResponseEntity.status(status).body(executionResponse(execution));
        } catch (SigningExecutionService.ExecutionNotFoundException exception) {
            throw new ResponseStatusException(HttpStatus.NOT_FOUND, "EXECUTION_NOT_FOUND", exception);
        }
    }

    @GetMapping("/executions/{operationUuid}/result")
    public ResponseEntity<InputStreamResource> executionResult(
            @PathVariable UUID operationUuid,
            HttpServletRequest request
    ) throws Exception {
        try {
            SigningExecutionRepository.ExecutionReadClaim readClaim = readClaim(request, operationUuid);
            ExecutionStorage.OpenResult result = executionService.openResult(readClaim);
            return ResponseEntity.ok()
                    .contentType(MediaType.APPLICATION_PDF)
                    .header(HttpHeaders.CONTENT_DISPOSITION, "attachment; filename=revision.pdf")
                    .header("X-Pdf-Sha256", result.sha256())
                    .contentLength(result.size())
                    .body(new InputStreamResource(result.input()));
        } catch (SigningExecutionService.ExecutionNotFoundException exception) {
            throw new ResponseStatusException(HttpStatus.NOT_FOUND, "EXECUTION_NOT_FOUND", exception);
        } catch (SigningExecutionService.ExecutionResultUnavailableException exception) {
            throw new ResponseStatusException(HttpStatus.CONFLICT, "EXECUTION_RESULT_UNAVAILABLE", exception);
        } catch (IllegalStateException exception) {
            throw new ResponseStatusException(HttpStatus.SERVICE_UNAVAILABLE,
                    "EXECUTION_RESULT_INTEGRITY_ERROR", exception);
        }
    }

    @PostMapping(
            value = "/executions/{operationUuid}/retirement-evidence/inspect",
            consumes = MediaType.APPLICATION_JSON_VALUE
    )
    public ExecutionStorage.RetirementEvidenceInspection inspectRetirementEvidence(
            @PathVariable UUID operationUuid,
            @RequestBody RetirementEvidenceRequest evidence,
            HttpServletRequest request
    ) throws Exception {
        readClaim(request, operationUuid);
        try {
            return executionStorage.inspectRetirementEvidence(
                    operationUuid,
                    evidence.retirementEpoch(),
                    evidence.retirementPhase(),
                    evidence.expectedSha256(),
                    evidence.expectedSize()
            );
        } catch (IllegalArgumentException exception) {
            throw new ResponseStatusException(
                    HttpStatus.UNPROCESSABLE_ENTITY,
                    "RETIREMENT_EVIDENCE_SNAPSHOT_INVALID",
                    exception
            );
        }
    }

    @PostMapping(value = "/verify", consumes = MediaType.MULTIPART_FORM_DATA_VALUE)
    public PdfSignatureVerifier.VerificationReport verify(
            @RequestPart("pdf") MultipartFile pdf,
            HttpServletRequest request
    ) throws Exception {
        return signatureVerifier.verify(pdf.getBytes());
    }

    private static ResponseEntity<ByteArrayResource> pdfResponse(byte[] pdf) throws Exception {
        return ResponseEntity.ok()
                .contentType(MediaType.APPLICATION_PDF)
                .header(HttpHeaders.CONTENT_DISPOSITION, "attachment; filename=revision.pdf")
                .header("X-Pdf-Sha256", HexFormat.of().formatHex(
                        MessageDigest.getInstance("SHA-256").digest(pdf)))
                .contentLength(pdf.length)
                .body(new ByteArrayResource(pdf));
    }

    private static boolean isTerminal(String state) {
        return switch (state) {
            case "completed", "failed_before_private_key", "failed_after_private_key_known", "outcome_unknown" -> true;
            default -> false;
        };
    }

    private static Map<String, Object> executionResponse(ExecutionRecord execution) {
        java.util.HashMap<String, Object> response = new java.util.HashMap<>();
        response.put("operationUuid", execution.operationUuid().toString());
        response.put("state", execution.state());
        response.put("attemptNumber", execution.attemptNumber());
        response.put("attemptCount", execution.attemptCount());
        response.put("maximumAttempts", execution.maxAttempts());
        response.put("retryability", execution.retryability());
        response.put("nextRetryAt", execution.nextRetryAt());
        response.put("retryExhaustedAt", execution.retryExhaustedAt());
        response.put("privateKeyStartedAt", execution.privateKeyStartedAt());
        response.put("executionDeadlineAt", execution.executionDeadlineAt());
        response.put("terminalAt", execution.terminalAt());
        response.put("errorCode", execution.errorCode());
        response.put("resultIntegrityState", execution.resultIntegrityState());
        response.put("resultSha256", execution.resultSha256());
        response.put("resultSize", execution.resultSize());
        response.put("statusUrl", "/internal/pdf/signatures/executions/" + execution.operationUuid());
        if ("completed".equals(execution.state()) && "available".equals(execution.resultIntegrityState())) {
            response.put("resultUrl", "/internal/pdf/signatures/executions/"
                    + execution.operationUuid() + "/result");
        }
        return response;
    }

    private static SigningExecutionRepository.ExecutionReadClaim readClaim(
            HttpServletRequest request,
            UUID operationUuid
    ) {
        try {
            if (!operationUuid.toString().equals(requiredHeader(request, "X-Pdf-Operation-Uuid"))) {
                throw new ResponseStatusException(HttpStatus.UNAUTHORIZED, "PDF_EXECUTION_METADATA_MISMATCH");
            }
            return new SigningExecutionRepository.ExecutionReadClaim(
                    operationUuid,
                    Long.parseLong(requiredHeader(request, "X-Pdf-Lease-Epoch")),
                    requiredHeader(request, "X-Pdf-Operation-Manifest-Sha256"),
                    requiredHeader(request, "X-Pdf-Input-Fingerprint"),
                    requiredHeader(request, "X-Pdf-Policy-Sha256")
            );
        } catch (NumberFormatException exception) {
            throw new ResponseStatusException(HttpStatus.BAD_REQUEST, "PDF_EXECUTION_METADATA_INVALID", exception);
        }
    }

    private static void validateExecutionHeaders(
            HttpServletRequest request,
            ExecutionOperation operation
    ) {
        SigningExecutionRepository.ExecutionReadClaim expected = operation.toReadClaim();
        SigningExecutionRepository.ExecutionReadClaim actual = readClaim(request, expected.operationUuid());
        if (!expected.equals(actual)
                || !expected.operationUuid().toString().equals(requiredHeader(request, "X-Pdf-Operation-Uuid"))
                || !Long.toString(operation.policyVersionId()).equals(requiredHeader(request, "X-Pdf-Policy-Version-Id"))
                || operation.policyVersionUuid() == null
                || !operation.policyVersionUuid().toString().equals(requiredHeader(request, "X-Pdf-Policy-Version-Uuid"))
                || !operation.configBundleHash().equals(requiredHeader(request, "X-Pdf-Config-Bundle-Sha256"))) {
            throw new ResponseStatusException(HttpStatus.UNAUTHORIZED, "PDF_EXECUTION_METADATA_MISMATCH");
        }
    }

    private static String requiredHeader(HttpServletRequest request, String name) {
        String value = request.getHeader(name);
        if (value == null || value.isBlank()) {
            throw new ResponseStatusException(HttpStatus.BAD_REQUEST, "PDF_EXECUTION_METADATA_REQUIRED");
        }
        return value;
    }

    public record ExecutionOperation(
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
    ) {
        SigningExecutionRepository.OperationClaim toClaim() {
            return new SigningExecutionRepository.OperationClaim(
                    operationUuid,
                    javaGateVersion,
                    leaseEpoch,
                    operationInputManifestHash,
                    inputFingerprint,
                    expectedSourceSha256,
                    policyVersionId,
                    policyVersionUuid,
                    policyHash,
                    configBundleHash,
                    appearanceManifestHash,
                    appearanceSha256,
                    pdfSignatureRole,
                    targetFieldName,
                    expectedCertificateFingerprint,
                    fieldLockPolicyHash
            );
        }

        SigningExecutionRepository.ExecutionReadClaim toReadClaim() {
            return new SigningExecutionRepository.ExecutionReadClaim(
                    operationUuid,
                    leaseEpoch,
                    operationInputManifestHash,
                    inputFingerprint,
                    policyHash
            );
        }
    }

    public record RetirementEvidenceRequest(
            long retirementEpoch,
            String retirementPhase,
            String expectedSha256,
            long expectedSize
    ) {}

}
