package com.luang.pdfsigner.security;

import java.time.Duration;
import java.time.Instant;
import java.util.concurrent.ConcurrentHashMap;
import org.springframework.boot.autoconfigure.condition.ConditionalOnProperty;
import org.springframework.stereotype.Component;

@Component
@ConditionalOnProperty(name = "pdf.security.hmac.nonce-store", havingValue = "memory")
public final class InMemoryHmacNonceStore implements HmacNonceStore {
    private final ConcurrentHashMap<String, Reservation> reservations = new ConcurrentHashMap<>();

    @Override
    public boolean reserve(String version, String keyId, String nonce, String correlationUuid, Duration ttl) {
        Instant now = Instant.now();
        reservations.entrySet().removeIf(entry -> !entry.getValue().expiresAt().isAfter(now));
        String key = version + ":" + keyId + ":" + nonce;
        return reservations.putIfAbsent(key, new Reservation(correlationUuid, now.plus(ttl))) == null;
    }

    private record Reservation(String correlationUuid, Instant expiresAt) {}
}
