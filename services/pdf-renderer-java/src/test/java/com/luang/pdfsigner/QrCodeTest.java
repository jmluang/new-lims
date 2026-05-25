package com.luang.pdfsigner;

import com.luang.pdfsigner.service.QrCodeService;
import org.junit.jupiter.api.Test;
import org.springframework.boot.test.context.SpringBootTest;

import javax.imageio.ImageIO;
import java.awt.image.BufferedImage;
import java.io.File;
import java.io.IOException;

import static org.assertj.core.api.Assertions.assertThat;

@SpringBootTest
public class QrCodeTest {

    @Test
    public void testQrCodeGeneration() throws IOException {
        QrCodeService qrCodeService = new QrCodeService();

        String testUrl = "https://example.com/certificate-query?query=XDP2025100073";
        int sizeMm = 30;

        BufferedImage qrImage = qrCodeService.generateQrCode(testUrl, sizeMm);

        // Verify QR code image is generated
        assertThat(qrImage).isNotNull();
        assertThat(qrImage.getWidth()).isGreaterThan(0);
        assertThat(qrImage.getHeight()).isGreaterThan(0);

        // For manual verification: save the QR code image
        File outputFile = new File("test-qr-code.png");
        ImageIO.write(qrImage, "PNG", outputFile);
        System.out.println("QR code saved to: " + outputFile.getAbsolutePath());

        // Verify file was created
        assertThat(outputFile.exists()).isTrue();
        assertThat(outputFile.length()).isGreaterThan(0);

        // Clean up
        outputFile.delete();
    }

    @Test
    public void testQrCodeGenerationBytes() {
        QrCodeService qrCodeService = new QrCodeService();

        String testUrl = "https://example.com/certificate-query?query=XDP2025100073";
        int sizeMm = 20;

        byte[] qrBytes = qrCodeService.generateQrCodeBytes(testUrl, sizeMm);

        // Verify QR code bytes are generated
        assertThat(qrBytes).isNotNull();
        assertThat(qrBytes.length).isGreaterThan(0);

        System.out.println("QR code bytes generated: " + qrBytes.length + " bytes");
    }

    @Test
    public void testQrCodeGenerationWithDifferentSizes() throws IOException {
        QrCodeService qrCodeService = new QrCodeService();

        String testUrl = "https://example.com/certificate-query?query=XDP2025100073";
        int[] sizes = {10, 20, 30, 50};

        for (int size : sizes) {
            BufferedImage qrImage = qrCodeService.generateQrCode(testUrl, size);

            assertThat(qrImage).isNotNull();
            assertThat(qrImage.getWidth()).isEqualTo(qrImage.getHeight());

            // Expected size in pixels (approximately): size in mm * 11.811
            int expectedPixels = (int) Math.round(size * 11.811);
            assertThat(qrImage.getWidth()).isBetween(expectedPixels - 10, expectedPixels + 10);

            System.out.println("QR code generated for size " + size + "mm: " + qrImage.getWidth() + "x" + qrImage.getHeight() + " pixels");
        }
    }
}