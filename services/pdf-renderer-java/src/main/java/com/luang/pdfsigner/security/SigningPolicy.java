package com.luang.pdfsigner.security;

import org.springframework.beans.factory.annotation.Value;
import org.springframework.stereotype.Component;

@Component
public final class SigningPolicy {
    private final String hashAlgorithm;

    public SigningPolicy(
            @Value("${pdf.signing-policy.hash-algorithm:SHA256}") String hashAlgorithm,
            @Value("${pdf.signing-policy.tsa-enabled:false}") boolean tsaEnabled
    ) {
        if (!"SHA256".equalsIgnoreCase(hashAlgorithm)) {
            throw new IllegalStateException("V1 PDF signing policy only permits SHA256");
        }
        if (tsaEnabled) {
            throw new IllegalStateException("TSA must remain disabled until RFC 3161 embedding is implemented and verified");
        }
        this.hashAlgorithm = "SHA256";
    }

    public String hashAlgorithm() {
        return hashAlgorithm;
    }
}
