package com.luang.pdfsigner;

import static org.assertj.core.api.Assertions.assertThat;
import static org.springframework.test.web.servlet.request.MockMvcRequestBuilders.multipart;
import static org.springframework.test.web.servlet.request.MockMvcRequestBuilders.post;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.jsonPath;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.header;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.status;

import com.luang.pdfsigner.security.PdfHmacAuthenticationFilter;
import com.fasterxml.jackson.databind.ObjectMapper;
import com.fasterxml.jackson.databind.SerializationFeature;
import java.io.InputStream;
import java.nio.charset.StandardCharsets;
import java.security.MessageDigest;
import java.time.Instant;
import java.util.ArrayList;
import java.util.HexFormat;
import java.util.List;
import java.util.Map;
import java.util.TreeMap;
import java.util.UUID;
import javax.crypto.Mac;
import javax.crypto.spec.SecretKeySpec;
import org.junit.jupiter.api.Test;
import org.springframework.beans.factory.annotation.Autowired;
import org.springframework.boot.test.autoconfigure.web.servlet.AutoConfigureMockMvc;
import org.springframework.boot.test.context.SpringBootTest;
import org.springframework.mock.web.MockMultipartFile;
import org.springframework.test.web.servlet.MockMvc;
import org.springframework.test.web.servlet.request.MockMultipartHttpServletRequestBuilder;
import org.springframework.test.web.servlet.request.MockHttpServletRequestBuilder;
import org.springframework.http.MediaType;

@SpringBootTest(properties = {
        "pdf.security.hmac.enabled=true",
        "pdf.security.hmac.keys=primary:MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=",
        "pdf.security.hmac.active-key-id=primary",
        "pdf.security.hmac.nonce-store=memory"
})
@AutoConfigureMockMvc
class PdfHmacControllerIntegrationTest {
    private static final byte[] SECRET = "0123456789abcdef0123456789abcdef".getBytes(StandardCharsets.UTF_8);
    private static final ObjectMapper CANONICAL = new ObjectMapper()
            .configure(SerializationFeature.ORDER_MAP_ENTRIES_BY_KEYS, true);

    @Autowired
    private MockMvc mockMvc;

    @Test
    void rejectsUnsignedMultipartBeforeControllerExecution() throws Exception {
        mockMvc.perform(unsignedRequest())
                .andExpect(status().isUnauthorized());
    }

    @Test
    void rejectsSignedMultipartWhenActualPartsDoNotMatchManifest() throws Exception {
        String wrongDigest = "0".repeat(64);
        MockMultipartHttpServletRequestBuilder request = unsignedRequest();
        addAuthentication(request, wrongDigest, "nonce-mismatch-0001");

        mockMvc.perform(request)
                .andExpect(status().isUnauthorized());
    }

    @Test
    void rejectsTamperedExtractCoverMultipartThroughTheCentralInterceptor() throws Exception {
        byte[] pdf = samplePdf();
        String path = "/api/pdf/extract-cover";
        MockMultipartHttpServletRequestBuilder request = multipart(path)
                .file(new MockMultipartFile("pdf", "sample.pdf", "application/pdf", pdf));
        addAuthentication(request, "0".repeat(64), "nonce-extract-tamper-0001", path);

        mockMvc.perform(request)
                .andExpect(status().isUnauthorized());
    }

    @Test
    void acceptsSignedMultipartWhenManifestMatchesExactParts() throws Exception {
        byte[] pdf = samplePdf();
        String digest = multipartManifestDigest(List.of(
                part("pdf", "application/pdf", pdf),
                part("mode", "text/plain;charset=utf-8", "stamp".getBytes(StandardCharsets.UTF_8))
        ));
        MockMultipartHttpServletRequestBuilder request = unsignedRequest();
        addAuthentication(request, digest, "nonce-valid-multipart-0001");

        String body = mockMvc.perform(request)
                .andExpect(status().isOk())
                .andReturn()
                .getResponse()
                .getContentAsString();

        assertThat(body).contains("\"success\":true", "\"pdf_base64\"");
    }

    @Test
    void acceptsSignedRetirementEvidenceProbeWithoutOpeningTheExecutionDatabase() throws Exception {
        UUID operationUuid = UUID.randomUUID();
        String body = """
                {"retirementEpoch":0,"retirementPhase":"none","expectedSha256":"%s","expectedSize":1}
                """.formatted("a".repeat(64)).trim();
        String path = "/internal/pdf/signatures/executions/" + operationUuid
                + "/retirement-evidence/inspect";
        MockHttpServletRequestBuilder request = post(path)
                .contentType(MediaType.APPLICATION_JSON)
                .content(body);
        OperationMetadata operation = new OperationMetadata(
                operationUuid,
                1,
                "b".repeat(64),
                "c".repeat(64),
                7,
                UUID.randomUUID(),
                "d".repeat(64),
                "e".repeat(64)
        );
        addAuthentication(request, bodyManifestDigest(body.getBytes(StandardCharsets.UTF_8), "application/json"),
                "nonce-retirement-probe-0001", path, operation);

        mockMvc.perform(request)
                .andExpect(status().isOk())
                .andExpect(jsonPath("$.state").value("missing"))
                .andExpect(jsonPath("$.operationUuid").value(operationUuid.toString()));
    }

    @Test
    void finalizesUnsignedPdfThroughAuthenticatedIdentityFinalizer() throws Exception {
        byte[] pdf = samplePdf();
        String path = "/internal/pdf/signatures/finalize-unsigned";
        String digest = multipartManifestDigest(List.of(part("pdf", "application/pdf", pdf)));
        MockMultipartHttpServletRequestBuilder request = multipart(path)
                .file(new MockMultipartFile("pdf", "sample.pdf", "application/pdf", pdf));
        addAuthentication(request, digest, "nonce-finalize-unsigned-0001", path);

        byte[] response = mockMvc.perform(request)
                .andExpect(status().isOk())
                .andExpect(header().string("X-Pdf-Sha256", sha256(pdf)))
                .andReturn()
                .getResponse()
                .getContentAsByteArray();

        assertThat(response).isEqualTo(pdf);
    }

    private MockMultipartHttpServletRequestBuilder unsignedRequest() throws Exception {
        MockMultipartHttpServletRequestBuilder request = multipart("/api/pdf/process");
        request.file(new MockMultipartFile("pdf", "sample.pdf", "application/pdf", samplePdf()));
        request.param("mode", "stamp");
        return request;
    }

    private void addAuthentication(
            MockMultipartHttpServletRequestBuilder request,
            String contentDigest,
            String nonce
    ) throws Exception {
        addAuthentication(request, contentDigest, nonce, "/api/pdf/process");
    }

    private void addAuthentication(
            MockHttpServletRequestBuilder request,
            String partManifestDigest,
            String nonce,
            String path
    ) throws Exception {
        addAuthentication(request, partManifestDigest, nonce, path, null);
    }

    private void addAuthentication(
            MockHttpServletRequestBuilder request,
            String partManifestDigest,
            String nonce,
            String path,
            OperationMetadata operation
    ) throws Exception {
        String timestamp = Long.toString(Instant.now().getEpochSecond());
        String correlationUuid = UUID.nameUUIDFromBytes(nonce.getBytes(StandardCharsets.UTF_8)).toString();
        String operationUuid = operation == null ? "-" : operation.operationUuid().toString();
        Map<String, Object> metadata = new TreeMap<>();
        metadata.put("correlation_uuid", correlationUuid);
        metadata.put("operation_uuid", operationUuid);
        metadata.put("version", "pdf-request-metadata-v1");
        if (operation != null) {
            metadata.put("config_bundle_hash", operation.configBundleHash());
            metadata.put("input_fingerprint", operation.inputFingerprint());
            metadata.put("lease_epoch", operation.leaseEpoch());
            metadata.put("operation_input_manifest_hash", operation.manifestHash());
            metadata.put("policy_hash", operation.policyHash());
            metadata.put("signing_policy_version_id", operation.policyVersionId());
            metadata.put("signing_policy_version_uuid", operation.policyVersionUuid().toString());
            request.header("X-Pdf-Operation-Uuid", operationUuid);
            request.header("X-Pdf-Lease-Epoch", Long.toString(operation.leaseEpoch()));
            request.header("X-Pdf-Operation-Manifest-Sha256", operation.manifestHash());
            request.header("X-Pdf-Input-Fingerprint", operation.inputFingerprint());
            request.header("X-Pdf-Policy-Version-Id", Long.toString(operation.policyVersionId()));
            request.header("X-Pdf-Policy-Version-Uuid", operation.policyVersionUuid().toString());
            request.header("X-Pdf-Policy-Sha256", operation.policyHash());
            request.header("X-Pdf-Config-Bundle-Sha256", operation.configBundleHash());
        }
        String metadataDigest = canonicalSha256(metadata);
        String canonical = PdfHmacAuthenticationFilter.canonicalString(
                PdfHmacAuthenticationFilter.VERSION,
                "primary",
                "POST",
                path,
                metadataDigest,
                partManifestDigest,
                timestamp,
                nonce,
                correlationUuid,
                operationUuid
        );
        request.header(PdfHmacAuthenticationFilter.AUTH_VERSION, PdfHmacAuthenticationFilter.VERSION);
        request.header(PdfHmacAuthenticationFilter.KEY_ID, "primary");
        request.header(PdfHmacAuthenticationFilter.TIMESTAMP, timestamp);
        request.header(PdfHmacAuthenticationFilter.NONCE, nonce);
        request.header(PdfHmacAuthenticationFilter.CORRELATION_ID, correlationUuid);
        request.header(PdfHmacAuthenticationFilter.OPERATION_ID, operationUuid);
        request.header(PdfHmacAuthenticationFilter.METADATA_SHA256, metadataDigest);
        request.header(PdfHmacAuthenticationFilter.PART_MANIFEST_SHA256, partManifestDigest);
        request.header(PdfHmacAuthenticationFilter.SIGNATURE, hmac(canonical));
    }

    private byte[] samplePdf() throws Exception {
        try (InputStream input = getClass().getResourceAsStream("/samples/sample.pdf")) {
            assertThat(input).isNotNull();
            return input.readAllBytes();
        }
    }

    private static Map<String, Object> part(String name, String contentType, byte[] value) throws Exception {
        Map<String, Object> part = new TreeMap<>();
        part.put("content_type", contentType);
        part.put("length", value.length);
        part.put("name", name);
        part.put("sha256", sha256(value));
        return part;
    }

    private static String multipartManifestDigest(List<Map<String, Object>> parts) throws Exception {
        List<Map<String, Object>> sorted = new ArrayList<>(parts);
        sorted.sort((left, right) -> ((String) left.get("name")).compareTo((String) right.get("name")));
        return canonicalSha256(Map.of("parts", sorted, "version", "pdf-part-manifest-v1"));
    }

    private static String bodyManifestDigest(byte[] body, String contentType) throws Exception {
        return canonicalSha256(Map.of(
                "parts", body.length == 0 ? List.of() : List.of(part("body", contentType, body)),
                "version", "pdf-part-manifest-v1"
        ));
    }

    private static String canonicalSha256(Object value) throws Exception {
        return sha256(CANONICAL.writeValueAsBytes(value));
    }

    private static String sha256(byte[] value) throws Exception {
        return HexFormat.of().formatHex(MessageDigest.getInstance("SHA-256").digest(value));
    }

    private static String hmac(String canonical) throws Exception {
        Mac mac = Mac.getInstance("HmacSHA256");
        mac.init(new SecretKeySpec(SECRET, "HmacSHA256"));
        return HexFormat.of().formatHex(mac.doFinal(canonical.getBytes(StandardCharsets.UTF_8)));
    }

    private record OperationMetadata(
            UUID operationUuid,
            long leaseEpoch,
            String manifestHash,
            String inputFingerprint,
            long policyVersionId,
            UUID policyVersionUuid,
            String policyHash,
            String configBundleHash
    ) {}
}
