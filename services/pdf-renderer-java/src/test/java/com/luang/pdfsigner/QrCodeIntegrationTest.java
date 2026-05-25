package com.luang.pdfsigner;

import com.luang.pdfsigner.service.SignerService;
import org.junit.jupiter.api.Test;
import org.springframework.beans.factory.annotation.Autowired;
import org.springframework.boot.test.context.SpringBootTest;
import org.springframework.mock.web.MockMultipartFile;
import org.springframework.web.multipart.MultipartFile;

import java.io.IOException;
import java.nio.file.Files;
import java.nio.file.Path;
import java.nio.file.Paths;
import java.util.Collections;

import static org.assertj.core.api.Assertions.assertThat;

@SpringBootTest
public class QrCodeIntegrationTest {

    @Autowired
    private SignerService signerService;

    @Test
    public void testPdfProcessingWithQrCode() throws Exception {
        // Load a test PDF file
        Path testPdfPath = Paths.get("src/test/resources/sample.pdf");
        if (!Files.exists(testPdfPath)) {
            System.out.println("Test PDF file not found at " + testPdfPath + ", skipping integration test");
            return;
        }

        byte[] pdfBytes = Files.readAllBytes(testPdfPath);
        MultipartFile pdfFile = new MockMultipartFile(
                "pdf",
                "sample.pdf",
                "application/pdf",
                pdfBytes
        );

        // Test parameters
        String mode = "custom";
        String qrCodeBaseUrl = "https://example.com/certificate-query?query=";
        int qrCodeSizeMm = 30;
        int qrCodePositionXMm = 20;  // Updated to true right position
        int qrCodePositionYMm = 270; // Updated to true top position

        System.out.println("Testing PDF processing with QR code enabled...");

        // Process PDF with QR code enabled
        SignerService.ProcessResult result = signerService.process(
                pdfFile,
                null, // no perforation
                null, // no signature image
                Collections.emptyList(), // no function stamps
                mode,
                null, null, null, null, // signing parameters
                "SHA256",
                false, null, // TSA
                null, // no custom QR image
                qrCodeBaseUrl
        );

        // Verify result
        assertThat(result).isNotNull();
        assertThat(result.getPdfBytes()).isNotNull();
        assertThat(result.getPdfBytes().length).isGreaterThan(0);

        System.out.println("PDF processed successfully with QR code");
        System.out.println("Input PDF size: " + pdfBytes.length + " bytes");
        System.out.println("Output PDF size: " + result.getPdfBytes().length + " bytes");
        System.out.println("Size increase: " + (result.getPdfBytes().length - pdfBytes.length) + " bytes");

        // Save result for manual inspection
        Path outputPath = Paths.get("target/test-output-with-qr.pdf");
        Files.createDirectories(outputPath.getParent());
        Files.write(outputPath, result.getPdfBytes());
        System.out.println("Result saved to: " + outputPath.toAbsolutePath());
    }

    @Test
    public void testPdfProcessingWithoutQrCode() throws Exception {
        // Load a test PDF file
        Path testPdfPath = Paths.get("src/test/resources/sample.pdf");
        if (!Files.exists(testPdfPath)) {
            System.out.println("Test PDF file not found at " + testPdfPath + ", skipping integration test");
            return;
        }

        byte[] pdfBytes = Files.readAllBytes(testPdfPath);
        MultipartFile pdfFile = new MockMultipartFile(
                "pdf",
                "sample.pdf",
                "application/pdf",
                pdfBytes
        );

        System.out.println("Testing PDF processing with QR code disabled...");

        // Process PDF with QR code disabled (default behavior)
        SignerService.ProcessResult result = signerService.process(
                pdfFile,
                null, // no perforation
                null, // no signature image
                Collections.emptyList(), // no function stamps
                "custom", // mode
                null, null, null, null, // signing parameters
                "SHA256",
                false, null, // TSA
                null, // no custom QR image
                null // no QR base url override
        );

        // Verify result
        assertThat(result).isNotNull();
        assertThat(result.getPdfBytes()).isNotNull();
        assertThat(result.getPdfBytes().length).isGreaterThan(0);

        System.out.println("PDF processed successfully without QR code");
        System.out.println("Input PDF size: " + pdfBytes.length + " bytes");
        System.out.println("Output PDF size: " + result.getPdfBytes().length + " bytes");

        // Save result for comparison
        Path outputPath = Paths.get("target/test-output-without-qr.pdf");
        Files.createDirectories(outputPath.getParent());
        Files.write(outputPath, result.getPdfBytes());
        System.out.println("Result saved to: " + outputPath.toAbsolutePath());
    }
}
