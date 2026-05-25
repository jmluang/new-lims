package com.luang.pdfsigner.service;

import com.google.zxing.BarcodeFormat;
import com.google.zxing.EncodeHintType;
import com.google.zxing.client.j2se.MatrixToImageWriter;
import com.google.zxing.common.BitMatrix;
import com.google.zxing.qrcode.QRCodeWriter;
import com.google.zxing.qrcode.decoder.ErrorCorrectionLevel;
import org.slf4j.Logger;
import org.slf4j.LoggerFactory;
import org.springframework.stereotype.Service;

import java.awt.image.BufferedImage;
import java.util.HashMap;
import java.util.Map;

/**
 * QR Code generation service for PDF documents.
 */
@Service
public class QrCodeService {

    private static final Logger log = LoggerFactory.getLogger(QrCodeService.class);

    /**
     * Generate QR code image for the given URL.
     *
     * @param url the URL to encode in the QR code
     * @param sizeMm the size in millimeters
     * @return BufferedImage containing the QR code
     */
    public BufferedImage generateQrCode(String url, int sizeMm) {
        if (url == null || url.trim().isEmpty()) {
            throw new IllegalArgumentException("URL cannot be null or empty");
        }

        try {
            // Convert mm to pixels (assuming 300 DPI: 1 mm = 11.811 pixels)
            int sizePixels = (int) Math.round(sizeMm * 11.811);
            log.debug("Generating QR code: size={}mm ({}px), url={}", sizeMm, sizePixels, url);

            QRCodeWriter qrCodeWriter = new QRCodeWriter();

            // Configure QR code hints
            Map<EncodeHintType, Object> hints = new HashMap<>();
            hints.put(EncodeHintType.ERROR_CORRECTION, ErrorCorrectionLevel.M);
            hints.put(EncodeHintType.CHARACTER_SET, "UTF-8");
            hints.put(EncodeHintType.MARGIN, 1); // Small margin for better integration

            // Generate QR code matrix
            BitMatrix bitMatrix = qrCodeWriter.encode(url, BarcodeFormat.QR_CODE, sizePixels, sizePixels, hints);

            // Convert to BufferedImage
            BufferedImage qrImage = MatrixToImageWriter.toBufferedImage(bitMatrix);

            log.debug("QR code generated successfully: {}x{} pixels", qrImage.getWidth(), qrImage.getHeight());
            return qrImage;

        } catch (Exception e) {
            log.error("Failed to generate QR code for URL: {}", url, e);
            throw new RuntimeException("Failed to generate QR code", e);
        }
    }

    /**
     * Generate QR code image bytes for the given URL.
     *
     * @param url the URL to encode in the QR code
     * @param sizeMm the size in millimeters
     * @return byte array containing the QR code image in PNG format
     */
    public byte[] generateQrCodeBytes(String url, int sizeMm) {
        try {
            BufferedImage qrImage = generateQrCode(url, sizeMm);

            // Convert BufferedImage to PNG byte array
            java.io.ByteArrayOutputStream baos = new java.io.ByteArrayOutputStream();
            javax.imageio.ImageIO.write(qrImage, "PNG", baos);

            byte[] result = baos.toByteArray();
            log.debug("QR code PNG generated: {} bytes", result.length);
            return result;

        } catch (Exception e) {
            log.error("Failed to generate QR code bytes for URL: {}", url, e);
            throw new RuntimeException("Failed to generate QR code bytes", e);
        }
    }
}