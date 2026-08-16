package com.luang.pdfsigner.security;

import static org.assertj.core.api.Assertions.assertThat;
import static org.assertj.core.api.Assertions.assertThatThrownBy;

import com.fasterxml.jackson.databind.JsonNode;
import com.fasterxml.jackson.databind.ObjectMapper;
import jakarta.servlet.FilterChain;
import java.io.InputStream;
import java.nio.charset.StandardCharsets;
import java.security.MessageDigest;
import java.time.Instant;
import java.util.Base64;
import java.util.HexFormat;
import java.util.List;
import java.util.Map;
import java.util.TreeMap;
import java.util.UUID;
import java.util.concurrent.atomic.AtomicReference;
import javax.crypto.Mac;
import javax.crypto.spec.SecretKeySpec;
import org.junit.jupiter.api.BeforeEach;
import org.junit.jupiter.api.Test;
import org.springframework.mock.web.MockHttpServletRequest;
import org.springframework.mock.web.MockHttpServletResponse;

class PdfHmacAuthenticationFilterTest {
    private static final byte[] SECRET = "0123456789abcdef0123456789abcdef".getBytes(StandardCharsets.UTF_8);
    private static final byte[] ROTATED_SECRET = "fedcba9876543210fedcba9876543210".getBytes(StandardCharsets.UTF_8);
    private static final String ENCODED_KEYS = "primary:" + Base64.getEncoder().encodeToString(SECRET);

    private PdfHmacAuthenticationFilter filter;

    @BeforeEach
    void setUp() {
        PdfHmacProperties properties = new PdfHmacProperties(true, "primary", ENCODED_KEYS, 60);
        filter = new PdfHmacAuthenticationFilter(properties, new InMemoryHmacNonceStore());
    }

    @Test
    void acceptsValidRequestAndPreservesBody() throws Exception {
        byte[] body = "{\"report\":\"XDP-1\"}".getBytes(StandardCharsets.UTF_8);
        MockHttpServletRequest request = signedRequest(body, "nonce-valid-0001", Instant.now().getEpochSecond());
        MockHttpServletResponse response = new MockHttpServletResponse();
        AtomicReference<String> observedBody = new AtomicReference<>();
        FilterChain chain = (servletRequest, servletResponse) -> {
            observedBody.set(new String(servletRequest.getInputStream().readAllBytes(), StandardCharsets.UTF_8));
            ((MockHttpServletResponse) servletResponse).setStatus(204);
        };

        filter.doFilter(request, response, chain);

        assertThat(response.getStatus()).isEqualTo(204);
        assertThat(observedBody).hasValue(new String(body, StandardCharsets.UTF_8));
    }

    @Test
    void rejectsMissingAuthentication() throws Exception {
        MockHttpServletRequest request = new MockHttpServletRequest("POST", "/api/pdf/contract");
        MockHttpServletResponse response = new MockHttpServletResponse();

        filter.doFilter(request, response, (servletRequest, servletResponse) -> {});

        assertThat(response.getStatus()).isEqualTo(401);
        assertThat(response.getContentAsString()).contains("PDF_HMAC_REQUIRED");
    }

    @Test
    void rejectsBodyTampering() throws Exception {
        byte[] body = "{\"report\":\"XDP-1\"}".getBytes(StandardCharsets.UTF_8);
        MockHttpServletRequest request = signedRequest(body, "nonce-tamper-0001", Instant.now().getEpochSecond());
        request.setContent("{\"report\":\"XDP-2\"}".getBytes(StandardCharsets.UTF_8));
        MockHttpServletResponse response = new MockHttpServletResponse();

        filter.doFilter(request, response, (servletRequest, servletResponse) -> {});

        assertThat(response.getStatus()).isEqualTo(401);
        assertThat(response.getContentAsString()).contains("PDF_HMAC_BODY_MISMATCH");
    }

    @Test
    void rejectsReplay() throws Exception {
        byte[] body = "{}".getBytes(StandardCharsets.UTF_8);
        long timestamp = Instant.now().getEpochSecond();
        MockHttpServletResponse firstResponse = new MockHttpServletResponse();
        filter.doFilter(signedRequest(body, "nonce-replay-0001", timestamp), firstResponse, acceptingChain());
        MockHttpServletResponse replayResponse = new MockHttpServletResponse();

        filter.doFilter(signedRequest(body, "nonce-replay-0001", timestamp), replayResponse, acceptingChain());

        assertThat(firstResponse.getStatus()).isEqualTo(204);
        assertThat(replayResponse.getStatus()).isEqualTo(409);
        assertThat(replayResponse.getContentAsString()).contains("PDF_HMAC_REPLAYED");
    }

    @Test
    void rejectsExpiredRequest() throws Exception {
        byte[] body = "{}".getBytes(StandardCharsets.UTF_8);
        MockHttpServletResponse response = new MockHttpServletResponse();

        filter.doFilter(
                signedRequest(body, "nonce-expired-0001", Instant.now().minusSeconds(61).getEpochSecond()),
                response,
                acceptingChain()
        );

        assertThat(response.getStatus()).isEqualTo(401);
        assertThat(response.getContentAsString()).contains("PDF_HMAC_EXPIRED");
    }

    @Test
    void rejectsUnknownKeyBeforeClaimingNonce() throws Exception {
        AtomicReference<Boolean> nonceClaimed = new AtomicReference<>(false);
        PdfHmacProperties properties = new PdfHmacProperties(true, "primary", ENCODED_KEYS, 60);
        PdfHmacAuthenticationFilter unknownKeyFilter = new PdfHmacAuthenticationFilter(
                properties,
                (version, keyId, nonce, correlationUuid, ttl) -> {
                    nonceClaimed.set(true);
                    return true;
                }
        );
        MockHttpServletRequest request = signedRequest(
                "{}".getBytes(StandardCharsets.UTF_8),
                "nonce-unknown-key-0001",
                Instant.now().getEpochSecond(),
                "unknown",
                SECRET
        );
        MockHttpServletResponse response = new MockHttpServletResponse();

        unknownKeyFilter.doFilter(request, response, acceptingChain());

        assertThat(response.getStatus()).isEqualTo(401);
        assertThat(response.getContentAsString()).contains("PDF_HMAC_KEY_UNKNOWN");
        assertThat(nonceClaimed).hasValue(false);
    }

    @Test
    void acceptsOldAndNewKeysDuringRotationWhileNewKeyIsActive() throws Exception {
        String rotatedKeys = "primary:" + Base64.getEncoder().encodeToString(SECRET)
                + ",rotated:" + Base64.getEncoder().encodeToString(ROTATED_SECRET);
        PdfHmacProperties properties = new PdfHmacProperties(true, "rotated", rotatedKeys, 60);
        PdfHmacAuthenticationFilter rotationFilter = new PdfHmacAuthenticationFilter(
                properties,
                new InMemoryHmacNonceStore()
        );
        long timestamp = Instant.now().getEpochSecond();
        MockHttpServletResponse oldKeyResponse = new MockHttpServletResponse();
        MockHttpServletResponse newKeyResponse = new MockHttpServletResponse();

        rotationFilter.doFilter(
                signedRequest(
                        "{}".getBytes(StandardCharsets.UTF_8),
                        "nonce-rotation-old-0001",
                        timestamp,
                        "primary",
                        SECRET
                ),
                oldKeyResponse,
                acceptingChain()
        );
        rotationFilter.doFilter(
                signedRequest(
                        "{}".getBytes(StandardCharsets.UTF_8),
                        "nonce-rotation-new-0001",
                        timestamp,
                        "rotated",
                        ROTATED_SECRET
                ),
                newKeyResponse,
                acceptingChain()
        );

        assertThat(oldKeyResponse.getStatus()).isEqualTo(204);
        assertThat(newKeyResponse.getStatus()).isEqualTo(204);
    }

    @Test
    void rejectsNonCanonicalRequestTargetsBeforeBodyProcessing() throws Exception {
        for (String target : List.of(
                "/api/pdf/contract?z=1&a=2",
                "/api/pdf/contract?a=1&a=2",
                "/api/pdf/contract?a=%2f",
                "/api/../pdf/contract"
        )) {
            MockHttpServletRequest request = signedRequest(
                    "{}".getBytes(StandardCharsets.UTF_8),
                    "nonce-target-" + Integer.toUnsignedString(target.hashCode()),
                    Instant.now().getEpochSecond()
            );
            int querySeparator = target.indexOf('?');
            request.setRequestURI(querySeparator >= 0 ? target.substring(0, querySeparator) : target);
            request.setQueryString(querySeparator >= 0 ? target.substring(querySeparator + 1) : null);
            MockHttpServletResponse response = new MockHttpServletResponse();

            filter.doFilter(request, response, acceptingChain());

            assertThat(response.getStatus()).as(target).isEqualTo(401);
            assertThat(response.getContentAsString()).contains("PDF_HMAC_CANONICALIZATION_INVALID");
        }
    }

    @Test
    void rejectsDuplicateAuthenticationHeadersAndNonCanonicalMacHex() throws Exception {
        MockHttpServletRequest duplicate = signedRequest(
                "{}".getBytes(StandardCharsets.UTF_8),
                "nonce-duplicate-header-0001",
                Instant.now().getEpochSecond()
        );
        duplicate.addHeader(PdfHmacAuthenticationFilter.KEY_ID, "primary");
        MockHttpServletResponse duplicateResponse = new MockHttpServletResponse();
        filter.doFilter(duplicate, duplicateResponse, acceptingChain());
        assertThat(duplicateResponse.getStatus()).isEqualTo(401);
        assertThat(duplicateResponse.getContentAsString()).contains("PDF_HMAC_HEADER_AMBIGUOUS");

        MockHttpServletRequest uppercase = signedRequest(
                "{}".getBytes(StandardCharsets.UTF_8),
                "nonce-uppercase-mac-0001",
                Instant.now().getEpochSecond()
        );
        uppercase.removeHeader(PdfHmacAuthenticationFilter.SIGNATURE);
        uppercase.addHeader(PdfHmacAuthenticationFilter.SIGNATURE, "A".repeat(64));
        MockHttpServletResponse uppercaseResponse = new MockHttpServletResponse();
        filter.doFilter(uppercase, uppercaseResponse, acceptingChain());
        assertThat(uppercaseResponse.getStatus()).isEqualTo(401);
        assertThat(uppercaseResponse.getContentAsString()).contains("PDF_HMAC_REQUIRED");
    }

    @Test
    void failsClosedWhenNonceStoreIsUnavailable() throws Exception {
        PdfHmacProperties properties = new PdfHmacProperties(true, "primary", ENCODED_KEYS, 60);
        PdfHmacAuthenticationFilter unavailableFilter = new PdfHmacAuthenticationFilter(
                properties,
                (version, keyId, nonce, correlationUuid, ttl) -> {
                    throw new IllegalStateException("redis unavailable");
                }
        );
        byte[] body = "{}".getBytes(StandardCharsets.UTF_8);
        MockHttpServletResponse response = new MockHttpServletResponse();

        unavailableFilter.doFilter(
                signedRequest(body, "nonce-store-down-0001", Instant.now().getEpochSecond()),
                response,
                acceptingChain()
        );

        assertThat(response.getStatus()).isEqualTo(503);
        assertThat(response.getContentAsString()).contains("PDF_HMAC_NONCE_STORE_UNAVAILABLE");
    }

    @Test
    void matchesSharedPositiveAndNegativeRequestToMacVectors() throws Exception {
        ObjectMapper mapper = new ObjectMapper();
        JsonNode vectors;
        try (InputStream input = getClass().getResourceAsStream("/pdf-hmac-v1-vectors.json")) {
            assertThat(input).isNotNull();
            vectors = mapper.readTree(input);
        }
        JsonNode positive = vectors.get("positive");
        Object metadata = mapper.convertValue(positive.get("metadata"), Object.class);
        Object partManifest = mapper.convertValue(positive.get("part_manifest"), Object.class);

        assertThat(CanonicalJson.encode(metadata)).isEqualTo(positive.get("metadata_jcs").asText());
        assertThat(CanonicalJson.sha256(metadata)).isEqualTo(positive.get("metadata_sha256").asText());
        assertThat(CanonicalJson.encode(partManifest)).isEqualTo(positive.get("part_manifest_jcs").asText());
        assertThat(CanonicalJson.sha256(partManifest)).isEqualTo(positive.get("part_manifest_sha256").asText());

        Map<String, String> fields = vectorFields(positive);
        String canonical = vectorCanonical(fields);
        assertThat(canonical).isEqualTo(positive.get("canonical").asText());
        assertThat(hmac(canonical)).isEqualTo(positive.get("signature").asText());

        for (JsonNode negative : vectors.get("negative")) {
            Map<String, String> tampered = new TreeMap<>(fields);
            tampered.put(negative.get("field").asText(), negative.get("value").asText());
            assertThat(hmac(vectorCanonical(tampered)))
                    .as(negative.get("name").asText())
                    .isNotEqualTo(positive.get("signature").asText());
        }
    }

    @Test
    void canonicalRequestJsonRejectsValuesOutsideTheInteroperableJcsSubset() {
        assertThatThrownBy(() -> CanonicalJson.encode(Map.of("value", 1.5d)))
                .isInstanceOf(IllegalArgumentException.class)
                .hasRootCauseMessage("Unsupported value type in canonical request JSON: class java.lang.Double");
        assertThatThrownBy(() -> CanonicalJson.encode(Map.of("value", Long.MAX_VALUE)))
                .isInstanceOf(IllegalArgumentException.class)
                .hasRootCauseMessage("Integer exceeds the RFC 8785 interoperable range");
    }

    private static Map<String, String> vectorFields(JsonNode positive) {
        Map<String, String> fields = new TreeMap<>();
        for (String field : List.of(
                "version", "key_id", "method", "path_and_query", "metadata_sha256",
                "part_manifest_sha256", "timestamp", "nonce", "correlation_uuid", "operation_uuid"
        )) {
            fields.put(field, positive.get(field).asText());
        }
        return fields;
    }

    private static String vectorCanonical(Map<String, String> fields) {
        return PdfHmacAuthenticationFilter.canonicalString(
                fields.get("version"),
                fields.get("key_id"),
                fields.get("method"),
                fields.get("path_and_query"),
                fields.get("metadata_sha256"),
                fields.get("part_manifest_sha256"),
                fields.get("timestamp"),
                fields.get("nonce"),
                fields.get("correlation_uuid"),
                fields.get("operation_uuid")
        );
    }

    private MockHttpServletRequest signedRequest(byte[] body, String nonce, long timestamp) throws Exception {
        return signedRequest(body, nonce, timestamp, "primary", SECRET);
    }

    private MockHttpServletRequest signedRequest(
            byte[] body,
            String nonce,
            long timestamp,
            String keyId,
            byte[] secret
    ) throws Exception {
        MockHttpServletRequest request = new MockHttpServletRequest("POST", "/api/pdf/contract");
        request.setContentType("application/json");
        request.setContent(body);
        String correlationUuid = UUID.nameUUIDFromBytes(nonce.getBytes(StandardCharsets.UTF_8)).toString();
        String metadataDigest = CanonicalJson.sha256(Map.of(
                "correlation_uuid", correlationUuid,
                "operation_uuid", "-",
                "version", "pdf-request-metadata-v1"
        ));
        Map<String, Object> part = new TreeMap<>();
        part.put("content_type", "application/json");
        part.put("length", body.length);
        part.put("name", "body");
        part.put("sha256", sha256(body));
        String partManifestDigest = CanonicalJson.sha256(Map.of(
                "parts", body.length == 0 ? List.of() : List.of(part),
                "version", "pdf-part-manifest-v1"
        ));
        String canonical = PdfHmacAuthenticationFilter.canonicalString(
                PdfHmacAuthenticationFilter.VERSION,
                keyId,
                "POST",
                "/api/pdf/contract",
                metadataDigest,
                partManifestDigest,
                Long.toString(timestamp),
                nonce,
                correlationUuid,
                "-"
        );
        request.addHeader(PdfHmacAuthenticationFilter.AUTH_VERSION, PdfHmacAuthenticationFilter.VERSION);
        request.addHeader(PdfHmacAuthenticationFilter.KEY_ID, keyId);
        request.addHeader(PdfHmacAuthenticationFilter.TIMESTAMP, Long.toString(timestamp));
        request.addHeader(PdfHmacAuthenticationFilter.NONCE, nonce);
        request.addHeader(PdfHmacAuthenticationFilter.CORRELATION_ID, correlationUuid);
        request.addHeader(PdfHmacAuthenticationFilter.OPERATION_ID, "-");
        request.addHeader(PdfHmacAuthenticationFilter.METADATA_SHA256, metadataDigest);
        request.addHeader(PdfHmacAuthenticationFilter.PART_MANIFEST_SHA256, partManifestDigest);
        request.addHeader(PdfHmacAuthenticationFilter.SIGNATURE, hmac(canonical, secret));
        return request;
    }

    private static FilterChain acceptingChain() {
        return (request, response) -> ((MockHttpServletResponse) response).setStatus(204);
    }

    private static String sha256(byte[] body) throws Exception {
        return HexFormat.of().formatHex(MessageDigest.getInstance("SHA-256").digest(body));
    }

    private static String hmac(String canonical) throws Exception {
        return hmac(canonical, SECRET);
    }

    private static String hmac(String canonical, byte[] secret) throws Exception {
        Mac mac = Mac.getInstance("HmacSHA256");
        mac.init(new SecretKeySpec(secret, "HmacSHA256"));
        return HexFormat.of().formatHex(mac.doFinal(canonical.getBytes(StandardCharsets.UTF_8)));
    }
}
