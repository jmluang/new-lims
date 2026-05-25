package com.luang.pdfsigner;

import org.apache.pdfbox.Loader;
import org.apache.pdfbox.pdmodel.*;
import org.apache.pdfbox.pdmodel.common.PDRectangle;
import org.apache.pdfbox.pdmodel.font.PDType1Font;
import org.apache.pdfbox.pdmodel.font.Standard14Fonts;
import org.apache.pdfbox.pdmodel.graphics.image.PDImageXObject;
import org.apache.pdfbox.pdmodel.graphics.image.LosslessFactory;
import org.apache.pdfbox.pdmodel.interactive.annotation.PDAnnotationWidget;
import org.apache.pdfbox.pdmodel.interactive.annotation.PDAppearanceDictionary;
import org.apache.pdfbox.pdmodel.interactive.annotation.PDAppearanceStream;
import org.apache.pdfbox.pdmodel.interactive.digitalsignature.PDSignature;
import org.apache.pdfbox.pdmodel.interactive.digitalsignature.SignatureInterface;
import org.apache.pdfbox.pdmodel.interactive.digitalsignature.SignatureOptions;
import org.apache.pdfbox.pdmodel.interactive.form.PDAcroForm;
import org.apache.pdfbox.pdmodel.interactive.form.PDSignatureField;
import org.junit.jupiter.api.Test;
import org.junit.jupiter.api.io.TempDir;

import javax.imageio.ImageIO;
import java.awt.*;
import java.awt.image.BufferedImage;
import java.io.*;
import java.nio.file.Path;
import java.util.Calendar;

import static org.junit.jupiter.api.Assertions.*;

public class VisualSignatureTest {

    @TempDir
    Path tempDir;

    /**
     * 测试方案1：直接在页面绘制图片，然后添加不可见签名
     */
    @Test
    public void testDirectDrawWithInvisibleSignature() throws Exception {
        System.out.println("\n=== 测试方案1：直接绘制 + 不可见签名 ===");
        
        // 先创建并保存初始PDF
        File tempFile = tempDir.resolve("temp.pdf").toFile();
        PDDocument doc = createTestPDF();
        doc.save(tempFile);
        doc.close();
        
        // 重新加载PDF以支持增量保存
        doc = Loader.loadPDF(tempFile);
        PDPage firstPage = doc.getPage(0);
        
        // 直接在页面上绘制功能章
        try (PDPageContentStream cs = new PDPageContentStream(doc, firstPage, 
                PDPageContentStream.AppendMode.APPEND, true, true)) {
            BufferedImage stamp = createStampImage("Stamp 1");
            PDImageXObject pdImage = LosslessFactory.createFromImage(doc, stamp);
            cs.drawImage(pdImage, 20, 700, 80, 40);
            System.out.println("✓ 绘制功能章成功");
        }
        
        // 添加不可见签名
        PDSignature signature = new PDSignature();
        signature.setFilter(PDSignature.FILTER_ADOBE_PPKLITE);
        signature.setSubFilter(PDSignature.SUBFILTER_ADBE_PKCS7_DETACHED);
        signature.setName("Test Signer");
        signature.setReason("Test with stamps");
        signature.setSignDate(Calendar.getInstance());
        
        doc.addSignature(signature, new DummySignatureInterface());
        
        File output = tempDir.resolve("test1_direct_draw.pdf").toFile();
        doc.saveIncremental(new FileOutputStream(output));
        doc.close();
        
        System.out.println("✓ 文档保存成功: " + output.getAbsolutePath());
        
        // 验证
        PDDocument signed = Loader.loadPDF(output);
        assertNotNull(signed);
        System.out.println("✓ 签名后文档可以打开");
        System.out.println("  页数: " + signed.getNumberOfPages());
        System.out.println("  签名数: " + signed.getSignatureDictionaries().size());
        signed.close();
        
        System.out.println("✅ 方案1测试通过！但不支持签名失效显示叉");
    }

    /**
     * 测试方案2：使用签名字段和外观流
     */
    @Test
    public void testSignatureFieldWithAppearance() throws Exception {
        System.out.println("\n=== 测试方案2：签名字段 + 外观流 ===");
        
        // 先创建并保存初始PDF
        File tempFile = tempDir.resolve("temp2.pdf").toFile();
        PDDocument doc = createTestPDF();
        doc.save(tempFile);
        doc.close();
        
        // 重新加载PDF以支持增量保存
        doc = Loader.loadPDF(tempFile);
        PDPage firstPage = doc.getPage(0);
        
        // 创建AcroForm
        PDAcroForm acroForm = new PDAcroForm(doc);
        doc.getDocumentCatalog().setAcroForm(acroForm);
        
        // 创建签名字段
        PDSignatureField sigField = new PDSignatureField(acroForm);
        sigField.setPartialName("FunctionStamp1");
        
        // 设置widget
        PDAnnotationWidget widget = sigField.getWidgets().get(0);
        widget.setPage(firstPage);
        PDRectangle rect = new PDRectangle(20, 700, 80, 40);
        widget.setRectangle(rect);
        
        // 创建外观流
        PDAppearanceStream appearanceStream = new PDAppearanceStream(doc);
        appearanceStream.setBBox(new PDRectangle(80, 40));
        appearanceStream.setResources(new PDResources());
        
        try (PDPageContentStream cs = new PDPageContentStream(doc, appearanceStream)) {
            BufferedImage stamp = createStampImage("Stamp 1");
            PDImageXObject pdImage = LosslessFactory.createFromImage(doc, stamp);
            cs.drawImage(pdImage, 0, 0, 80, 40);
        }
        
        // 设置外观
        PDAppearanceDictionary appearance = new PDAppearanceDictionary();
        appearance.setNormalAppearance(appearanceStream);
        widget.setAppearance(appearance);
        
        // 添加到页面和表单
        firstPage.getAnnotations().add(widget);
        acroForm.getFields().add(sigField);
        
        // 创建签名
        PDSignature signature = new PDSignature();
        signature.setFilter(PDSignature.FILTER_ADOBE_PPKLITE);
        signature.setSubFilter(PDSignature.SUBFILTER_ADBE_PKCS7_DETACHED);
        signature.setName("Test Signer");
        signature.setReason("Test with stamp field");
        signature.setSignDate(Calendar.getInstance());
        
        // 设置签名到字段
        sigField.setValue(signature);
        
        // 添加签名（不使用setVisualSignature）
        doc.addSignature(signature, new DummySignatureInterface());
        
        File output = tempDir.resolve("test2_sig_field.pdf").toFile();
        doc.saveIncremental(new FileOutputStream(output));
        doc.close();
        
        System.out.println("✓ 文档保存成功: " + output.getAbsolutePath());
        
        // 验证
        PDDocument signed = Loader.loadPDF(output);
        assertNotNull(signed);
        PDAcroForm form = signed.getDocumentCatalog().getAcroForm();
        assertNotNull(form);
        assertEquals(1, form.getFields().size());
        System.out.println("✓ 签名字段创建成功");
        System.out.println("  签名数: " + signed.getSignatureDictionaries().size());
        signed.close();
        
        System.out.println("✅ 方案2测试通过！支持签名失效显示");
    }

    /**
     * 测试方案3：多个功能章，单个签名
     */
    @Test
    public void testMultipleStampsWithSingleSignature() throws Exception {
        System.out.println("\n=== 测试方案3：多个功能章，单个签名 ===");
        
        // 先创建并保存初始PDF
        File tempFile = tempDir.resolve("temp3.pdf").toFile();
        PDDocument doc = createTestPDF();
        doc.save(tempFile);
        doc.close();
        
        // 重新加载PDF以支持增量保存
        doc = Loader.loadPDF(tempFile);
        PDPage firstPage = doc.getPage(0);
        
        // 创建AcroForm
        PDAcroForm acroForm = new PDAcroForm(doc);
        doc.getDocumentCatalog().setAcroForm(acroForm);
        
        float x = 20;
        PDSignatureField mainSigField = null;
        
        // 创建3个功能章
        for (int i = 0; i < 3; i++) {
            // 直接绘制图片
            try (PDPageContentStream cs = new PDPageContentStream(doc, firstPage, 
                    PDPageContentStream.AppendMode.APPEND, true, true)) {
                BufferedImage stamp = createStampImage("Stamp " + (i + 1));
                PDImageXObject pdImage = LosslessFactory.createFromImage(doc, stamp);
                cs.drawImage(pdImage, x, 700, 60, 30);
            }
            
            if (i == 0) {
                // 第一个位置创建签名字段（透明）
                mainSigField = new PDSignatureField(acroForm);
                mainSigField.setPartialName("MainSignature");
                
                PDAnnotationWidget widget = mainSigField.getWidgets().get(0);
                widget.setPage(firstPage);
                widget.setRectangle(new PDRectangle(x, 700, 60, 30));
                
                // 创建透明外观
                PDAppearanceStream appearanceStream = new PDAppearanceStream(doc);
                appearanceStream.setBBox(new PDRectangle(60, 30));
                appearanceStream.setResources(new PDResources());
                
                PDAppearanceDictionary appearance = new PDAppearanceDictionary();
                appearance.setNormalAppearance(appearanceStream);
                widget.setAppearance(appearance);
                
                firstPage.getAnnotations().add(widget);
                acroForm.getFields().add(mainSigField);
            }
            
            x += 70;
        }
        
        // 创建签名
        PDSignature signature = new PDSignature();
        signature.setFilter(PDSignature.FILTER_ADOBE_PPKLITE);
        signature.setSubFilter(PDSignature.SUBFILTER_ADBE_PKCS7_DETACHED);
        signature.setName("Test Signer");
        signature.setReason("Multiple stamps test");
        signature.setSignDate(Calendar.getInstance());
        
        // 设置签名到主字段
        mainSigField.setValue(signature);
        
        // 添加签名
        doc.addSignature(signature, new DummySignatureInterface());
        
        File output = tempDir.resolve("test3_multi_stamps.pdf").toFile();
        doc.saveIncremental(new FileOutputStream(output));
        doc.close();
        
        System.out.println("✓ 文档保存成功: " + output.getAbsolutePath());
        System.out.println("✅ 方案3测试通过！多个功能章，单个签名");
    }

    private PDDocument createTestPDF() throws IOException {
        PDDocument doc = new PDDocument();
        PDPage page = new PDPage(PDRectangle.A4);
        doc.addPage(page);
        
        try (PDPageContentStream cs = new PDPageContentStream(doc, page)) {
            cs.beginText();
            cs.setFont(new PDType1Font(Standard14Fonts.FontName.HELVETICA), 12);
            cs.newLineAtOffset(100, 600);
            cs.showText("Test PDF for Function Stamps");
            cs.endText();
        }
        
        return doc;
    }

    private BufferedImage createStampImage(String text) {
        BufferedImage img = new BufferedImage(100, 50, BufferedImage.TYPE_INT_ARGB);
        Graphics2D g = img.createGraphics();
        g.setRenderingHint(RenderingHints.KEY_ANTIALIASING, RenderingHints.VALUE_ANTIALIAS_ON);
        
        // 背景
        g.setColor(new Color(255, 255, 255, 200));
        g.fillRect(0, 0, 100, 50);
        
        // 边框
        g.setColor(Color.RED);
        g.setStroke(new BasicStroke(2));
        g.drawRect(2, 2, 96, 46);
        
        // 文字
        g.setColor(Color.BLACK);
        g.setFont(new Font("Arial", Font.BOLD, 12));
        g.drawString(text, 15, 30);
        
        g.dispose();
        return img;
    }

    private static class DummySignatureInterface implements SignatureInterface {
        @Override
        public byte[] sign(InputStream content) throws IOException {
            return "DUMMY_SIGNATURE".getBytes();
        }
    }
}
