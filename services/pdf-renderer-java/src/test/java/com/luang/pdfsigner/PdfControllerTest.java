package com.luang.pdfsigner;

import org.junit.jupiter.api.Test;
import org.apache.pdfbox.cos.COSDictionary;
import org.apache.pdfbox.cos.COSName;
import org.apache.pdfbox.pdmodel.PDDocument;
import org.apache.pdfbox.pdmodel.PDPage;
import org.springframework.beans.factory.annotation.Autowired;
import org.springframework.boot.test.autoconfigure.web.servlet.AutoConfigureMockMvc;
import org.springframework.boot.test.context.SpringBootTest;
import org.springframework.http.MediaType;
import org.springframework.mock.web.MockMultipartFile;
import org.springframework.test.web.servlet.MockMvc;

import java.io.InputStream;
import java.util.Base64;
import org.json.JSONObject;

import static org.assertj.core.api.Assertions.assertThat;
import static org.springframework.test.web.servlet.request.MockMvcRequestBuilders.multipart;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.status;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.jsonPath;

@SpringBootTest
@AutoConfigureMockMvc
class PdfControllerTest {

    @Autowired
    private MockMvc mockMvc;

    @Test
    void processRejectsCallerControlledSigningPolicy() throws Exception {
        try (InputStream pdf = getClass().getResourceAsStream("/samples/sample.pdf")) {
            assertThat(pdf).isNotNull();
            MockMultipartFile pdfPart = new MockMultipartFile("pdf", "sample.pdf", "application/pdf", pdf);

            mockMvc.perform(multipart("/api/pdf/process")
                            .file(pdfPart)
                            .param("mode", "sign")
                            .param("hash_algo", "SHA512")
                            .contentType(MediaType.MULTIPART_FORM_DATA))
                    .andExpect(status().isUnprocessableEntity());
        }
    }

    @Test
    void processRejectsPdfThatAlreadyCarriesDocMdpSignatureState() throws Exception {
        MockMultipartFile pdfPart = new MockMultipartFile(
                "pdf",
                "already-signed.pdf",
                "application/pdf",
                pdfWithDocMdpMarker()
        );

        mockMvc.perform(multipart("/api/pdf/process")
                        .file(pdfPart)
                        .param("mode", "stamp")
                        .contentType(MediaType.MULTIPART_FORM_DATA))
                .andExpect(status().isUnprocessableEntity())
                .andExpect(jsonPath("$.error").value("PDF_LEGACY_PROCESS_SIGNED_INPUT_FORBIDDEN"));
    }

    @Test
    void stampAndSign_shouldReturnPdf() throws Exception {
        try (InputStream pdf = getClass().getResourceAsStream("/samples/sample.pdf")) {
            assertThat(pdf).isNotNull();

            // 生成内存中的简单 PNG 作为骑缝章
            java.awt.image.BufferedImage img = new java.awt.image.BufferedImage(5, 5, java.awt.image.BufferedImage.TYPE_INT_ARGB);
            java.awt.Graphics2D g = img.createGraphics();
            g.setColor(new java.awt.Color(255, 0, 0, 128));
            g.fillRect(0, 0, 5, 5);
            g.dispose();
            java.io.ByteArrayOutputStream baos = new java.io.ByteArrayOutputStream();
            javax.imageio.ImageIO.write(img, "png", baos);
            byte[] stampBytes = baos.toByteArray();

            MockMultipartFile pdfPart = new MockMultipartFile("pdf", "sample.pdf", "application/pdf", pdf);
            MockMultipartFile perfPart = new MockMultipartFile("perforation_image", "stamp.png", "image/png", stampBytes);

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
            String header = new String(pdfBytes, 0, Math.min(pdfBytes.length, 5));
            assertThat(header).startsWith("%PDF-");
        }
    }

    @Test
    void customModeWithFunctionStamps_shouldReturnPdf() throws Exception {
        try (InputStream pdf = getClass().getResourceAsStream("/samples/sample.pdf")) {
            assertThat(pdf).isNotNull();

            // 生成内存中的简单 PNG 作为骑缝章
            java.awt.image.BufferedImage perfImg = new java.awt.image.BufferedImage(5, 5, java.awt.image.BufferedImage.TYPE_INT_ARGB);
            java.awt.Graphics2D g1 = perfImg.createGraphics();
            g1.setColor(new java.awt.Color(255, 0, 0, 128));
            g1.fillRect(0, 0, 5, 5);
            g1.dispose();
            java.io.ByteArrayOutputStream perfBaos = new java.io.ByteArrayOutputStream();
            javax.imageio.ImageIO.write(perfImg, "png", perfBaos);
            byte[] perfBytes = perfBaos.toByteArray();

            // 生成签名外观图片
            java.awt.image.BufferedImage sigImg = new java.awt.image.BufferedImage(10, 10, java.awt.image.BufferedImage.TYPE_INT_ARGB);
            java.awt.Graphics2D g2 = sigImg.createGraphics();
            g2.setColor(new java.awt.Color(0, 0, 255, 128));
            g2.fillRect(0, 0, 10, 10);
            g2.dispose();
            java.io.ByteArrayOutputStream sigBaos = new java.io.ByteArrayOutputStream();
            javax.imageio.ImageIO.write(sigImg, "png", sigBaos);
            byte[] sigBytes = sigBaos.toByteArray();

            // 生成功能章图片
            java.awt.image.BufferedImage funcImg1 = new java.awt.image.BufferedImage(8, 8, java.awt.image.BufferedImage.TYPE_INT_ARGB);
            java.awt.Graphics2D g3 = funcImg1.createGraphics();
            g3.setColor(new java.awt.Color(0, 255, 0, 128));
            g3.fillRect(0, 0, 8, 8);
            g3.dispose();
            java.io.ByteArrayOutputStream func1Baos = new java.io.ByteArrayOutputStream();
            javax.imageio.ImageIO.write(funcImg1, "png", func1Baos);
            byte[] func1Bytes = func1Baos.toByteArray();

            java.awt.image.BufferedImage funcImg2 = new java.awt.image.BufferedImage(8, 8, java.awt.image.BufferedImage.TYPE_INT_ARGB);
            java.awt.Graphics2D g4 = funcImg2.createGraphics();
            g4.setColor(new java.awt.Color(255, 255, 0, 128));
            g4.fillRect(0, 0, 8, 8);
            g4.dispose();
            java.io.ByteArrayOutputStream func2Baos = new java.io.ByteArrayOutputStream();
            javax.imageio.ImageIO.write(funcImg2, "png", func2Baos);
            byte[] func2Bytes = func2Baos.toByteArray();

            MockMultipartFile pdfPart = new MockMultipartFile("pdf", "sample.pdf", "application/pdf", pdf);
            MockMultipartFile perfPart = new MockMultipartFile("perforation_image", "perf.png", "image/png", perfBytes);
            MockMultipartFile sigPart = new MockMultipartFile("signature_appearance_image", "sig.png", "image/png", sigBytes);
            MockMultipartFile func1Part = new MockMultipartFile("function_stamp_0", "func1.png", "image/png", func1Bytes);
            MockMultipartFile func2Part = new MockMultipartFile("function_stamp_1", "func2.png", "image/png", func2Bytes);

            var result = mockMvc.perform(multipart("/api/pdf/process")
                            .file(pdfPart)
                            .file(perfPart)
                            .file(sigPart)
                            .file(func1Part)
                            .file(func2Part)
                            .param("mode", "custom")
                            .param("function_stamp_count", "2")
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
            String header = new String(pdfBytes, 0, Math.min(pdfBytes.length, 5));
            assertThat(header).startsWith("%PDF-");
        }
    }

    @Test
    void processWithQrCode_shouldReturnPdfWithQrCode() throws Exception {
        try (InputStream pdf = getClass().getResourceAsStream("/samples/sample.pdf")) {
            assertThat(pdf).isNotNull();

            // 生成简单的二维码图片（模拟）
            java.awt.image.BufferedImage qrImg = new java.awt.image.BufferedImage(200, 200, java.awt.image.BufferedImage.TYPE_INT_ARGB);
            java.awt.Graphics2D g = qrImg.createGraphics();
            g.setColor(java.awt.Color.WHITE);
            g.fillRect(0, 0, 200, 200);
            g.setColor(java.awt.Color.BLACK);
            // 简单的二维码模拟图案
            for (int i = 0; i < 200; i += 20) {
                for (int j = 0; j < 200; j += 20) {
                    if ((i + j) % 40 == 0) {
                        g.fillRect(i, j, 20, 20);
                    }
                }
            }
            g.dispose();
            java.io.ByteArrayOutputStream qrBaos = new java.io.ByteArrayOutputStream();
            javax.imageio.ImageIO.write(qrImg, "png", qrBaos);
            byte[] qrBytes = qrBaos.toByteArray();

            MockMultipartFile pdfPart = new MockMultipartFile("pdf", "sample.pdf", "application/pdf", pdf);
            MockMultipartFile qrPart = new MockMultipartFile("certificate_query_qr_code", "qrcode.png", "image/png", qrBytes);

            var result = mockMvc.perform(multipart("/api/pdf/process")
                            .file(pdfPart)
                            .file(qrPart)
                            .param("mode", "sign")
                            .param("certificate_query_qr_code_url", "http://example.com/certificate-query?query=TEST123")
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
            String header = new String(pdfBytes, 0, Math.min(pdfBytes.length, 5));
            assertThat(header).startsWith("%PDF-");

            // 验证封面信息字段存在
            assertThat(jsonResponse.has("cover_fields")).isTrue();
            JSONObject coverFields = jsonResponse.getJSONObject("cover_fields");
            assertThat(coverFields).isNotNull();
        }
    }

    private static byte[] pdfWithDocMdpMarker() throws Exception {
        try (PDDocument document = new PDDocument()) {
            document.addPage(new PDPage());
            COSDictionary permissions = new COSDictionary();
            permissions.setItem(COSName.getPDFName("DocMDP"), new COSDictionary());
            document.getDocumentCatalog().getCOSObject().setItem(COSName.getPDFName("Perms"), permissions);
            java.io.ByteArrayOutputStream output = new java.io.ByteArrayOutputStream();
            document.save(output);
            return output.toByteArray();
        }
    }
}
