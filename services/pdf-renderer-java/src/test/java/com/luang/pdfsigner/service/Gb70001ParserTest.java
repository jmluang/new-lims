package com.luang.pdfsigner.service;

import java.util.Objects;
import org.apache.pdfbox.Loader;
import org.apache.pdfbox.pdmodel.PDDocument;
import org.apache.pdfbox.text.PDFTextStripper;
import org.junit.jupiter.api.Assertions;
import org.junit.jupiter.api.Test;

public class Gb70001ParserTest {

    @Test
    void parseVersionedSamplePdf() throws Exception {
        byte[] pdf = Objects.requireNonNull(
                getClass().getClassLoader().getResourceAsStream("samples/sample.pdf"),
                "Versioned sample PDF is missing"
        ).readAllBytes();
        try (PDDocument doc = Loader.loadPDF(pdf)) {
            PDFTextStripper stripper = new PDFTextStripper();
            String text = stripper.getText(doc);
            Assertions.assertFalse(text.isBlank(), "extracted text should not be blank");
        }
    }
}
