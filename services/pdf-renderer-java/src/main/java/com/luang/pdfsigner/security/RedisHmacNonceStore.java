package com.luang.pdfsigner.security;

import java.time.Duration;
import org.springframework.boot.autoconfigure.condition.ConditionalOnProperty;
import org.springframework.data.redis.core.StringRedisTemplate;
import org.springframework.stereotype.Component;

@Component
@ConditionalOnProperty(name = "pdf.security.hmac.nonce-store", havingValue = "redis", matchIfMissing = true)
public final class RedisHmacNonceStore implements HmacNonceStore {
    private static final String KEY_PREFIX = "pdf-hmac:";

    private final StringRedisTemplate redis;

    public RedisHmacNonceStore(StringRedisTemplate redis) {
        this.redis = redis;
    }

    @Override
    public boolean reserve(String version, String keyId, String nonce, String correlationUuid, Duration ttl) {
        Boolean reserved = redis.opsForValue().setIfAbsent(
                KEY_PREFIX + version + ":" + keyId + ":" + nonce,
                correlationUuid,
                ttl
        );
        return Boolean.TRUE.equals(reserved);
    }
}
