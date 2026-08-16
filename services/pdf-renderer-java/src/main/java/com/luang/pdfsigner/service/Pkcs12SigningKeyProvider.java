package com.luang.pdfsigner.service;

import java.io.File;
import java.io.FileInputStream;
import java.io.InputStream;
import java.security.KeyStore;
import java.security.PrivateKey;
import java.security.cert.Certificate;
import org.springframework.stereotype.Component;

@Component
public final class Pkcs12SigningKeyProvider {
    public SigningKeyMaterial load() throws Exception {
        String configuredPath = config("DEFAULT_PFX_PATH");
        String password = config("DEFAULT_PFX_PASS");
        if (password == null) {
            throw new IllegalStateException("DEFAULT_PFX_PASS is required; default keystore passwords are forbidden");
        }

        String path = configuredPath != null ? configuredPath : resolveDefaultPath();
        KeyStore keyStore = KeyStore.getInstance("PKCS12");
        try (InputStream input = new FileInputStream(path)) {
            keyStore.load(input, password.toCharArray());
        }
        if (!keyStore.aliases().hasMoreElements()) {
            throw new IllegalStateException("The configured PKCS#12 keystore contains no signing identity");
        }

        String alias = keyStore.aliases().nextElement();
        PrivateKey privateKey = (PrivateKey) keyStore.getKey(alias, password.toCharArray());
        Certificate[] chain = keyStore.getCertificateChain(alias);
        if (privateKey == null || chain == null || chain.length == 0) {
            throw new IllegalStateException("The configured PKCS#12 alias does not contain a private key and certificate chain");
        }
        return new SigningKeyMaterial(privateKey, chain);
    }

    public boolean ready() {
        try {
            load();
            return true;
        } catch (Exception exception) {
            return false;
        }
    }

    private static String config(String name) {
        String value = System.getenv(name);
        if (value == null || value.isBlank()) {
            value = System.getProperty(name);
        }
        return value == null || value.isBlank() ? null : value;
    }

    private static String resolveDefaultPath() {
        File dockerPath = new File("/keys/signer.pfx");
        if (dockerPath.exists()) {
            return dockerPath.getPath();
        }
        File localPath = new File("keys/signer.pfx");
        if (localPath.exists()) {
            return localPath.getPath();
        }
        return "/keys/signer.pfx";
    }

    public record SigningKeyMaterial(PrivateKey privateKey, Certificate[] certificateChain) {}
}
