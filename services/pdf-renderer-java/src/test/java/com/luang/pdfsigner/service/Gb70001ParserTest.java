package com.luang.pdfsigner.service;

import com.luang.pdfsigner.service.renderer.Gb70001FormRenderer;
import java.io.IOException;
import java.nio.file.Files;
import java.nio.file.Path;
import java.util.HashMap;
import java.util.Map;
import org.apache.pdfbox.Loader;
import org.apache.pdfbox.pdmodel.PDDocument;
import org.apache.pdfbox.text.PDFTextStripper;
import org.junit.jupiter.api.Assertions;
import org.junit.jupiter.api.Test;

public class Gb70001ParserTest {

    @Test
    void parseExtraPdf() throws Exception {
        Path pdf = Path.of("../../extra.pdf").normalize();
        Assertions.assertTrue(Files.exists(pdf), "extra.pdf not found");
        try (PDDocument doc = Loader.loadPDF(pdf.toFile())) {
            PDFTextStripper stripper = new PDFTextStripper();
            String text = stripper.getText(doc);
            System.out.println(text);
            Assertions.assertFalse(text.isBlank(), "extracted text should not be blank");
        }
    }
}
