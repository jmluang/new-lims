package com.luang.pdfsigner.signature;

import com.luang.pdfsigner.service.Pkcs12SigningKeyProvider.SigningKeyMaterial;
import java.io.InputStream;
import java.io.OutputStream;
import java.security.MessageDigest;
import java.security.Security;
import java.security.cert.X509Certificate;
import java.util.Arrays;
import java.util.Hashtable;
import org.bouncycastle.asn1.DERSet;
import org.bouncycastle.asn1.cms.Attribute;
import org.bouncycastle.asn1.cms.AttributeTable;
import org.bouncycastle.asn1.cms.CMSAttributes;
import org.bouncycastle.asn1.ess.ESSCertIDv2;
import org.bouncycastle.asn1.ess.SigningCertificateV2;
import org.bouncycastle.asn1.pkcs.PKCSObjectIdentifiers;
import org.bouncycastle.cert.jcajce.JcaCertStore;
import org.bouncycastle.cert.jcajce.JcaX509CertificateHolder;
import org.bouncycastle.cms.CMSException;
import org.bouncycastle.cms.CMSTypedData;
import org.bouncycastle.cms.CMSSignedData;
import org.bouncycastle.cms.CMSSignedDataGenerator;
import org.bouncycastle.cms.DefaultSignedAttributeTableGenerator;
import org.bouncycastle.cms.SignerInformation;
import org.bouncycastle.cms.SignerInformationStore;
import org.bouncycastle.cms.jcajce.JcaSignerInfoGeneratorBuilder;
import org.bouncycastle.jce.provider.BouncyCastleProvider;
import org.bouncycastle.operator.ContentSigner;
import org.bouncycastle.operator.jcajce.JcaContentSignerBuilder;
import org.bouncycastle.operator.jcajce.JcaDigestCalculatorProviderBuilder;
import org.bouncycastle.asn1.cms.CMSObjectIdentifiers;

final class PadesCmsSigner {
    private final Rfc3161TimestampClient timestampClient;

    PadesCmsSigner(Rfc3161TimestampClient timestampClient) {
        this.timestampClient = timestampClient;
    }

    byte[] sign(InputStream content, SigningKeyMaterial material) throws Exception {
        ensureProvider();
        // Before the private key, not after it: a signature this method cannot
        // finish is worse than one it never starts.
        timestampClient.requireReadyConfiguration();
        X509Certificate signingCertificate = (X509Certificate) material.certificateChain()[0];
        ContentSigner contentSigner = new JcaContentSignerBuilder("SHA256withRSA")
                .setProvider(BouncyCastleProvider.PROVIDER_NAME)
                .build(material.privateKey());

        ESSCertIDv2 certificateIdentifier = new ESSCertIDv2(
                MessageDigest.getInstance("SHA-256").digest(signingCertificate.getEncoded())
        );
        SigningCertificateV2 signingCertificateV2 = new SigningCertificateV2(
                new ESSCertIDv2[] {certificateIdentifier}
        );
        Hashtable<org.bouncycastle.asn1.ASN1ObjectIdentifier, Attribute> attributes = new Hashtable<>();
        attributes.put(
                PKCSObjectIdentifiers.id_aa_signingCertificateV2,
                new Attribute(PKCSObjectIdentifiers.id_aa_signingCertificateV2, new DERSet(signingCertificateV2))
        );

        JcaSignerInfoGeneratorBuilder signerInfoBuilder = new JcaSignerInfoGeneratorBuilder(
                new JcaDigestCalculatorProviderBuilder()
                        .setProvider(BouncyCastleProvider.PROVIDER_NAME)
                        .build()
        );
        DefaultSignedAttributeTableGenerator defaults =
                new DefaultSignedAttributeTableGenerator(new AttributeTable(attributes));
        signerInfoBuilder.setSignedAttributeGenerator(parameters ->
                defaults.getAttributes(parameters).remove(CMSAttributes.signingTime));

        CMSSignedDataGenerator generator = new CMSSignedDataGenerator();
        generator.addSignerInfoGenerator(
                signerInfoBuilder.build(contentSigner, new JcaX509CertificateHolder(signingCertificate))
        );
        generator.addCertificates(new JcaCertStore(Arrays.asList(material.certificateChain())));

        CMSSignedData signedData = generator.generate(new InputStreamTypedData(content), false);
        SignerInformation signer = signedData.getSignerInfos().getSigners().iterator().next();
        org.bouncycastle.tsp.TimeStampToken timestamp = timestampClient.timestamp(signer.getSignature());
        Attribute timestampAttribute = new Attribute(
                PKCSObjectIdentifiers.id_aa_signatureTimeStampToken,
                new DERSet(timestamp.toCMSSignedData().toASN1Structure())
        );
        Hashtable<org.bouncycastle.asn1.ASN1ObjectIdentifier, Attribute> unsignedAttributes = new Hashtable<>();
        unsignedAttributes.put(PKCSObjectIdentifiers.id_aa_signatureTimeStampToken, timestampAttribute);
        SignerInformation timestampedSigner = SignerInformation.replaceUnsignedAttributes(
                signer,
                new AttributeTable(unsignedAttributes)
        );
        return CMSSignedData.replaceSigners(
                signedData,
                new SignerInformationStore(java.util.List.of(timestampedSigner))
        ).getEncoded();
    }

    private static void ensureProvider() {
        if (Security.getProvider(BouncyCastleProvider.PROVIDER_NAME) == null) {
            Security.addProvider(new BouncyCastleProvider());
        }
    }

    private record InputStreamTypedData(InputStream input) implements CMSTypedData {
        @Override
        public org.bouncycastle.asn1.ASN1ObjectIdentifier getContentType() {
            return CMSObjectIdentifiers.data;
        }

        @Override
        public Object getContent() {
            return input;
        }

        @Override
        public void write(OutputStream output) throws java.io.IOException, CMSException {
            input.transferTo(output);
        }
    }
}
