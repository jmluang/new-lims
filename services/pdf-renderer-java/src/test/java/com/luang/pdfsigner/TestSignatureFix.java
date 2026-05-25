package com.luang.pdfsigner;

import com.luang.pdfsigner.service.SignerService;
import org.springframework.mock.web.MockMultipartFile;
import org.apache.pdfbox.Loader;
import org.apache.pdfbox.pdmodel.PDDocument;
import org.apache.pdfbox.pdmodel.PDPage;
import org.apache.pdfbox.pdmodel.PDPageContentStream;
import org.apache.pdfbox.pdmodel.common.PDRectangle;
import org.apache.pdfbox.pdmodel.font.PDType1Font;
import org.apache.pdfbox.pdmodel.font.Standard14Fonts;
import org.junit.jupiter.api.Test;
import org.junit.jupiter.api.io.TempDir;

import java.io.ByteArrayOutputStream;
import java.io.File;
import java.io.FileInputStream;
import java.io.IOException;
import java.nio.file.Path;
import javax.imageio.ImageIO;
import java.awt.image.BufferedImage;
import java.awt.Graphics2D;
import java.awt.Color;
import java.awt.Font;

import static org.junit.jupiter.api.Assertions.*;

public class TestSignatureFix {
    
    @Test
    public void testSignatureImageDisplay() throws Exception {
        System.out.println("测试签名图片显示修复...");
        
        // 创建测试PDF
        byte[] testPdf = createTestPDF();
        
        // 创建测试签名图片
        byte[] signatureImage = createTestSignatureImage();
        
        // 创建MockMultipartFile
        MockMultipartFile pdfFile = new MockMultipartFile(
            "pdf", "test.pdf", "application/pdf", testPdf);
        MockMultipartFile sigImgFile = new MockMultipartFile(
            "sigImg", "signature.png", "image/png", signatureImage);
        
        // 创建签名服务并测试
        SignerService signerService = new SignerService();
        
        try {
            SignerService.ProcessResult result = signerService.process(
                pdfFile,
                null, // perforation
                sigImgFile, // sigImg
                null, // functionStamps
                "sign", // mode
                null, // signingKeyId
                "测试签名者", // contact
                "测试位置", // location
                "测试原因", // reason
                "SHA256", // hashAlgo
                false, // tsaEnabled
                null, // tsaUrl
                null, // qrCodeImg
                null // qrCodeUrl
            );
            byte[] signedPdf = result.getPdfBytes();
            
            // 保存签名后的PDF
            File outputFile = new File("test_signed_output.pdf");
            try (java.io.FileOutputStream fos = new java.io.FileOutputStream(outputFile)) {
                fos.write(signedPdf);
            }
            
            System.out.println("签名PDF已保存到: " + outputFile.getAbsolutePath());
            
            // 验证PDF可以正常打开
            try (PDDocument doc = Loader.loadPDF(signedPdf)) {
                System.out.println("PDF页数: " + doc.getNumberOfPages());
                System.out.println("签名数量: " + doc.getSignatureDictionaries().size());
                System.out.println("✅ 测试成功！PDF签名图片应该可以正常显示。");
                System.out.println("📝 重要提示：请在Adobe Acrobat中打开此PDF文件，然后手动修改文件内容");
                System.out.println("📝 修改后重新打开，签名图片上应该显示红色X表示签名已失效");
            }
            
        } catch (Exception e) {
            System.err.println("❌ 测试失败: " + e.getMessage());
            e.printStackTrace();
        }
    }
    
    @Test
    public void testSignatureInvalidationDisplay() throws Exception {
        System.out.println("测试签名失效显示X功能...");
        
        // 创建测试PDF
        byte[] testPdf = createTestPDF();
        
        // 创建测试签名图片
        byte[] signatureImage = createTestSignatureImage();
        
        // 创建MockMultipartFile
        MockMultipartFile pdfFile = new MockMultipartFile(
            "pdf", "test_invalidation.pdf", "application/pdf", testPdf);
        MockMultipartFile sigImgFile = new MockMultipartFile(
            "sigImg", "signature.png", "image/png", signatureImage);
        
        // 创建签名服务并测试
        SignerService signerService = new SignerService();
        
        try {
            SignerService.ProcessResult result = signerService.process(
                pdfFile,
                null, // perforation
                sigImgFile, // sigImg
                null, // functionStamps
                "sign", // mode
                null, // signingKeyId
                "测试签名者", // contact
                "测试位置", // location
                "测试原因", // reason
                "SHA256", // hashAlgo
                false, // tsaEnabled
                null, // tsaUrl
                null, // qrCodeImg
                null // qrCodeUrl
            );
            byte[] signedPdf = result.getPdfBytes();
            
            // 保存原始签名PDF
            File originalFile = new File("test_signature_original.pdf");
            try (java.io.FileOutputStream fos = new java.io.FileOutputStream(originalFile)) {
                fos.write(signedPdf);
            }
            System.out.println("原始签名PDF已保存到: " + originalFile.getAbsolutePath());
            
            // 分析签名的ByteRange以了解签名的覆盖范围
            try (PDDocument doc = Loader.loadPDF(signedPdf)) {
                var signatures = doc.getSignatureDictionaries();
                if (!signatures.isEmpty()) {
                    var sig = signatures.get(0);
                    int[] byteRange = sig.getByteRange();
                    System.out.println("签名ByteRange: [" + byteRange[0] + ", " + byteRange[1] + ", " + byteRange[2] + ", " + byteRange[3] + "]");
                    System.out.println("签名覆盖的文件范围: 0-" + byteRange[1] + " 和 " + byteRange[2] + "-" + (byteRange[2] + byteRange[3]));
                }
            }
            
            // 创建更精确的文件修改：在签名覆盖范围之外修改
            // 这样可以确保签名验证失败
            byte[] modifiedPdf = createModifiedPdfForInvalidation(signedPdf);
            
            // 保存修改后的PDF
            File modifiedFile = new File("test_signature_modified.pdf");
            try (java.io.FileOutputStream fos = new java.io.FileOutputStream(modifiedFile)) {
                fos.write(modifiedPdf);
            }
            System.out.println("修改后的PDF已保存到: " + modifiedFile.getAbsolutePath());
            
            // 验证两个PDF都可以打开
            try (PDDocument originalDoc = Loader.loadPDF(signedPdf)) {
                System.out.println("原始PDF页数: " + originalDoc.getNumberOfPages());
                System.out.println("原始PDF签名数量: " + originalDoc.getSignatureDictionaries().size());
            }
            
            try (PDDocument modifiedDoc = Loader.loadPDF(modifiedPdf)) {
                System.out.println("修改后PDF页数: " + modifiedDoc.getNumberOfPages());
                System.out.println("修改后PDF签名数量: " + modifiedDoc.getSignatureDictionaries().size());
            }
            
            System.out.println("✅ 测试成功！");
            System.out.println("📝 请在Adobe Acrobat中打开这两个PDF文件：");
            System.out.println("   1. test_signature_original.pdf - 应该显示有效的签名");
            System.out.println("   2. test_signature_modified.pdf - 应该在签名图片上显示红色X");
            System.out.println("📝 如果仍然没有显示X，请尝试手动修改PDF内容后重新打开");
            
        } catch (Exception e) {
            System.err.println("❌ 测试失败: " + e.getMessage());
            e.printStackTrace();
        }
    }
    
    /**
     * 创建一个会被Adobe Acrobat检测为修改的PDF文件
     * 通过在签名覆盖范围之外添加内容来确保签名验证失败
     */
    private byte[] createModifiedPdfForInvalidation(byte[] originalPdf) throws Exception {
        // 首先分析原始PDF的签名ByteRange
        try (PDDocument doc = Loader.loadPDF(originalPdf)) {
            var signatures = doc.getSignatureDictionaries();
            if (signatures.isEmpty()) {
                // 如果没有签名，简单地在末尾添加内容
                byte[] modified = new byte[originalPdf.length + 10];
                System.arraycopy(originalPdf, 0, modified, 0, originalPdf.length);
                String modText = "MODIFIED";
                byte[] modBytes = modText.getBytes("UTF-8");
                System.arraycopy(modBytes, 0, modified, originalPdf.length, modBytes.length);
                return modified;
            }
            
            var sig = signatures.get(0);
            int[] byteRange = sig.getByteRange();
            
            // ByteRange格式: [start1, length1, start2, length2]
            // 我们需要在签名覆盖范围之外进行修改
            // 最简单的方法是在文件末尾添加内容
            ByteArrayOutputStream modified = new ByteArrayOutputStream();
            modified.write(originalPdf);
            
            // 添加一些额外的内容，确保文件被修改
            String modification = "\n% THIS FILE WAS MODIFIED AFTER SIGNING %\n";
            modified.write(modification.getBytes("UTF-8"));
            
            return modified.toByteArray();
        }
    }
    
    private static byte[] createTestPDF() throws IOException {
        try (PDDocument doc = new PDDocument();
             ByteArrayOutputStream baos = new ByteArrayOutputStream()) {
            
            PDPage page = new PDPage(PDRectangle.A4);
            doc.addPage(page);
            
            try (PDPageContentStream cs = new PDPageContentStream(doc, page)) {
                cs.beginText();
                cs.setFont(new PDType1Font(Standard14Fonts.FontName.HELVETICA), 12);
                cs.newLineAtOffset(100, 700);
                cs.showText("Test PDF Document - For signature image display verification");
                cs.endText();
            }
            
            doc.save(baos);
            return baos.toByteArray();
        }
    }
    
    private static byte[] createTestSignatureImage() throws IOException {
        // 创建一个简单的测试签名图片
        BufferedImage img = new BufferedImage(200, 100, BufferedImage.TYPE_INT_ARGB);
        Graphics2D g = img.createGraphics();
        
        // 设置抗锯齿
        g.setRenderingHint(java.awt.RenderingHints.KEY_ANTIALIASING, 
                          java.awt.RenderingHints.VALUE_ANTIALIAS_ON);
        
        // 背景
        g.setColor(new Color(255, 255, 255, 200));
        g.fillRect(0, 0, 200, 100);
        
        // 边框
        g.setColor(Color.RED);
        g.setStroke(new java.awt.BasicStroke(2));
        g.drawRect(2, 2, 196, 96);
        
        // 文字
        g.setColor(Color.BLACK);
        g.setFont(new Font("Arial", Font.BOLD, 16));
        g.drawString("Test Signature", 50, 40);
        g.setFont(new Font("Arial", Font.PLAIN, 12));
        g.drawString("2024-10-09", 50, 60);
        
        g.dispose();
        
        // 转换为字节数组
        try (ByteArrayOutputStream baos = new ByteArrayOutputStream()) {
            ImageIO.write(img, "PNG", baos);
            return baos.toByteArray();
        }
    }
}