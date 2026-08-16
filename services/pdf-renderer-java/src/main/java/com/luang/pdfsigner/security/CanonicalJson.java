package com.luang.pdfsigner.security;

import com.fasterxml.jackson.databind.ObjectMapper;
import com.fasterxml.jackson.databind.SerializationFeature;
import java.nio.charset.StandardCharsets;
import java.security.MessageDigest;
import java.util.HexFormat;
import java.util.List;
import java.util.Map;

final class CanonicalJson {
    private static final long MAX_SAFE_INTEGER = 9_007_199_254_740_991L;
    private static final ObjectMapper MAPPER = new ObjectMapper()
            .configure(SerializationFeature.ORDER_MAP_ENTRIES_BY_KEYS, true);
    private static final HexFormat HEX = HexFormat.of();

    private CanonicalJson() {}

    static String encode(Object value) {
        try {
            validate(value);
            return MAPPER.writeValueAsString(value);
        } catch (Exception exception) {
            throw new IllegalArgumentException("Unable to encode canonical request metadata", exception);
        }
    }

    private static void validate(Object value) {
        if (value == null || value instanceof Boolean) {
            return;
        }
        if (value instanceof String string) {
            validateUnicode(string);
            return;
        }
        if (value instanceof Byte || value instanceof Short || value instanceof Integer || value instanceof Long) {
            long number = ((Number) value).longValue();
            if (number < -MAX_SAFE_INTEGER || number > MAX_SAFE_INTEGER) {
                throw new IllegalArgumentException("Integer exceeds the RFC 8785 interoperable range");
            }
            return;
        }
        if (value instanceof Map<?, ?> map) {
            for (Map.Entry<?, ?> entry : map.entrySet()) {
                if (!(entry.getKey() instanceof String key) || !key.matches("[\\x20-\\x7E]+")) {
                    throw new IllegalArgumentException("Canonical request object keys must be printable ASCII");
                }
                validate(entry.getValue());
            }
            return;
        }
        if (value instanceof List<?> list) {
            list.forEach(CanonicalJson::validate);
            return;
        }
        throw new IllegalArgumentException("Unsupported value type in canonical request JSON: " + value.getClass());
    }

    private static void validateUnicode(String value) {
        for (int index = 0; index < value.length(); index++) {
            char current = value.charAt(index);
            if (Character.isHighSurrogate(current)) {
                if (index + 1 >= value.length() || !Character.isLowSurrogate(value.charAt(index + 1))) {
                    throw new IllegalArgumentException("Canonical request strings must contain valid Unicode");
                }
                index++;
            } else if (Character.isLowSurrogate(current)) {
                throw new IllegalArgumentException("Canonical request strings must contain valid Unicode");
            }
        }
    }

    static String sha256(Object value) {
        try {
            return HEX.formatHex(MessageDigest.getInstance("SHA-256")
                    .digest(encode(value).getBytes(StandardCharsets.UTF_8)));
        } catch (Exception exception) {
            throw new IllegalStateException("Unable to hash canonical request metadata", exception);
        }
    }
}
