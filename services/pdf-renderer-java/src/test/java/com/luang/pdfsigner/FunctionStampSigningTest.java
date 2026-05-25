package com.luang.pdfsigner;

import org.apache.pdfbox.Loader;
import org.apache.pdfbox.pdmodel.PDDocument;
import org.apache.pdfbox.pdmodel.PDPage;
import org.apache.pdfbox.pdmodel.PDPageContentStream;
import org.apache.pdfbox.pdmodel.PDResources;
import org.apache.pdfbox.pdmodel.common.PDRectangle;
import org.apache.pdfbox.pdmodel.font.PDType1Font;
import org.apache.pdfbox.pdmodel.font.Standard14Fonts;
import org.apache.pdfbox.pdmodel.graphics.image.PDImageXObject;
import org.apache.pdfbox.pdmodel.graphics.image.LosslessFactory;
import org.apache.pdfbox.pdmodel.interactive.annotation.PDAnnotationWidget;
import org.apache.pdfbox.pdmodel.interactive.annotation.PDAppearanceDictionary;
import org.apache.pdfbox.pdmodel.interactive.annotation.PDAppearanceStream;
import org.apache.pdfbox.pdmodel.interactive.digitalsignature.ExternalSigningSupport;
import org.apache.pdfbox.pdmodel.interactive.digitalsignature.PDSignature;
import org.apache.pdfbox.pdmodel.interactive.digitalsignature.SignatureOptions;
import org.apache.pdfbox.pdmodel.interactive.form.PDAcroForm;
import org.apache.pdfbox.pdmodel.interactive.form.PDSignatureField;
import org.junit.jupiter.api.Test;
import org.junit.jupiter.api.io.TempDir;

import javax.imageio.ImageIO;
import java.awt.*;
import java.awt.image.BufferedImage;
import java.io.ByteArrayInputStream;
import java.io.ByteArrayOutputStream;
import java.io.File;
import java.io.FileOutputStream;
import java.nio.file.Path;
import java.security.KeyStore;
import java.security.PrivateKey;
import java.security.cert.Certificate;
import java.util.Calendar;

import static org.junit.jupiter.api.Assertions.*;

public class FunctionStampSigningTest {

    @TempDir
    Path tempDir;

    @Test
    public void testFunctionStampWithSignature() throws Exception {
        System.out.println("测试功能章签名...");
        
        // 创建测试PDF
        PDDocument doc = new PDDocument();
        PDPage page = new PDPage(PDRectangle.A4);
        doc.addPage(page);
        
        try (PDPageContentStream cs = new PDPageContentStream(doc, page)) {
            cs.beginText();
            cs.setFont(new PDType1Font(Standard14Fonts.FontName.HELVETICA), 12);
            cs.newLineAtOffset(100, 700);
            cs.showText("Test PDF for Function Stamps");
            cs.endText();
        }
        
        // 保存初始PDF
        File testPdf = tempDir.resolve("test.pdf").toFile();
        doc.save(testPdf);
        doc.close();
        
        // 重新加载PDF进行签名
        doc = Loader.loadPDF(testPdf);
        
        // 创建测试图片（模拟功能章）
        BufferedImage stampImage = createTestStampImage("Stamp 1");
        byte[] stampBytes = imageToBytes(stampImage);
        
        // 应用功能章并签名
        byte[] signedPdf = applyFunctionStampsAndSign(doc, new byte[][] {stampBytes});
        
        assertNotNull(signedPdf);
        assertTrue(signedPdf.length > 0);
        
        // 保存签名后的PDF
        File signedFile = tempDir.resolve("signed.pdf").toFile();
        try (FileOutputStream fos = new FileOutputStream(signedFile)) {
            fos.write(signedPdf);
        }
        
        System.out.println("签名PDF已保存到: " + signedFile.getAbsolutePath());
        
        // 验证签名的PDF可以正常打开
        PDDocument signedDoc = Loader.loadPDF(signedFile);
        assertNotNull(signedDoc);
        assertEquals(1, signedDoc.getNumberOfPages());
        
        // 验证表单字段存在
        PDAcroForm acroForm = signedDoc.getDocumentCatalog().getAcroForm();
        assertNotNull(acroForm);
        assertTrue(acroForm.getFields().size() > 0);
        
        signedDoc.close();
        
        System.out.println("✅ 测试通过：功能章签名成功！");
    }
    
    private byte[] applyFunctionStampsAndSign(PDDocument doc, byte[][] stampImages) throws Exception {
        // 获取第一页
        PDPage firstPage = doc.getPage(0);
        PDRectangle box = firstPage.getMediaBox();
        
        // 创建AcroForm
        PDAcroForm acroForm = new PDAcroForm(doc);
        doc.getDocumentCatalog().setAcroForm(acroForm);
        acroForm.setSignaturesExist(true);
        acroForm.setAppendOnly(true);
        acroForm.getCOSObject().setDirect(true);
        
        // 设置功能章位置参数
        float margin = 20f;
        float stampHeight = 40f;
        float spacing = 10f;
        float y = box.getHeight() - stampHeight - margin;
        float currentX = margin;
        
        // 添加所有功能章的视觉外观
        PDSignatureField firstField = null;
        for (int i = 0; i < stampImages.length; i++) {
            byte[] stampData = stampImages[i];
            
            // 计算宽度
            BufferedImage img = ImageIO.read(new ByteArrayInputStream(stampData));
            float aspectRatio = (float) img.getWidth() / img.getHeight();
            float stampWidth = stampHeight * aspectRatio;
            
            // 创建签名字段
            PDSignatureField field = new PDSignatureField(acroForm);
            field.setPartialName("FunctionStamp" + i);
            
            // 创建widget
            PDAnnotationWidget widget = field.getWidgets().get(0);
            widget.setPage(firstPage);
            widget.setRectangle(new PDRectangle(currentX, y, stampWidth, stampHeight));
            widget.setPrinted(true);
            
            // 创建外观
            PDAppearanceStream appearance = createAppearanceStream(doc, stampData, stampWidth, stampHeight);
            PDAppearanceDictionary appDict = new PDAppearanceDictionary();
            appDict.setNormalAppearance(appearance);
            widget.setAppearance(appDict);
            
            // 添加到页面和表单
            firstPage.getAnnotations().add(widget);
            acroForm.getFields().add(field);
            
            if (i == 0) {
                firstField = field;
            }
            
            currentX += stampWidth + spacing;
        }
        
        // 创建签名
        PDSignature signature = new PDSignature();
        signature.setFilter(PDSignature.FILTER_ADOBE_PPKLITE);
        signature.setSubFilter(PDSignature.SUBFILTER_ADBE_PKCS7_DETACHED);
        signature.setName("Test Signer");
        signature.setLocation("Test Location");
        signature.setReason("Test Reason");
        signature.setSignDate(Calendar.getInstance());
        
        // 关键：先将签名设置到字段，然后使用addSignature
        firstField.setValue(signature);
        
        // 使用addSignature方法
        SignatureOptions sigOpts = new SignatureOptions();
        sigOpts.setPreferredSignatureSize(SignatureOptions.DEFAULT_SIGNATURE_SIZE);
        
        doc.addSignature(signature, new TestSignatureInterface(), sigOpts);
        
        // 保存签名
        ByteArrayOutputStream baos = new ByteArrayOutputStream();
        doc.saveIncremental(baos);
        doc.close();
        
        return baos.toByteArray();
    }
    
    private PDAppearanceStream createAppearanceStream(PDDocument doc, byte[] imageData, float width, float height) throws Exception {
        PDAppearanceStream stream = new PDAppearanceStream(doc);
        stream.setBBox(new PDRectangle(width, height));
        stream.setResources(new PDResources());
        
        try (PDPageContentStream cs = new PDPageContentStream(doc, stream)) {
            BufferedImage img = ImageIO.read(new ByteArrayInputStream(imageData));
            PDImageXObject pdImage = LosslessFactory.createFromImage(doc, img);
            cs.drawImage(pdImage, 0, 0, width, height);
        }
        
        return stream;
    }
    
    private BufferedImage createTestStampImage(String text) {
        BufferedImage img = new BufferedImage(100, 60, BufferedImage.TYPE_INT_ARGB);
        Graphics2D g = img.createGraphics();
        g.setColor(Color.WHITE);
        g.fillRect(0, 0, 100, 60);
        g.setColor(Color.RED);
        g.drawRect(2, 2, 96, 56);
        g.setColor(Color.BLACK);
        g.drawString(text, 20, 35);
        g.dispose();
        return img;
    }
    
    private byte[] imageToBytes(BufferedImage img) throws Exception {
        ByteArrayOutputStream baos = new ByteArrayOutputStream();
        ImageIO.write(img, "PNG", baos);
        return baos.toByteArray();
    }
    
    // 简单的签名接口实现
    private static class TestSignatureInterface implements org.apache.pdfbox.pdmodel.interactive.digitalsignature.SignatureInterface {
        @Override
        public byte[] sign(java.io.InputStream content) throws java.io.IOException {
            // 返回模拟的签名数据
            return "TEST_SIGNATURE".getBytes();
        }
    }
}
