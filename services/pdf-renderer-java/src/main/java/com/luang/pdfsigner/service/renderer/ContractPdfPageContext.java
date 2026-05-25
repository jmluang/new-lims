package com.luang.pdfsigner.service.renderer;

import java.io.IOException;
import org.apache.pdfbox.pdmodel.PDDocument;
import org.apache.pdfbox.pdmodel.PDPage;
import org.apache.pdfbox.pdmodel.PDPageContentStream;
import org.apache.pdfbox.pdmodel.PDPageContentStream.AppendMode;
import org.apache.pdfbox.pdmodel.common.PDRectangle;

public class ContractPdfPageContext {

    private final PDDocument document;
    private final float contentTop;
    private final float contentBottom;

    public ContractPdfPageContext(PDDocument document, float contentTop, float contentBottom) {
        this.document = document;
        this.contentTop = contentTop;
        this.contentBottom = contentBottom;
    }

    public BodyCanvas newPage() throws IOException {
        PDPage page = new PDPage(PDRectangle.A4);
        document.addPage(page);
        PDPageContentStream content = new PDPageContentStream(document, page, AppendMode.APPEND, true, true);
        return new BodyCanvas(page, content, contentTop, contentBottom);
    }

    public static final class BodyCanvas implements AutoCloseable {
        private final PDPage page;
        private final PDPageContentStream content;
        private final float contentTop;
        private final float contentBottom;

        private BodyCanvas(PDPage page, PDPageContentStream content, float contentTop, float contentBottom) {
            this.page = page;
            this.content = content;
            this.contentTop = contentTop;
            this.contentBottom = contentBottom;
        }

        public PDPage page() {
            return page;
        }

        public PDPageContentStream content() {
            return content;
        }

        public float contentTop() {
            return contentTop;
        }

        public float contentBottom() {
            return contentBottom;
        }

        @Override
        public void close() throws IOException {
            content.close();
        }
    }
}
