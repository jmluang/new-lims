package com.luang.pdfsigner;

import com.fasterxml.jackson.databind.ObjectMapper;
import com.luang.pdfsigner.service.*;
import com.luang.pdfsigner.web.*;
import jakarta.servlet.ServletOutputStream;
import jakarta.servlet.WriteListener;
import java.io.IOException;
import java.nio.file.*;
import java.util.Base64;
import org.apache.pdfbox.Loader;
import org.junit.jupiter.api.Test;
import org.junit.jupiter.api.io.TempDir;
import org.springframework.mock.web.*;
import org.springframework.test.web.servlet.setup.MockMvcBuilders;
import static org.assertj.core.api.Assertions.*;
import static org.mockito.ArgumentMatchers.*;
import static org.mockito.Mockito.*;
import static org.springframework.test.web.servlet.request.MockMvcRequestBuilders.*;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.*;

class PdfHttpResourceTest {
    @TempDir Path temp;

    @Test
    void preservesAllFourHttpContracts() throws Exception {
        var mvc = MockMvcBuilders.standaloneSetup(controller(new SignerService())).addFilters(new PdfConcurrencyFilter(new PdfWorkLimiter(1))).build();
        Path input = temp.resolve("input.pdf");
        PdfMemoryRegressionTest.createPdf(input, 3, 0);
        var upload = new MockMultipartFile("pdf", "input.pdf", "application/pdf", Files.readAllBytes(input));
        var processed = mvc.perform(multipart("/api/pdf/process").file(upload).param("mode", "custom"))
                .andExpect(status().isOk()).andExpect(content().contentTypeCompatibleWith("application/json"))
                .andExpect(jsonPath("$.success").value(true))
                .andExpect(jsonPath("$.cover_fields.report_number").value("MEMORY-001"))
                .andReturn().getResponse().getContentAsByteArray();
        byte[] pdf = Base64.getDecoder().decode(new ObjectMapper().readTree(processed).get("pdf_base64").asText());
        try (var document = Loader.loadPDF(pdf)) {
            assertThat(document.getNumberOfPages()).isEqualTo(3);
            assertThat(document.getPage(0).getResources().getXObjectNames()).isEmpty();
        }
        mvc.perform(multipart("/api/pdf/extract-cover").file(upload))
                .andExpect(status().isOk()).andExpect(jsonPath("$.data.report_number").value("MEMORY-001"));
        mvc.perform(multipart("/api/pdf/extract-cover")
                .file(new MockMultipartFile("pdf", "bad.pdf", "application/pdf", new byte[]{1, 2})))
                .andExpect(status().isInternalServerError()).andExpect(jsonPath("$.success").value(false));
        mvc.perform(post("/api/pdf/contract").contentType("application/json").content("{}"))
                .andExpect(status().isOk()).andExpect(content().contentType("application/pdf"))
                .andExpect(header().string("Content-Disposition", "attachment; filename=contract.pdf"));
        try (var json = getClass().getResourceAsStream("/entrust-memory-payload.json")) {
            mvc.perform(post("/api/pdf/entrust-order").contentType("application/json").content(json.readAllBytes()))
                    .andExpect(status().isOk()).andExpect(content().contentType("application/pdf"))
                    .andExpect(header().string("Content-Disposition", "attachment; filename=entrust-order.pdf"));
        }
    }

    @Test
    void deletesOutputWhenResponseWritingFails() throws Exception {
        PdfFiles files = new PdfFiles();
        var file = files.create();
        Files.writeString(file.toPath(), "%PDF-test");
        var signer = mock(SignerService.class);
        when(signer.processToFile(any(), any(), any(), any(), any(), any(), any(), any(), any(), any(), anyBoolean(), any(), any(), any(), any(), any()))
                .thenReturn(new SignerService.FileProcessResult(file, null, files));
        var controller = controller(signer);
        var response = new MockHttpServletResponse() {
            @Override public ServletOutputStream getOutputStream() {
                return new ServletOutputStream() {
                    @Override public boolean isReady() { return true; }
                    @Override public void setWriteListener(WriteListener listener) { }
                    @Override public void write(int value) throws IOException { throw new IOException("Disconnected"); }
                };
            }
        };
        var upload = new MockMultipartFile("pdf", new byte[]{1});
        assertThatThrownBy(() -> controller.process(upload, null, null, null, "custom", null,
                null, null, null, null, null, null, new MockHttpServletRequest(), response))
                .isInstanceOf(IOException.class);
        assertThat(file.toPath().getParent()).doesNotExist();
    }
    @Test
    void controllerReadsPdfOnceWithoutMaterializingTheUpload() throws Exception {
        Path input = temp.resolve("streamed.pdf");
        PdfMemoryRegressionTest.createPdf(input, 1, 0);
        byte[] bytes = Files.readAllBytes(input);
        int[] calls = new int[2];
        var upload = new MockMultipartFile("pdf", "input.pdf", "application/pdf", bytes) {
            @Override public byte[] getBytes() { throw new AssertionError("PDF upload must stay on disk"); }
            @Override public java.io.InputStream getInputStream() {
                calls[0]++;
                return new java.io.ByteArrayInputStream(bytes) {
                    @Override public void close() throws IOException { calls[1]++; super.close(); }
                };
            }
        };
        controller(new SignerService()).process(upload, null, null, null, "custom", null, null,
                null, null, null, null, null, new MockHttpServletRequest(), new MockHttpServletResponse());
        assertThat(calls).containsExactly(1, 1);
    }

    @Test
    void cleansRenderingFilesWhenClientDisconnects() throws Exception {
        var before = operationDirectories();
        var response = new MockHttpServletResponse() {
            @Override public ServletOutputStream getOutputStream() {
                return new ServletOutputStream() {
                    @Override public boolean isReady() { return true; }
                    @Override public void setWriteListener(WriteListener listener) {}
                    @Override public void write(int value) throws IOException { throw new IOException("Disconnected"); }
                };
            }
        };
        assertThatThrownBy(() -> controller(new SignerService()).renderContract(ContractPdfPayload.sample(), response))
                .isInstanceOf(IOException.class);
        assertThat(operationDirectories()).isEqualTo(before);
        try (var json = getClass().getResourceAsStream("/entrust-memory-payload.json")) {
            var payload = new ObjectMapper().readValue(json, com.luang.pdfsigner.dto.EntrustOrderPayload.class);
            assertThatThrownBy(() -> controller(new SignerService()).renderEntrustOrder(payload, response))
                    .isInstanceOf(IOException.class);
        }
        assertThat(operationDirectories()).isEqualTo(before);
    }

    @Test
    void cleansTemporaryFilesAfterUploadAndPdfFailures() throws Exception {
        var before = operationDirectories();
        var brokenUpload = new MockMultipartFile("pdf", new byte[]{1}) {
            @Override public java.io.InputStream getInputStream() {
                return new java.io.InputStream() {
                    @Override public int read() throws IOException { throw new IOException("Upload interrupted"); }
                };
            }
        };
        for (var upload : java.util.List.of(brokenUpload, new MockMultipartFile("pdf", new byte[]{1, 2}))) {
            assertThatThrownBy(() -> new SignerService().processToFile(upload, null, null, java.util.List.of(),
                    "custom", null, null, null, null, "SHA256", false, null, null, null))
                    .isInstanceOf(IOException.class);
            assertThat(operationDirectories()).isEqualTo(before);
        }
    }

    private static java.util.Set<Path> operationDirectories() throws IOException {
        try (var paths = Files.list(Path.of(System.getProperty("java.io.tmpdir")))) {
            return paths.filter(path -> path.getFileName().toString().startsWith("pdf-operation-"))
                    .collect(java.util.stream.Collectors.toSet());
        }
    }

    private PdfController controller(SignerService signer) {
        return new PdfController(signer, new EntrustOrderRenderer(), new ContractPdfRenderer(),
                new com.luang.pdfsigner.security.PdfHmacProperties(false, "primary", "", 60),
                new com.luang.pdfsigner.security.SigningPolicy("SHA256", false),
                new com.luang.pdfsigner.execution.ExecutionStorage(
                        new com.luang.pdfsigner.execution.ExecutionLedgerProperties(false, "", "", "", temp.toString())),
                mock(com.luang.pdfsigner.execution.SigningExecutionRepository.class));
    }

}
