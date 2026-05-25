package com.luang.pdfsigner.service;

import org.bouncycastle.asn1.pkcs.PrivateKeyInfo;
import org.bouncycastle.cert.jcajce.JcaX509CertificateConverter;
import org.bouncycastle.openssl.PEMKeyPair;
import org.bouncycastle.openssl.PEMParser;
import org.bouncycastle.openssl.jcajce.JcaPEMKeyConverter;
import org.bouncycastle.openssl.jcajce.JceOpenSSLPKCS8DecryptorProviderBuilder;
import org.bouncycastle.operator.InputDecryptorProvider;

import java.io.FileReader;
import java.io.IOException;
import java.io.Reader;
import java.security.PrivateKey;
import java.security.Security;
import java.security.cert.Certificate;
import java.security.cert.X509Certificate;
import java.util.ArrayList;
import java.util.List;

record PemMaterial(PrivateKey privateKey, Certificate[] certificateChain) {}

public class PemKeyLoader {
    public static PemMaterial load(String crtPath, String keyPath, String keyPass) throws IOException {
        if (Security.getProvider("BC") == null) {
            Security.addProvider(new org.bouncycastle.jce.provider.BouncyCastleProvider());
        }
        PrivateKey privateKey = loadPrivateKey(keyPath, keyPass);
        Certificate[] chain = loadCertificates(crtPath);
        return new PemMaterial(privateKey, chain);
    }

    private static PrivateKey loadPrivateKey(String keyPath, String keyPass) throws IOException {
        try (Reader r = new FileReader(keyPath); PEMParser parser = new PEMParser(r)) {
            Object obj = parser.readObject();
            JcaPEMKeyConverter converter = new JcaPEMKeyConverter().setProvider("BC");
            if (obj instanceof PEMKeyPair pemKeyPair) {
                return converter.getPrivateKey(pemKeyPair.getPrivateKeyInfo());
            } else if (obj instanceof PrivateKeyInfo pkInfo) {
                return converter.getPrivateKey(pkInfo);
            } else if (obj instanceof org.bouncycastle.openssl.PEMEncryptedKeyPair encPair) {
                if (keyPass == null) throw new IOException("Encrypted key requires password");
                var decProv = new org.bouncycastle.openssl.jcajce.JcePEMDecryptorProviderBuilder().build(keyPass.toCharArray());
                var kp = ((org.bouncycastle.openssl.PEMEncryptedKeyPair) obj).decryptKeyPair(decProv);
                return converter.getPrivateKey(kp.getPrivateKeyInfo());
            } else if (obj instanceof org.bouncycastle.pkcs.PKCS8EncryptedPrivateKeyInfo encPkcs8) {
                if (keyPass == null) throw new IOException("Encrypted PKCS#8 key requires password");
                InputDecryptorProvider decProv = new JceOpenSSLPKCS8DecryptorProviderBuilder().build(keyPass.toCharArray());
                PrivateKeyInfo pkInfo2 = encPkcs8.decryptPrivateKeyInfo(decProv);
                return converter.getPrivateKey(pkInfo2);
            }
            throw new IOException("Unsupported key format");
        } catch (Exception e) {
            throw new IOException("Load private key failed: " + e.getMessage(), e);
        }
    }

    private static Certificate[] loadCertificates(String crtPath) throws IOException {
        try (Reader r = new FileReader(crtPath); PEMParser parser = new PEMParser(r)) {
            List<Certificate> list = new ArrayList<>();
            JcaX509CertificateConverter conv = new JcaX509CertificateConverter().setProvider("BC");
            Object obj;
            while ((obj = parser.readObject()) != null) {
                if (obj instanceof org.bouncycastle.cert.X509CertificateHolder holder) {
                    X509Certificate cert = conv.getCertificate(holder);
                    list.add(cert);
                }
            }
            if (list.isEmpty()) throw new IOException("No certificate found in crt file");
            return list.toArray(new Certificate[0]);
        } catch (Exception e) {
            throw new IOException("Load certificate failed: " + e.getMessage(), e);
        }
    }
}
