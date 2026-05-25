package com.luang.pdfsigner.web;

import com.luang.pdfsigner.dto.CoverExtractionResponse;
import com.luang.pdfsigner.dto.EntrustOrderPayload;
import com.luang.pdfsigner.service.ContractPdfPayload;
import com.luang.pdfsigner.service.ContractPdfRenderer;
import com.luang.pdfsigner.service.PdfCoverExtractor;
import com.luang.pdfsigner.service.EntrustOrderRenderer;
import com.luang.pdfsigner.service.SignerService;
import org.slf4j.Logger;
import org.slf4j.LoggerFactory;
import org.springframework.core.io.ByteArrayResource;
import org.springframework.http.HttpHeaders;
import org.springframework.http.HttpStatus;
import org.springframework.http.MediaType;
import org.springframework.http.ResponseEntity;
import org.springframework.web.bind.annotation.*;
import org.springframework.web.multipart.MultipartFile;

import jakarta.servlet.http.HttpServletRequest;
import java.io.InputStream;
import java.util.List;
import java.util.Map;
import java.util.ArrayList;
import org.springframework.web.multipart.MultipartHttpServletRequest;

@RestController
@RequestMapping("/api/pdf")
public class PdfController {

    private final SignerService signerService;
    private final EntrustOrderRenderer entrustOrderRenderer;
    private final ContractPdfRenderer contractPdfRenderer;
    private final PdfCoverExtractor pdfCoverExtractor;
    private static final Logger log = LoggerFactory.getLogger(PdfController.class);

    public PdfController(SignerService signerService, EntrustOrderRenderer entrustOrderRenderer, ContractPdfRenderer contractPdfRenderer) {
        this.signerService = signerService;
        this.entrustOrderRenderer = entrustOrderRenderer;
        this.contractPdfRenderer = contractPdfRenderer;
        this.pdfCoverExtractor = new PdfCoverExtractor();
    }

    @PostMapping(value = "/process", consumes = MediaType.MULTIPART_FORM_DATA_VALUE)
    public ResponseEntity<Map<String, Object>> process(
            @RequestPart("pdf") MultipartFile pdf,
            @RequestPart(value = "perforation_image", required = false) MultipartFile perforation,
            @RequestPart(value = "signature_appearance_image", required = false) MultipartFile sigImg,
            @RequestPart(value = "certificate_query_qr_code", required = false) MultipartFile qrCodeImg,
            @RequestParam(name = "mode") String mode,
            @RequestParam(required = false, name = "signing_key_id") String signingKeyId,
            @RequestParam(required = false, name = "signature_contact") String contact,
            @RequestParam(required = false, name = "signature_location") String location,
            @RequestParam(required = false, name = "signature_reason") String reason,
            @RequestParam(required = false, name = "hash_algo") String hashAlgo,
            @RequestParam(required = false, name = "tsa_enabled") Boolean tsaEnabled,
            @RequestParam(required = false, name = "tsa_url") String tsaUrl,
            @RequestParam(required = false, name = "function_stamp_count") Integer functionStampCount,
            @RequestParam(required = false, name = "certificate_query_qr_code_url") String qrCodeUrl,
            HttpServletRequest request
    ) throws Exception {
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
        log.info("/api/pdf/process called: mode={}, pdfSize={}, perfSize={}, sigImgSize={}, qrCodeSize={}, functionStamps={}, hashAlgo={}, tsaEnabled={}, tsaUrl={}",
                mode,
                safeSize(pdf),
                safeSize(perforation),
                safeSize(sigImg),
                safeSize(qrCodeImg),
                functionStamps.size(),
                hashAlgo,
                tsaEnabled,
                tsaUrl);

        SignerService.ProcessResult result = signerService.process(pdf, perforation, sigImg, functionStamps,
                mode,
                signingKeyId, contact, location, reason,
                hashAlgo != null ? hashAlgo : "SHA256",
                tsaEnabled != null && tsaEnabled,
                tsaUrl,
                qrCodeImg,
                qrCodeUrl);

        // 返回JSON响应：包含PDF base64编码和封面信息
        Map<String, Object> response = new java.util.HashMap<>();
        response.put("success", true);
        response.put("pdf_base64", java.util.Base64.getEncoder().encodeToString(result.getPdfBytes()));

        // 添加封面信息
        CoverExtractionResponse cover = result.getCoverFields();
        if (cover != null) {
            Map<String, String> coverFields = new java.util.HashMap<>();
            coverFields.put("report_number", cover.reportNumber());
            coverFields.put("product_name", cover.productName());
            coverFields.put("model_specification", cover.modelSpecification());
            coverFields.put("entrust_company", cover.entrustCompany());
            coverFields.put("test_items", cover.testItems());
            coverFields.put("report_date", cover.reportDate());
            response.put("cover_fields", coverFields);
        }

        return ResponseEntity.ok(response);
    }

    @PostMapping(value = "/entrust-order", consumes = MediaType.APPLICATION_JSON_VALUE)
    public ResponseEntity<ByteArrayResource> renderEntrustOrder(@RequestBody EntrustOrderPayload payload) throws Exception {
        byte[] bytes = entrustOrderRenderer.render(payload);
        ByteArrayResource resource = new ByteArrayResource(bytes);
        return ResponseEntity.ok()
                .contentType(MediaType.APPLICATION_PDF)
                .header(HttpHeaders.CONTENT_DISPOSITION, "attachment; filename=entrust-order.pdf")
                .body(resource);
    }

    @PostMapping(value = "/contract", consumes = MediaType.APPLICATION_JSON_VALUE)
    public ResponseEntity<ByteArrayResource> renderContract(@RequestBody ContractPdfPayload payload) throws Exception {
        byte[] bytes = contractPdfRenderer.render(payload);
        ByteArrayResource resource = new ByteArrayResource(bytes);
        return ResponseEntity.ok()
                .contentType(MediaType.APPLICATION_PDF)
                .header(HttpHeaders.CONTENT_DISPOSITION, "attachment; filename=contract.pdf")
                .body(resource);
    }

    private Long safeSize(MultipartFile f) {
        try { return (f == null || f.isEmpty()) ? null : f.getSize(); } catch (Exception e) { return null; }
    }

    /**
     * 安全地将字符串转换为JSON字符串（带引号和转义）
     */
    private String jsonString(String str) {
        if (str == null) {
            return "null";
        }
        String escaped = str.replace("\\", "\\\\")
                           .replace("\"", "\\\"")
                           .replace("\n", "\\n")
                           .replace("\r", "\\r")
                           .replace("\t", "\\t");
        return "\"" + escaped + "\"";
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
