package com.luang.pdfsigner.signature;

import java.io.OutputStream;
import java.math.BigInteger;
import java.nio.file.Files;
import java.nio.file.Path;
import java.security.KeyPair;
import java.security.KeyPairGenerator;
import java.security.KeyStore;
import java.security.MessageDigest;
import java.security.SecureRandom;
import java.security.Security;
import java.security.cert.X509Certificate;
import java.time.Instant;
import java.util.Date;
import java.util.HexFormat;
import org.bouncycastle.asn1.x500.X500Name;
import org.bouncycastle.asn1.x509.BasicConstraints;
import org.bouncycastle.asn1.x509.ExtendedKeyUsage;
import org.bouncycastle.asn1.x509.Extension;
import org.bouncycastle.asn1.x509.KeyPurposeId;
import org.bouncycastle.asn1.x509.KeyUsage;
import org.bouncycastle.cert.jcajce.JcaX509CertificateConverter;
import org.bouncycastle.cert.jcajce.JcaX509v3CertificateBuilder;
import org.bouncycastle.jce.provider.BouncyCastleProvider;
import org.bouncycastle.operator.jcajce.JcaContentSignerBuilder;

final class TestSigningIdentity {
    private static final char[] PASSWORD = "phase0a-test-password".toCharArray();
    private final String certificateFingerprint;
    private final String rootFingerprint;

    TestSigningIdentity() throws Exception {
        if (Security.getProvider(BouncyCastleProvider.PROVIDER_NAME) == null) {
            Security.addProvider(new BouncyCastleProvider());
        }
        KeyPairGenerator generator = KeyPairGenerator.getInstance("RSA");
        generator.initialize(2048);
        KeyPair rootPair = generator.generateKeyPair();
        KeyPair pair = generator.generateKeyPair();
        X500Name rootName = new X500Name("CN=LIMS Test Root CA,O=LIMS Test,C=CN");
        X500Name name = new X500Name("CN=LIMS Organization PDF Signer,O=LIMS Test,C=CN");
        Instant now = Instant.now();
        JcaX509v3CertificateBuilder rootBuilder = new JcaX509v3CertificateBuilder(
                rootName,
                new BigInteger(160, new SecureRandom()),
                Date.from(now.minusSeconds(3600)),
                Date.from(now.plusSeconds(86400)),
                rootName,
                rootPair.getPublic()
        );
        rootBuilder.addExtension(Extension.basicConstraints, true, new BasicConstraints(1));
        rootBuilder.addExtension(Extension.keyUsage, true,
                new KeyUsage(KeyUsage.keyCertSign | KeyUsage.cRLSign));
        X509Certificate root = new JcaX509CertificateConverter()
                .setProvider(BouncyCastleProvider.PROVIDER_NAME)
                .getCertificate(rootBuilder.build(new JcaContentSignerBuilder("SHA256withRSA")
                        .setProvider(BouncyCastleProvider.PROVIDER_NAME)
                        .build(rootPair.getPrivate())));
        JcaX509v3CertificateBuilder builder = new JcaX509v3CertificateBuilder(
                rootName,
                new BigInteger(160, new SecureRandom()),
                Date.from(now.minusSeconds(3600)),
                Date.from(now.plusSeconds(86400)),
                name,
                pair.getPublic()
        );
        builder.addExtension(Extension.basicConstraints, true, new BasicConstraints(false));
        builder.addExtension(Extension.keyUsage, true,
                new KeyUsage(KeyUsage.digitalSignature | KeyUsage.nonRepudiation));
        builder.addExtension(Extension.extendedKeyUsage, false,
                new ExtendedKeyUsage(KeyPurposeId.getInstance(
                        new org.bouncycastle.asn1.ASN1ObjectIdentifier("1.3.6.1.5.5.7.3.36"))));
        X509Certificate certificate = new JcaX509CertificateConverter()
                .setProvider(BouncyCastleProvider.PROVIDER_NAME)
                .getCertificate(builder.build(new JcaContentSignerBuilder("SHA256withRSA")
                        .setProvider(BouncyCastleProvider.PROVIDER_NAME)
                        .build(rootPair.getPrivate())));
        this.certificateFingerprint = HexFormat.of().formatHex(
                MessageDigest.getInstance("SHA-256").digest(certificate.getEncoded()));
        this.rootFingerprint = HexFormat.of().formatHex(
                MessageDigest.getInstance("SHA-256").digest(root.getEncoded()));

        KeyStore keyStore = KeyStore.getInstance("PKCS12");
        keyStore.load(null, PASSWORD);
        keyStore.setKeyEntry("signer", pair.getPrivate(), PASSWORD,
                new java.security.cert.Certificate[] {certificate, root});
        Path path = Files.createTempFile("lims-phase0a-signer-", ".p12");
        path.toFile().deleteOnExit();
        try (OutputStream output = Files.newOutputStream(path)) {
            keyStore.store(output, PASSWORD);
        }
        System.setProperty("DEFAULT_PFX_PATH", path.toString());
        System.setProperty("DEFAULT_PFX_PASS", new String(PASSWORD));
    }

    String certificateFingerprint() {
        return certificateFingerprint;
    }

    String rootFingerprint() {
        return rootFingerprint;
    }
}
