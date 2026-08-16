package com.luang.pdfsigner.security;

import java.time.Duration;

public interface HmacNonceStore {
    boolean reserve(String version, String keyId, String nonce, String correlationUuid, Duration ttl);
}
