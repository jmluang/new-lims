package com.luang.pdfsigner.service;

import org.apache.pdfbox.cos.COSArray;
import org.apache.pdfbox.cos.COSName;
import org.apache.pdfbox.pdmodel.interactive.digitalsignature.SignatureInterface;
import org.bouncycastle.cms.*;
import org.bouncycastle.operator.ContentSigner;
import org.bouncycastle.operator.jcajce.JcaContentSignerBuilder;
import org.bouncycastle.operator.jcajce.JcaDigestCalculatorProviderBuilder;
import org.bouncycastle.cms.jcajce.JcaSignerInfoGeneratorBuilder;
import org.bouncycastle.operator.OperatorCreationException;
import org.bouncycastle.cert.jcajce.JcaCertStore;
import org.bouncycastle.cert.jcajce.JcaX509CertificateHolder;
import org.bouncycastle.util.Store;

import java.io.IOException;
import java.io.InputStream;
import java.security.PrivateKey;
import java.security.Security;
import java.security.cert.Certificate;
import java.security.cert.X509Certificate;
import java.security.cert.CertificateEncodingException;
import java.util.Arrays;
import java.util.Collection;
import java.util.Collections;

public class SimpleSignatureInterface implements SignatureInterface {

    private final PrivateKey privateKey;
    private final Certificate[] chain;
    private final String hashAlgo;
    private final boolean tsaEnabled;
    private final String tsaUrl;

    public SimpleSignatureInterface(PrivateKey privateKey, Certificate[] chain, String hashAlgo, boolean tsaEnabled, String tsaUrl) {
        this.privateKey = privateKey;
        this.chain = chain;
        this.hashAlgo = (hashAlgo == null) ? "SHA256" : hashAlgo;
        this.tsaEnabled = tsaEnabled;
        this.tsaUrl = tsaUrl;
    }

    @Override
    public byte[] sign(InputStream content) throws IOException {
        try {
            // 确保已注册 BouncyCastle Provider
            if (Security.getProvider("BC") == null) {
                Security.addProvider(new org.bouncycastle.jce.provider.BouncyCastleProvider());
            }
            String sigAlg = hashAlgo + "withRSA"; // e.g., SHA256withRSA
            ContentSigner contentSigner = new JcaContentSignerBuilder(sigAlg)
                    .setProvider("BC")
                    .build(privateKey);

            CMSSignedDataGenerator gen = new CMSSignedDataGenerator();
            X509Certificate cert = (X509Certificate) chain[0];
            try {
                Store certStore = new JcaCertStore(Arrays.asList(chain));
                JcaSignerInfoGeneratorBuilder sigInfoGenBuilder = new JcaSignerInfoGeneratorBuilder(new JcaDigestCalculatorProviderBuilder().setProvider("BC").build());
                gen.addSignerInfoGenerator(sigInfoGenBuilder.build(contentSigner, new JcaX509CertificateHolder(cert)));
                gen.addCertificates(certStore);
            } catch (CertificateEncodingException e) {
                throw new IOException("Certificate encoding failed: " + e.getMessage(), e);
            } catch (Exception e) {
                throw new IOException("Signer initialization failed: " + e.getMessage(), e);
            }

            CMSProcessableByteArray msg = new CMSProcessableByteArray(content.readAllBytes());
            // 必须使用 DETACHED 模式以匹配 /SubFilter ETSI.CAdES.detached
            CMSSignedData signedData = gen.generate(msg, false);

            // TODO: 如果启用 TSA，在此对签名进行时间戳增强（CAdES-T），并返回更新后的 CMS
            return signedData.getEncoded();
        } catch (OperatorCreationException | CMSException e) {
            throw new IOException("签名生成失败: " + e.getMessage(), e);
        }
    }
}
