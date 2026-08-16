package com.luang.pdfsigner.signature;

import java.math.BigInteger;
import java.security.KeyPair;
import java.security.KeyPairGenerator;
import java.security.MessageDigest;
import java.security.SecureRandom;
import java.security.cert.X509Certificate;
import java.util.HexFormat;
import java.time.Instant;
import java.util.Date;
import java.util.List;
import java.util.concurrent.atomic.AtomicLong;
import org.bouncycastle.asn1.ASN1ObjectIdentifier;
import org.bouncycastle.asn1.nist.NISTObjectIdentifiers;
import org.bouncycastle.asn1.x500.X500Name;
import org.bouncycastle.asn1.x509.BasicConstraints;
import org.bouncycastle.asn1.x509.ExtendedKeyUsage;
import org.bouncycastle.asn1.x509.Extension;
import org.bouncycastle.asn1.x509.KeyPurposeId;
import org.bouncycastle.asn1.x509.KeyUsage;
import org.bouncycastle.asn1.x509.AlgorithmIdentifier;
import org.bouncycastle.cert.X509CertificateHolder;
import org.bouncycastle.cert.jcajce.JcaCertStore;
import org.bouncycastle.cert.jcajce.JcaX509CertificateConverter;
import org.bouncycastle.cert.jcajce.JcaX509v3CertificateBuilder;
import org.bouncycastle.cms.jcajce.JcaSignerInfoGeneratorBuilder;
import org.bouncycastle.jce.provider.BouncyCastleProvider;
import org.bouncycastle.operator.ContentSigner;
import org.bouncycastle.operator.DigestCalculator;
import org.bouncycastle.operator.jcajce.JcaContentSignerBuilder;
import org.bouncycastle.operator.jcajce.JcaDigestCalculatorProviderBuilder;
import org.bouncycastle.tsp.TimeStampRequest;
import org.bouncycastle.tsp.TimeStampRequestGenerator;
import org.bouncycastle.tsp.TimeStampToken;
import org.bouncycastle.tsp.TimeStampTokenGenerator;

final class TestTimestampAuthority implements Rfc3161TimestampClient {
    private static final ASN1ObjectIdentifier POLICY = new ASN1ObjectIdentifier("1.2.3.4.5.6.7");
    private final TimeStampTokenGenerator generator;
    private final AtomicLong serial = new AtomicLong(1000);
    private final String certificateFingerprint;

    TestTimestampAuthority() throws Exception {
        KeyPairGenerator keyPairGenerator = KeyPairGenerator.getInstance("RSA");
        keyPairGenerator.initialize(2048);
        KeyPair keyPair = keyPairGenerator.generateKeyPair();
        X500Name name = new X500Name("CN=Test RFC3161 Authority,O=LIMS Test,C=CN");
        Instant now = Instant.now();
        JcaX509v3CertificateBuilder certificateBuilder = new JcaX509v3CertificateBuilder(
                name,
                new BigInteger(160, new SecureRandom()),
                Date.from(now.minusSeconds(3600)),
                Date.from(now.plusSeconds(86400)),
                name,
                keyPair.getPublic()
        );
        certificateBuilder.addExtension(Extension.basicConstraints, true, new BasicConstraints(false));
        certificateBuilder.addExtension(Extension.keyUsage, true, new KeyUsage(KeyUsage.digitalSignature));
        certificateBuilder.addExtension(
                Extension.extendedKeyUsage,
                true,
                new ExtendedKeyUsage(KeyPurposeId.id_kp_timeStamping)
        );
        ContentSigner certificateSigner = new JcaContentSignerBuilder("SHA256withRSA")
                .setProvider(BouncyCastleProvider.PROVIDER_NAME)
                .build(keyPair.getPrivate());
        X509CertificateHolder holder = certificateBuilder.build(certificateSigner);
        X509Certificate certificate = new JcaX509CertificateConverter()
                .setProvider(BouncyCastleProvider.PROVIDER_NAME)
                .getCertificate(holder);
        this.certificateFingerprint = HexFormat.of().formatHex(
                MessageDigest.getInstance("SHA-256").digest(certificate.getEncoded()));
        ContentSigner tokenSigner = new JcaContentSignerBuilder("SHA256withRSA")
                .setProvider(BouncyCastleProvider.PROVIDER_NAME)
                .build(keyPair.getPrivate());
        var digestProvider = new JcaDigestCalculatorProviderBuilder()
                .setProvider(BouncyCastleProvider.PROVIDER_NAME)
                .build();
        DigestCalculator certificateDigest = digestProvider.get(new AlgorithmIdentifier(NISTObjectIdentifiers.id_sha256));
        this.generator = new TimeStampTokenGenerator(
                new JcaSignerInfoGeneratorBuilder(digestProvider).build(tokenSigner, certificate),
                certificateDigest,
                POLICY
        );
        this.generator.addCertificates(new JcaCertStore(List.of(certificate)));
    }

    @Override
    public TimeStampToken timestamp(byte[] signatureValue) throws Exception {
        byte[] imprint = MessageDigest.getInstance("SHA-256").digest(signatureValue);
        TimeStampRequestGenerator requestGenerator = new TimeStampRequestGenerator();
        requestGenerator.setCertReq(true);
        requestGenerator.setReqPolicy(POLICY);
        TimeStampRequest request = requestGenerator.generate(
                NISTObjectIdentifiers.id_sha256,
                imprint,
                BigInteger.valueOf(serial.incrementAndGet())
        );
        return generator.generate(request, BigInteger.valueOf(serial.incrementAndGet()), new Date());
    }

    String certificateFingerprint() {
        return certificateFingerprint;
    }
}
