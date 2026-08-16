package com.luang.pdfsigner.security;

import java.io.InputStream;
import java.nio.charset.StandardCharsets;
import java.security.MessageDigest;
import java.util.ArrayList;
import java.util.Comparator;
import java.util.HexFormat;
import java.util.HashSet;
import java.util.List;
import java.util.Map;
import java.util.Set;
import java.util.TreeMap;
import org.springframework.stereotype.Component;
import org.springframework.web.multipart.MultipartFile;
import org.springframework.web.multipart.MultipartHttpServletRequest;

@Component
public final class MultipartRequestDigestVerifier {
    private static final HexFormat HEX = HexFormat.of();

    public void verify(MultipartHttpServletRequest request, String expectedDigest) {
        String actual = digest(request);
        if (expectedDigest == null || !MessageDigest.isEqual(
                actual.getBytes(StandardCharsets.US_ASCII),
                expectedDigest.toLowerCase().getBytes(StandardCharsets.US_ASCII)
        )) {
            throw new MultipartDigestMismatchException();
        }
    }

    public String digest(MultipartHttpServletRequest request) {
        try {
            if (PdfHmacAuthenticationFilter.bodyReceiveDeadlineExceeded(request)) {
                throw new BodyReceiveDeadlineExceededException();
            }
            List<Map<String, Object>> entries = new ArrayList<>();
            Set<String> names = new HashSet<>();
            request.getParameterMap().forEach((name, values) -> {
                if (values.length != 1 || !names.add(name)) {
                    throw new IllegalArgumentException("Repeated multipart field is not allowed: " + name);
                }
                byte[] value = values[0].getBytes(StandardCharsets.UTF_8);
                entries.add(part(name, "text/plain;charset=utf-8", value.length, sha256(value)));
            });
            request.getMultiFileMap().forEach((name, files) -> {
                if (files.size() != 1 || !names.add(name)) {
                    throw new IllegalArgumentException("Repeated multipart file is not allowed: " + name);
                }
                MultipartFile file = files.get(0);
                entries.add(part(
                        name,
                        PdfHmacAuthenticationFilter.normalizeContentType(file.getContentType()),
                        file.getSize(),
                        sha256(file)
                ));
            });
            validateEndpointParts(request, names);
            entries.sort(Comparator.comparing(entry -> (String) entry.get("name")));
            return CanonicalJson.sha256(Map.of(
                    "parts", entries,
                    "version", "pdf-part-manifest-v1"
            ));
        } catch (MultipartDigestMismatchException | BodyReceiveDeadlineExceededException exception) {
            throw exception;
        } catch (Exception exception) {
            throw new IllegalArgumentException("Unable to verify multipart request digest", exception);
        }
    }

    private static String sha256(MultipartFile file) {
        try (InputStream input = file.getInputStream()) {
            MessageDigest digest = MessageDigest.getInstance("SHA-256");
            byte[] buffer = new byte[8192];
            int read;
            while ((read = input.read(buffer)) != -1) {
                digest.update(buffer, 0, read);
            }
            return HEX.formatHex(digest.digest());
        } catch (Exception exception) {
            throw new IllegalArgumentException("Unable to hash multipart file", exception);
        }
    }

    private static String sha256(byte[] value) {
        try {
            return HEX.formatHex(MessageDigest.getInstance("SHA-256").digest(value));
        } catch (Exception exception) {
            throw new IllegalStateException("Unable to hash multipart manifest", exception);
        }
    }

    private static Map<String, Object> part(String name, String contentType, long length, String sha256) {
        Map<String, Object> part = new TreeMap<>();
        part.put("content_type", contentType);
        part.put("length", length);
        part.put("name", name);
        part.put("sha256", sha256);
        return part;
    }

    private static void validateEndpointParts(MultipartHttpServletRequest request, Set<String> names) {
        String path = request.getRequestURI();
        Set<String> required;
        Set<String> allowed;
        Set<String> dynamicAllowed = Set.of();
        switch (path) {
            case "/internal/pdf/signatures/inspect",
                 "/internal/pdf/signatures/finalize-unsigned",
                 "/internal/pdf/signatures/verify",
                 "/api/pdf/extract-cover" -> {
                required = Set.of("pdf");
                allowed = required;
            }
            case "/internal/pdf/signatures/prepare" -> {
                required = Set.of("pdf", "field_plan");
                allowed = required;
            }
            case "/internal/pdf/signatures/sign-existing-field" -> {
                required = Set.of("pdf", "appearance", "operation", "command");
                allowed = required;
            }
            case "/api/pdf/process" -> {
                required = Set.of("pdf", "mode");
                allowed = Set.of(
                        "pdf", "perforation_image", "signature_appearance_image",
                        "certificate_query_qr_code", "mode", "signature_contact",
                        "signature_location", "signature_reason", "function_stamp_count",
                        "certificate_query_qr_code_url",
                        // The signing desk sends its perforation geometry on every
                        // sealed request; leaving these out rejected the whole
                        // legacy flow as a body mismatch.
                        "options[group_size]", "options[stamp_total_height_mm]",
                        "options[signature_size_mm]", "options[signature_margin_mm]"
                );
                String countValue = request.getParameter("function_stamp_count");
                int count = 0;
                if (countValue != null) {
                    try {
                        count = Integer.parseInt(countValue);
                    } catch (NumberFormatException exception) {
                        throw new IllegalArgumentException("Function stamp count is invalid", exception);
                    }
                    if (count < 0 || count > 100) {
                        throw new IllegalArgumentException("Function stamp count is outside the allowed range");
                    }
                }
                Set<String> indexed = new HashSet<>();
                for (int index = 0; index < count; index++) {
                    indexed.add("function_stamp_" + index);
                }
                dynamicAllowed = Set.copyOf(indexed);
                if (!names.containsAll(dynamicAllowed)) {
                    throw new IllegalArgumentException("Declared function stamp part is missing");
                }
            }
            default -> throw new IllegalArgumentException("Multipart endpoint is not registered: " + path);
        }
        if (!names.containsAll(required)) {
            throw new IllegalArgumentException("Required multipart part is missing");
        }
        for (String name : names) {
            if (!allowed.contains(name) && !dynamicAllowed.contains(name)) {
                throw new IllegalArgumentException("Unknown multipart part: " + name);
            }
        }
    }

    public static final class MultipartDigestMismatchException extends RuntimeException {}

    public static final class BodyReceiveDeadlineExceededException extends RuntimeException {}
}
