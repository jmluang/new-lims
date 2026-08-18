package com.luang.pdfsigner.signature;

import java.security.MessageDigest;
import java.security.Security;
import java.util.ArrayList;
import java.util.Arrays;
import java.util.HexFormat;
import java.util.List;
import java.util.Set;
import java.util.stream.Collectors;
import org.apache.pdfbox.Loader;
import org.apache.pdfbox.pdmodel.PDDocument;
import org.apache.pdfbox.pdmodel.interactive.digitalsignature.PDSignature;
import org.bouncycastle.asn1.ASN1Primitive;
import org.bouncycastle.asn1.cms.Attribute;
import org.bouncycastle.asn1.cms.CMSAttributes;
import org.bouncycastle.asn1.cms.ContentInfo;
import org.bouncycastle.asn1.pkcs.PKCSObjectIdentifiers;
import org.bouncycastle.cert.X509CertificateHolder;
import org.bouncycastle.cms.CMSProcessableByteArray;
import org.bouncycastle.cms.CMSSignedData;
import org.bouncycastle.cms.SignerInformation;
import org.bouncycastle.cms.jcajce.JcaSimpleSignerInfoVerifierBuilder;
import org.bouncycastle.jce.provider.BouncyCastleProvider;
import org.bouncycastle.tsp.TimeStampToken;
import org.springframework.beans.factory.annotation.Value;
import org.springframework.stereotype.Service;

@Service
public class PdfSignatureVerifier {
    private static final HexFormat HEX = HexFormat.of();
    private final Set<String> trustedDocumentRootFingerprints;
    private final Set<String> trustedTsaFingerprints;

    public PdfSignatureVerifier(
            @Value("${pdf.pades-bt.document-signer-trusted-root-sha256:}") String signerFingerprints,
            @Value("${pdf.pades-bt.tsa-trusted-certificate-sha256:}") String tsaFingerprints
    ) {
        this.trustedDocumentRootFingerprints = parseFingerprints(signerFingerprints);
        this.trustedTsaFingerprints = parseFingerprints(tsaFingerprints);
        // Every verification below asks for the BC provider by name, but the
        // only code that registered it was the signer. Verification therefore
        // worked only in a process that had already signed something: after a
        // restart the first approval signature read a perfectly good document
        // as invalid. Registering here, in a component built at startup, means
        // no caller depends on that ordering.
        ensureProvider();
    }

    private static void ensureProvider() {
        if (Security.getProvider(BouncyCastleProvider.PROVIDER_NAME) == null) {
            Security.addProvider(new BouncyCastleProvider());
        }
    }

    public VerificationReport verify(byte[] pdf) {
        List<SignatureVerification> signatures = new ArrayList<>();
        Integer docMdpPermission;
        try {
            IncrementalSigningService inspectionService = null;
            try (PDDocument document = Loader.loadPDF(pdf)) {
                docMdpPermission = extractDocMdpPermission(document);
                int index = 0;
                for (PDSignature signature : document.getSignatureDictionaries()) {
                    signatures.add(verifySignature(pdf, signature, index++));
                }
            }
        } catch (Exception exception) {
            return new VerificationReport(
                    "invalid", null, List.of(), "PDF_PARSE_OR_STRUCTURE_INVALID: " + exception.getMessage());
        }
        PdfRevisionPermissionValidator.Validation permissionValidation;
        try {
            permissionValidation = new PdfRevisionPermissionValidator().validate(pdf);
        } catch (Exception exception) {
            permissionValidation = new PdfRevisionPermissionValidator.Validation(
                    false,
                    "REVISION_PERMISSION_VALIDATION_FAILED: " + exception.getMessage()
            );
        }
        if (!permissionValidation.valid()) {
            signatures = signatures.stream().map(item -> new SignatureVerification(
                    item.index(),
                    item.signerName(),
                    item.subFilter(),
                    item.cmsIntegrity(),
                    item.certificateTrust(),
                    item.signedRevisionIntegrity(),
                    "invalid",
                    item.timestampTrust(),
                    "invalid",
                    item.error()
            )).toList();
        }
        boolean integrityValid = signatures.stream().allMatch(item -> "valid".equals(item.cmsIntegrity())
                && "valid".equals(item.signedRevisionIntegrity()));
        boolean permissionValid = signatures.isEmpty()
                || (Integer.valueOf(2).equals(docMdpPermission) && permissionValidation.valid());
        boolean trustInvalid = signatures.stream().anyMatch(item -> "invalid".equals(item.certificateTrust())
                || "invalid".equals(item.timestampTrust()));
        boolean trustIndeterminate = signatures.stream().anyMatch(item -> "indeterminate".equals(item.certificateTrust())
                || "indeterminate".equals(item.timestampTrust()));
        String state = !integrityValid || !permissionValid || trustInvalid
                ? "invalid"
                : trustIndeterminate ? "indeterminate" : "valid";
        return new VerificationReport(
                state,
                docMdpPermission,
                List.copyOf(signatures),
                permissionValidation.error()
        );
    }

    private SignatureVerification verifySignature(byte[] pdf, PDSignature signature, int index) {
        try {
            validateByteRangeAndContents(pdf, signature);
            CMSSignedData cms = new CMSSignedData(
                    new CMSProcessableByteArray(signature.getSignedContent(pdf)),
                    signature.getContents(pdf)
            );
            if (cms.getSignerInfos().size() != 1) {
                throw new IllegalArgumentException("CMS must contain exactly one SignerInfo");
            }
            SignerInformation signer = cms.getSignerInfos().getSigners().iterator().next();
            X509CertificateHolder certificate = onlyCertificate(cms, signer);
            if (!signer.verify(new JcaSimpleSignerInfoVerifierBuilder()
                    .setProvider(BouncyCastleProvider.PROVIDER_NAME)
                    .build(certificate))) {
                throw new IllegalArgumentException("CMS signature verification failed");
            }
            validateSignedAttributes(signer);
            String timestampTrust = validateTimestamp(signer);
            String certificateTrust = certificateTrust(certificate, cms);
            return new SignatureVerification(
                    index,
                    signature.getName(),
                    signature.getSubFilter(),
                    "valid",
                    certificateTrust,
                    "valid",
                    "valid",
                    timestampTrust,
                    "valid",
                    null
            );
        } catch (Exception exception) {
            return new SignatureVerification(
                    index,
                    signature.getName(),
                    signature.getSubFilter(),
                    "invalid",
                    "indeterminate",
                    "invalid",
                    "invalid",
                    "invalid",
                    "invalid",
                    exception.getMessage()
            );
        }
    }

    private static void validateByteRangeAndContents(byte[] pdf, PDSignature signature) throws Exception {
        int[] range = signature.getByteRange();
        if (range == null || range.length != 4 || range[0] != 0
                || range[1] < 0 || range[2] < range[1] || range[3] < 0
                || (long) range[2] + range[3] > pdf.length) {
            throw new IllegalArgumentException("Invalid PDF signature ByteRange");
        }
        byte[] gap = Arrays.copyOfRange(pdf, range[1], range[2]);
        if (gap.length < 4 || gap[0] != '<' || gap[gap.length - 1] != '>') {
            throw new IllegalArgumentException("ByteRange gap is not the signature Contents hex string");
        }
        String hex = new String(gap, 1, gap.length - 2, java.nio.charset.StandardCharsets.US_ASCII);
        if ((hex.length() & 1) != 0 || !hex.matches("[0-9A-Fa-f]+")) {
            throw new IllegalArgumentException("Signature Contents is not canonical hexadecimal data");
        }
        byte[] padded = HEX.parseHex(hex);
        ASN1Primitive cmsObject;
        try (org.bouncycastle.asn1.ASN1InputStream asn1 =
                     new org.bouncycastle.asn1.ASN1InputStream(padded)) {
            cmsObject = asn1.readObject();
        }
        if (cmsObject == null) {
            throw new IllegalArgumentException("Signature Contents is empty");
        }
        byte[] canonicalCms = cmsObject.getEncoded();
        if (canonicalCms.length > padded.length
                || !MessageDigest.isEqual(canonicalCms, Arrays.copyOf(padded, canonicalCms.length))) {
            throw new IllegalArgumentException("Signature Contents does not contain one canonical DER CMS");
        }
        for (int offset = canonicalCms.length; offset < padded.length; offset++) {
            if (padded[offset] != 0) {
                throw new IllegalArgumentException("Signature Contents padding must be all zero");
            }
        }
    }

    private static void validateSignedAttributes(SignerInformation signer) {
        if (signer.getSignedAttributes() == null
                || signer.getSignedAttributes().get(CMSAttributes.contentType) == null
                || signer.getSignedAttributes().get(CMSAttributes.messageDigest) == null
                || signer.getSignedAttributes().get(PKCSObjectIdentifiers.id_aa_signingCertificateV2) == null
                || signer.getSignedAttributes().get(CMSAttributes.signingTime) != null) {
            throw new IllegalArgumentException("CMS signed attributes do not match the PAdES-B-T policy");
        }
    }

    private String validateTimestamp(SignerInformation signer) throws Exception {
        if (signer.getUnsignedAttributes() == null) {
            throw new IllegalArgumentException("RFC 3161 signature timestamp is missing");
        }
        var values = signer.getUnsignedAttributes().getAll(PKCSObjectIdentifiers.id_aa_signatureTimeStampToken);
        if (values.size() != 1) {
            throw new IllegalArgumentException("Exactly one RFC 3161 signature timestamp is required");
        }
        Attribute attribute = Attribute.getInstance(values.get(0));
        if (attribute.getAttrValues().size() != 1) {
            throw new IllegalArgumentException("RFC 3161 timestamp attribute must contain one token");
        }
        TimeStampToken timestamp = new TimeStampToken(
                ContentInfo.getInstance(attribute.getAttrValues().getObjectAt(0)));
        if (!MessageDigest.isEqual(
                timestamp.getTimeStampInfo().getMessageImprintDigest(),
                MessageDigest.getInstance("SHA-256").digest(signer.getSignature()))) {
            throw new IllegalArgumentException("RFC 3161 timestamp imprint does not bind the CMS signature value");
        }
        java.util.Collection<?> matches = timestamp.getCertificates().getMatches(timestamp.getSID());
        if (matches.size() != 1) {
            throw new IllegalArgumentException("RFC 3161 token must contain its one signer certificate");
        }
        X509CertificateHolder certificate = (X509CertificateHolder) matches.iterator().next();
        timestamp.validate(new JcaSimpleSignerInfoVerifierBuilder()
                .setProvider(BouncyCastleProvider.PROVIDER_NAME)
                .build(certificate));
        return trustedTsaFingerprints.contains(fingerprint(certificate)) ? "valid" : "indeterminate";
    }

    private String certificateTrust(X509CertificateHolder certificate, CMSSignedData cms) throws Exception {
        java.security.cert.X509Certificate x509 = new org.bouncycastle.cert.jcajce.JcaX509CertificateConverter()
                .setProvider(BouncyCastleProvider.PROVIDER_NAME)
                .getCertificate(certificate);
        if (x509.getBasicConstraints() >= 0) {
            return "invalid";
        }
        boolean[] keyUsage = x509.getKeyUsage();
        if (keyUsage == null || keyUsage.length < 2 || (!keyUsage[0] && !keyUsage[1])) {
            return "invalid";
        }
        List<String> extendedKeyUsage = x509.getExtendedKeyUsage();
        if (extendedKeyUsage == null || !extendedKeyUsage.contains("1.3.6.1.5.5.7.3.36")) {
            return "invalid";
        }
        for (Object candidate : cms.getCertificates().getMatches(null)) {
            X509CertificateHolder holder = (X509CertificateHolder) candidate;
            java.security.cert.X509Certificate root = new org.bouncycastle.cert.jcajce.JcaX509CertificateConverter()
                    .setProvider(BouncyCastleProvider.PROVIDER_NAME)
                    .getCertificate(holder);
            if (root.getBasicConstraints() >= 0
                    && trustedDocumentRootFingerprints.contains(fingerprint(holder))) {
                root.checkValidity();
                root.verify(root.getPublicKey());
                x509.verify(root.getPublicKey());
                return "valid";
            }
        }
        return "indeterminate";
    }

    private static X509CertificateHolder onlyCertificate(CMSSignedData cms, SignerInformation signer) {
        java.util.Collection<?> matches = cms.getCertificates().getMatches(signer.getSID());
        if (matches.size() != 1) {
            throw new IllegalArgumentException("CMS must contain exactly one matching signer certificate");
        }
        return (X509CertificateHolder) matches.iterator().next();
    }

    private static String fingerprint(X509CertificateHolder certificate) throws Exception {
        return HEX.formatHex(MessageDigest.getInstance("SHA-256").digest(certificate.getEncoded()));
    }

    private static Set<String> parseFingerprints(String raw) {
        if (raw == null || raw.isBlank()) {
            return Set.of();
        }
        return Arrays.stream(raw.split(","))
                .map(value -> value.replace(":", "").trim().toLowerCase())
                .filter(value -> value.matches("[0-9a-f]{64}"))
                .collect(Collectors.toUnmodifiableSet());
    }

    private static Integer extractDocMdpPermission(PDDocument document) {
        org.apache.pdfbox.cos.COSDictionary catalog = document.getDocumentCatalog().getCOSObject();
        org.apache.pdfbox.cos.COSDictionary perms = asDictionary(catalog.getDictionaryObject("Perms"));
        org.apache.pdfbox.cos.COSDictionary signature = perms == null ? null
                : asDictionary(perms.getDictionaryObject("DocMDP"));
        org.apache.pdfbox.cos.COSArray refs = signature == null ? null
                : (org.apache.pdfbox.cos.COSArray) signature.getDictionaryObject("Reference");
        if (refs == null) {
            return null;
        }
        for (org.apache.pdfbox.cos.COSBase value : refs) {
            org.apache.pdfbox.cos.COSDictionary ref = asDictionary(value);
            if (ref != null && "DocMDP".equals(ref.getNameAsString("TransformMethod"))) {
                org.apache.pdfbox.cos.COSDictionary params = asDictionary(ref.getDictionaryObject("TransformParams"));
                return params == null ? null : params.getInt("P");
            }
        }
        return null;
    }

    private static org.apache.pdfbox.cos.COSDictionary asDictionary(org.apache.pdfbox.cos.COSBase value) {
        if (value instanceof org.apache.pdfbox.cos.COSObject object) {
            value = object.getObject();
        }
        return value instanceof org.apache.pdfbox.cos.COSDictionary dictionary ? dictionary : null;
    }

    public record SignatureVerification(
            int index,
            String signerName,
            String subFilter,
            String cmsIntegrity,
            String certificateTrust,
            String signedRevisionIntegrity,
            String laterRevisionPermission,
            String timestampTrust,
            String documentCurrentState,
            String error
    ) {}

    public record VerificationReport(
            String documentCurrentState,
            Integer docMdpPermission,
            List<SignatureVerification> signatures,
            String error
    ) {}
}
