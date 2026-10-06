package com.luang.pdfsigner.web;

import com.luang.pdfsigner.dto.CoverExtractionResponse;
import com.luang.pdfsigner.dto.EntrustOrderPayload;
import com.luang.pdfsigner.service.ContractPdfPayload;
import com.luang.pdfsigner.service.ContractPdfRenderer;
import com.luang.pdfsigner.service.PdfCoverExtractor;
import com.luang.pdfsigner.service.EntrustOrderRenderer;
import com.luang.pdfsigner.service.SignerService;
import com.luang.pdfsigner.security.PdfHmacProperties;
import com.luang.pdfsigner.security.SigningPolicy;
import com.luang.pdfsigner.execution.ExecutionStorage;
import com.luang.pdfsigner.execution.SigningExecutionRepository;
import org.slf4j.Logger;
import org.slf4j.LoggerFactory;
import org.springframework.http.HttpHeaders;
import org.springframework.http.HttpStatus;
import org.springframework.http.MediaType;
import org.springframework.http.ResponseEntity;
import org.springframework.web.bind.annotation.*;
import org.springframework.web.multipart.MultipartFile;

import jakarta.servlet.http.HttpServletRequest;
import java.io.InputStream;
import java.nio.file.Files;
import com.fasterxml.jackson.core.JsonFactory;
import com.luang.pdfsigner.service.PdfFiles;
import jakarta.servlet.http.HttpServletResponse;
import java.util.List;
import java.util.Map;
import java.util.ArrayList;
import java.util.Set;
import org.springframework.web.multipart.MultipartHttpServletRequest;

@RestController
@RequestMapping("/api/pdf")
public class PdfController {

    private final SignerService signerService;
    private final EntrustOrderRenderer entrustOrderRenderer;
    private final ContractPdfRenderer contractPdfRenderer;
    private final PdfCoverExtractor pdfCoverExtractor;
    private final PdfHmacProperties hmacProperties;
    private final SigningPolicy signingPolicy;
    private final ExecutionStorage executionStorage;
    private final SigningExecutionRepository executionRepository;
    private static final Logger log = LoggerFactory.getLogger(PdfController.class);
    private static final Set<String> FORBIDDEN_SIGNING_POLICY_PARAMETERS = Set.of(
            "signing_key_id", "hash_algo", "tsa_enabled", "tsa_url"
    );

    public PdfController(
            SignerService signerService,
            EntrustOrderRenderer entrustOrderRenderer,
            ContractPdfRenderer contractPdfRenderer,
            PdfHmacProperties hmacProperties,
            SigningPolicy signingPolicy,
            ExecutionStorage executionStorage,
            SigningExecutionRepository executionRepository
    ) {
        this.signerService = signerService;
        this.entrustOrderRenderer = entrustOrderRenderer;
        this.contractPdfRenderer = contractPdfRenderer;
        this.pdfCoverExtractor = new PdfCoverExtractor();
        this.hmacProperties = hmacProperties;
        this.signingPolicy = signingPolicy;
        this.executionStorage = executionStorage;
        this.executionRepository = executionRepository;
    }

    @GetMapping("/health")
    public ResponseEntity<Map<String, Object>> health() {
        boolean hmacReady = hmacProperties.ready();
        boolean signingMaterialReady = signerService.signingMaterialReady();
        boolean executionDatabaseReady = executionRepository.readinessProbe();
        boolean executionStorageReady = executionStorage.readinessProbe();
        boolean ready = hmacReady && signingMaterialReady && executionDatabaseReady && executionStorageReady;
        Map<String, Object> body = Map.of(
                "status", ready ? "ok" : "not_ready",
                "service", "pdf-renderer-java",
                "hmac_ready", hmacReady,
                "signing_material_ready", signingMaterialReady,
                "execution_database_ready", executionDatabaseReady,
                "execution_storage_ready", executionStorageReady
        );
        return ready
                ? ResponseEntity.ok(body)
                : ResponseEntity.status(HttpStatus.SERVICE_UNAVAILABLE).body(body);
    }

    @PostMapping(value = "/process", consumes = MediaType.MULTIPART_FORM_DATA_VALUE)
    public void process(
            @RequestPart("pdf") MultipartFile pdf,
            @RequestPart(value = "perforation_image", required = false) MultipartFile perforation,
            @RequestPart(value = "signature_appearance_image", required = false) MultipartFile sigImg,
            @RequestPart(value = "certificate_query_qr_code", required = false) MultipartFile qrCodeImg,
            @RequestParam(name = "mode") String mode,
            @RequestParam(required = false, name = "signature_contact") String contact,
            @RequestParam(required = false, name = "signature_location") String location,
            @RequestParam(required = false, name = "signature_reason") String reason,
            @RequestParam(required = false, name = "function_stamp_count") Integer functionStampCount,
            @RequestParam(required = false, name = "certificate_query_qr_code_url") String qrCodeUrl,
            @RequestParam(required = false, name = "report_number") String reportNumber,
            HttpServletRequest request,
            HttpServletResponse response
    ) throws Exception {
        for (String parameter : FORBIDDEN_SIGNING_POLICY_PARAMETERS) {
            if (request.getParameterMap().containsKey(parameter)) {
                writePolicyError(response, "PDF_SIGNING_POLICY_OVERRIDE_FORBIDDEN");
                return;
            }
        }
        // 收集功能章图片
        List<MultipartFile> functionStamps = new ArrayList<>();
        if (functionStampCount != null && functionStampCount > 0) {
            // 尝试将HttpServletRequest转换为MultipartHttpServletRequest
            if (request instanceof MultipartHttpServletRequest) {
                MultipartHttpServletRequest multipartRequest = (MultipartHttpServletRequest) request;
                for (int i = 0; i < functionStampCount; i++) {
                    MultipartFile stamp = multipartRequest.getFile("function_stamp_" + i);
                    if (stamp != null && !stamp.isEmpty()) {
                        functionStamps.add(stamp);
                        log.debug("Added function_stamp_{} with size {}", i, stamp.getSize());
                    }
                }
            } else {
                log.warn("Request is not MultipartHttpServletRequest, cannot extract function stamps");
            }
        }
        
        // 请求日志
        log.info("/api/pdf/process called: mode={}, pdfSize={}, perfSize={}, sigImgSize={}, qrCodeSize={}, functionStamps={}",
                mode,
                safeSize(pdf),
                safeSize(perforation),
                safeSize(sigImg),
                safeSize(qrCodeImg),
                functionStamps.size());

        try (SignerService.FileProcessResult result = signerService.processToFile(pdf, perforation, sigImg, functionStamps,
                mode, null, contact, location, reason,
                signingPolicy.hashAlgorithm(), false,
                null, qrCodeImg, qrCodeUrl, reportNumber)) {
            response.setContentType(MediaType.APPLICATION_JSON_VALUE);
            try (var json = new JsonFactory().createGenerator(response.getOutputStream());
                 InputStream input = Files.newInputStream(result.pdfFile().toPath())) {
                json.writeStartObject();
                json.writeBooleanField("success", true);
                json.writeFieldName("pdf_base64");
                json.writeBinary(com.fasterxml.jackson.core.Base64Variants.getDefaultVariant(), input, -1);
                CoverExtractionResponse cover = result.coverFields();
                if (cover != null) {
                    json.writeObjectFieldStart("cover_fields");
                    json.writeStringField("report_number", cover.reportNumber());
                    json.writeStringField("product_name", cover.productName());
                    json.writeStringField("model_specification", cover.modelSpecification());
                    json.writeStringField("entrust_company", cover.entrustCompany());
                    json.writeStringField("test_items", cover.testItems());
                    json.writeStringField("report_date", cover.reportDate());
                    json.writeEndObject();
                }
                json.writeEndObject();
            }
        } catch (SignerService.SignedInputException exception) {
            writePolicyError(response, exception.getMessage());
        }
    }

    @PostMapping(value = "/entrust-order", consumes = MediaType.APPLICATION_JSON_VALUE)
    public void renderEntrustOrder(@RequestBody EntrustOrderPayload payload, HttpServletResponse response) throws Exception {
        try (PdfFiles files = new PdfFiles()) {
            var file = files.create();
            try (var output = new java.io.BufferedOutputStream(Files.newOutputStream(file.toPath()))) {
                entrustOrderRenderer.render(payload, output);
            }
            writePdf(response, file, "entrust-order.pdf");
        }
    }

    @PostMapping(value = "/contract", consumes = MediaType.APPLICATION_JSON_VALUE)
    public void renderContract(@RequestBody ContractPdfPayload payload, HttpServletResponse response) throws Exception {
        try (PdfFiles files = new PdfFiles()) {
            var file = files.create();
            try (var output = new java.io.BufferedOutputStream(Files.newOutputStream(file.toPath()))) {
                contractPdfRenderer.render(payload, output);
            }
            writePdf(response, file, "contract.pdf");
        }
    }

    private void writePdf(HttpServletResponse response, java.io.File file, String filename) throws java.io.IOException {
        response.setContentType(MediaType.APPLICATION_PDF_VALUE);
        response.setHeader(HttpHeaders.CONTENT_DISPOSITION, "attachment; filename=" + filename);
        response.setContentLengthLong(file.length());
        try (InputStream input = Files.newInputStream(file.toPath())) {
            input.transferTo(response.getOutputStream());
        }
    }

    private Long safeSize(MultipartFile f) {
        try { return (f == null || f.isEmpty()) ? null : f.getSize(); } catch (Exception e) { return null; }
    }

    private static void writePolicyError(HttpServletResponse response, String error) throws java.io.IOException {
        response.setStatus(HttpStatus.UNPROCESSABLE_ENTITY.value());
        response.setContentType(MediaType.APPLICATION_JSON_VALUE);
        try (var json = new JsonFactory().createGenerator(response.getOutputStream())) {
            json.writeStartObject();
            json.writeBooleanField("success", false);
            json.writeStringField("error", error);
            json.writeEndObject();
        }
    }

    @PostMapping(value = "/extract-cover", consumes = MediaType.MULTIPART_FORM_DATA_VALUE)
    public ResponseEntity<?> extractCover(@RequestPart("pdf") MultipartFile pdf) {
        try (InputStream stream = pdf.getInputStream()) {
            CoverExtractionResponse response = pdfCoverExtractor.extract(stream);

            Map<String, Object> data = new java.util.HashMap<>();
            data.put("report_number", response.reportNumber());
            data.put("product_name", response.productName());
            data.put("model_specification", response.modelSpecification());
            data.put("entrust_company", response.entrustCompany());
            data.put("test_items", response.testItems());
            data.put("report_date", response.reportDate());
            data.put("extraction_status", response.extractionStatus());

            Map<String, Object> body = new java.util.HashMap<>();
            body.put("success", true);
            body.put("message", "Cover extracted");
            body.put("data", data);

            return ResponseEntity.ok(body);
        } catch (Exception e) {
            log.error("Failed to extract cover fields", e);
            Map<String, Object> body = new java.util.HashMap<>();
            body.put("success", false);
            body.put("message", "Cover extraction failed");
            body.put("error", e.getMessage());
            return ResponseEntity.status(500).body(body);
        }
    }
}
