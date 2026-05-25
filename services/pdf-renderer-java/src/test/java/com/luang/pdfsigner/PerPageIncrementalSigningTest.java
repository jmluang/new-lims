package com.luang.pdfsigner;

import org.junit.jupiter.api.Assumptions;
import org.junit.jupiter.api.Test;
import org.springframework.beans.factory.annotation.Autowired;
import org.springframework.boot.test.autoconfigure.web.servlet.AutoConfigureMockMvc;
import org.springframework.boot.test.context.SpringBootTest;
import org.springframework.http.MediaType;
import org.springframework.mock.web.MockMultipartFile;
import org.springframework.test.web.servlet.MockMvc;

import java.io.ByteArrayOutputStream;

import static org.assertj.core.api.Assertions.assertThat;
import static org.springframework.test.web.servlet.request.MockMvcRequestBuilders.multipart;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.status;

import org.apache.pdfbox.Loader;
import org.apache.pdfbox.pdmodel.PDDocument;
import org.apache.pdfbox.pdmodel.PDPage;
import org.apache.pdfbox.pdmodel.common.PDRectangle;
import org.apache.pdfbox.pdmodel.interactive.form.PDAcroForm;
import org.apache.pdfbox.pdmodel.interactive.form.PDField;
import org.apache.pdfbox.pdmodel.interactive.form.PDSignatureField;
import org.apache.pdfbox.pdmodel.interactive.digitalsignature.PDSignature;

@SpringBootTest
@AutoConfigureMockMvc
class PerPageIncrementalSigningTest {

    @Autowired
    private MockMvc mockMvc;

    @Test
    void stamp_and_sign_shouldCreateOneSignaturePerPage_andTamperMarksAll() throws Exception {
        String crt = System.getenv("DEFAULT_PEM_CRT_PATH");
        String key = System.getenv("DEFAULT_PEM_KEY_PATH");
        Assumptions.assumeTrue(crt != null && !crt.isBlank() && key != null && !key.isBlank(),
                "PEM not configured, skipping per-page sign test");

        byte[] threePagePdf = createMultiPagePdf(3);
        byte[] perfPng = createTinyPng(120, 480, 0, 128, 255, 220);
        byte[] frontSeal = createTinyPng(180, 180, 255, 0, 0, 200);

        MockMultipartFile pdfPart = new MockMultipartFile("pdf", "multi.pdf", "application/pdf", threePagePdf);
        MockMultipartFile perfPart = new MockMultipartFile("perforation_image", "perf.png", "image/png", perfPng);
        MockMultipartFile sigPart = new MockMultipartFile("signature_appearance_image", "seal.png", "image/png", frontSeal);

        var result = mockMvc.perform(multipart("/api/pdf/process")
                        .file(pdfPart)
                        .file(perfPart)
                        .file(sigPart)
                        .param("mode", "stamp_and_sign")
                        .param("hash_algo", "SHA256")
                        .contentType(MediaType.MULTIPART_FORM_DATA))
                .andExpect(status().isOk())
                .andReturn();

        byte[] signed = result.getResponse().getContentAsByteArray();
        assertThat(signed).isNotEmpty();
        assertThat(new String(signed, 0, Math.min(signed.length, 5))).startsWith("%PDF-");

        int pageCount;
        int signatureFieldCount = 0;
        try (PDDocument doc = Loader.loadPDF(signed)) {
            pageCount = doc.getNumberOfPages();
            PDAcroForm acro = doc.getDocumentCatalog().getAcroForm();
            assertThat(acro).isNotNull();
            for (PDField f : acro.getFields()) {
                if (f instanceof PDSignatureField) {
                    signatureFieldCount++;
                    PDSignature sig = ((PDSignatureField) f).getSignature();
                    assertThat(sig).as("signature dict present").isNotNull();
                }
            }
        }
        assertThat(signatureFieldCount).as("front seal + one signature per page").isEqualTo(pageCount + 1);

        // Tamper: append an incremental update
        byte[] tampered;
        try (PDDocument doc = Loader.loadPDF(signed)) {
            doc.getDocumentInformation().setCustomMetadataValue("tamper", Long.toString(System.currentTimeMillis()));
            ByteArrayOutputStream baos = new ByteArrayOutputStream();
            doc.saveIncremental(baos);
            tampered = baos.toByteArray();
        }

        // Verify each signature's ByteRange ends before file end -> indicates modification after signing
        try (PDDocument doc = Loader.loadPDF(tampered)) {
            PDAcroForm acro = doc.getDocumentCatalog().getAcroForm();
            assertThat(acro).isNotNull();
            int tamperedLen = tampered.length;
            int checked = 0;
            for (PDField f : acro.getFields()) {
                if (f instanceof PDSignatureField) {
                    PDSignature sig = ((PDSignatureField) f).getSignature();
                    assertThat(sig).isNotNull();
                    int[] br = sig.getByteRange();
                    assertThat(br).isNotNull();
                    assertThat(br.length).isEqualTo(4);
                    int signedEnd = br[2] + br[3];
                    assertThat(tamperedLen).isGreaterThan(signedEnd);
                    checked++;
                }
            }
            assertThat(checked).isEqualTo(signatureFieldCount);
        }
    }

    private byte[] createMultiPagePdf(int pages) throws Exception {
        try (PDDocument doc = new PDDocument()) {
            for (int i = 0; i < pages; i++) {
                PDPage p = new PDPage(PDRectangle.A4);
                doc.addPage(p);
            }
            ByteArrayOutputStream baos = new ByteArrayOutputStream();
            doc.save(baos);
            return baos.toByteArray();
        }
    }

    private byte[] createTinyPng(int w, int h, int r, int g, int b, int a) throws Exception {
        java.awt.image.BufferedImage img = new java.awt.image.BufferedImage(w, h, java.awt.image.BufferedImage.TYPE_INT_ARGB);
        java.awt.Graphics2D g2 = img.createGraphics();
        g2.setColor(new java.awt.Color(r, g, b, a));
        g2.fillRect(0, 0, w, h);
        g2.dispose();
        ByteArrayOutputStream baos = new ByteArrayOutputStream();
        javax.imageio.ImageIO.write(img, "png", baos);
        return baos.toByteArray();
    }
}
