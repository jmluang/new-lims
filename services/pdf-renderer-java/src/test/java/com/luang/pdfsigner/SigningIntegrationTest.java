package com.luang.pdfsigner;

import org.junit.jupiter.api.Assumptions;
import org.junit.jupiter.api.Test;
import org.springframework.beans.factory.annotation.Autowired;
import org.springframework.boot.test.autoconfigure.web.servlet.AutoConfigureMockMvc;
import org.springframework.boot.test.context.SpringBootTest;
import org.springframework.http.MediaType;
import org.springframework.mock.web.MockMultipartFile;
import org.springframework.test.web.servlet.MockMvc;

import java.io.InputStream;

import static org.assertj.core.api.Assertions.assertThat;
import static org.springframework.test.web.servlet.request.MockMvcRequestBuilders.multipart;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.status;

@SpringBootTest
@AutoConfigureMockMvc
class SigningIntegrationTest {

    @Autowired
    private MockMvc mockMvc;

    @Test
    void sign_mode_shouldNotThrow_whenPemConfigured() throws Exception {
        String crt = System.getenv("DEFAULT_PEM_CRT_PATH");
        String key = System.getenv("DEFAULT_PEM_KEY_PATH");
        Assumptions.assumeTrue(crt != null && key != null, "PEM not configured, skipping sign test");

        try (InputStream pdf = getClass().getResourceAsStream("/samples/sample.pdf")) {
            assertThat(pdf).isNotNull();

            MockMultipartFile pdfPart = new MockMultipartFile("pdf", "sample.pdf", "application/pdf", pdf);

            var result = mockMvc.perform(multipart("/api/pdf/process")
                            .file(pdfPart)
                            .param("mode", "sign")
                            .param("hash_algo", "SHA256")
                            .contentType(MediaType.MULTIPART_FORM_DATA))
                    .andExpect(status().isOk())
                    .andReturn();

            byte[] body = result.getResponse().getContentAsByteArray();
            assertThat(body).isNotEmpty();
            String header = new String(body, 0, Math.min(body.length, 5));
            assertThat(header).startsWith("%PDF-");
        }
    }
}
