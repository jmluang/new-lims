package com.luang.pdfsigner;

import org.apache.pdfbox.Loader;
import org.apache.pdfbox.cos.COSName;
import org.apache.pdfbox.pdmodel.PDDocument;
import org.apache.pdfbox.pdmodel.PDPage;
import org.apache.pdfbox.pdmodel.interactive.annotation.PDAnnotation;
import org.apache.pdfbox.pdmodel.interactive.annotation.PDAnnotationWidget;
import org.apache.pdfbox.pdmodel.interactive.form.PDAcroForm;
import org.apache.pdfbox.pdmodel.interactive.form.PDField;
import org.apache.pdfbox.pdmodel.interactive.form.PDSignatureField;
import org.apache.pdfbox.pdmodel.interactive.digitalsignature.PDSignature;
import org.junit.jupiter.api.Assumptions;
import org.junit.jupiter.api.Test;

import java.io.File;
import java.util.List;

import static org.assertj.core.api.Assertions.assertThat;

public class InspectExternalPdfSignatureTest {

    @Test
    void inspectReferencePdf() throws Exception {
        // 优先读取系统属性，其次环境变量，否则使用用户给的默认路径
        String path = System.getProperty("INSPECT_PDF_PATH");
        if (path == null || path.isBlank()) path = System.getenv("INSPECT_PDF_PATH");
        if (path == null || path.isBlank()) path = "/Users/luang/Downloads/YDM25_000712_副本.pdf";

        File f = new File(path);
        System.out.println("[inspect] open: " + f.getAbsolutePath() + ", exists=" + f.exists());
        Assumptions.assumeTrue(f.exists(), "reference pdf exists");

        try (PDDocument doc = Loader.loadPDF(f)) {
            PDAcroForm acro = doc.getDocumentCatalog().getAcroForm();
            System.out.println("[inspect] hasAcroForm=" + (acro != null));
            if (acro == null) return;

            List<PDField> fields = acro.getFields();
            System.out.println("[inspect] fieldCount=" + fields.size());
            int pageIndex = 0;
            for (PDField field : fields) {
                System.out.println("[inspect] field name=" + field.getFullyQualifiedName() + ", type=" + field.getClass().getSimpleName());
                if (field instanceof PDSignatureField sigField) {
                    PDSignature sig = sigField.getSignature();
                    System.out.println("  signature dict present=" + (sig != null));
                    if (sig != null) {
                        System.out.println("  filter=" + sig.getFilter() + ", subFilter=" + sig.getSubFilter() + ", name=" + sig.getName());
                    }
                    for (PDAnnotationWidget w : sigField.getWidgets()) {
                        var rect = w.getRectangle();
                        System.out.println("  widget rect=" + rect);
                        // page
                        PDPage widgetPage = w.getPage();
                        if (widgetPage == null) {
                            // fallback: find page containing the widget
                            for (int i = 0; i < doc.getNumberOfPages(); i++) {
                                PDPage p = doc.getPage(i);
                                for (PDAnnotation ann : p.getAnnotations()) {
                                    if (ann == w) { widgetPage = p; pageIndex = i; break; }
                                }
                                if (widgetPage != null) break;
                            }
                        } else {
                            // try to resolve index
                            for (int i = 0; i < doc.getNumberOfPages(); i++) {
                                if (doc.getPage(i) == widgetPage) { pageIndex = i; break; }
                            }
                        }
                        System.out.println("  widget pageIndex=" + pageIndex);
                        var ap = w.getAppearance();
                        System.out.println("  hasAppearanceDict=" + (ap != null));
                        if (ap != null) {
                            var normal = ap.getNormalAppearance();
                            System.out.println("  hasNormalAppearance=" + (normal != null));
                            if (normal != null) {
                                var stream = normal.getAppearanceStream();
                                System.out.println("  normalStream bbox=" + (stream != null ? stream.getBBox() : null));
                                System.out.println("  normalStream resources null?=" + (stream == null || stream.getResources() == null));
                            }
                        }
                        // appearance state
                        var cos = w.getCOSObject();
                        System.out.println("  AP state=" + cos.getNameAsString(COSName.AS));
                    }
                }
            }
        }
    }
}
