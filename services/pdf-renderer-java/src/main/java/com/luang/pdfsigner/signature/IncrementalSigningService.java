package com.luang.pdfsigner.signature;

import com.luang.pdfsigner.service.Pkcs12SigningKeyProvider;
import com.luang.pdfsigner.service.Pkcs12SigningKeyProvider.SigningKeyMaterial;
import java.awt.image.BufferedImage;
import java.io.ByteArrayInputStream;
import java.io.ByteArrayOutputStream;
import java.math.BigDecimal;
import java.math.RoundingMode;
import java.nio.charset.StandardCharsets;
import java.security.MessageDigest;
import java.util.ArrayList;
import java.util.Base64;
import java.util.Calendar;
import java.util.HashSet;
import java.util.HexFormat;
import java.util.IdentityHashMap;
import java.util.LinkedHashMap;
import java.util.LinkedHashSet;
import java.util.List;
import java.util.Locale;
import java.util.Map;
import java.util.Set;
import javax.imageio.ImageIO;
import org.apache.pdfbox.Loader;
import org.apache.pdfbox.cos.COSArray;
import org.apache.pdfbox.cos.COSBase;
import org.apache.pdfbox.cos.COSDictionary;
import org.apache.pdfbox.cos.COSName;
import org.apache.pdfbox.cos.COSObject;
import org.apache.pdfbox.cos.COSObjectKey;
import org.apache.pdfbox.cos.COSStream;
import org.apache.pdfbox.cos.COSString;
import org.apache.pdfbox.pdmodel.PDDocument;
import org.apache.pdfbox.pdmodel.PDDocumentCatalog;
import org.apache.pdfbox.pdmodel.PDPage;
import org.apache.pdfbox.pdmodel.PDPageContentStream;
import org.apache.pdfbox.pdmodel.PDResources;
import org.apache.pdfbox.pdmodel.common.PDRectangle;
import org.apache.pdfbox.pdmodel.graphics.image.LosslessFactory;
import org.apache.pdfbox.pdmodel.graphics.image.PDImageXObject;
import org.apache.pdfbox.pdmodel.interactive.annotation.PDAnnotationWidget;
import org.apache.pdfbox.pdmodel.interactive.annotation.PDAppearanceDictionary;
import org.apache.pdfbox.pdmodel.interactive.annotation.PDAppearanceStream;
import org.apache.pdfbox.pdmodel.interactive.digitalsignature.ExternalSigningSupport;
import org.apache.pdfbox.pdmodel.interactive.digitalsignature.PDSignature;
import org.apache.pdfbox.pdmodel.interactive.digitalsignature.SignatureOptions;
import org.apache.pdfbox.pdmodel.interactive.form.PDAcroForm;
import org.apache.pdfbox.pdmodel.interactive.form.PDField;
import org.apache.pdfbox.pdmodel.interactive.form.PDSignatureField;
import org.springframework.stereotype.Service;

@Service
public final class IncrementalSigningService {
    private static final COSName LOCK = COSName.getPDFName("Lock");
    private static final COSName SIG_FIELD_LOCK = COSName.getPDFName("SigFieldLock");
    private static final COSName ACTION = COSName.getPDFName("Action");
    private static final COSName INCLUDE = COSName.getPDFName("Include");
    private static final COSName FIELDS = COSName.getPDFName("Fields");
    private static final COSName PERMS = COSName.getPDFName("Perms");
    private static final COSName DOC_MDP = COSName.getPDFName("DocMDP");
    private static final COSName REFERENCE = COSName.getPDFName("Reference");
    private static final COSName SIG_REF = COSName.getPDFName("SigRef");
    private static final COSName TRANSFORM_METHOD = COSName.getPDFName("TransformMethod");
    private static final COSName TRANSFORM_PARAMS = COSName.getPDFName("TransformParams");
    private static final COSName FIELD_MDP = COSName.getPDFName("FieldMDP");
    private static final COSName DATA = COSName.getPDFName("Data");
    private static final COSName P = COSName.getPDFName("P");
    private static final COSName V = COSName.getPDFName("V");
    private static final COSName VERSION_1_2 = COSName.getPDFName("1.2");
    private static final HexFormat HEX = HexFormat.of();

    private final Pkcs12SigningKeyProvider signingKeyProvider;
    private final DocumentSigningCertificatePolicy certificatePolicy;
    private final PadesCmsSigner cmsSigner;

    public IncrementalSigningService(
            Pkcs12SigningKeyProvider signingKeyProvider,
            Rfc3161TimestampClient timestampClient,
            DocumentSigningCertificatePolicy certificatePolicy
    ) {
        this.signingKeyProvider = signingKeyProvider;
        this.cmsSigner = new PadesCmsSigner(timestampClient);
        this.certificatePolicy = certificatePolicy;
    }

    public Inspection inspect(byte[] pdfBytes) throws Exception {
        try (PDDocument document = Loader.loadPDF(pdfBytes)) {
            List<PageGeometry> pages = new ArrayList<>();
            for (int index = 0; index < document.getNumberOfPages(); index++) {
                PDPage page = document.getPage(index);
                PDRectangle crop = page.getCropBox();
                pages.add(new PageGeometry(
                        index,
                        decimal(crop.getLowerLeftX()),
                        decimal(crop.getLowerLeftY()),
                        decimal(crop.getWidth()),
                        decimal(crop.getHeight()),
                        pageRotation(page),
                        decimal(page.getUserUnit())
                ));
            }

            List<FieldInspection> fields = new ArrayList<>();
            ObjectIndex objectIndex = ObjectIndex.of(document);
            PDAcroForm acroForm = document.getDocumentCatalog().getAcroForm();
            if (acroForm != null) {
                for (PDField field : acroForm.getFieldTree()) {
                    if (field instanceof PDSignatureField signatureField) {
                        List<WidgetInspection> widgets = new ArrayList<>();
                        for (int widgetIndex = 0; widgetIndex < signatureField.getWidgets().size(); widgetIndex++) {
                            PDAnnotationWidget widget = signatureField.getWidgets().get(widgetIndex);
                            int pageIndex = widgetPageIndex(document, widget);
                            PDPage page = document.getPage(pageIndex);
                            widgets.add(new WidgetInspection(
                                    widgetIndex,
                                    pageIndex,
                                    normalizedRectangle(page, widget.getRectangle()),
                                    objectIndex.reference(widget.getCOSObject()),
                                    appearanceObjectReferences(widget, objectIndex)
                            ));
                        }
                        fields.add(new FieldInspection(
                                field.getFullyQualifiedName(),
                                signatureField.getSignature() != null,
                                hasSelfOnlyLock(signatureField),
                                signatureField.getWidgets().size(),
                                objectIndex.reference(field.getCOSObject()),
                                List.copyOf(widgets)
                        ));
                    }
                }
            }

            return new Inspection(
                    sha256(pdfBytes),
                    document.getNumberOfPages(),
                    document.isEncrypted(),
                    document.getSignatureDictionaries().size(),
                    docMdpPermission(document),
                    List.copyOf(pages),
                    List.copyOf(fields)
            );
        }
    }

    public byte[] prepareFields(byte[] unsignedPdf, List<FieldPlan> fieldPlans) throws Exception {
        if (fieldPlans == null || fieldPlans.isEmpty()) {
            throw new IllegalArgumentException("At least one signature field is required");
        }

        try (PDDocument document = Loader.loadPDF(unsignedPdf)) {
            if (document.isEncrypted()) {
                throw new IllegalArgumentException("Encrypted PDFs are not supported");
            }
            if (!document.getSignatureDictionaries().isEmpty() || docMdpPermission(document) != null) {
                throw new IllegalArgumentException("Fields must be prepared before the first signature");
            }

            PDAcroForm acroForm = document.getDocumentCatalog().getAcroForm();
            if (acroForm == null) {
                acroForm = new PDAcroForm(document);
                document.getDocumentCatalog().setAcroForm(acroForm);
            }
            if (acroForm.hasXFA()) {
                throw new IllegalArgumentException("XFA forms are not supported");
            }
            acroForm.setSignaturesExist(true);
            acroForm.setAppendOnly(true);
            acroForm.setNeedAppearances(false);
            if (acroForm.getDefaultResources() == null) {
                acroForm.setDefaultResources(new PDResources());
            }

            Map<String, PDSignatureField> fields = new LinkedHashMap<>();
            for (FieldPlan plan : fieldPlans) {
                boolean existingPlanField = plan != null && plan.fieldName() != null
                        && fields.containsKey(plan.fieldName());
                validateFieldPlan(document, acroForm, plan, existingPlanField);
                PDPage page = document.getPage(plan.pageIndex());
                PDRectangle rectangle = toPdfRectangle(page, plan.rectangle());
                PDSignatureField field = fields.get(plan.fieldName());
                if (field == null) {
                    field = new PDSignatureField(acroForm);
                    field.setPartialName(plan.fieldName());
                    field.getCOSObject().setItem(LOCK, selfOnlyLock(plan.fieldName()));
                    field.getCOSObject().setItem(COSName.KIDS, new COSArray());
                    acroForm.getFields().add(field);
                    fields.put(plan.fieldName(), field);
                }
                COSArray kids = field.getCOSObject().getCOSArray(COSName.KIDS);
                if (kids.size() >= 8) {
                    throw new IllegalArgumentException("A signature field supports at most eight widgets");
                }
                PDAnnotationWidget widget = new PDAnnotationWidget();
                widget.setParent(field);
                kids.add(widget.getCOSObject());
                widget.setPage(page);
                widget.setRectangle(rectangle);
                widget.setPrinted(true);
                widget.setAppearance(blankAppearance(document, rectangle.getWidth(), rectangle.getHeight()));
                page.getAnnotations().add(widget);
            }

            ByteArrayOutputStream output = new ByteArrayOutputStream();
            document.save(output);
            byte[] prepared = output.toByteArray();
            Inspection inspection = inspect(prepared);
            if (inspection.signatureCount() != 0 || inspection.fields().size() != fields.size()) {
                throw new IllegalStateException("Prepared field plan failed round-trip inspection");
            }
            return prepared;
        }
    }

    public byte[] signExistingField(byte[] preparedPdf, byte[] appearancePng, SignCommand command) throws Exception {
        return signExistingField(preparedPdf, appearancePng, command, null);
    }

    public byte[] signExistingField(
            byte[] preparedPdf,
            byte[] appearancePng,
            SignCommand command,
            String expectedCertificateFingerprint
    ) throws Exception {
        validateSignCommand(appearancePng, command);
        validateSignTarget(preparedPdf, command);
        try (PDDocument document = Loader.loadPDF(preparedPdf)) {
            if (document.isEncrypted()) {
                throw new IllegalArgumentException("Encrypted PDFs are not supported");
            }
            PDAcroForm acroForm = document.getDocumentCatalog().getAcroForm();
            if (acroForm == null || !(acroForm.getField(command.fieldName()) instanceof PDSignatureField field)) {
                throw new IllegalArgumentException("The target signature field does not exist");
            }
            if (field.getSignature() != null) {
                throw new IllegalArgumentException("The target signature field is already signed");
            }
            if (!hasSelfOnlyLock(field)) {
                throw new IllegalArgumentException("The target field does not have the required self-only lock");
            }

            int existingSignatures = document.getSignatureDictionaries().size();
            Integer existingDocMdp = docMdpPermission(document);
            SignatureRole role = SignatureRole.parse(command.signatureRole());
            if (role == SignatureRole.CERTIFICATION_P2) {
                if (existingSignatures != 0 || existingDocMdp != null) {
                    throw new IllegalArgumentException("A certification signature must be the first signature");
                }
            } else if (existingSignatures == 0 || existingDocMdp == null || existingDocMdp != 2) {
                throw new IllegalArgumentException("Approval signatures require an existing DocMDP P=2 certification signature");
            }

            PDSignature signature = new PDSignature();
            signature.setFilter(PDSignature.FILTER_ADOBE_PPKLITE);
            signature.setSubFilter(PDSignature.SUBFILTER_ETSI_CADES_DETACHED);
            signature.setName(command.signerName());
            signature.setReason(command.reason());
            signature.setLocation(command.location());
            signature.setContactInfo(command.contact());
            signature.setSignDate(Calendar.getInstance());
            addFieldMdpReference(signature, document.getDocumentCatalog(), command.fieldName());
            if (role == SignatureRole.CERTIFICATION_P2) {
                addDocMdpReference(signature, document.getDocumentCatalog());
            }

            List<PDRectangle> widgetRectangles = field.getWidgets().stream()
                    .map(PDAnnotationWidget::getRectangle)
                    .map(rectangle -> new PDRectangle(
                            rectangle.getLowerLeftX(),
                            rectangle.getLowerLeftY(),
                            rectangle.getWidth(),
                            rectangle.getHeight()
                    ))
                    .toList();
            field.getCOSObject().setItem(COSName.V, signature.getCOSObject());
            SigningKeyMaterial keyMaterial = signingKeyProvider.load();
            String actualCertificateFingerprint = certificatePolicy.validate(keyMaterial);
            if (expectedCertificateFingerprint != null
                    && !MessageDigest.isEqual(
                    actualCertificateFingerprint.getBytes(StandardCharsets.US_ASCII),
                    expectedCertificateFingerprint.toLowerCase(Locale.ROOT).getBytes(StandardCharsets.US_ASCII))) {
                throw new IllegalArgumentException("The active document-signing certificate does not match the frozen operation");
            }

            ByteArrayOutputStream output = new ByteArrayOutputStream();
            try (SignatureOptions options = new SignatureOptions()) {
                options.setPreferredSignatureSize(signatureReservedSize());
                document.addSignature(signature, options);
                restoreWidgetRectangles(field, widgetRectangles);
                applyAppearance(document, field, appearancePng);
                ExternalSigningSupport externalSigning = document.saveIncrementalForExternalSigning(output);
                externalSigning.setSignature(cmsSigner.sign(externalSigning.getContent(), keyMaterial));
            }
            byte[] signed = output.toByteArray();
            if (!startsWith(signed, preparedPdf)) {
                throw new IllegalStateException("Incremental signing changed the immutable input prefix");
            }
            Inspection inspection = inspect(signed);
            if (inspection.signatureCount() != existingSignatures + 1 || inspection.docMdpPermission() != 2) {
                throw new IllegalStateException("Signed PDF failed structural round-trip verification");
            }
            return signed;
        }
    }

    public void validateSignTarget(byte[] preparedPdf, SignCommand command) throws Exception {
        if (command == null || command.fieldName() == null || command.fieldName().isBlank()) {
            throw new IllegalArgumentException("A target signature field is required");
        }
        try (PDDocument document = Loader.loadPDF(preparedPdf)) {
            if (document.isEncrypted()) {
                throw new IllegalArgumentException("Encrypted PDFs are not supported");
            }
            PDAcroForm acroForm = document.getDocumentCatalog().getAcroForm();
            if (acroForm == null || !(acroForm.getField(command.fieldName()) instanceof PDSignatureField field)) {
                throw new IllegalArgumentException("The target signature field does not exist");
            }
            if (field.getSignature() != null) {
                throw new IllegalArgumentException("The target signature field is already signed");
            }
            if (!hasSelfOnlyLock(field)) {
                throw new IllegalArgumentException("The target field does not have the required self-only lock");
            }
            int existingSignatures = document.getSignatureDictionaries().size();
            Integer existingDocMdp = docMdpPermission(document);
            SignatureRole role = SignatureRole.parse(command.signatureRole());
            if (role == SignatureRole.CERTIFICATION_P2) {
                if (existingSignatures != 0 || existingDocMdp != null) {
                    throw new IllegalArgumentException("A certification signature must be the first signature");
                }
            } else if (existingSignatures == 0 || existingDocMdp == null || existingDocMdp != 2) {
                throw new IllegalArgumentException("Approval signatures require an existing DocMDP P=2 certification signature");
            }
        }
    }

    private static void validateFieldPlan(
            PDDocument document,
            PDAcroForm acroForm,
            FieldPlan plan,
            boolean existingPlanField
    ) {
        if (plan == null || plan.fieldName() == null || !plan.fieldName().matches("[A-Za-z0-9_-]{1,128}")) {
            throw new IllegalArgumentException("Invalid signature field name");
        }
        if (!existingPlanField && acroForm.getField(plan.fieldName()) != null) {
            throw new IllegalArgumentException("Duplicate signature field name: " + plan.fieldName());
        }
        if (plan.pageIndex() < 0 || plan.pageIndex() >= document.getNumberOfPages()) {
            throw new IllegalArgumentException("Signature field page is outside the document");
        }
        NormalizedRectangle rectangle = plan.rectangle();
        if (rectangle == null) {
            throw new IllegalArgumentException("Signature field rectangle is required");
        }
        BigDecimal x = coordinate(rectangle.x());
        BigDecimal y = coordinate(rectangle.y());
        BigDecimal width = coordinate(rectangle.width());
        BigDecimal height = coordinate(rectangle.height());
        if (width.signum() <= 0 || height.signum() <= 0
                || x.add(width).compareTo(BigDecimal.ONE) > 0
                || y.add(height).compareTo(BigDecimal.ONE) > 0) {
            throw new IllegalArgumentException("Signature field rectangle must fit within the normalized page");
        }
    }

    private static PDRectangle toPdfRectangle(PDPage page, NormalizedRectangle normalized) {
        PDRectangle crop = page.getCropBox();
        float x = coordinate(normalized.x()).floatValue();
        float top = coordinate(normalized.y()).floatValue();
        float width = coordinate(normalized.width()).floatValue();
        float height = coordinate(normalized.height()).floatValue();
        int rotation = pageRotation(page);
        float visualWidth = rotation == 90 || rotation == 270 ? crop.getHeight() : crop.getWidth();
        float visualHeight = rotation == 90 || rotation == 270 ? crop.getWidth() : crop.getHeight();
        float vx = x * visualWidth;
        float vy = (1f - top - height) * visualHeight;
        float vw = width * visualWidth;
        float vh = height * visualHeight;

        float ux;
        float uy;
        float uw;
        float uh;
        switch (rotation) {
            case 90 -> {
                ux = crop.getWidth() - vy - vh;
                uy = vx;
                uw = vh;
                uh = vw;
            }
            case 180 -> {
                ux = crop.getWidth() - vx - vw;
                uy = crop.getHeight() - vy - vh;
                uw = vw;
                uh = vh;
            }
            case 270 -> {
                ux = vy;
                uy = crop.getHeight() - vx - vw;
                uw = vh;
                uh = vw;
            }
            default -> {
                ux = vx;
                uy = vy;
                uw = vw;
                uh = vh;
            }
        }
        return new PDRectangle(crop.getLowerLeftX() + ux, crop.getLowerLeftY() + uy, uw, uh);
    }

    private static NormalizedRectangle normalizedRectangle(PDPage page, PDRectangle rectangle) {
        if (rectangle == null) {
            throw new IllegalArgumentException("Signature widget rectangle is required");
        }
        PDRectangle crop = page.getCropBox();
        float ux = rectangle.getLowerLeftX() - crop.getLowerLeftX();
        float uy = rectangle.getLowerLeftY() - crop.getLowerLeftY();
        float uw = rectangle.getWidth();
        float uh = rectangle.getHeight();
        int rotation = pageRotation(page);
        float visualWidth = rotation == 90 || rotation == 270 ? crop.getHeight() : crop.getWidth();
        float visualHeight = rotation == 90 || rotation == 270 ? crop.getWidth() : crop.getHeight();
        float vx;
        float vy;
        float vw;
        float vh;
        switch (rotation) {
            case 90 -> {
                vx = uy;
                vy = crop.getWidth() - ux - uw;
                vw = uh;
                vh = uw;
            }
            case 180 -> {
                vx = crop.getWidth() - ux - uw;
                vy = crop.getHeight() - uy - uh;
                vw = uw;
                vh = uh;
            }
            case 270 -> {
                vx = crop.getHeight() - uy - uh;
                vy = ux;
                vw = uh;
                vh = uw;
            }
            default -> {
                vx = ux;
                vy = uy;
                vw = uw;
                vh = uh;
            }
        }
        return new NormalizedRectangle(
                normalizedDecimal(vx / visualWidth),
                normalizedDecimal(1f - ((vy + vh) / visualHeight)),
                normalizedDecimal(vw / visualWidth),
                normalizedDecimal(vh / visualHeight)
        );
    }

    private static int widgetPageIndex(PDDocument document, PDAnnotationWidget widget) throws Exception {
        COSDictionary target = widget.getCOSObject();
        for (int pageIndex = 0; pageIndex < document.getNumberOfPages(); pageIndex++) {
            for (var annotation : document.getPage(pageIndex).getAnnotations()) {
                if (annotation.getCOSObject() == target) {
                    return pageIndex;
                }
            }
        }
        throw new IllegalArgumentException("Signature widget is not attached to a document page");
    }

    private static List<String> appearanceObjectReferences(
            PDAnnotationWidget widget,
            ObjectIndex objectIndex
    ) {
        COSBase appearance = widget.getCOSObject().getDictionaryObject(COSName.AP);
        if (!(appearance instanceof COSDictionary dictionary)) {
            return List.of();
        }
        Set<String> references = new LinkedHashSet<>();
        Set<COSBase> visited = java.util.Collections.newSetFromMap(new IdentityHashMap<>());
        for (COSName key : List.of(COSName.N, COSName.R, COSName.D)) {
            collectAppearanceReferences(dictionary.getItem(key), objectIndex, references, visited);
        }
        return List.copyOf(references);
    }

    private static void collectAppearanceReferences(
            COSBase value,
            ObjectIndex objectIndex,
            Set<String> references,
            Set<COSBase> visited
    ) {
        if (value instanceof COSObject object) {
            value = object.getObject();
        }
        if (value == null || !visited.add(value)) {
            return;
        }
        String reference = objectIndex.reference(value);
        if (reference != null) {
            references.add(reference);
        }
        if (value instanceof COSDictionary dictionary && !(value instanceof COSStream)) {
            for (COSBase child : dictionary.getValues()) {
                collectAppearanceReferences(child, objectIndex, references, visited);
            }
        }
    }

    private static String normalizedDecimal(float value) {
        return BigDecimal.valueOf(value)
                .setScale(6, RoundingMode.HALF_UP)
                .toPlainString();
    }

    private static COSDictionary selfOnlyLock(String fieldName) {
        COSDictionary lock = new COSDictionary();
        lock.setItem(COSName.TYPE, SIG_FIELD_LOCK);
        lock.setItem(ACTION, INCLUDE);
        COSArray fields = new COSArray();
        fields.add(new COSString(fieldName));
        lock.setItem(FIELDS, fields);
        return lock;
    }

    private static boolean hasSelfOnlyLock(PDSignatureField field) {
        COSDictionary lock = dictionary(field.getCOSObject().getDictionaryObject(LOCK));
        if (lock == null || !INCLUDE.equals(lock.getCOSName(ACTION))) {
            return false;
        }
        COSBase base = lock.getDictionaryObject(FIELDS);
        if (!(base instanceof COSArray fields) || fields.size() != 1) {
            return false;
        }
        COSBase value = resolve(fields.get(0));
        return value instanceof COSString string
                && field.getFullyQualifiedName().equals(string.getString());
    }

    private static void addFieldMdpReference(PDSignature signature, PDDocumentCatalog catalog, String fieldName) {
        COSDictionary parameters = new COSDictionary();
        parameters.setItem(COSName.TYPE, TRANSFORM_PARAMS);
        parameters.setItem(V, VERSION_1_2);
        parameters.setItem(ACTION, INCLUDE);
        COSArray fields = new COSArray();
        fields.add(new COSString(fieldName));
        parameters.setItem(FIELDS, fields);

        COSDictionary reference = new COSDictionary();
        reference.setItem(COSName.TYPE, SIG_REF);
        reference.setItem(TRANSFORM_METHOD, FIELD_MDP);
        reference.setItem(TRANSFORM_PARAMS, parameters);
        reference.setItem(DATA, catalog.getCOSObject());
        COSArray references = new COSArray();
        references.add(reference);
        signature.getCOSObject().setItem(REFERENCE, references);
    }

    private static void addDocMdpReference(PDSignature signature, PDDocumentCatalog catalog) {
        COSDictionary parameters = new COSDictionary();
        parameters.setItem(COSName.TYPE, TRANSFORM_PARAMS);
        parameters.setItem(V, VERSION_1_2);
        parameters.setInt(P, 2);

        COSDictionary reference = new COSDictionary();
        reference.setItem(COSName.TYPE, SIG_REF);
        reference.setItem(TRANSFORM_METHOD, DOC_MDP);
        reference.setItem(TRANSFORM_PARAMS, parameters);
        reference.setItem(DATA, catalog.getCOSObject());

        COSArray references = (COSArray) signature.getCOSObject().getDictionaryObject(REFERENCE);
        references.add(0, reference);
        COSDictionary permissions = dictionary(catalog.getCOSObject().getDictionaryObject(PERMS));
        if (permissions == null) {
            permissions = new COSDictionary();
            catalog.getCOSObject().setItem(PERMS, permissions);
        }
        if (permissions.containsKey(DOC_MDP)) {
            throw new IllegalArgumentException("The document already has a DocMDP permission signature");
        }
        permissions.setItem(DOC_MDP, signature.getCOSObject());
    }

    private static Integer docMdpPermission(PDDocument document) {
        COSDictionary permissions = dictionary(document.getDocumentCatalog().getCOSObject().getDictionaryObject(PERMS));
        if (permissions == null) {
            return null;
        }
        COSDictionary signature = dictionary(permissions.getDictionaryObject(DOC_MDP));
        if (signature == null) {
            return null;
        }
        COSBase referenceBase = signature.getDictionaryObject(REFERENCE);
        if (!(referenceBase instanceof COSArray references)) {
            return null;
        }
        for (COSBase item : references) {
            COSDictionary reference = dictionary(item);
            if (reference != null && DOC_MDP.equals(reference.getCOSName(TRANSFORM_METHOD))) {
                COSDictionary parameters = dictionary(reference.getDictionaryObject(TRANSFORM_PARAMS));
                return parameters == null ? null : parameters.getInt(P);
            }
        }
        return null;
    }

    private static void applyAppearance(PDDocument document, PDSignatureField field, byte[] appearancePng) throws Exception {
        if (field.getWidgets().isEmpty()) {
            throw new IllegalArgumentException("The target signature field has no widget");
        }
        BufferedImage image = ImageIO.read(new ByteArrayInputStream(appearancePng));
        if (image == null) {
            throw new IllegalArgumentException("The appearance is not a supported image");
        }
        PDImageXObject imageObject = LosslessFactory.createFromImage(document, image);
        for (PDAnnotationWidget widget : field.getWidgets()) {
            PDRectangle rectangle = widget.getRectangle();
            PDAppearanceStream stream = new PDAppearanceStream(document);
            stream.setBBox(new PDRectangle(rectangle.getWidth(), rectangle.getHeight()));
            stream.setResources(new PDResources());
            try (PDPageContentStream content = new PDPageContentStream(document, stream)) {
                content.drawImage(imageObject, 0, 0, rectangle.getWidth(), rectangle.getHeight());
            }
            PDAppearanceDictionary appearance = new PDAppearanceDictionary();
            appearance.setNormalAppearance(stream);
            widget.setAppearance(appearance);
            widget.setPrinted(true);
        }
    }

    private static void restoreWidgetRectangles(PDSignatureField field, List<PDRectangle> rectangles) {
        if (field.getWidgets().size() != rectangles.size()) {
            throw new IllegalStateException("The target widget set changed while registering the signature");
        }
        for (int index = 0; index < rectangles.size(); index++) {
            field.getWidgets().get(index).setRectangle(rectangles.get(index));
        }
    }

    private static PDAppearanceDictionary blankAppearance(PDDocument document, float width, float height) {
        PDAppearanceStream stream = new PDAppearanceStream(document);
        stream.setBBox(new PDRectangle(width, height));
        stream.setResources(new PDResources());
        PDAppearanceDictionary appearance = new PDAppearanceDictionary();
        appearance.setNormalAppearance(stream);
        return appearance;
    }

    private static COSDictionary dictionary(COSBase value) {
        COSBase resolved = resolve(value);
        return resolved instanceof COSDictionary dictionary ? dictionary : null;
    }

    private static COSBase resolve(COSBase value) {
        return value instanceof COSObject object ? object.getObject() : value;
    }

    private static BigDecimal coordinate(String value) {
        try {
            BigDecimal coordinate = new BigDecimal(value);
            if (coordinate.scale() > 6 || coordinate.compareTo(BigDecimal.ZERO) < 0
                    || coordinate.compareTo(BigDecimal.ONE) > 0) {
                throw new IllegalArgumentException("Normalized coordinates require 0..1 with at most six decimals");
            }
            return coordinate;
        } catch (NumberFormatException exception) {
            throw new IllegalArgumentException("Normalized coordinate is invalid", exception);
        }
    }

    private static int normalizeRotation(int rotation) {
        int normalized = Math.floorMod(rotation, 360);
        if (normalized % 90 != 0) {
            throw new IllegalArgumentException("Only PDF page rotations in 90-degree increments are supported");
        }
        return normalized;
    }

    private static int pageRotation(PDPage page) {
        int rotation = page.getCOSObject().containsKey(COSName.ROTATE)
                ? page.getCOSObject().getInt(COSName.ROTATE)
                : page.getRotation();
        return normalizeRotation(rotation);
    }

    private static void validateSignCommand(byte[] appearancePng, SignCommand command) {
        if (appearancePng == null || appearancePng.length == 0) {
            throw new IllegalArgumentException("A signature appearance is required");
        }
        if (command == null || command.fieldName() == null || command.fieldName().isBlank()) {
            throw new IllegalArgumentException("A target signature field is required");
        }
        if (command.signerName() == null || command.signerName().isBlank()) {
            throw new IllegalArgumentException("A signer display name is required");
        }
    }

    private static int signatureReservedSize() {
        String raw = System.getenv("SIGNATURE_RESERVED_SIZE");
        if (raw == null || raw.isBlank()) {
            raw = System.getProperty("SIGNATURE_RESERVED_SIZE", "32768");
        }
        int value = Integer.parseInt(raw);
        if (value < 16384 || value > 1024 * 1024) {
            throw new IllegalStateException("SIGNATURE_RESERVED_SIZE must be between 16 KiB and 1 MiB");
        }
        return value;
    }

    private static boolean startsWith(byte[] value, byte[] prefix) {
        if (value.length < prefix.length) {
            return false;
        }
        return MessageDigest.isEqual(java.util.Arrays.copyOf(value, prefix.length), prefix);
    }

    private static String sha256(byte[] value) throws Exception {
        return HEX.formatHex(MessageDigest.getInstance("SHA-256").digest(value));
    }

    private static String decimal(float value) {
        return BigDecimal.valueOf(value).stripTrailingZeros().toPlainString();
    }

    public record NormalizedRectangle(String x, String y, String width, String height) {}

    public record FieldPlan(String fieldName, int pageIndex, NormalizedRectangle rectangle, boolean deferred) {}

    public record SignCommand(
            String fieldName,
            String signatureRole,
            String signerName,
            String reason,
            String location,
            String contact
    ) {}

    public record PageGeometry(
            int pageIndex,
            String cropLowerLeftX,
            String cropLowerLeftY,
            String cropWidth,
            String cropHeight,
            int rotation,
            String userUnit
    ) {}

    public record WidgetInspection(
            int widgetIndex,
            int pageIndex,
            NormalizedRectangle normalizedRectangle,
            String objectRef,
            List<String> appearanceObjectRefs
    ) {}

    public record FieldInspection(
            String fieldName,
            boolean signed,
            boolean selfOnlyLock,
            int widgetCount,
            String objectRef,
            List<WidgetInspection> widgets
    ) {}

    public record Inspection(
            String sha256,
            int pageCount,
            boolean encrypted,
            int signatureCount,
            Integer docMdpPermission,
            List<PageGeometry> pages,
            List<FieldInspection> fields
    ) {}

    private record ObjectIndex(IdentityHashMap<COSBase, COSObjectKey> byIdentity) {
        static ObjectIndex of(PDDocument document) throws Exception {
            Set<COSObjectKey> keys = new HashSet<>(document.getDocument().getXrefTable().keySet());
            IdentityHashMap<COSBase, COSObjectKey> byIdentity = new IdentityHashMap<>();
            for (COSObjectKey key : keys) {
                COSObject object = document.getDocument().getObjectFromPool(key);
                COSBase value = object.getObject();
                if (value != null) {
                    byIdentity.put(value, key);
                }
            }
            return new ObjectIndex(byIdentity);
        }

        String reference(COSBase value) {
            if (value instanceof COSObject object) {
                value = object.getObject();
            }
            COSObjectKey key = byIdentity.get(value);
            return key == null ? null : key.getNumber() + " " + key.getGeneration() + " R";
        }
    }

    private enum SignatureRole {
        CERTIFICATION_P2,
        APPROVAL;

        static SignatureRole parse(String value) {
            return switch (value == null ? "" : value.toLowerCase(Locale.ROOT)) {
                case "certification_p2" -> CERTIFICATION_P2;
                case "approval" -> APPROVAL;
                default -> throw new IllegalArgumentException("Unsupported PDF signature role");
            };
        }
    }
}
