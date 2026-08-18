package com.luang.pdfsigner.signature;

import java.math.BigInteger;
import java.net.URI;
import java.net.http.HttpClient;
import java.net.http.HttpRequest;
import java.net.http.HttpResponse;
import java.security.MessageDigest;
import java.security.SecureRandom;
import java.time.Duration;
import java.util.HexFormat;
import java.util.Set;
import java.util.stream.Collectors;
import org.bouncycastle.asn1.nist.NISTObjectIdentifiers;
import org.bouncycastle.cert.X509CertificateHolder;
import org.bouncycastle.cms.jcajce.JcaSimpleSignerInfoVerifierBuilder;
import org.bouncycastle.jce.provider.BouncyCastleProvider;
import org.bouncycastle.tsp.TimeStampRequest;
import org.bouncycastle.tsp.TimeStampRequestGenerator;
import org.bouncycastle.tsp.TimeStampResponse;
import org.bouncycastle.tsp.TimeStampToken;
import org.springframework.beans.factory.annotation.Value;
import org.springframework.stereotype.Component;

@Component
public final class HttpRfc3161TimestampClient implements Rfc3161TimestampClient {
    private static final HexFormat HEX = HexFormat.of();

    private final URI tsaUri;
    private final String policyOid;
    private final Set<String> trustedCertificateFingerprints;
    private final Duration timeout;
    private final HttpClient client;
    private final SecureRandom random = new SecureRandom();

    public HttpRfc3161TimestampClient(
            @Value("${pdf.pades-bt.tsa-url:}") String tsaUrl,
            @Value("${pdf.pades-bt.tsa-policy-oid:}") String policyOid,
            @Value("${pdf.pades-bt.tsa-trusted-certificate-sha256:}") String fingerprints,
            @Value("${pdf.pades-bt.tsa-timeout-seconds:10}") int timeoutSeconds
    ) {
        this.tsaUri = tsaUrl == null || tsaUrl.isBlank() ? null : URI.create(tsaUrl);
        this.policyOid = policyOid == null || policyOid.isBlank() ? null : policyOid;
        this.trustedCertificateFingerprints = parseFingerprints(fingerprints);
        if (timeoutSeconds < 1 || timeoutSeconds > 60) {
            throw new IllegalStateException("RFC 3161 timeout must be between 1 and 60 seconds");
        }
        this.timeout = Duration.ofSeconds(timeoutSeconds);
        this.client = HttpClient.newBuilder().connectTimeout(this.timeout).build();
    }

    @Override
    public void requireReadyConfiguration() {
        if (tsaUri == null || trustedCertificateFingerprints.isEmpty()) {
            throw new IllegalStateException("PAdES-B-T requires a TSA URL and certificate fingerprint allowlist");
        }
    }

    @Override
    public TimeStampToken timestamp(byte[] signatureValue) throws Exception {
        requireReadyConfiguration();
        TimeStampRequestGenerator generator = new TimeStampRequestGenerator();
        generator.setCertReq(true);
        if (policyOid != null) {
            generator.setReqPolicy(policyOid);
        }
        BigInteger nonce = new BigInteger(160, random);
        TimeStampRequest request = generator.generate(
                NISTObjectIdentifiers.id_sha256,
                MessageDigest.getInstance("SHA-256").digest(signatureValue),
                nonce
        );
        HttpRequest httpRequest = HttpRequest.newBuilder(tsaUri)
                .timeout(timeout)
                .header("Content-Type", "application/timestamp-query")
                .header("Accept", "application/timestamp-reply")
                .POST(HttpRequest.BodyPublishers.ofByteArray(request.getEncoded()))
                .build();
        HttpResponse<byte[]> httpResponse = client.send(httpRequest, HttpResponse.BodyHandlers.ofByteArray());
        if (httpResponse.statusCode() / 100 != 2) {
            throw new IllegalStateException("RFC 3161 service returned HTTP " + httpResponse.statusCode());
        }
        TimeStampResponse response = new TimeStampResponse(httpResponse.body());
        response.validate(request);
        TimeStampToken token = response.getTimeStampToken();
        if (token == null) {
            throw new IllegalStateException("RFC 3161 response did not contain a timestamp token");
        }
        java.util.Collection<?> matches = token.getCertificates().getMatches(token.getSID());
        if (matches.isEmpty()) {
            throw new IllegalStateException("RFC 3161 token omitted its signer certificate");
        }
        X509CertificateHolder certificate = (X509CertificateHolder) matches.iterator().next();
        String fingerprint = HEX.formatHex(MessageDigest.getInstance("SHA-256").digest(certificate.getEncoded()));
        if (!trustedCertificateFingerprints.contains(fingerprint)) {
            throw new IllegalStateException("RFC 3161 token signer is not in the configured trust allowlist");
        }
        token.validate(new JcaSimpleSignerInfoVerifierBuilder()
                .setProvider(BouncyCastleProvider.PROVIDER_NAME)
                .build(certificate));
        return token;
    }

    private static Set<String> parseFingerprints(String raw) {
        if (raw == null || raw.isBlank()) {
            return Set.of();
        }
        return java.util.Arrays.stream(raw.split(","))
                .map(value -> value.replace(":", "").trim().toLowerCase())
                .filter(value -> !value.isBlank())
                .peek(value -> {
                    if (!value.matches("[0-9a-f]{64}")) {
                        throw new IllegalStateException("TSA certificate fingerprints must be SHA-256 hex");
                    }
                })
                .collect(Collectors.toUnmodifiableSet());
    }
}
