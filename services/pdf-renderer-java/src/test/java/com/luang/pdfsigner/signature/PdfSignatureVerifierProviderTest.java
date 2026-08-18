package com.luang.pdfsigner.signature;

import static org.assertj.core.api.Assertions.assertThat;

import java.security.Security;
import org.bouncycastle.jce.provider.BouncyCastleProvider;
import org.junit.jupiter.api.Test;

class PdfSignatureVerifierProviderTest {
    /**
     * The verifier asks for the BC provider by name but used to leave someone
     * else to register it, which only the signer ever did. A process that had
     * not yet signed anything read every signature as invalid: after a restart,
     * the next approval signature was refused because the document it was about
     * to countersign looked broken.
     */
    @Test
    void registersTheProviderItVerifiesWith() {
        Security.removeProvider(BouncyCastleProvider.PROVIDER_NAME);
        assertThat(Security.getProvider(BouncyCastleProvider.PROVIDER_NAME)).isNull();

        new PdfSignatureVerifier("", "");

        assertThat(Security.getProvider(BouncyCastleProvider.PROVIDER_NAME)).isNotNull();
    }
}
