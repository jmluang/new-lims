package com.luang.pdfsigner;

import org.junit.jupiter.api.Assumptions;
import org.junit.jupiter.api.Test;
import org.springframework.beans.factory.annotation.Autowired;
import org.springframework.boot.test.autoconfigure.web.servlet.AutoConfigureMockMvc;
import org.springframework.boot.test.context.SpringBootTest;
import org.springframework.http.MediaType;
import org.springframework.mock.web.MockMultipartFile;
import org.springframework.test.web.servlet.MockMvc;

import java.io.IOException;
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
class SampleFilesFlowTest {

    @Autowired
    private MockMvc mockMvc;

    @Test
    void stamp_only_with_samples_shouldReturnPdf() throws Exception {
        byte[] inputPdf = readRes("/samples/file.pdf");
        byte[] perfPng = readRes("/samples/stamp2.png");
        assertThat(inputPdf).isNotEmpty();
        assertThat(perfPng).isNotEmpty();

        MockMultipartFile pdfPart = new MockMultipartFile("pdf", "file.pdf", "application/pdf", inputPdf);
        MockMultipartFile perfPart = new MockMultipartFile("perforation_image", "stamp2.png", "image/png", perfPng);

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
        byte[] out = Base64.getDecoder().decode(pdfBase64);
        assertThat(out).isNotEmpty();
        assertThat(new String(out, 0, Math.min(out.length, 5))).startsWith("%PDF-");
        assertThat(out.length).isGreaterThan(inputPdf.length);
    }

    @Test
    void stamp_and_sign_with_samples_shouldCreateSignatureField_whenPemConfigured() throws Exception {
        String crt = System.getenv("DEFAULT_PEM_CRT_PATH");
        String key = System.getenv("DEFAULT_PEM_KEY_PATH");
        Assumptions.assumeTrue(crt != null && !crt.isBlank() && key != null && !key.isBlank(),
                "PEM not configured, skipping sign test");

        byte[] inputPdf = readRes("/samples/file.pdf");
        byte[] perfPng = readRes("/samples/stamp2.png");
        byte[] sigPng = readRes("/samples/stamp1.png");
        assertThat(inputPdf).isNotEmpty();
        assertThat(perfPng).isNotEmpty();
        assertThat(sigPng).isNotEmpty();

        MockMultipartFile pdfPart = new MockMultipartFile("pdf", "file.pdf", "application/pdf", inputPdf);
        MockMultipartFile perfPart = new MockMultipartFile("perforation_image", "stamp2.png", "image/png", perfPng);
        MockMultipartFile sigPart = new MockMultipartFile("signature_appearance_image", "stamp1.png", "image/png", sigPng);

        var result = mockMvc.perform(multipart("/api/pdf/process")
                        .file(pdfPart)
                        .file(perfPart)
                        .file(sigPart)
                        .param("mode", "stamp_and_sign")
                        .param("hash_algo", "SHA256")
                        .contentType(MediaType.MULTIPART_FORM_DATA))
                .andExpect(status().isOk())
                .andReturn();

        byte[] out = result.getResponse().getContentAsByteArray();
        assertThat(out).isNotEmpty();
        assertThat(new String(out, 0, Math.min(out.length, 5))).startsWith("%PDF-");

        try (PDDocument doc = Loader.loadPDF(out)) {
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

    private byte[] readRes(String path) throws IOException {
        try (InputStream is = getClass().getResourceAsStream(path)) {
            assertThat(is).as("resource %s exists", path).isNotNull();
            return is.readAllBytes();
        }
    }
}
