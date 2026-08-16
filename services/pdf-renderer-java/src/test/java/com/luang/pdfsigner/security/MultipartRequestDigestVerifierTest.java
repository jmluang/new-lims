package com.luang.pdfsigner.security;

import static org.assertj.core.api.Assertions.assertThat;
import static org.assertj.core.api.Assertions.assertThatThrownBy;

import java.nio.charset.StandardCharsets;
import java.security.MessageDigest;
import java.time.Duration;
import java.util.ArrayList;
import java.util.HexFormat;
import java.util.List;
import java.util.Map;
import java.util.TreeMap;
import org.junit.jupiter.api.Test;
import org.springframework.mock.web.MockMultipartFile;
import org.springframework.mock.web.MockMultipartHttpServletRequest;

class MultipartRequestDigestVerifierTest {
    private final MultipartRequestDigestVerifier verifier = new MultipartRequestDigestVerifier();

    @Test
    void matchesTheCrossRuntimeMultipartManifestContract() throws Exception {
        MockMultipartHttpServletRequest request = new MockMultipartHttpServletRequest();
        request.setRequestURI("/api/pdf/process");
        request.addParameter("mode", "custom");
        request.addParameter("signature_reason", "审核通过");
        request.addFile(new MockMultipartFile("pdf", "report.pdf", "application/pdf", "%PDF-1.7".getBytes(StandardCharsets.UTF_8)));
        request.addFile(new MockMultipartFile("signature_appearance_image", "signature.png", "image/png", "png-data".getBytes(StandardCharsets.UTF_8)));

        List<Map<String, Object>> entries = new ArrayList<>();
        entries.add(part("mode", "text/plain;charset=utf-8", "custom".getBytes(StandardCharsets.UTF_8)));
        entries.add(part("signature_reason", "text/plain;charset=utf-8", "审核通过".getBytes(StandardCharsets.UTF_8)));
        entries.add(part("pdf", "application/pdf", "%PDF-1.7".getBytes(StandardCharsets.UTF_8)));
        entries.add(part("signature_appearance_image", "image/png", "png-data".getBytes(StandardCharsets.UTF_8)));
        entries.sort((left, right) -> ((String) left.get("name")).compareTo((String) right.get("name")));
        String expected = CanonicalJson.sha256(Map.of(
                "parts", entries,
                "version", "pdf-part-manifest-v1"
        ));

        assertThat(verifier.digest(request)).isEqualTo(expected);
    }

    /**
     * The signing desk puts its perforation geometry in options[...] parts on every
     * sealed request. They were missing from the allowlist, so each one was
     * rejected as an unknown part and surfaced as a body mismatch, which took the
     * whole legacy flow down.
     */
    @Test
    void acceptsTheGeometryPartsTheLegacySigningDeskAlwaysSends() {
        MockMultipartHttpServletRequest request = new MockMultipartHttpServletRequest();
        request.setRequestURI("/api/pdf/process");
        request.addParameter("mode", "custom");
        request.addParameter("options[group_size]", "10");
        request.addParameter("options[stamp_total_height_mm]", "13.5");
        request.addParameter("options[signature_size_mm]", "13.5");
        request.addParameter("options[signature_margin_mm]", "10");
        request.addParameter("signature_contact", "lims@example.invalid");
        request.addParameter("signature_location", "lab");
        request.addParameter("signature_reason", "report release");
        request.addParameter("function_stamp_count", "1");
        request.addFile(new MockMultipartFile("pdf", "report.pdf", "application/pdf", "%PDF-1.7".getBytes(StandardCharsets.UTF_8)));
        request.addFile(new MockMultipartFile("signature_appearance_image", "signature.png", "image/png", "png".getBytes(StandardCharsets.UTF_8)));
        request.addFile(new MockMultipartFile("perforation_image", "stamp.png", "image/png", "png".getBytes(StandardCharsets.UTF_8)));
        request.addFile(new MockMultipartFile("function_stamp_0", "fn.png", "image/png", "png".getBytes(StandardCharsets.UTF_8)));

        assertThat(verifier.digest(request)).isNotBlank();
    }

    @Test
    void stillRejectsAnUnknownPartOnTheLegacyEndpoint() {
        MockMultipartHttpServletRequest request = new MockMultipartHttpServletRequest();
        request.setRequestURI("/api/pdf/process");
        request.addParameter("mode", "custom");
        request.addParameter("options[not_a_real_option]", "1");
        request.addFile(new MockMultipartFile("pdf", "report.pdf", "application/pdf", "%PDF-1.7".getBytes(StandardCharsets.UTF_8)));

        assertThatThrownBy(() -> verifier.digest(request))
                .isInstanceOf(IllegalArgumentException.class);
    }

    @Test
    void rejectsMultipartThatCompletedAfterTheFixedReceiveDeadline() {
        MockMultipartHttpServletRequest request = new MockMultipartHttpServletRequest();
        request.setRequestURI("/internal/pdf/signatures/inspect");
        request.addFile(new MockMultipartFile("pdf", "report.pdf", "application/pdf", new byte[] {1}));
        request.setAttribute(
                PdfHmacAuthenticationFilter.RECEIPT_NANOS_ATTRIBUTE,
                System.nanoTime() - Duration.ofSeconds(121).toNanos()
        );

        assertThatThrownBy(() -> verifier.digest(request))
                .isInstanceOf(MultipartRequestDigestVerifier.BodyReceiveDeadlineExceededException.class);
    }

    @Test
    void rejectsUndeclaredDynamicLegacyParts() {
        MockMultipartHttpServletRequest request = new MockMultipartHttpServletRequest();
        request.setRequestURI("/api/pdf/process");
        request.addParameter("mode", "stamp");
        request.addFile(new MockMultipartFile("pdf", "report.pdf", "application/pdf", new byte[] {1}));
        request.addFile(new MockMultipartFile("function_stamp_0", "stamp.png", "image/png", new byte[] {2}));

        assertThatThrownBy(() -> verifier.digest(request))
                .hasMessageContaining("Unable to verify multipart request digest")
                .hasRootCauseMessage("Unknown multipart part: function_stamp_0");
    }

    private static Map<String, Object> part(String name, String contentType, byte[] bytes) throws Exception {
        Map<String, Object> part = new TreeMap<>();
        part.put("content_type", contentType);
        part.put("length", bytes.length);
        part.put("name", name);
        part.put("sha256", sha256(bytes));
        return part;
    }

    private static String sha256(byte[] bytes) throws Exception {
        return HexFormat.of().formatHex(MessageDigest.getInstance("SHA-256").digest(bytes));
    }
}
