package com.luang.pdfsigner.signature;

import com.luang.pdfsigner.service.Pkcs12SigningKeyProvider.SigningKeyMaterial;
import java.security.MessageDigest;
import java.security.cert.Certificate;
import java.security.cert.X509Certificate;
import java.security.interfaces.RSAPublicKey;
import java.util.Arrays;
import java.util.HexFormat;
import java.util.List;
import java.util.Set;
import java.util.stream.Collectors;
import org.springframework.beans.factory.annotation.Value;
import org.springframework.stereotype.Component;

@Component
public final class DocumentSigningCertificatePolicy {
    private static final String DOCUMENT_SIGNING_EKU = "1.3.6.1.5.5.7.3.36";
    private static final HexFormat HEX = HexFormat.of();
    private final Set<String> trustedRootFingerprints;

    public DocumentSigningCertificatePolicy(
            @Value("${pdf.pades-bt.document-signer-trusted-root-sha256:}") String fingerprints
    ) {
        this.trustedRootFingerprints = parseFingerprints(fingerprints);
    }

    public String validate(SigningKeyMaterial material) throws Exception {
        if (trustedRootFingerprints.isEmpty()) {
            throw new IllegalStateException("A versioned document-signing trust root allowlist is required");
        }
        Certificate[] rawChain = material.certificateChain();
        if (rawChain.length < 2 || !(rawChain[0] instanceof X509Certificate leaf)) {
            throw new IllegalStateException("The document-signing identity requires a leaf and trust chain");
        }
        leaf.checkValidity();
        if (leaf.getBasicConstraints() >= 0) {
            throw new IllegalStateException("The document-signing leaf certificate must have CA=false");
        }
        if (!(leaf.getPublicKey() instanceof RSAPublicKey rsa) || rsa.getModulus().bitLength() < 2048) {
            throw new IllegalStateException("The document-signing leaf must use RSA with at least 2048 bits");
        }
        boolean[] keyUsage = leaf.getKeyUsage();
        if (keyUsage == null || keyUsage.length < 2 || (!keyUsage[0] && !keyUsage[1])) {
            throw new IllegalStateException("The document-signing leaf requires digitalSignature or nonRepudiation KeyUsage");
        }
        List<String> extendedKeyUsage = leaf.getExtendedKeyUsage();
        if (extendedKeyUsage == null || !extendedKeyUsage.contains(DOCUMENT_SIGNING_EKU)) {
            throw new IllegalStateException("The document-signing leaf requires the documentSigning EKU");
        }

        for (int index = 0; index < rawChain.length; index++) {
            if (!(rawChain[index] instanceof X509Certificate certificate)) {
                throw new IllegalStateException("The document-signing chain must contain only X.509 certificates");
            }
            certificate.checkValidity();
            if (index + 1 < rawChain.length) {
                certificate.verify(rawChain[index + 1].getPublicKey());
            }
        }
        X509Certificate root = (X509Certificate) rawChain[rawChain.length - 1];
        if (root.getBasicConstraints() < 0) {
            throw new IllegalStateException("The document-signing trust root must be a CA certificate");
        }
        root.verify(root.getPublicKey());
        if (!trustedRootFingerprints.contains(fingerprint(root))) {
            throw new IllegalStateException("The document-signing chain does not terminate at an allowed trust root");
        }
        return fingerprint(leaf);
    }

    private static String fingerprint(X509Certificate certificate) throws Exception {
        return HEX.formatHex(MessageDigest.getInstance("SHA-256").digest(certificate.getEncoded()));
    }

    private static Set<String> parseFingerprints(String raw) {
        if (raw == null || raw.isBlank()) {
            return Set.of();
        }
        return Arrays.stream(raw.split(","))
                .map(value -> value.replace(":", "").trim().toLowerCase())
                .filter(value -> !value.isBlank())
                .peek(value -> {
                    if (!value.matches("[0-9a-f]{64}")) {
                        throw new IllegalStateException("Document trust root fingerprints must be SHA-256 hex");
                    }
                })
                .collect(Collectors.toUnmodifiableSet());
    }
}
