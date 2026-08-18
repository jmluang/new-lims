package com.luang.pdfsigner.signature;

import static org.assertj.core.api.Assertions.assertThat;
import static org.assertj.core.api.Assertions.assertThatThrownBy;

import com.luang.pdfsigner.service.Pkcs12SigningKeyProvider;
import java.awt.Color;
import java.awt.Graphics2D;
import java.awt.image.BufferedImage;
import java.io.ByteArrayOutputStream;
import java.security.Security;
import java.security.MessageDigest;
import java.util.Arrays;
import java.util.List;
import javax.imageio.ImageIO;
import org.apache.pdfbox.Loader;
import org.apache.pdfbox.cos.COSArray;
import org.apache.pdfbox.cos.COSBase;
import org.apache.pdfbox.cos.COSDictionary;
import org.apache.pdfbox.cos.COSName;
import org.apache.pdfbox.cos.COSObject;
import org.apache.pdfbox.pdmodel.PDDocument;
import org.apache.pdfbox.pdmodel.PDPage;
import org.apache.pdfbox.pdmodel.PDPageContentStream;
import org.apache.pdfbox.pdmodel.common.PDRectangle;
import org.apache.pdfbox.pdmodel.font.PDType1Font;
import org.apache.pdfbox.pdmodel.font.Standard14Fonts;
import org.apache.pdfbox.pdmodel.interactive.digitalsignature.PDSignature;
import org.apache.pdfbox.pdmodel.interactive.form.PDAcroForm;
import org.apache.pdfbox.pdmodel.interactive.form.PDField;
import org.apache.pdfbox.pdmodel.interactive.form.PDSignatureField;
import org.bouncycastle.asn1.pkcs.PKCSObjectIdentifiers;
import org.bouncycastle.cms.CMSProcessableByteArray;
import org.bouncycastle.cms.CMSSignedData;
import org.bouncycastle.cms.SignerInformation;
import org.bouncycastle.cms.jcajce.JcaSimpleSignerInfoVerifierBuilder;
import org.bouncycastle.cert.X509CertificateHolder;
import org.bouncycastle.tsp.TimeStampToken;
import org.bouncycastle.jce.provider.BouncyCastleProvider;
import org.junit.jupiter.api.BeforeAll;
import org.junit.jupiter.api.Test;

class IncrementalSigningServiceTest {
    private static final COSName LOCK = COSName.getPDFName("Lock");
    private static final COSName FIELDS = COSName.getPDFName("Fields");
    private static final COSName PERMS = COSName.getPDFName("Perms");
    private static final COSName DOC_MDP = COSName.getPDFName("DocMDP");

    private final IncrementalSigningService service;
    private final TestTimestampAuthority timestampAuthority;
    private final TestSigningIdentity signingIdentity;

    IncrementalSigningServiceTest() throws Exception {
        this.signingIdentity = new TestSigningIdentity();
        this.timestampAuthority = new TestTimestampAuthority();
        this.service = new IncrementalSigningService(
                new Pkcs12SigningKeyProvider(),
                timestampAuthority,
                new DocumentSigningCertificatePolicy(signingIdentity.rootFingerprint())
        );
    }

    @BeforeAll
    static void addProvider() {
        if (Security.getProvider(BouncyCastleProvider.PROVIDER_NAME) == null) {
            Security.addProvider(new BouncyCastleProvider());
        }
    }

    @Test
    void preparesEveryFieldBeforeCertificationAndSignsOnlyExistingFieldsIncrementally() throws Exception {
        byte[] source = createSourcePdf();
        List<IncrementalSigningService.FieldPlan> plans = List.of(
                plan("approval_inspector", 0, "0.08", "0.68", "0.24", "0.08", false),
                plan("approval_reviewer", 0, "0.38", "0.68", "0.24", "0.08", false),
                plan("approval_issuer", 1, "0.08", "0.12", "0.24", "0.08", false),
                plan("seal_homepage", 0, "0.74", "0.08", "0.16", "0.16", true)
        );

        byte[] prepared = service.prepareFields(source, plans);
        IncrementalSigningService.Inspection preparedInspection = service.inspect(prepared);
        assertThat(preparedInspection.signatureCount()).isZero();
        assertThat(preparedInspection.docMdpPermission()).isNull();
        assertThat(preparedInspection.fields()).hasSize(4).allSatisfy(field -> {
            assertThat(field.signed()).isFalse();
            assertThat(field.selfOnlyLock()).isTrue();
            assertThat(field.objectRef()).matches("[0-9]+ [0-9]+ R");
            assertThat(field.widgets()).hasSize(field.widgetCount()).allSatisfy(widget -> {
                assertThat(widget.objectRef()).matches("[0-9]+ [0-9]+ R");
                assertThat(widget.appearanceObjectRefs()).isNotEmpty()
                        .allMatch(reference -> reference.matches("[0-9]+ [0-9]+ R"));
            });
        });
        IncrementalSigningService.FieldInspection inspectorField = preparedInspection.fields().stream()
                .filter(field -> field.fieldName().equals("approval_inspector"))
                .findFirst()
                .orElseThrow();
        assertThat(inspectorField.widgetCount()).isEqualTo(1);
        assertThat(inspectorField.widgets()).extracting(
                IncrementalSigningService.WidgetInspection::pageIndex,
                widget -> widget.normalizedRectangle().x(),
                widget -> widget.normalizedRectangle().y(),
                widget -> widget.normalizedRectangle().width(),
                widget -> widget.normalizedRectangle().height()
        ).containsExactly(
                org.assertj.core.groups.Tuple.tuple(0, "0.080000", "0.680000", "0.240000", "0.080000")
        );

        byte[] inspector = sign(prepared, "approval_inspector", "certification_p2", Color.BLUE);
        assertThat(Arrays.copyOf(inspector, prepared.length)).isEqualTo(prepared);
        assertDocumentState(inspector, 1, "approval_inspector");
        try (PDDocument document = Loader.loadPDF(inspector)) {
            PDSignatureField field = (PDSignatureField) document.getDocumentCatalog()
                    .getAcroForm().getField("approval_inspector");
            assertThat(field.getWidgets()).hasSize(1).allSatisfy(widget -> {
                assertThat(widget.getAppearance()).isNotNull();
                assertThat(widget.getAppearance().getNormalAppearance()).isNotNull();
                assertThat(widget.getRectangle().getWidth()).isPositive();
                assertThat(widget.getRectangle().getHeight()).isPositive();
            });
        }
        String originalDocMdp = docMdpFingerprint(inspector);

        byte[] reviewer = sign(inspector, "approval_reviewer", "approval", Color.BLACK);
        assertThat(Arrays.copyOf(reviewer, inspector.length)).isEqualTo(inspector);
        assertDocumentState(reviewer, 2, "approval_inspector", "approval_reviewer");
        assertThat(docMdpFingerprint(reviewer)).isEqualTo(originalDocMdp);

        byte[] issuer = sign(reviewer, "approval_issuer", "approval", Color.DARK_GRAY);
        byte[] sealed = sign(issuer, "seal_homepage", "approval", Color.RED);
        assertDocumentState(sealed, 4,
                "approval_inspector", "approval_reviewer", "approval_issuer", "seal_homepage");
        assertThat(service.inspect(sealed).docMdpPermission()).isEqualTo(2);
        PdfSignatureVerifier.VerificationReport verification = new PdfSignatureVerifier(
                signingIdentity.rootFingerprint(),
                timestampAuthority.certificateFingerprint()
        ).verify(sealed);
        assertThat(verification.documentCurrentState()).as(verification.toString()).isEqualTo("valid");
        assertThat(verification.signatures()).hasSize(4).allSatisfy(signature -> {
            assertThat(signature.cmsIntegrity()).isEqualTo("valid");
            assertThat(signature.certificateTrust()).isEqualTo("valid");
            assertThat(signature.timestampTrust()).isEqualTo("valid");
        });

        assertThatThrownBy(() -> sign(sealed, "approval_inspector", "approval", Color.BLUE))
                .isInstanceOf(IllegalArgumentException.class)
                .hasMessageContaining("already signed");
        assertThatThrownBy(() -> sign(sealed, "dynamic_field", "approval", Color.BLUE))
                .isInstanceOf(IllegalArgumentException.class)
                .hasMessageContaining("does not exist");
    }

    /**
     * A field carrying widgets in /Kids must not also present itself as one.
     *
     * PDSignatureField hands back a field merged with its own widget, which is
     * only legal while the field has no kids. Left merged, the field declared
     * /Subtype /Widget while carrying no /Rect, and Acrobat refused to open the
     * signature, reporting that it expected a dictionary object.
     */
    @Test
    void preparedFieldsDoNotAlsoClaimToBeWidgets() throws Exception {
        byte[] prepared = service.prepareFields(createSourcePdf(), List.of(
                plan("approval_inspector", 0, "0.08", "0.68", "0.24", "0.08", false),
                plan("approval_reviewer", 1, "0.08", "0.68", "0.24", "0.08", false)
        ));

        try (PDDocument document = Loader.loadPDF(prepared)) {
            PDAcroForm acroForm = document.getDocumentCatalog().getAcroForm();
            for (PDField field : acroForm.getFieldTree()) {
                COSDictionary dictionary = field.getCOSObject();
                COSArray kids = dictionary.getCOSArray(COSName.KIDS);
                if (kids == null || kids.size() == 0) {
                    continue;
                }
                assertThat(dictionary.getItem(COSName.SUBTYPE)).isNull();
                assertThat(dictionary.getItem(COSName.TYPE)).isNull();
                // Every widget entry belongs to the kids, which carry the /Rect
                // that makes them annotations in the first place.
                for (int index = 0; index < kids.size(); index++) {
                    COSDictionary kid = (COSDictionary) kids.getObject(index);
                    assertThat(kid.getItem(COSName.RECT)).isNotNull();
                    assertThat(kid.getNameAsString(COSName.SUBTYPE)).isEqualTo("Widget");
                }
            }
        }
    }

    /**
     * The visible signature PDFBox builds covers one widget.
     *
     * A field with a second widget would show an empty box exactly where a
     * signature is supposed to be, so the plan is refused rather than half met.
     */
    @Test
    void refusesASecondWidgetOnTheSameField() {
        assertThatThrownBy(() -> service.prepareFields(createSourcePdf(), List.of(
                plan("approval_inspector", 0, "0.08", "0.68", "0.24", "0.08", false),
                plan("approval_inspector", 1, "0.08", "0.68", "0.24", "0.08", false)
        ))).hasMessageContaining("exactly one widget");
    }

    @Test
    void rejectsUnsignedAndSignedUnauthorizedIncrementalRevisions() throws Exception {
        byte[] prepared = service.prepareFields(createSourcePdf(), List.of(
                plan("approval_inspector", 0, "0.08", "0.68", "0.24", "0.08", false),
                plan("approval_reviewer", 0, "0.38", "0.68", "0.24", "0.08", false)
        ));
        byte[] certified = sign(prepared, "approval_inspector", "certification_p2", Color.BLUE);
        byte[] tampered;
        try (PDDocument document = Loader.loadPDF(certified)) {
            document.getDocumentInformation().setCustomMetadataValue("tamper", "unauthorized");
            ByteArrayOutputStream output = new ByteArrayOutputStream();
            document.saveIncremental(output);
            tampered = output.toByteArray();
        }
        PdfSignatureVerifier verifier = new PdfSignatureVerifier(
                signingIdentity.rootFingerprint(),
                timestampAuthority.certificateFingerprint()
        );

        PdfSignatureVerifier.VerificationReport unsignedReport = verifier.verify(tampered);
        assertThat(unsignedReport.documentCurrentState()).isEqualTo("invalid");
        assertThat(unsignedReport.error()).isEqualTo("UNSIGNED_OR_AMBIGUOUS_LATER_REVISION");
        assertThat(unsignedReport.signatures()).singleElement()
                .extracting(PdfSignatureVerifier.SignatureVerification::laterRevisionPermission)
                .isEqualTo("invalid");

        byte[] countersigned = sign(tampered, "approval_reviewer", "approval", Color.BLACK);
        PdfSignatureVerifier.VerificationReport signedReport = verifier.verify(countersigned);
        assertThat(signedReport.documentCurrentState()).isEqualTo("invalid");
        assertThat(signedReport.error()).isEqualTo("UNAUTHORIZED_INCREMENTAL_OBJECT_CHANGE");
        assertThat(signedReport.signatures())
                .allSatisfy(signature -> assertThat(signature.laterRevisionPermission()).isEqualTo("invalid"));
    }

    @Test
    void rejectsPreparationAfterAnySignatureAndRejectsUnsupportedRotation() throws Exception {
        byte[] prepared = service.prepareFields(createSourcePdf(), List.of(
                plan("approval_inspector", 0, "0.1", "0.1", "0.2", "0.1", false)
        ));
        byte[] signed = sign(prepared, "approval_inspector", "certification_p2", Color.BLUE);
        assertThatThrownBy(() -> service.prepareFields(signed, List.of(
                plan("late_field", 0, "0.1", "0.3", "0.2", "0.1", false)
        ))).isInstanceOf(IllegalArgumentException.class).hasMessageContaining("before the first signature");

        try (PDDocument document = new PDDocument()) {
            PDPage page = new PDPage(PDRectangle.A4);
            page.getCOSObject().setInt(COSName.ROTATE, 45);
            document.addPage(page);
            ByteArrayOutputStream output = new ByteArrayOutputStream();
            document.save(output);
            assertThatThrownBy(() -> service.inspect(output.toByteArray()))
                    .isInstanceOf(IllegalArgumentException.class)
                    .hasMessageContaining("90-degree");
        }
    }

    private byte[] sign(byte[] input, String fieldName, String role, Color color) throws Exception {
        return service.signExistingField(input, appearance(color), new IncrementalSigningService.SignCommand(
                fieldName,
                role,
                "Organization certificate / " + fieldName,
                "Controlled approval",
                "LIMS",
                "security@example.invalid"
        ));
    }

    private static void assertDocumentState(byte[] pdf, int expectedSignatures, String... signedFields)
            throws Exception {
        try (PDDocument document = Loader.loadPDF(pdf)) {
            assertThat(document.getSignatureDictionaries()).hasSize(expectedSignatures);
            assertThat(docMdpObject(document)).isNotNull();
            PDAcroForm form = document.getDocumentCatalog().getAcroForm();
            assertThat(form).isNotNull();
            for (PDField rawField : form.getFieldTree()) {
                if (!(rawField instanceof PDSignatureField field)) {
                    continue;
                }
                boolean expectedSigned = Arrays.asList(signedFields).contains(field.getFullyQualifiedName());
                assertThat(field.getSignature() != null).as(field.getFullyQualifiedName()).isEqualTo(expectedSigned);
                assertSelfOnlyLock(field);
                if (field.getSignature() != null) {
                    verifyCms(pdf, field.getSignature());
                }
            }
        }
    }

    private static void verifyCms(byte[] pdf, PDSignature signature) throws Exception {
        int[] range = signature.getByteRange();
        assertThat(range).hasSize(4);
        assertThat(range[0]).isZero();
        assertThat(range[2] + range[3]).isLessThanOrEqualTo(pdf.length);
        CMSSignedData cms = new CMSSignedData(
                new CMSProcessableByteArray(signature.getSignedContent(pdf)),
                signature.getContents(pdf)
        );
        assertThat(cms.getSignerInfos().getSigners()).hasSize(1);
        SignerInformation signer = cms.getSignerInfos().getSigners().iterator().next();
        X509CertificateHolder certificate = (X509CertificateHolder)
                cms.getCertificates().getMatches(signer.getSID()).iterator().next();
        assertThat(signer.verify(new JcaSimpleSignerInfoVerifierBuilder()
                .setProvider(BouncyCastleProvider.PROVIDER_NAME)
                .build(certificate))).isTrue();
        assertThat(signer.getSignedAttributes().get(PKCSObjectIdentifiers.id_aa_signingCertificateV2)).isNotNull();
        assertThat(signer.getSignedAttributes().get(org.bouncycastle.asn1.cms.CMSAttributes.contentType)).isNotNull();
        assertThat(signer.getSignedAttributes().get(org.bouncycastle.asn1.cms.CMSAttributes.messageDigest)).isNotNull();
        assertThat(signer.getSignedAttributes().get(org.bouncycastle.asn1.cms.CMSAttributes.signingTime)).isNull();
        var timestampAttributes = signer.getUnsignedAttributes()
                .getAll(PKCSObjectIdentifiers.id_aa_signatureTimeStampToken);
        assertThat(timestampAttributes.size()).isEqualTo(1);
        TimeStampToken timestamp = new TimeStampToken(
                org.bouncycastle.asn1.cms.ContentInfo.getInstance(
                        org.bouncycastle.asn1.cms.Attribute.getInstance(timestampAttributes.get(0))
                                .getAttrValues().getObjectAt(0)
                )
        );
        assertThat(timestamp.getTimeStampInfo().getMessageImprintDigest())
                .isEqualTo(MessageDigest.getInstance("SHA-256").digest(signer.getSignature()));
        X509CertificateHolder tsaCertificate = (X509CertificateHolder) timestamp.getCertificates()
                .getMatches(timestamp.getSID()).iterator().next();
        timestamp.validate(new JcaSimpleSignerInfoVerifierBuilder()
                .setProvider(BouncyCastleProvider.PROVIDER_NAME)
                .build(tsaCertificate));
    }

    private static void assertSelfOnlyLock(PDSignatureField field) {
        COSDictionary lock = dictionary(field.getCOSObject().getDictionaryObject(LOCK));
        assertThat(lock).isNotNull();
        assertThat(lock.getNameAsString("Action")).isEqualTo("Include");
        COSArray fields = (COSArray) lock.getDictionaryObject(FIELDS);
        assertThat(fields).hasSize(1);
        assertThat(fields.getString(0)).isEqualTo(field.getFullyQualifiedName());
    }

    private static String docMdpFingerprint(byte[] pdf) throws Exception {
        try (PDDocument document = Loader.loadPDF(pdf)) {
            COSDictionary value = dictionary(docMdpObject(document));
            if (value == null) {
                return null;
            }
            PDSignature signature = new PDSignature(value);
            return Arrays.toString(signature.getByteRange()) + ":"
                    + java.util.HexFormat.of().formatHex(
                            java.security.MessageDigest.getInstance("SHA-256").digest(signature.getContents(pdf))
                    );
        }
    }

    private static COSBase docMdpObject(PDDocument document) {
        COSDictionary permissions = dictionary(
                document.getDocumentCatalog().getCOSObject().getDictionaryObject(PERMS));
        return permissions == null ? null : permissions.getDictionaryObject(DOC_MDP);
    }

    private static COSDictionary dictionary(COSBase value) {
        if (value instanceof COSObject object) {
            value = object.getObject();
        }
        return value instanceof COSDictionary dictionary ? dictionary : null;
    }

    private static IncrementalSigningService.FieldPlan plan(
            String name, int page, String x, String y, String width, String height, boolean deferred
    ) {
        return new IncrementalSigningService.FieldPlan(
                name,
                page,
                new IncrementalSigningService.NormalizedRectangle(x, y, width, height),
                deferred
        );
    }

    private static byte[] createSourcePdf() throws Exception {
        try (PDDocument document = new PDDocument()) {
            for (int index = 0; index < 2; index++) {
                PDPage page = new PDPage(PDRectangle.A4);
                if (index == 1) {
                    page.setRotation(90);
                }
                document.addPage(page);
                try (PDPageContentStream content = new PDPageContentStream(document, page)) {
                    content.beginText();
                    content.setFont(new PDType1Font(Standard14Fonts.FontName.HELVETICA), 18);
                    content.newLineAtOffset(72, 740);
                    content.showText("Immutable LIMS report page " + (index + 1));
                    content.endText();
                }
            }
            ByteArrayOutputStream output = new ByteArrayOutputStream();
            document.save(output);
            return output.toByteArray();
        }
    }

    private static byte[] appearance(Color color) throws Exception {
        BufferedImage image = new BufferedImage(360, 120, BufferedImage.TYPE_INT_ARGB);
        Graphics2D graphics = image.createGraphics();
        graphics.setColor(new Color(255, 255, 255, 0));
        graphics.fillRect(0, 0, image.getWidth(), image.getHeight());
        graphics.setColor(color);
        graphics.setStroke(new java.awt.BasicStroke(8f, java.awt.BasicStroke.CAP_ROUND,
                java.awt.BasicStroke.JOIN_ROUND));
        graphics.drawLine(24, 82, 100, 38);
        graphics.drawLine(100, 38, 150, 84);
        graphics.drawLine(150, 84, 235, 28);
        graphics.drawLine(235, 28, 332, 76);
        graphics.dispose();
        ByteArrayOutputStream output = new ByteArrayOutputStream();
        ImageIO.write(image, "png", output);
        return output.toByteArray();
    }
}
