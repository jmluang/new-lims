package com.luang.pdfsigner.security;

import jakarta.servlet.FilterChain;
import jakarta.servlet.ServletException;
import jakarta.servlet.http.HttpServletRequest;
import jakarta.servlet.http.HttpServletResponse;
import java.io.ByteArrayOutputStream;
import java.io.IOException;
import java.nio.charset.StandardCharsets;
import java.security.MessageDigest;
import java.time.Duration;
import java.time.Instant;
import java.util.HexFormat;
import java.util.HashSet;
import java.util.List;
import java.util.Locale;
import java.util.Map;
import java.util.Set;
import java.util.TreeMap;
import java.util.UUID;
import javax.crypto.Mac;
import javax.crypto.spec.SecretKeySpec;
import org.springframework.http.MediaType;
import org.springframework.stereotype.Component;
import org.springframework.web.filter.OncePerRequestFilter;

@Component
public final class PdfHmacAuthenticationFilter extends OncePerRequestFilter {
    public static final String AUTH_VERSION = "X-Pdf-Auth-Version";
    public static final String KEY_ID = "X-Pdf-Key-Id";
    public static final String TIMESTAMP = "X-Pdf-Timestamp";
    public static final String NONCE = "X-Pdf-Nonce";
    public static final String CORRELATION_ID = "X-Pdf-Correlation-Id";
    public static final String OPERATION_ID = "X-Pdf-Operation-Id";
    public static final String METADATA_SHA256 = "X-Pdf-Metadata-Sha256";
    public static final String PART_MANIFEST_SHA256 = "X-Pdf-Part-Manifest-Sha256";
    public static final String SIGNATURE = "X-Pdf-Signature";
    public static final String VERSION = "PDF-HMAC-V1";
    public static final String RECEIPT_NANOS_ATTRIBUTE = PdfHmacAuthenticationFilter.class.getName() + ".receiptNanos";
    public static final int BODY_RECEIVE_DEADLINE_SECONDS = 120;
    public static final int NONCE_TTL_SECONDS = 300;

    private static final int MAX_NON_MULTIPART_BODY_BYTES = 24 * 1024 * 1024;
    private static final HexFormat HEX = HexFormat.of();

    private final PdfHmacProperties properties;
    private final HmacNonceStore nonceStore;

    public PdfHmacAuthenticationFilter(PdfHmacProperties properties, HmacNonceStore nonceStore) {
        this.properties = properties;
        this.nonceStore = nonceStore;
    }

    @Override
    protected boolean shouldNotFilter(HttpServletRequest request) {
        return !properties.enabled() || ("GET".equals(request.getMethod()) && "/api/pdf/health".equals(request.getRequestURI()));
    }

    @Override
    protected void doFilterInternal(HttpServletRequest request, HttpServletResponse response, FilterChain chain)
            throws ServletException, IOException {
        Instant receiptTime = Instant.now();
        long receiptNanos = System.nanoTime();
        if (hasDuplicateAuthenticationHeader(request)) {
            reject(response, HttpServletResponse.SC_UNAUTHORIZED, "PDF_HMAC_HEADER_AMBIGUOUS");
            return;
        }
        String version = request.getHeader(AUTH_VERSION);
        String keyId = request.getHeader(KEY_ID);
        String timestampHeader = request.getHeader(TIMESTAMP);
        String nonce = request.getHeader(NONCE);
        String correlationUuid = request.getHeader(CORRELATION_ID);
        String operationUuid = request.getHeader(OPERATION_ID);
        String metadataSha256 = normalizeHex(request.getHeader(METADATA_SHA256));
        String partManifestSha256 = normalizeHex(request.getHeader(PART_MANIFEST_SHA256));
        String suppliedSignature = normalizeHex(request.getHeader(SIGNATURE));

        if (!VERSION.equals(version) || keyId == null || !keyId.matches("[A-Za-z0-9._-]{1,64}")
                || timestampHeader == null || !timestampHeader.matches("0|[1-9][0-9]{0,10}") || isBlank(nonce)
                || !validUuid(correlationUuid)
                || !("-".equals(operationUuid) || validUuid(operationUuid))
                || metadataSha256 == null || partManifestSha256 == null || suppliedSignature == null
                || !request.getMethod().equals(request.getMethod().toUpperCase(Locale.ROOT))) {
            reject(response, HttpServletResponse.SC_UNAUTHORIZED, "PDF_HMAC_REQUIRED");
            return;
        }
        if (!nonce.matches("[A-Za-z0-9._-]{16,128}")) {
            reject(response, HttpServletResponse.SC_UNAUTHORIZED, "PDF_HMAC_NONCE_INVALID");
            return;
        }

        long timestamp;
        try {
            timestamp = Long.parseLong(timestampHeader);
        } catch (NumberFormatException exception) {
            reject(response, HttpServletResponse.SC_UNAUTHORIZED, "PDF_HMAC_TIMESTAMP_INVALID");
            return;
        }
        long now = receiptTime.getEpochSecond();
        if (Math.abs(now - timestamp) > properties.maxClockSkewSeconds()) {
            reject(response, HttpServletResponse.SC_UNAUTHORIZED, "PDF_HMAC_EXPIRED");
            return;
        }

        byte[] secret = properties.key(keyId);
        if (secret == null) {
            reject(response, HttpServletResponse.SC_UNAUTHORIZED, "PDF_HMAC_KEY_UNKNOWN");
            return;
        }

        String pathAndQuery;
        Map<String, Object> metadata;
        try {
            pathAndQuery = canonicalPathAndQuery(request);
            metadata = requestMetadata(request, correlationUuid, operationUuid);
        } catch (IllegalArgumentException exception) {
            reject(response, HttpServletResponse.SC_UNAUTHORIZED, "PDF_HMAC_CANONICALIZATION_INVALID");
            return;
        }
        String actualMetadataSha256 = CanonicalJson.sha256(metadata);
        if (!constantTimeEquals(actualMetadataSha256, metadataSha256)) {
            reject(response, HttpServletResponse.SC_UNAUTHORIZED, "PDF_HMAC_METADATA_MISMATCH");
            return;
        }

        String canonical = canonicalString(
                version,
                keyId,
                request.getMethod(),
                pathAndQuery,
                metadataSha256,
                partManifestSha256,
                timestampHeader,
                nonce,
                correlationUuid,
                operationUuid
        );
        String expectedSignature = hmacHex(secret, canonical);
        if (!constantTimeEquals(expectedSignature, suppliedSignature)) {
            reject(response, HttpServletResponse.SC_UNAUTHORIZED, "PDF_HMAC_INVALID");
            return;
        }

        try {
            if (!nonceStore.reserve(
                    version,
                    keyId,
                    nonce,
                    correlationUuid,
                    Duration.ofSeconds(NONCE_TTL_SECONDS)
            )) {
                reject(response, HttpServletResponse.SC_CONFLICT, "PDF_HMAC_REPLAYED");
                return;
            }
        } catch (RuntimeException exception) {
            reject(response, HttpServletResponse.SC_SERVICE_UNAVAILABLE, "PDF_HMAC_NONCE_STORE_UNAVAILABLE");
            return;
        }

        HttpServletRequest verifiedRequest = request;
        request.setAttribute(RECEIPT_NANOS_ATTRIBUTE, receiptNanos);
        if (!isMultipart(request)) {
            byte[] body;
            try {
                body = readBounded(request, MAX_NON_MULTIPART_BODY_BYTES);
            } catch (BodyTooLargeException exception) {
                reject(response, HttpServletResponse.SC_REQUEST_ENTITY_TOO_LARGE, "PDF_REQUEST_TOO_LARGE");
                return;
            }
            if (bodyReceiveDeadlineExceeded(request)) {
                reject(response, HttpServletResponse.SC_REQUEST_TIMEOUT, "PDF_HMAC_BODY_RECEIVE_TIMEOUT");
                return;
            }
            String actualPartManifestSha256 = bodyManifestSha256(body, request.getContentType());
            if (!constantTimeEquals(actualPartManifestSha256, partManifestSha256)) {
                reject(response, HttpServletResponse.SC_UNAUTHORIZED, "PDF_HMAC_BODY_MISMATCH");
                return;
            }
            verifiedRequest = new CachedBodyHttpServletRequest(request, body);
        }

        chain.doFilter(verifiedRequest, response);
    }

    static boolean bodyReceiveDeadlineExceeded(HttpServletRequest request) {
        Object receipt = request.getAttribute(RECEIPT_NANOS_ATTRIBUTE);
        return receipt instanceof Long receiptNanos
                && System.nanoTime() - receiptNanos > Duration.ofSeconds(BODY_RECEIVE_DEADLINE_SECONDS).toNanos();
    }

    private static boolean hasDuplicateAuthenticationHeader(HttpServletRequest request) {
        for (String header : List.of(
                AUTH_VERSION, KEY_ID, TIMESTAMP, NONCE, CORRELATION_ID,
                OPERATION_ID, METADATA_SHA256, PART_MANIFEST_SHA256, SIGNATURE,
                "X-Pdf-Operation-Uuid", "X-Pdf-Lease-Epoch",
                "X-Pdf-Operation-Manifest-Sha256", "X-Pdf-Input-Fingerprint",
                "X-Pdf-Policy-Version-Id", "X-Pdf-Policy-Version-Uuid",
                "X-Pdf-Policy-Sha256", "X-Pdf-Config-Bundle-Sha256"
        )) {
            if (java.util.Collections.list(request.getHeaders(header)).size() > 1) {
                return true;
            }
        }
        return false;
    }

    public static String canonicalString(
            String version,
            String keyId,
            String method,
            String pathAndQuery,
            String metadataSha256,
            String partManifestSha256,
            String timestamp,
            String nonce,
            String correlationUuid,
            String operationUuid
    ) {
        return String.join("\n",
                version,
                keyId,
                method.toUpperCase(Locale.ROOT),
                pathAndQuery,
                metadataSha256.toLowerCase(Locale.ROOT),
                partManifestSha256.toLowerCase(Locale.ROOT),
                timestamp,
                nonce,
                correlationUuid,
                operationUuid
        );
    }

    private static Map<String, Object> requestMetadata(
            HttpServletRequest request,
            String correlationUuid,
            String operationUuid
    ) {
        Map<String, Object> metadata = new TreeMap<>();
        metadata.put("correlation_uuid", correlationUuid);
        metadata.put("operation_uuid", operationUuid);
        metadata.put("version", "pdf-request-metadata-v1");
        if ("-".equals(operationUuid)) {
            for (String header : List.of(
                    "X-Pdf-Operation-Uuid",
                    "X-Pdf-Lease-Epoch",
                    "X-Pdf-Operation-Manifest-Sha256",
                    "X-Pdf-Input-Fingerprint",
                    "X-Pdf-Policy-Version-Id",
                    "X-Pdf-Policy-Version-Uuid",
                    "X-Pdf-Policy-Sha256",
                    "X-Pdf-Config-Bundle-Sha256"
            )) {
                if (!isBlank(request.getHeader(header))) {
                    throw new IllegalArgumentException("Operation metadata is forbidden for a non-operation request");
                }
            }
            return metadata;
        }
        if (!operationUuid.equals(request.getHeader("X-Pdf-Operation-Uuid"))) {
            throw new IllegalArgumentException("Operation UUID headers disagree");
        }
        long leaseEpoch = positiveLong(request.getHeader("X-Pdf-Lease-Epoch"));
        long policyVersionId = positiveLong(request.getHeader("X-Pdf-Policy-Version-Id"));
        String manifestHash = requiredHex(request.getHeader("X-Pdf-Operation-Manifest-Sha256"));
        String inputFingerprint = requiredHex(request.getHeader("X-Pdf-Input-Fingerprint"));
        String policyHash = requiredHex(request.getHeader("X-Pdf-Policy-Sha256"));
        String configBundleHash = requiredHex(request.getHeader("X-Pdf-Config-Bundle-Sha256"));
        String policyVersionUuid = request.getHeader("X-Pdf-Policy-Version-Uuid");
        if (!validUuid(policyVersionUuid)) {
            throw new IllegalArgumentException("Signing policy version UUID is invalid");
        }
        metadata.put("config_bundle_hash", configBundleHash);
        metadata.put("input_fingerprint", inputFingerprint);
        metadata.put("lease_epoch", leaseEpoch);
        metadata.put("operation_input_manifest_hash", manifestHash);
        metadata.put("policy_hash", policyHash);
        metadata.put("signing_policy_version_id", policyVersionId);
        metadata.put("signing_policy_version_uuid", policyVersionUuid);
        return metadata;
    }

    private static String canonicalPathAndQuery(HttpServletRequest request) {
        String path = request.getRequestURI();
        if (isBlank(path) || !path.startsWith("/") || path.contains("//") || path.contains("\\")) {
            throw new IllegalArgumentException("Request path is not canonical");
        }
        validateRfc3986Component(path, true, false);
        for (String segment : path.split("/", -1)) {
            if (".".equals(segment) || "..".equals(segment)) {
                throw new IllegalArgumentException("Dot path segments are forbidden");
            }
        }
        String query = request.getQueryString();
        if (query == null) {
            return path;
        }
        if (query.isEmpty()) {
            return path + "?";
        }
        Set<String> keys = new HashSet<>();
        List<String> orderedKeys = new java.util.ArrayList<>();
        for (String pair : query.split("&", -1)) {
            int separator = pair.indexOf('=');
            String key = separator >= 0 ? pair.substring(0, separator) : pair;
            String value = separator >= 0 ? pair.substring(separator + 1) : "";
            if (key.isEmpty() || !keys.add(key)) {
                throw new IllegalArgumentException("Query keys must be unique and non-empty");
            }
            validateRfc3986Component(key, false, true);
            validateRfc3986Component(value, false, true);
            orderedKeys.add(key);
        }
        List<String> sortedKeys = new java.util.ArrayList<>(orderedKeys);
        sortedKeys.sort(String::compareTo);
        if (!orderedKeys.equals(sortedKeys)) {
            throw new IllegalArgumentException("Query keys must use ASCII sort order");
        }
        return path + "?" + query;
    }

    private static void validateRfc3986Component(String value, boolean path, boolean query) {
        for (int index = 0; index < value.length(); index++) {
            char current = value.charAt(index);
            if (current == '%') {
                if (index + 2 >= value.length()
                        || !isUpperHex(value.charAt(index + 1))
                        || !isUpperHex(value.charAt(index + 2))) {
                    throw new IllegalArgumentException("Percent encoding must use uppercase hexadecimal");
                }
                int decoded = Character.digit(value.charAt(index + 1), 16) * 16
                        + Character.digit(value.charAt(index + 2), 16);
                if (isUnreserved((char) decoded) || decoded == '/' || decoded == '\\') {
                    throw new IllegalArgumentException("Percent encoding is not minimal");
                }
                index += 2;
                continue;
            }
            if (current > 0x7f || !(isUnreserved(current)
                    || isSubDelimiter(current)
                    || current == ':'
                    || current == '@'
                    || (path && current == '/')
                    || (query && (current == '/' || current == '?' || current == '=')))) {
                throw new IllegalArgumentException("Request target contains a non-RFC3986 character");
            }
        }
    }

    private static boolean isUpperHex(char value) {
        return value >= '0' && value <= '9' || value >= 'A' && value <= 'F';
    }

    private static boolean isUnreserved(char value) {
        return value >= 'a' && value <= 'z'
                || value >= 'A' && value <= 'Z'
                || value >= '0' && value <= '9'
                || value == '-' || value == '.' || value == '_' || value == '~';
    }

    private static boolean isSubDelimiter(char value) {
        return value == '!' || value == '$' || value == '&' || value == '\''
                || value == '(' || value == ')' || value == '*' || value == '+'
                || value == ',' || value == ';' || value == '=';
    }

    private static String bodyManifestSha256(byte[] body, String contentType) {
        if (body.length == 0) {
            return CanonicalJson.sha256(Map.of(
                    "parts", List.of(),
                    "version", "pdf-part-manifest-v1"
            ));
        }
        Map<String, Object> part = new TreeMap<>();
        part.put("content_type", normalizeContentType(contentType));
        part.put("length", body.length);
        part.put("name", "body");
        part.put("sha256", sha256(body));
        return CanonicalJson.sha256(Map.of(
                "parts", List.of(part),
                "version", "pdf-part-manifest-v1"
        ));
    }

    static String normalizeContentType(String value) {
        if (isBlank(value)) {
            return "application/octet-stream";
        }
        return value.replaceAll("\\s+", "").toLowerCase(Locale.ROOT);
    }

    private static long positiveLong(String value) {
        try {
            long parsed = Long.parseLong(value);
            if (parsed < 1) {
                throw new IllegalArgumentException("Positive integer required");
            }
            return parsed;
        } catch (RuntimeException exception) {
            throw new IllegalArgumentException("Positive integer required", exception);
        }
    }

    private static String requiredHex(String value) {
        String normalized = normalizeHex(value);
        if (normalized == null) {
            throw new IllegalArgumentException("SHA-256 value is invalid");
        }
        return normalized;
    }

    private static boolean validUuid(String value) {
        try {
            return value != null && UUID.fromString(value).toString().equals(value);
        } catch (IllegalArgumentException exception) {
            return false;
        }
    }

    private static boolean isMultipart(HttpServletRequest request) {
        String contentType = request.getContentType();
        return contentType != null && contentType.toLowerCase(Locale.ROOT).startsWith(MediaType.MULTIPART_FORM_DATA_VALUE);
    }

    private static byte[] readBounded(HttpServletRequest request, int maxBytes) throws IOException, BodyTooLargeException {
        try (ByteArrayOutputStream output = new ByteArrayOutputStream()) {
            byte[] buffer = new byte[8192];
            int total = 0;
            int read;
            while ((read = request.getInputStream().read(buffer)) != -1) {
                total += read;
                if (total > maxBytes) {
                    throw new BodyTooLargeException();
                }
                output.write(buffer, 0, read);
            }
            return output.toByteArray();
        }
    }

    private static String hmacHex(byte[] secret, String canonical) {
        try {
            Mac mac = Mac.getInstance("HmacSHA256");
            mac.init(new SecretKeySpec(secret, "HmacSHA256"));
            return HEX.formatHex(mac.doFinal(canonical.getBytes(StandardCharsets.UTF_8)));
        } catch (Exception exception) {
            throw new IllegalStateException("Unable to calculate PDF request HMAC", exception);
        }
    }

    private static String sha256(byte[] body) {
        try {
            return HEX.formatHex(MessageDigest.getInstance("SHA-256").digest(body));
        } catch (Exception exception) {
            throw new IllegalStateException("Unable to calculate PDF request digest", exception);
        }
    }

    private static boolean constantTimeEquals(String left, String right) {
        return MessageDigest.isEqual(
                left.getBytes(StandardCharsets.US_ASCII),
                right.getBytes(StandardCharsets.US_ASCII)
        );
    }

    private static String normalizeHex(String value) {
        if (value == null || !value.matches("[0-9a-f]{64}")) {
            return null;
        }
        return value;
    }

    private static boolean isBlank(String value) {
        return value == null || value.isBlank();
    }

    private static void reject(HttpServletResponse response, int status, String code) throws IOException {
        response.setStatus(status);
        response.setContentType(MediaType.APPLICATION_JSON_VALUE);
        response.getWriter().write("{\"success\":false,\"error\":\"" + code + "\"}");
    }

    private static final class BodyTooLargeException extends Exception {}
}
