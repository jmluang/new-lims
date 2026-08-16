package com.luang.pdfsigner.security;

import java.util.Base64;
import java.util.LinkedHashMap;
import java.util.Map;
import org.springframework.beans.factory.annotation.Value;
import org.springframework.stereotype.Component;

@Component
public final class PdfHmacProperties {
    private final boolean enabled;
    private final String activeKeyId;
    private final Map<String, byte[]> keys;
    private final long maxClockSkewSeconds;

    public PdfHmacProperties(
            @Value("${pdf.security.hmac.enabled:true}") boolean enabled,
            @Value("${pdf.security.hmac.active-key-id:primary}") String activeKeyId,
            @Value("${pdf.security.hmac.keys:}") String encodedKeys,
            @Value("${pdf.security.hmac.max-clock-skew-seconds:60}") long maxClockSkewSeconds
    ) {
        this.enabled = enabled;
        this.activeKeyId = activeKeyId;
        this.keys = parseKeys(encodedKeys);
        this.maxClockSkewSeconds = maxClockSkewSeconds;
    }

    public boolean enabled() {
        return enabled;
    }

    public String activeKeyId() {
        return activeKeyId;
    }

    public byte[] key(String keyId) {
        return keys.get(keyId);
    }

    public long maxClockSkewSeconds() {
        return maxClockSkewSeconds;
    }

    public boolean ready() {
        return !enabled || keys.containsKey(activeKeyId);
    }

    private static Map<String, byte[]> parseKeys(String encodedKeys) {
        Map<String, byte[]> parsed = new LinkedHashMap<>();
        if (encodedKeys == null || encodedKeys.isBlank()) {
            return Map.of();
        }

        for (String entry : encodedKeys.split(",")) {
            String[] parts = entry.trim().split(":", 2);
            if (parts.length != 2 || !parts[0].matches("[A-Za-z0-9._-]{1,64}") || parts[1].isBlank()) {
                throw new IllegalStateException("PDF_SERVICE_HMAC_KEYS must use key-id:base64-secret entries");
            }
            byte[] secret;
            try {
                secret = Base64.getDecoder().decode(parts[1]);
            } catch (IllegalArgumentException exception) {
                throw new IllegalStateException("PDF service HMAC key is not valid Base64", exception);
            }
            if (secret.length < 32) {
                throw new IllegalStateException("PDF service HMAC keys must contain at least 32 random bytes");
            }
            if (parsed.putIfAbsent(parts[0], secret) != null) {
                throw new IllegalStateException("Duplicate PDF service HMAC key id: " + parts[0]);
            }
        }
        return Map.copyOf(parsed);
    }
}
