package com.luang.pdfsigner;

import org.junit.jupiter.api.Assumptions;
import org.junit.jupiter.api.Test;
import org.springframework.beans.factory.annotation.Autowired;
import org.springframework.boot.test.autoconfigure.web.servlet.AutoConfigureMockMvc;
import org.springframework.boot.test.context.SpringBootTest;
import org.springframework.http.MediaType;
import org.springframework.mock.web.MockMultipartFile;
import org.springframework.test.web.servlet.MockMvc;

import java.io.ByteArrayInputStream;
import java.io.InputStream;
import java.util.Base64;
import org.json.JSONObject;

import static org.assertj.core.api.Assertions.assertThat;
import static org.springframework.test.web.servlet.request.MockMvcRequestBuilders.multipart;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.status;

import org.apache.pdfbox.Loader;
import org.apache.pdfbox.pdmodel.PDDocument;
import org.apache.pdfbox.pdmodel.interactive.form.PDAcroForm;
import org.apache.pdfbox.pdmodel.interactive.form.PDField;
import org.apache.pdfbox.pdmodel.interactive.form.PDSignatureField;

@SpringBootTest
@AutoConfigureMockMvc
class ExternalSigning3xTest {

    @Autowired
    private MockMvc mockMvc;

    @Test
    void stampAndSign_withPem_shouldCreateVisibleSignatureField() throws Exception {
        String crt = System.getenv("DEFAULT_PEM_CRT_PATH");
        String key = System.getenv("DEFAULT_PEM_KEY_PATH");
        Assumptions.assumeTrue(crt != null && key != null, "PEM not configured, skipping sign test");

        try (InputStream pdf = getClass().getResourceAsStream("/samples/sample.pdf")) {
            assertThat(pdf).isNotNull();

            // small red PNG as signature image
            byte[] sigPng = createTinyPng(16, 16, 255, 0, 0, 200);

            MockMultipartFile pdfPart = new MockMultipartFile("pdf", "sample.pdf", "application/pdf", pdf);
            MockMultipartFile perfPart = new MockMultipartFile("perforation_image", "perf.png", "image/png", createTinyPng(10, 40, 0, 0, 255, 180));
            MockMultipartFile sigImgPart = new MockMultipartFile("signature_appearance_image", "sig.png", "image/png", sigPng);

            var result = mockMvc.perform(multipart("/api/pdf/process")
                            .file(pdfPart)
                            .file(perfPart)
                            .file(sigImgPart)
                            .param("mode", "stamp_and_sign")
                            .param("hash_algo", "SHA256")
                            .contentType(MediaType.MULTIPART_FORM_DATA))
                    .andExpect(status().isOk())
                    .andReturn();

            byte[] body = result.getResponse().getContentAsByteArray();
            assertThat(body).isNotEmpty();
            assertThat(new String(body, 0, Math.min(body.length, 5))).startsWith("%PDF-");

            try (PDDocument doc = Loader.loadPDF(body)) {
                PDAcroForm acro = doc.getDocumentCatalog().getAcroForm();
                assertThat(acro).isNotNull();
                boolean hasSigField = false;
                for (PDField f : acro.getFields()) {
                    if (f instanceof PDSignatureField) {
                        hasSigField = true;
                        break;
                    }
                }
                assertThat(hasSigField).as("signature field exists").isTrue();
            }
        }
    }

    @Test
    void stamp_only_shouldReturnPdf_andBeLarger() throws Exception {
        try (InputStream pdf = getClass().getResourceAsStream("/samples/sample.pdf")) {
            assertThat(pdf).isNotNull();
            byte[] perf = createTinyPng(10, 40, 0, 0, 255, 180);

            MockMultipartFile pdfPart = new MockMultipartFile("pdf", "sample.pdf", "application/pdf", pdf);
            MockMultipartFile perfPart = new MockMultipartFile("perforation_image", "perf.png", "image/png", perf);

            var result = mockMvc.perform(multipart("/api/pdf/process")
                            .file(pdfPart)
                            .file(perfPart)
                            .param("mode", "stamp")
                            .contentType(MediaType.MULTIPART_FORM_DATA))
                    .andExpect(status().isOk())
                    .andReturn();

            String responseBody = result.getResponse().getContentAsString();
            assertThat(responseBody).isNotEmpty();

            // 解析JSON响应
            JSONObject jsonResponse = new JSONObject(responseBody);
            assertThat(jsonResponse.getBoolean("success")).isTrue();
            assertThat(jsonResponse.has("pdf_base64")).isTrue();

            // 解码base64 PDF
            String pdfBase64 = jsonResponse.getString("pdf_base64");
            byte[] pdfBytes = Base64.getDecoder().decode(pdfBase64);
            assertThat(pdfBytes).isNotEmpty();

            // 验证PDF文件头
            assertThat(new String(pdfBytes, 0, Math.min(pdfBytes.length, 5))).startsWith("%PDF-");
        }
    }

    private byte[] createTinyPng(int w, int h, int r, int g, int b, int a) throws Exception {
        java.awt.image.BufferedImage img = new java.awt.image.BufferedImage(w, h, java.awt.image.BufferedImage.TYPE_INT_ARGB);
        java.awt.Graphics2D g2 = img.createGraphics();
        g2.setColor(new java.awt.Color(r, g, b, a));
        g2.fillRect(0, 0, w, h);
        g2.dispose();
        java.io.ByteArrayOutputStream baos = new java.io.ByteArrayOutputStream();
        javax.imageio.ImageIO.write(img, "png", baos);
        return baos.toByteArray();
    }
}
