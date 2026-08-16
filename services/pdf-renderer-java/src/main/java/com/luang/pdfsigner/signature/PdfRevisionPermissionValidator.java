package com.luang.pdfsigner.signature;

import java.nio.charset.StandardCharsets;
import java.util.ArrayList;
import java.util.Arrays;
import java.util.Comparator;
import java.util.HashSet;
import java.util.IdentityHashMap;
import java.util.LinkedHashSet;
import java.util.List;
import java.util.Map;
import java.util.Set;
import org.apache.pdfbox.Loader;
import org.apache.pdfbox.cos.COSArray;
import org.apache.pdfbox.cos.COSBase;
import org.apache.pdfbox.cos.COSBoolean;
import org.apache.pdfbox.cos.COSDictionary;
import org.apache.pdfbox.cos.COSName;
import org.apache.pdfbox.cos.COSNull;
import org.apache.pdfbox.cos.COSNumber;
import org.apache.pdfbox.cos.COSObject;
import org.apache.pdfbox.cos.COSObjectKey;
import org.apache.pdfbox.cos.COSStream;
import org.apache.pdfbox.cos.COSString;
import org.apache.pdfbox.pdmodel.PDDocument;
import org.apache.pdfbox.pdmodel.interactive.annotation.PDAnnotationWidget;
import org.apache.pdfbox.pdmodel.interactive.digitalsignature.PDSignature;
import org.apache.pdfbox.pdmodel.interactive.form.PDAcroForm;
import org.apache.pdfbox.pdmodel.interactive.form.PDField;
import org.apache.pdfbox.pdmodel.interactive.form.PDSignatureField;

final class PdfRevisionPermissionValidator {
    private static final COSName ACTION = COSName.getPDFName("Action");
    private static final COSName DATA = COSName.getPDFName("Data");
    private static final COSName DOC_MDP = COSName.getPDFName("DocMDP");
    private static final COSName FIELD_MDP = COSName.getPDFName("FieldMDP");
    private static final COSName FIELDS = COSName.getPDFName("Fields");
    private static final COSName INCLUDE = COSName.getPDFName("Include");
    private static final COSName LOCK = COSName.getPDFName("Lock");
    private static final COSName P = COSName.getPDFName("P");
    private static final COSName PERMS = COSName.getPDFName("Perms");
    private static final COSName REFERENCE = COSName.getPDFName("Reference");
    private static final COSName TRANSFORM_METHOD = COSName.getPDFName("TransformMethod");
    private static final COSName TRANSFORM_PARAMS = COSName.getPDFName("TransformParams");
    private static final COSName V = COSName.getPDFName("V");

    Validation validate(byte[] pdf) throws Exception {
        try (PDDocument finalDocument = Loader.loadPDF(pdf)) {
            List<PDSignature> signatures = new ArrayList<>(finalDocument.getSignatureDictionaries());
            signatures.sort(Comparator.comparingInt(PdfRevisionPermissionValidator::signedRevisionEnd));
            if (signatures.isEmpty()) {
                return new Validation(true, null);
            }
            int previousEnd = -1;
            for (int index = 0; index < signatures.size(); index++) {
                int currentEnd = signedRevisionEnd(signatures.get(index));
                if (currentEnd <= previousEnd || currentEnd > pdf.length
                        || (index == signatures.size() - 1 && currentEnd != pdf.length)) {
                    return new Validation(false, "UNSIGNED_OR_AMBIGUOUS_LATER_REVISION");
                }
                byte[] currentRevision = Arrays.copyOf(pdf, currentEnd);
                int baseEnd = index == 0
                        ? previousRevisionEnd(currentRevision)
                        : previousEnd;
                if (baseEnd <= 0 || baseEnd >= currentEnd) {
                    return new Validation(false, "SIGNED_REVISION_BOUNDARY_INVALID");
                }
                byte[] baseRevision = Arrays.copyOf(pdf, baseEnd);
                validateTransition(baseRevision, currentRevision, index);
                previousEnd = currentEnd;
            }
            return new Validation(true, null);
        } catch (PermissionViolation violation) {
            return new Validation(false, violation.getMessage());
        }
    }

    private static void validateTransition(byte[] baseBytes, byte[] currentBytes, int signatureIndex)
            throws Exception {
        try (PDDocument base = Loader.loadPDF(baseBytes);
             PDDocument current = Loader.loadPDF(currentBytes)) {
            List<PDSignature> currentSignatures = new ArrayList<>(current.getSignatureDictionaries());
            currentSignatures.sort(Comparator.comparingInt(PdfRevisionPermissionValidator::signedRevisionEnd));
            if (base.getSignatureDictionaries().size() != signatureIndex
                    || currentSignatures.size() != signatureIndex + 1) {
                throw violation("SIGNATURE_REVISION_SEQUENCE_INVALID");
            }
            PDSignature signature = currentSignatures.get(signatureIndex);
            PDSignatureField targetField = owningField(current, signature);
            validateSelfOnlyLock(targetField);
            validateSignatureTransforms(current, signature, targetField, signatureIndex == 0);
            validateStableDocumentContainers(base, current);
            validateStableTargetField(base, current, targetField.getFullyQualifiedName());
            validateChangedObjects(base, current, targetField, signature, signatureIndex == 0);
        }
    }

    private static void validateStableDocumentContainers(PDDocument base, PDDocument current) throws Exception {
        if (!canonicalDictionary(
                base.getDocumentCatalog().getCOSObject(),
                Set.of(PERMS, COSName.ACRO_FORM)
        ).equals(canonicalDictionary(
                current.getDocumentCatalog().getCOSObject(),
                Set.of(PERMS, COSName.ACRO_FORM)
        ))) {
            throw violation("CATALOG_CHANGED_OUTSIDE_SIGNATURE_PERMISSIONS");
        }
        PDAcroForm baseForm = base.getDocumentCatalog().getAcroForm();
        PDAcroForm currentForm = current.getDocumentCatalog().getAcroForm();
        if (baseForm == null || currentForm == null
                || !canonicalDictionary(baseForm.getCOSObject(), Set.of(COSName.DR))
                .equals(canonicalDictionary(currentForm.getCOSObject(), Set.of(COSName.DR)))) {
            throw violation("ACROFORM_CHANGED_OUTSIDE_SIGNATURE_RESOURCES");
        }
    }

    private static void validateChangedObjects(
            PDDocument base,
            PDDocument current,
            PDSignatureField targetField,
            PDSignature signature,
            boolean certification
    ) throws Exception {
        ObjectIndex baseIndex = ObjectIndex.of(base);
        ObjectIndex currentIndex = ObjectIndex.of(current);
        Set<COSObjectKey> changed = new LinkedHashSet<>();
        for (COSObjectKey key : base.getDocument().getXrefTable().keySet()) {
            if (!current.getDocument().getXrefTable().containsKey(key)) {
                throw violation("INCREMENTAL_OBJECT_REMOVED");
            }
        }
        for (Map.Entry<COSObjectKey, Long> entry : current.getDocument().getXrefTable().entrySet()) {
            Long previousOffset = base.getDocument().getXrefTable().get(entry.getKey());
            if (previousOffset == null || !previousOffset.equals(entry.getValue())) {
                changed.add(entry.getKey());
            }
        }

        Set<COSObjectKey> allowedExisting = new HashSet<>();
        addKey(allowedExisting, currentIndex, targetField.getCOSObject());
        for (PDAnnotationWidget widget : targetField.getWidgets()) {
            addKey(allowedExisting, currentIndex, widget.getCOSObject());
        }
        Set<COSObjectKey> allowedGraph = new HashSet<>();
        PDAcroForm acroForm = current.getDocumentCatalog().getAcroForm();
        if (acroForm != null) {
            addKey(allowedExisting, currentIndex, acroForm.getCOSObject());
            collectReferences(
                    acroForm.getCOSObject().getItem(COSName.DR),
                    currentIndex,
                    allowedExisting,
                    Set.of()
            );
            collectReferences(
                    acroForm.getCOSObject().getItem(COSName.DR),
                    currentIndex,
                    allowedGraph,
                    Set.of()
            );
        }
        if (certification) {
            addKey(allowedExisting, currentIndex, current.getDocumentCatalog().getCOSObject());
            collectReferences(
                    current.getDocumentCatalog().getCOSObject().getItem(PERMS),
                    currentIndex,
                    allowedGraph,
                    Set.of(DATA)
            );
        }

        collectReferences(signature.getCOSObject(), currentIndex, allowedGraph,
                Set.of(DATA, COSName.PARENT, COSName.P));
        for (PDAnnotationWidget widget : targetField.getWidgets()) {
            collectReferences(widget.getCOSObject().getItem(COSName.AP), currentIndex, allowedGraph, Set.of());
        }

        for (COSObjectKey key : changed) {
            boolean existed = baseIndex.contains(key);
            if (existed && canonical(
                    base.getDocument().getObjectFromPool(key).getObject(),
                    Set.of(),
                    true,
                    new IdentityHashMap<>()
            ).equals(canonical(
                    current.getDocument().getObjectFromPool(key).getObject(),
                    Set.of(),
                    true,
                    new IdentityHashMap<>()
            ))) {
                continue;
            }
            if ((existed && allowedExisting.contains(key)) || (!existed && allowedGraph.contains(key))) {
                continue;
            }
            throw violation("UNAUTHORIZED_INCREMENTAL_OBJECT_CHANGE");
        }
    }

    private static void validateStableTargetField(
            PDDocument base,
            PDDocument current,
            String fieldName
    ) throws Exception {
        PDAcroForm baseForm = base.getDocumentCatalog().getAcroForm();
        PDAcroForm currentForm = current.getDocumentCatalog().getAcroForm();
        if (baseForm == null || currentForm == null
                || !(baseForm.getField(fieldName) instanceof PDSignatureField baseField)
                || !(currentForm.getField(fieldName) instanceof PDSignatureField currentField)) {
            throw violation("TARGET_FIELD_NOT_PRECREATED");
        }
        if (!canonicalDictionary(baseField.getCOSObject(), Set.of(V))
                .equals(canonicalDictionary(currentField.getCOSObject(), Set.of(V)))) {
            throw violation("TARGET_FIELD_CHANGED_OUTSIDE_SIGNATURE_VALUE");
        }
        if (baseField.getWidgets().size() != currentField.getWidgets().size()) {
            throw violation("TARGET_WIDGET_SET_CHANGED");
        }
        for (int index = 0; index < baseField.getWidgets().size(); index++) {
            PDAnnotationWidget beforeWidget = baseField.getWidgets().get(index);
            PDAnnotationWidget afterWidget = currentField.getWidgets().get(index);
            COSDictionary before = beforeWidget.getCOSObject();
            COSDictionary after = afterWidget.getCOSObject();
            if (!sameRectangle(beforeWidget, afterWidget)
                    || !canonicalDictionary(before, Set.of(COSName.AP, COSName.RECT))
                    .equals(canonicalDictionary(after, Set.of(COSName.AP, COSName.RECT)))) {
                throw violation("TARGET_WIDGET_CHANGED_OUTSIDE_APPEARANCE");
            }
        }
    }

    private static boolean sameRectangle(PDAnnotationWidget before, PDAnnotationWidget after) {
        if (before.getRectangle() == null || after.getRectangle() == null) {
            return before.getRectangle() == after.getRectangle();
        }
        return close(before.getRectangle().getLowerLeftX(), after.getRectangle().getLowerLeftX())
                && close(before.getRectangle().getLowerLeftY(), after.getRectangle().getLowerLeftY())
                && close(before.getRectangle().getUpperRightX(), after.getRectangle().getUpperRightX())
                && close(before.getRectangle().getUpperRightY(), after.getRectangle().getUpperRightY());
    }

    private static boolean close(float left, float right) {
        return Math.abs(left - right) <= 0.0001f;
    }

    private static void validateSignatureTransforms(
            PDDocument document,
            PDSignature signature,
            PDSignatureField field,
            boolean certification
    ) {
        COSBase referencesBase = signature.getCOSObject().getDictionaryObject(REFERENCE);
        if (!(referencesBase instanceof COSArray references)) {
            throw violation("SIGNATURE_TRANSFORMS_MISSING");
        }
        int fieldMdpCount = 0;
        int docMdpCount = 0;
        for (COSBase item : references) {
            COSDictionary reference = dictionary(item);
            if (reference == null) {
                throw violation("SIGNATURE_TRANSFORM_INVALID");
            }
            COSName method = reference.getCOSName(TRANSFORM_METHOD);
            COSDictionary parameters = dictionary(reference.getDictionaryObject(TRANSFORM_PARAMS));
            if (FIELD_MDP.equals(method)) {
                fieldMdpCount++;
                validateIncludedField(parameters, field.getFullyQualifiedName());
            } else if (DOC_MDP.equals(method)) {
                docMdpCount++;
                if (parameters == null || parameters.getInt(P) != 2) {
                    throw violation("DOC_MDP_PERMISSION_INVALID");
                }
            } else {
                throw violation("UNSUPPORTED_SIGNATURE_TRANSFORM");
            }
        }
        if (fieldMdpCount != 1 || docMdpCount != (certification ? 1 : 0)) {
            throw violation("SIGNATURE_TRANSFORM_COUNT_INVALID");
        }
        COSDictionary permissions = dictionary(
                document.getDocumentCatalog().getCOSObject().getDictionaryObject(PERMS));
        COSDictionary certificationSignature = permissions == null
                ? null
                : dictionary(permissions.getDictionaryObject(DOC_MDP));
        if (certificationSignature == null) {
            throw violation("CATALOG_DOC_MDP_MISSING");
        }
        if (certification && certificationSignature != signature.getCOSObject()) {
            throw violation("CATALOG_DOC_MDP_TARGET_INVALID");
        }
    }

    private static void validateIncludedField(COSDictionary parameters, String fieldName) {
        if (parameters == null || !INCLUDE.equals(parameters.getCOSName(ACTION))) {
            throw violation("FIELD_MDP_ACTION_INVALID");
        }
        COSBase fieldsBase = parameters.getDictionaryObject(FIELDS);
        if (!(fieldsBase instanceof COSArray fields) || fields.size() != 1
                || !(resolve(fields.get(0)) instanceof COSString name)
                || !fieldName.equals(name.getString())) {
            throw violation("FIELD_MDP_SCOPE_INVALID");
        }
    }

    private static PDSignatureField owningField(PDDocument document, PDSignature signature) {
        PDAcroForm acroForm = document.getDocumentCatalog().getAcroForm();
        if (acroForm == null) {
            throw violation("SIGNATURE_FIELD_MISSING");
        }
        PDSignatureField owner = null;
        for (PDField field : acroForm.getFieldTree()) {
            if (field instanceof PDSignatureField signatureField
                    && signatureField.getSignature() != null
                    && signatureField.getSignature().getCOSObject() == signature.getCOSObject()) {
                if (owner != null) {
                    throw violation("SIGNATURE_FIELD_AMBIGUOUS");
                }
                owner = signatureField;
            }
        }
        if (owner == null) {
            throw violation("SIGNATURE_FIELD_MISSING");
        }
        return owner;
    }

    private static void validateSelfOnlyLock(PDSignatureField field) {
        COSDictionary lock = dictionary(field.getCOSObject().getDictionaryObject(LOCK));
        if (lock == null || !INCLUDE.equals(lock.getCOSName(ACTION))) {
            throw violation("FIELD_LOCK_INVALID");
        }
        COSBase fieldsBase = lock.getDictionaryObject(FIELDS);
        if (!(fieldsBase instanceof COSArray fields) || fields.size() != 1
                || !(resolve(fields.get(0)) instanceof COSString name)
                || !field.getFullyQualifiedName().equals(name.getString())) {
            throw violation("FIELD_LOCK_SCOPE_INVALID");
        }
    }

    private static int signedRevisionEnd(PDSignature signature) {
        int[] range = signature.getByteRange();
        if (range == null || range.length != 4) {
            return -1;
        }
        long end = (long) range[2] + range[3];
        return end > Integer.MAX_VALUE ? -1 : (int) end;
    }

    private static int previousRevisionEnd(byte[] revision) {
        byte[] marker = "%%EOF".getBytes(StandardCharsets.US_ASCII);
        int last = lastIndexOf(revision, marker, revision.length - marker.length);
        if (last < 0) {
            return -1;
        }
        int previous = lastIndexOf(revision, marker, last - 1);
        return previous < 0 ? -1 : previous + marker.length;
    }

    private static int lastIndexOf(byte[] value, byte[] needle, int fromIndex) {
        for (int index = Math.min(fromIndex, value.length - needle.length); index >= 0; index--) {
            boolean matches = true;
            for (int offset = 0; offset < needle.length; offset++) {
                if (value[index + offset] != needle[offset]) {
                    matches = false;
                    break;
                }
            }
            if (matches) {
                return index;
            }
        }
        return -1;
    }

    private static void collectReferences(
            COSBase value,
            ObjectIndex index,
            Set<COSObjectKey> references,
            Set<COSName> skippedKeys
    ) throws Exception {
        collectReferences(value, index, references, skippedKeys, new IdentityHashMap<>());
    }

    private static void collectReferences(
            COSBase value,
            ObjectIndex index,
            Set<COSObjectKey> references,
            Set<COSName> skippedKeys,
            IdentityHashMap<COSBase, Boolean> visited
    ) throws Exception {
        if (value == null || visited.put(value, Boolean.TRUE) != null) {
            return;
        }
        if (value instanceof COSObject object) {
            COSObjectKey key = new COSObjectKey(object.getObjectNumber(), object.getGenerationNumber());
            references.add(key);
            collectReferences(object.getObject(), index, references, skippedKeys, visited);
            return;
        }
        COSObjectKey key = index.keyOf(value);
        if (key != null) {
            references.add(key);
        }
        if (value instanceof COSDictionary dictionary) {
            for (COSName name : dictionary.keySet()) {
                if (!skippedKeys.contains(name)) {
                    collectReferences(dictionary.getItem(name), index, references, skippedKeys, visited);
                }
            }
        } else if (value instanceof COSArray array) {
            for (COSBase item : array) {
                collectReferences(item, index, references, skippedKeys, visited);
            }
        }
    }

    private static String canonicalDictionary(COSDictionary dictionary, Set<COSName> excludedRootKeys)
            throws Exception {
        return canonical(dictionary, excludedRootKeys, true, new IdentityHashMap<>());
    }

    private static String canonical(
            COSBase value,
            Set<COSName> excludedRootKeys,
            boolean root,
            IdentityHashMap<COSBase, Boolean> visited
    ) throws Exception {
        if (value == null || value instanceof COSNull) {
            return "null";
        }
        if (value instanceof COSObject object) {
            return "ref:" + object.getObjectNumber() + ':' + object.getGenerationNumber();
        }
        if (value instanceof COSName name) {
            return "name:" + name.getName();
        }
        if (value instanceof COSString string) {
            return "str:" + java.util.HexFormat.of().formatHex(string.getBytes());
        }
        if (value instanceof COSNumber number) {
            return "num:" + number;
        }
        if (value instanceof COSBoolean bool) {
            return "bool:" + bool.getValue();
        }
        if (visited.put(value, Boolean.TRUE) != null) {
            return "cycle";
        }
        try {
            if (value instanceof COSArray array) {
                StringBuilder result = new StringBuilder("[");
                for (COSBase item : array) {
                    result.append(canonical(item, excludedRootKeys, false, visited)).append(',');
                }
                return result.append(']').toString();
            }
            if (value instanceof COSDictionary dictionary) {
                List<COSName> keys = new ArrayList<>(dictionary.keySet());
                keys.sort(Comparator.comparing(COSName::getName));
                StringBuilder result = new StringBuilder(value instanceof COSStream ? "stream{" : "dict{");
                for (COSName key : keys) {
                    if (root && excludedRootKeys.contains(key)) {
                        continue;
                    }
                    result.append(key.getName()).append('=')
                            .append(canonical(dictionary.getItem(key), excludedRootKeys, false, visited))
                            .append(';');
                }
                if (value instanceof COSStream stream) {
                    try (java.io.InputStream input = stream.createRawInputStream()) {
                        result.append("raw=")
                                .append(java.util.HexFormat.of().formatHex(
                                        java.security.MessageDigest.getInstance("SHA-256")
                                                .digest(input.readAllBytes())
                                ))
                                .append(';');
                    }
                }
                return result.append('}').toString();
            }
            return value.toString();
        } finally {
            visited.remove(value);
        }
    }

    private static COSDictionary dictionary(COSBase value) {
        COSBase resolved = resolve(value);
        return resolved instanceof COSDictionary dictionary ? dictionary : null;
    }

    private static COSBase resolve(COSBase value) {
        return value instanceof COSObject object ? object.getObject() : value;
    }

    private static void addKey(Set<COSObjectKey> keys, ObjectIndex index, COSBase value) {
        COSObjectKey key = index.keyOf(value);
        if (key != null) {
            keys.add(key);
        }
    }

    private static PermissionViolation violation(String code) {
        return new PermissionViolation(code);
    }

    record Validation(boolean valid, String error) {}

    private record ObjectIndex(Set<COSObjectKey> keys, IdentityHashMap<COSBase, COSObjectKey> byIdentity) {
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
            return new ObjectIndex(keys, byIdentity);
        }

        boolean contains(COSObjectKey key) {
            return keys.contains(key);
        }

        COSObjectKey keyOf(COSBase value) {
            return byIdentity.get(value);
        }
    }

    private static final class PermissionViolation extends RuntimeException {
        private PermissionViolation(String message) {
            super(message);
        }
    }
}
