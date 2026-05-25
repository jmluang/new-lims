package com.luang.pdfsigner.service;

import com.luang.pdfsigner.dto.CoverExtractionResponse;
import org.apache.pdfbox.Loader;
import org.apache.pdfbox.pdmodel.PDDocument;
import org.apache.pdfbox.pdmodel.PDPage;
import org.apache.pdfbox.pdmodel.PDPageContentStream;
import org.apache.pdfbox.pdmodel.PDPageContentStream.AppendMode;
import org.apache.pdfbox.pdmodel.graphics.image.PDImageXObject;
import org.apache.pdfbox.pdmodel.graphics.image.LosslessFactory;
import org.apache.pdfbox.pdmodel.interactive.digitalsignature.PDSignature;
import org.apache.pdfbox.pdmodel.interactive.digitalsignature.ExternalSigningSupport;
// import org.apache.pdfbox.pdmodel.interactive.digitalsignature.SignatureOptions;
import org.apache.pdfbox.pdmodel.interactive.annotation.PDAnnotationWidget;
import org.apache.pdfbox.pdmodel.interactive.form.PDAcroForm;
import org.apache.pdfbox.pdmodel.interactive.form.PDSignatureField;
import org.apache.pdfbox.pdmodel.interactive.form.PDField;
import org.apache.pdfbox.pdmodel.interactive.annotation.PDAppearanceDictionary;
import org.apache.pdfbox.pdmodel.interactive.annotation.PDAppearanceStream;
import org.apache.pdfbox.pdmodel.PDResources;
import org.apache.pdfbox.pdmodel.interactive.digitalsignature.SignatureOptions;
import org.slf4j.Logger;
import org.slf4j.LoggerFactory;
import org.springframework.stereotype.Service;
import org.springframework.web.multipart.MultipartFile;

import java.io.*;
import java.util.List;
import java.security.KeyStore;
import java.security.PrivateKey;
import java.security.cert.Certificate;
import javax.imageio.ImageIO;
import java.awt.Color;
import java.awt.image.BufferedImage;
import org.apache.pdfbox.pdmodel.common.PDRectangle;
import org.apache.pdfbox.cos.COSName;
import org.apache.pdfbox.cos.COSDictionary;
import org.apache.pdfbox.cos.COSBase;
import org.apache.pdfbox.cos.COSObject;
import org.apache.pdfbox.cos.COSStream;
import org.apache.pdfbox.io.MemoryUsageSetting;
import org.apache.pdfbox.util.Matrix;
import java.util.concurrent.ConcurrentHashMap;
import java.util.Map;
import java.util.UUID;

@Service
public class SignerService {
    private static final Logger log = LoggerFactory.getLogger(SignerService.class);

    // 图像缓存
    private static final Map<String, BufferedImage> imageCache = new ConcurrentHashMap<>();
    /**
     * 处理结果，包含PDF字节和封面信息
     */
    public static class ProcessResult {
        private final byte[] pdfBytes;
        private final CoverExtractionResponse coverFields;

        public ProcessResult(byte[] pdfBytes, CoverExtractionResponse coverFields) {
            this.pdfBytes = pdfBytes;
            this.coverFields = coverFields;
        }

        public byte[] getPdfBytes() {
            return pdfBytes;
        }

        public CoverExtractionResponse getCoverFields() {
            return coverFields;
        }
    }

    // 新的process方法，支持功能章和二维码，返回包含封面信息的结果
    public ProcessResult process(
            MultipartFile pdf,
            MultipartFile perforation,
            MultipartFile sigImg,
            List<MultipartFile> functionStamps,
            String mode,
            String signingKeyId,
            String contact,
            String location,
            String reason,
            String hashAlgo,
            boolean tsaEnabled,
            String tsaUrl,
            MultipartFile qrCodeImg,
            String qrCodeUrl
    ) throws Exception {
        // 首先提取封面信息
        File tempPdf = toTempFile(pdf);
        CoverExtractionResponse coverFields = extractCoverFields(tempPdf);

        // 然后处理PDF（使用原有逻辑）
        byte[] processedPdf = processPdf(pdf, perforation, sigImg, functionStamps, mode, signingKeyId, contact, location, reason, hashAlgo, tsaEnabled, tsaUrl, coverFields);

        return new ProcessResult(processedPdf, coverFields);
    }

    // 原有的process逻辑，抽取为私有方法
    private byte[] processPdf(
            MultipartFile pdf,
            MultipartFile perforation,
            MultipartFile sigImg,
            List<MultipartFile> functionStamps,
            String mode,
            String signingKeyId,
            String contact,
            String location,
            String reason,
            String hashAlgo,
            boolean tsaEnabled,
            String tsaUrl,
            CoverExtractionResponse coverFields
    ) throws Exception {
        File tempPdf = toTempFile(pdf);
        log.info("SignerService.processPdf: mode={}, pdfTemp={}, perfPresent={}, sigImgPresent={}",
                mode, tempPdf.getAbsolutePath(), perforation != null && !perforation.isEmpty(), sigImg != null && !sigImg.isEmpty());
        PDDocument doc = null;
        try {
            doc = Loader.loadPDF(tempPdf);

            // 清理上传PDF的元信息，避免使用原始文件的元信息
            cleanPdfMetadata(doc, coverFields);

            String extractedReportNumber = (coverFields != null) ? coverFields.reportNumber() : null;

            if (extractedReportNumber != null && !extractedReportNumber.isBlank()) {
                log.info("Extracted report number: {}", extractedReportNumber);

                // 生成证书查询二维码
                String baseUrl = getCfg("CERTIFICATE_QUERY_BASE_URL");
                if (baseUrl == null || baseUrl.isBlank()) {
                    // 默认使用本地URL
                    baseUrl = "http://localhost:8080/certificate-query";
                }
                String queryUrl = baseUrl + "?query=" + java.net.URLEncoder.encode(extractedReportNumber, java.nio.charset.StandardCharsets.UTF_8);

                log.info("Generating QR code for URL: {}", queryUrl);

                try {
                    // 获取配置的二维码像素尺寸
                    int[] pixelSize = getQrCodePixelSize();
                    int qrWidth = pixelSize[0];
                    int qrHeight = pixelSize[1];

                    byte[] qrCodeBytes = generateQrCode(queryUrl, qrWidth, qrHeight);
                    doc = addQrCodeToFirstPage(doc, qrCodeBytes);
                    log.info("QR code added successfully to first page");
                } catch (Exception qrEx) {
                    log.error("Failed to generate or add QR code", qrEx);
                    // 继续处理，不因二维码失败而中断
                }
            } else {
                log.info("No report number found in PDF, skipping QR code generation");
            }

            boolean hasFunctionStamps = functionStamps != null && !functionStamps.isEmpty();
            boolean hasFrontSeal = sigImg != null && !sigImg.isEmpty();
            boolean hasPerforation = perforation != null && !perforation.isEmpty();

            // 检查是否需要签名功能（功能章需要签名支持）
            boolean needsSignature = "sign".equalsIgnoreCase(mode) || 
                                    "stamp_and_sign".equalsIgnoreCase(mode) || 
                                    "custom".equalsIgnoreCase(mode) ||
                                    hasFunctionStamps ||
                                    hasFrontSeal;
            
            if ("stamp".equalsIgnoreCase(mode)) {
                if (hasPerforation) {
                    log.info("Applying perforation: pages={} bytes={}", doc.getNumberOfPages(), perforation.getSize());
                    applyPerforation(doc, perforation.getBytes());
                }
                
                // 如果有功能章，需要签名
                if (hasFunctionStamps) {
                    log.info("Applying {} function stamps with signature", functionStamps.size());
                    return signExternalWithFunctionStamps(doc, functionStamps, null, null, signingKeyId, contact, location, reason, hashAlgo, tsaEnabled, tsaUrl);
                }
            }

            if ("sign".equalsIgnoreCase(mode)) {
                // 如果有功能章，集成到签名流程中
                if (hasFunctionStamps || hasFrontSeal) {
                    log.info("Signing with function/front seals: functionCount={}, hasFrontSeal={}",
                            hasFunctionStamps ? functionStamps.size() : 0, hasFrontSeal);
                    return signExternalWithFunctionStamps(
                            doc,
                            hasFunctionStamps ? functionStamps : java.util.Collections.emptyList(),
                            hasFrontSeal ? sigImg.getBytes() : null,
                            null,
                            signingKeyId,
                            contact,
                            location,
                            reason,
                            hashAlgo,
                            tsaEnabled,
                            tsaUrl
                    );
                }
                log.info("Signing incremental (single visible signature if provided): hashAlgo={}, tsaEnabled={}", hashAlgo, tsaEnabled);
                return signExternal(doc, sigImg, signingKeyId, contact, location, reason, hashAlgo, tsaEnabled, tsaUrl);
            }

            if ("custom".equalsIgnoreCase(mode)) {
                // Custom模式：可以有功能章、骑缝章、签名的任意组合
                if (hasFunctionStamps || hasPerforation || hasFrontSeal) {
                    log.info("Custom mode with stamps: functionCount={}, hasPerforation={}, hasFrontSeal={}",
                            hasFunctionStamps ? functionStamps.size() : 0, hasPerforation, hasFrontSeal);
                    return signExternalWithAllStamps(
                            doc,
                            hasPerforation ? perforation.getBytes() : null,
                            hasFrontSeal ? sigImg.getBytes() : null,
                            hasFunctionStamps ? functionStamps : java.util.Collections.emptyList(),
                            signingKeyId,
                            contact,
                            location,
                            reason,
                            hashAlgo,
                            tsaEnabled,
                            tsaUrl
                    );
                }
                // 如果什么都没有，返回原PDF
                ByteArrayOutputStream out = new ByteArrayOutputStream();
                doc.save(out);
                doc.close();
                return out.toByteArray();
            }
            
            if ("stamp_and_sign".equalsIgnoreCase(mode)) {
                // 逐页：为每一页创建右侧细长签名域（使用骑缝章切片作为 AP），并进行一次增量外部签名
                if (perforation == null || perforation.isEmpty()) {
                    throw new IllegalArgumentException("stamp_and_sign requires 'perforation_image' for visible appearance slices");
                }
                log.info("Per-page incremental signing with perforation slices: pages={}, hashAlgo={}, tsaEnabled={}",
                        doc.getNumberOfPages(), hashAlgo, tsaEnabled);

                return signExternalPerPageIncrementalOptimized(
                        doc,
                        perforation.getBytes(),
                        sigImg != null && !sigImg.isEmpty() ? sigImg.getBytes() : null,
                        signingKeyId,
                        contact,
                        location,
                        reason,
                        hashAlgo,
                        tsaEnabled,
                        tsaUrl
                );
            }

            ByteArrayOutputStream out = new ByteArrayOutputStream();
            doc.save(out);
            log.info("Processing done. Output bytes={}", out.size());
            return out.toByteArray();
        } finally {
            if (doc != null) {
                try { doc.close(); } catch (IOException ignore) {}
            }
            tempPdf.delete();
        }
    }

    // 优化内存策略：根据页数动态调整
    private MemoryUsageSetting pdfMemorySetting() {
        return pdfMemorySetting(0);
    }

    private MemoryUsageSetting pdfMemorySetting(int pageCount) {
        String mode = getCfg("PDFBOX_MEMORY_MODE"); // temp | mixed | auto

        // 自动模式：根据页数决定
        if (mode == null || mode.isBlank() || mode.equalsIgnoreCase("auto")) {
            if (pageCount > 50) {
                // 大文档使用纯临时文件
                return MemoryUsageSetting.setupTempFileOnly();
            } else if (pageCount > 20) {
                // 中等文档使用混合模式，减少内存
                return MemoryUsageSetting.setupMixed(32L * 1024L * 1024L);
            } else {
                // 小文档可以使用更多内存
                return MemoryUsageSetting.setupMixed(64L * 1024L * 1024L);
            }
        }

        if (mode.equalsIgnoreCase("temp")) {
            return MemoryUsageSetting.setupTempFileOnly();
        }
        if (mode.equalsIgnoreCase("mixed")) {
            long mb = 64; // 默认 64MB 堆内缓存
            try {
                String v = getCfg("PDFBOX_MAX_MAIN_MEMORY_MB");
                if (v != null && !v.isBlank()) mb = Long.parseLong(v.trim());
            } catch (Exception ignore) {}
            return MemoryUsageSetting.setupMixed(mb * 1024L * 1024L);
        }
        return MemoryUsageSetting.setupTempFileOnly();
    }

    // 新的签名方法：每个功能章独立增量签名（支持失效显示叉）
    private byte[] signExternalWithFunctionStamps(
            PDDocument doc,
            List<MultipartFile> functionStamps,
            byte[] sigImgData,  // 添加首页盖章参数
            byte[] perforationImageBytes,  // 添加骑缝章参数
            String signingKeyId,
            String contact,
            String location,
            String reason,
            String hashAlgo,
            boolean tsaEnabled,
            String tsaUrl
    ) throws Exception {
        // 仅使用 PFX
        KeyMaterial km0 = loadPfxMaterial(getCfg("DEFAULT_PFX_PATH"));
        PrivateKey privateKey = km0.privateKey();
        Certificate[] chain = km0.certificateChain();

        // 规范化功能章列表，便于统一处理
        List<MultipartFile> normalizedFunctionStamps = functionStamps != null
                ? functionStamps
                : java.util.Collections.emptyList();

        // 保存初始文档
        ByteArrayOutputStream initialDoc = new ByteArrayOutputStream();
        doc.save(initialDoc);
        doc.close();
        byte[] currentPdfBytes = initialDoc.toByteArray();
        
        // 设置功能章位置参数
        // 左/右边距（mm，可通过环境变量 FUNCTION_STAMP_LEFT_MARGIN_MM 配置；默认约7mm）
        float leftMarginMm = 7.0f;
        try {
            String cfgLeftMm = getCfg("FUNCTION_STAMP_LEFT_MARGIN_MM");
            if (cfgLeftMm != null && !cfgLeftMm.isBlank()) {
                leftMarginMm = Float.parseFloat(cfgLeftMm.trim());
            }
        } catch (Exception ignore) {}
        float leftMarginPt = (float) (leftMarginMm * 2.83465);
        float rightMarginPt = leftMarginPt;
        // 顶部边距（mm，可通过环境变量 FUNCTION_STAMP_TOP_MARGIN_MM 配置；默认40mm）
        float topMarginMm = 40.0f;
        try {
            String cfgTopMm = getCfg("FUNCTION_STAMP_TOP_MARGIN_MM");
            if (cfgTopMm != null && !cfgTopMm.isBlank()) {
                topMarginMm = Float.parseFloat(cfgTopMm);
            }
        } catch (Exception ignore) {}
        float topMarginPt = (float) (topMarginMm * 2.83465);

        float stampHeight = (float) (20.0 * 2.83465);  // 固定高度3cm（30mm）转换为点
        float spacing = 10f;
        float currentX = leftMarginPt;
        
        // 如果需要首页盖章，优先处理，使其成为认证签名
        if (sigImgData != null && sigImgData.length > 0) {
            currentPdfBytes = addFrontSealSignatures(currentPdfBytes, sigImgData, contact,
                    location, reason, privateKey, chain, hashAlgo, tsaEnabled, tsaUrl);
        }

        // 为每个功能章创建独立的增量签名
        for (int i = 0; i < normalizedFunctionStamps.size(); i++) {
            MultipartFile stampFile = normalizedFunctionStamps.get(i);
            if (stampFile == null || stampFile.isEmpty()) continue;
            
            byte[] stampBytes = stampFile.getBytes();
            BufferedImage stampImage = ImageIO.read(new ByteArrayInputStream(stampBytes));
            if (stampImage == null) {
                log.warn("Function stamp {} is not a valid image, skipping", i);
                continue;
            }
            float aspectRatio = (float) stampImage.getWidth() / stampImage.getHeight();
            float stampWidth = stampHeight * aspectRatio;
            
            // 重新加载PDF
            PDDocument currentDoc = Loader.loadPDF(currentPdfBytes);
            PDPage firstPage = currentDoc.getPage(0);
            PDRectangle box = firstPage.getMediaBox();
            // 从页面顶部向下偏移 topMarginPt，使顶部间距为 topMarginMm 毫米
            float y = box.getHeight() - stampHeight - topMarginPt;
            
            // 检查边界
            if (currentX + stampWidth > box.getWidth() - rightMarginPt) {
                log.warn("Function stamp {} would exceed page boundary, skipping", i);
                currentDoc.close();
                break;
            }
            
            // 创建签名
            PDSignature signature = createDetachedSignature();
            
            // 创建签名选项（注意：使用 try-with-resources 以避免内存泄漏）
            try (SignatureOptions sigOpts = new SignatureOptions()) {
                sigOpts.setPreferredSignatureSize(SignatureOptions.DEFAULT_SIGNATURE_SIZE);

                try (InputStream visualTemplate = createVisualSignatureTemplateStream(
                        box.getWidth(), box.getHeight(),
                        currentX, y, stampWidth, stampHeight,
                        stampBytes,
                        randomFieldName()
                )) {
                    sigOpts.setVisualSignature(visualTemplate);
                    sigOpts.setPage(0);

                    currentDoc.addSignature(signature, new SimpleSignatureInterface(privateKey, chain, hashAlgo, tsaEnabled, tsaUrl), sigOpts);
                }

                // 保存增量更新
                ByteArrayOutputStream tempOut = new ByteArrayOutputStream();
                normalizeSignatureAppearanceStates(currentDoc);
                currentDoc.saveIncremental(tempOut);
                currentDoc.close();

                // 更新当前PDF字节供下次使用
                currentPdfBytes = tempOut.toByteArray();
                currentX += stampWidth + spacing;

                log.info("Added function stamp {} with incremental signature at position ({}, {})", i, currentX - stampWidth - spacing, y);
            }
        }
        
        // 处理骑缝章（如果有） - 为每页创建签名域并添加到签名列表
        if (perforationImageBytes != null && perforationImageBytes.length > 0) {
            log.info("Adding perforation stamps to signature list");
            
            // 加载骑缝章图像
            java.awt.image.BufferedImage fullImg = loadBufferedImage(perforationImageBytes);
            int imgW = fullImg.getWidth();
            int imgH = fullImg.getHeight();
            float perforationStampHeightMm = 35.0f;
            try {
                String cfgSize = getCfg("PERFORATION_STAMP_HEIGHT_MM");
                if (cfgSize != null && !cfgSize.isBlank()) {
                    perforationStampHeightMm = Float.parseFloat(cfgSize.trim());
                }
            } catch (Exception ignore) {}
            float totalStampHeightPt = (float) (perforationStampHeightMm * 2.83465);
            float totalStampWidthPt = totalStampHeightPt * ((float) imgW / (float) imgH);
            
            // 获取总页数
            int totalPages;
            try (PDDocument probe = Loader.loadPDF(currentPdfBytes)) {
                totalPages = probe.getNumberOfPages();
            }
            
            // 为每页添加骑缝章签名
            for (int pageIndex = 0; pageIndex < totalPages; pageIndex++) {
                try (PDDocument currentDoc = Loader.loadPDF(currentPdfBytes)) {
                    PDPage page = currentDoc.getPage(pageIndex);
                    PDRectangle box = page.getMediaBox();
                    
                    // 切片（按总页数平均分）
                    int endPixelX = Math.round(imgW * (pageIndex + 1) / (float) totalPages);
                    int startPixelX = (pageIndex == 0) ? 0 : Math.round(imgW * pageIndex / (float) totalPages);
                    int sliceWidthPx = Math.max(1, endPixelX - startPixelX);
                    java.awt.image.BufferedImage slice = fullImg.getSubimage(startPixelX, 0, sliceWidthPx, imgH);
                    
                    // 转换切片为PNG字节
                    byte[] slicePng;
                    try (ByteArrayOutputStream sliceOut = new ByteArrayOutputStream()) {
                        javax.imageio.ImageIO.write(slice, "png", sliceOut);
                        slicePng = sliceOut.toByteArray();
                    }
                    
                    float sliceWidthPt = totalStampWidthPt / totalPages;
                    float x = box.getWidth() - sliceWidthPt;
                    float y = (box.getHeight() - totalStampHeightPt) / 2f;
                    
                    // 创建签名
                    PDSignature signature = createDetachedSignature();
                    
                    // 创建签名选项（用完即关）
                    try (SignatureOptions sigOpts = new SignatureOptions()) {
                        sigOpts.setPreferredSignatureSize(SignatureOptions.DEFAULT_SIGNATURE_SIZE);

                        // 创建可视签名模板
                        InputStream template = createVisualSignatureTemplateStream(
                                box.getWidth(), box.getHeight(),
                                x, y, sliceWidthPt, totalStampHeightPt,
                                slicePng,
                                randomFieldName()
                        );
                        sigOpts.setVisualSignature(template);
                        sigOpts.setPage(pageIndex);

                        currentDoc.addSignature(signature, new SimpleSignatureInterface(privateKey, chain, hashAlgo, tsaEnabled, tsaUrl), sigOpts);

                        // 保存增量更新
                        ByteArrayOutputStream tempOut = new ByteArrayOutputStream();
                        normalizeSignatureAppearanceStates(currentDoc);
                        currentDoc.saveIncremental(tempOut);
                        currentDoc.close();

                        currentPdfBytes = tempOut.toByteArray();
                        log.info("Added perforation slice {} of {} to signature list", pageIndex + 1, totalPages);
                    }
                }
            }
        }

        return currentPdfBytes;
    }
    
    // 创建可视签名流
    private InputStream createVisualSignatureStream(PDDocument doc, byte[] stampData, float width, float height) throws IOException {
        // 创建一个临时的PDF文档作为外观
        PDDocument appearanceDoc = new PDDocument();
        PDPage appearancePage = new PDPage(new PDRectangle(width, height));
        appearanceDoc.addPage(appearancePage);
        
        try (PDPageContentStream cs = new PDPageContentStream(appearanceDoc, appearancePage)) {
            BufferedImage stampImage = ImageIO.read(new ByteArrayInputStream(stampData));
            PDImageXObject pdImage = LosslessFactory.createFromImage(appearanceDoc, stampImage);
            // 关键修复：为Image XObject添加空的Resources字典，避免Acrobat报错
            pdImage.getCOSObject().setItem(COSName.RESOURCES, new COSDictionary());
            cs.drawImage(pdImage, 0, 0, width, height);
        }
        
        ByteArrayOutputStream baos = new ByteArrayOutputStream();
        appearanceDoc.save(baos);
        appearanceDoc.close();
        
        return new ByteArrayInputStream(baos.toByteArray());
    }
    
    // 创建功能章外观流
    // 新方法：创建功能章外观流（支持自定义宽高）
    private PDAppearanceStream createFunctionStampAppearanceWithSize(PDDocument doc, byte[] stampData, float width, float height) throws IOException {
        PDAppearanceStream appearanceStream = new PDAppearanceStream(doc);
        appearanceStream.setBBox(new org.apache.pdfbox.pdmodel.common.PDRectangle(width, height));

        // 重要：必须设置Resources
        PDResources resources = new PDResources();
        appearanceStream.setResources(resources);

        // 关键修复:为外观流设置Type为XObject,Subtype为Form
        appearanceStream.getCOSObject().setName(COSName.TYPE, COSName.XOBJECT.getName());
        appearanceStream.getCOSObject().setName(COSName.SUBTYPE, COSName.FORM.getName());

        // 使用try-with-resources确保内容流正确关闭
        try (PDPageContentStream contentStream = new PDPageContentStream(doc, appearanceStream)) {
            // 创建图片对象
            BufferedImage stampImage = ImageIO.read(new ByteArrayInputStream(stampData));
            PDImageXObject pdImage = LosslessFactory.createFromImage(doc, stampImage);
            // 关键修复：为Image XObject添加空的Resources字典，避免Acrobat报错
            pdImage.getCOSObject().setItem(COSName.RESOURCES, new COSDictionary());

            // 绘制图片（填满指定区域）
            contentStream.saveGraphicsState();
            contentStream.drawImage(pdImage, 0, 0, width, height);
            contentStream.restoreGraphicsState();
        }

        // 关键修复：确保外观流具有正确的签名验证属性
        // 这将使Adobe Acrobat在文件被修改时显示红叉
        appearanceStream.getCOSObject().setBoolean(COSName.getPDFName("SV"), true);

        return appearanceStream;
    }
    
    // 保留原方法以兼容
    private PDAppearanceStream createFunctionStampAppearance(PDDocument doc, byte[] stampData, float size) throws IOException {
        PDAppearanceStream appearanceStream = new PDAppearanceStream(doc);
        appearanceStream.setBBox(new org.apache.pdfbox.pdmodel.common.PDRectangle(size, size));
        appearanceStream.setResources(new PDResources());

        // 关键修复:为外观流设置Type为XObject,Subtype为Form
        appearanceStream.getCOSObject().setName(COSName.TYPE, COSName.XOBJECT.getName());
        appearanceStream.getCOSObject().setName(COSName.SUBTYPE, COSName.FORM.getName());

        try (PDPageContentStream cs = new PDPageContentStream(doc, appearanceStream)) {
            PDImageXObject img = loadImage(doc, stampData);
            cs.saveGraphicsState();
            cs.drawImage(img, 0, 0, size, size);
            cs.restoreGraphicsState();
        }

        // 关键修复：确保外观流具有正确的签名验证属性
        // 这将使Adobe Acrobat在文件被修改时显示红叉
        appearanceStream.getCOSObject().setBoolean(COSName.getPDFName("SV"), true);

        return appearanceStream;
    }
    
    // 综合处理所有类型的章（功能章、骑缝章、首页章）
    private byte[] signExternalWithAllStamps(
            PDDocument doc,
            byte[] perforationData,
            byte[] sigImgData,
            List<MultipartFile> functionStamps,
            String signingKeyId,
            String contact,
            String location,
            String reason,
            String hashAlgo,
            boolean tsaEnabled,
            String tsaUrl
    ) throws Exception {
        List<MultipartFile> normalizedFunctionStamps = functionStamps != null
                ? functionStamps
                : java.util.Collections.emptyList();
        boolean hasFunctionStamps = !normalizedFunctionStamps.isEmpty();
        boolean hasFrontSeal = sigImgData != null && sigImgData.length > 0;
        boolean hasPerforation = perforationData != null && perforationData.length > 0;

        // 统一处理所有章和签名（功能章、首页盖章、骑缝章）
        if (hasFunctionStamps || hasFrontSeal || hasPerforation) {
            return signExternalWithFunctionStamps(
                    doc,
                    normalizedFunctionStamps,
                    hasFrontSeal ? sigImgData : null,
                    hasPerforation ? perforationData : null,
                    signingKeyId,
                    contact,
                    location,
                    reason,
                    hashAlgo,
                    tsaEnabled,
                    tsaUrl
            );
        }

        // 如果没有任何章，只执行普通签名
        return signExternal(doc, null, signingKeyId, contact, location, reason, hashAlgo, tsaEnabled, tsaUrl);
    }
    
    // 保留原有的重载方法以保持向后兼容
    public byte[] process(
            MultipartFile pdf,
            MultipartFile perforation,
            MultipartFile sigImg,
            String mode,
            String signingKeyId,
            String contact,
            String location,
            String reason,
            String hashAlgo,
            boolean tsaEnabled,
            String tsaUrl
    ) throws Exception {
        ProcessResult result = process(pdf, perforation, sigImg, null, mode, signingKeyId, contact, location, reason, hashAlgo, tsaEnabled, tsaUrl, null, null);
        return result.getPdfBytes();
    }
    
    private void applyPerforation(PDDocument doc, byte[] stampBytes) throws IOException {
        // 优化：缓存图像
        String cacheKey = "perf_full_" + stampBytes.length;
        java.awt.image.BufferedImage full = imageCache.computeIfAbsent(cacheKey, k -> {
            try {
                return loadBufferedImage(stampBytes);
            } catch (IOException e) {
                throw new RuntimeException(e);
            }
        });
        int imgW = full.getWidth();
        int imgH = full.getHeight();

        int pageCount = doc.getNumberOfPages();
        if (pageCount <= 1) return;

        int groupSize = 10;
        // PDF 上目标总高度：默认35mm，可配置
        float perforationStampHeightMm = 35.0f;
        try {
            String cfgSize = getCfg("PERFORATION_STAMP_HEIGHT_MM");
            if (cfgSize != null && !cfgSize.isBlank()) {
                perforationStampHeightMm = Float.parseFloat(cfgSize.trim());
            }
        } catch (Exception ignore) {}
        float totalStampHeightPt = (float) (perforationStampHeightMm * 2.83465);
        float aspect = (float) imgW / (float) imgH;
        float totalStampWidthPt = totalStampHeightPt * aspect;

        for (int i = 0; i < pageCount; i++) {
            PDPage page = doc.getPage(i);
            var box = page.getMediaBox();
            float pageW = box.getWidth();
            float pageH = box.getHeight();

            int startPage = (i / groupSize) * groupSize;
            int endPage = Math.min(startPage + groupSize - 1, pageCount - 1);
            int pagesInGroup = endPage - startPage + 1;
            int pageIndexInGroup = (i - startPage) + 1; // 1-based

            // 计算像素级裁剪范围（纵向整高，横向均分）
            int endPixelX = Math.round(imgW * pageIndexInGroup / (float) pagesInGroup);
            int startPixelX = (pageIndexInGroup == 1) ? 0 : Math.round(imgW * (pageIndexInGroup - 1) / (float) pagesInGroup);
            int sliceWidthPx = endPixelX - startPixelX;
            if (sliceWidthPx <= 0) continue;

            // 优化：缓存切片
            String sliceCacheKey = "perf_slice_" + i + "_" + startPixelX + "_" + sliceWidthPx;
            BufferedImage slice = imageCache.computeIfAbsent(sliceCacheKey, k ->
                full.getSubimage(startPixelX, 0, sliceWidthPx, imgH)
            );

            PDImageXObject sliceX = LosslessFactory.createFromImage(doc, slice);
            // 关键修复：为Image XObject添加空的Resources字典，避免Acrobat报错
            sliceX.getCOSObject().setItem(COSName.RESOURCES, new COSDictionary());

            // 在 PDF 上每页等宽显示
            float sliceWidthPt = totalStampWidthPt / pagesInGroup;
            float x = pageW - sliceWidthPt;
            float y = (pageH - totalStampHeightPt) / 2f;
            if (i == 0) {
                log.info("Perforation debug(crop): pageW={}, pageH={}, slicePx={}, slicePt={}, stampHpt={}", pageW, pageH, sliceWidthPx, sliceWidthPt, totalStampHeightPt);
            }

            try (PDPageContentStream cs = new PDPageContentStream(doc, page, AppendMode.APPEND, true, true)) {
                cs.drawImage(sliceX, x, y, sliceWidthPt, totalStampHeightPt);
            }
        }
    }

    private PDImageXObject loadImage(PDDocument doc, byte[] data) throws IOException {
        try (java.io.ByteArrayInputStream bais = new java.io.ByteArrayInputStream(data)) {
            java.awt.image.BufferedImage img = javax.imageio.ImageIO.read(bais);
            if (img == null) {
                throw new IOException("Unsupported image format for perforation stamp");
            }
            PDImageXObject pdImage = LosslessFactory.createFromImage(doc, img);
            // 关键修复：为Image XObject添加空的Resources字典，避免Acrobat报错
            pdImage.getCOSObject().setItem(COSName.RESOURCES, new COSDictionary());
            return pdImage;
        }
    }

    private java.awt.image.BufferedImage loadBufferedImage(byte[] data) throws IOException {
        try (java.io.ByteArrayInputStream bais = new java.io.ByteArrayInputStream(data)) {
            java.awt.image.BufferedImage img = javax.imageio.ImageIO.read(bais);
            if (img == null) throw new IOException("Unsupported image format");
            return img;
        }
    }

    private byte[] signExternal(
            PDDocument doc,
            MultipartFile sigImg,
            String signingKeyId,
            String contact,
            String location,
            String reason,
            String hashAlgo,
            boolean tsaEnabled,
            String tsaUrl
    ) throws Exception {
        // 仅使用 PFX
        KeyMaterial km1 = loadPfxMaterial(getCfg("DEFAULT_PFX_PATH"));
        PrivateKey privateKey = km1.privateKey();
        Certificate[] chain = km1.certificateChain();

        PDSignature signature = createDetachedSignature();

        // 创建签名字段（如果有签名图片）
        if (sigImg != null && !sigImg.isEmpty()) {
            byte[] signatureImageBytes = sigImg.getBytes();
            var page = doc.getPage(0);
            var box = page.getMediaBox();

            float stampSize = resolveFrontSealSizePt();
            float margin = (float) (10 * 2.83465);

            float[] offsets = getFrontSealOffsetsForPage(0);
            float[] position = computeFrontSealPosition(box, stampSize, margin, offsets[0], offsets[1]);
            float x = position[0];
            float y = position[1];

            // 创建或获取AcroForm
            PDAcroForm acroForm = ensureAcroForm(doc);

            // 创建签名字段
            PDSignatureField signatureField = new PDSignatureField(acroForm);
            signatureField.setPartialName(randomFieldName());

            // 创建主页面widget
            PDAnnotationWidget widget = signatureField.getWidgets().get(0);
            widget.setPage(page);
            widget.setRectangle(new PDRectangle(x, y, stampSize, stampSize));
            widget.setPrinted(true);

            BufferedImage signatureImage = ImageIO.read(new ByteArrayInputStream(signatureImageBytes));
            if (signatureImage == null) {
                throw new IOException("Unsupported signature image format");
            }
            PDImageXObject pdImage = LosslessFactory.createFromImage(doc, signatureImage);

            applyCompliantSignatureAppearance(doc, widget, pdImage, stampSize, stampSize, true);

            // 添加到页面和表单
            page.getAnnotations().add(widget);
            acroForm.getFields().add(signatureField);
            signatureField.setValue(signature);

            // 在第三页添加同样的可视签名
            if (doc.getNumberOfPages() >= 3) {
                PDPage thirdPage = doc.getPage(2);
                PDRectangle thirdBox = thirdPage.getMediaBox();
                float[] thirdOffsets = getFrontSealOffsetsForPage(2);
                float[] thirdPosition = computeFrontSealPosition(thirdBox, stampSize, margin, thirdOffsets[0], thirdOffsets[1]);
                float thirdX = thirdPosition[0];
                float thirdY = thirdPosition[1];

                PDSignatureField thirdField = new PDSignatureField(acroForm);
                thirdField.setPartialName(randomFieldName());
                PDAnnotationWidget thirdWidget = thirdField.getWidgets().get(0);
                thirdWidget.setRectangle(new PDRectangle(thirdX, thirdY, stampSize, stampSize));
                thirdWidget.setPage(thirdPage);
                thirdWidget.setPrinted(true);

                applyCompliantSignatureAppearance(doc, thirdWidget, pdImage, stampSize, stampSize, true);

                thirdPage.getAnnotations().add(thirdWidget);
                acroForm.getFields().add(thirdField);
                thirdField.setValue(signature);
                log.info("Added main signature widget on page 3 at position ({}, {})", thirdX, thirdY);
            } else {
                log.info("Skipping third-page main signature widget: document has only {} pages",
                        doc.getNumberOfPages());
            }
        }

        // 添加签名（确保 SignatureOptions 及时关闭以释放资源）
        ByteArrayOutputStream signedOut = new ByteArrayOutputStream();
        try (SignatureOptions sigOpts = new SignatureOptions()) {
            try {
                int defaultSize = 512 * 1024;
                int reserved = Integer.parseInt(System.getenv().getOrDefault("SIGNATURE_RESERVED_SIZE", String.valueOf(defaultSize)));
                sigOpts.setPreferredSignatureSize(reserved);
            } catch (Exception ignore) {}

            doc.addSignature(signature, sigOpts);
            normalizeSignatureAppearanceStates(doc);

            // 外部签名：生成 CMS（CAdES detached），回填
            ExternalSigningSupport ext = doc.saveIncrementalForExternalSigning(signedOut);
            byte[] cms = new SimpleSignatureInterface(privateKey, chain, hashAlgo, tsaEnabled, tsaUrl).sign(ext.getContent());
            ext.setSignature(cms);
        }
        doc.close();
        log.info("Processing done (3.x ext sign). Output bytes={}", signedOut.size());
        return signedOut.toByteArray();
    }

    private void applyCompliantSignatureAppearance(
            PDDocument doc,
            PDAnnotationWidget widget,
            PDImageXObject image,
            float width,
            float height,
            boolean multiLayer
    ) throws IOException {
        if (!multiLayer) {
            PDAppearanceStream simpleStream = createStateStream(doc, image, width, height, false);
            COSDictionary apDict = new COSDictionary();
            apDict.setItem(COSName.N, simpleStream.getCOSObject());
            widget.getCOSObject().setItem(COSName.AP, apDict);
            widget.getCOSObject().removeItem(COSName.AS);
            widget.getCOSObject().setInt(COSName.F, 4);
            widget.getCOSObject().setBoolean(COSName.getPDFName("SV"), true);
            return;
        }

        PDRectangle bbox = new PDRectangle(width, height);

        PDAppearanceStream blankStream = createBlankFormStream(doc, bbox);
        PDAppearanceStream unknownStream = createBlankFormStream(doc, bbox);
        PDAppearanceStream validStream = createStateStream(doc, image, width, height, false);
        PDAppearanceStream invalidStream = createStateStream(doc, image, width, height, true);

        PDAppearanceStream frmStream = new PDAppearanceStream(doc);
        frmStream.setBBox(bbox);
        frmStream.getCOSObject().setName(COSName.TYPE, COSName.XOBJECT.getName());
        frmStream.getCOSObject().setName(COSName.SUBTYPE, COSName.FORM.getName());
        PDResources frmResources = new PDResources();
        frmResources.put(COSName.getPDFName("n0"), blankStream);
        frmResources.put(COSName.getPDFName("n1"), invalidStream);
        frmResources.put(COSName.getPDFName("n2"), validStream);
        frmResources.put(COSName.getPDFName("n3"), unknownStream);
        frmStream.setResources(frmResources);

        try (PDPageContentStream cs = new PDPageContentStream(doc, frmStream)) {
            cs.saveGraphicsState();
            cs.drawForm(blankStream);
            cs.restoreGraphicsState();

            cs.saveGraphicsState();
            cs.transform(new Matrix(0.0001f, 0, 0, 0.0001f, 0, 0));
            cs.drawForm(invalidStream);
            cs.restoreGraphicsState();

            cs.saveGraphicsState();
            cs.drawForm(validStream);
            cs.restoreGraphicsState();

            cs.saveGraphicsState();
            cs.transform(new Matrix(1.01f, 0, 0, 1.01f, 6.1f, 5.6f));
            cs.drawForm(unknownStream);
            cs.restoreGraphicsState();
        }

        COSDictionary apDict = new COSDictionary();
        apDict.setItem(COSName.N, frmStream.getCOSObject());
        widget.getCOSObject().setItem(COSName.AP, apDict);
        widget.getCOSObject().setName(COSName.AS, "n2");
        widget.getCOSObject().setInt(COSName.F, 4);
        widget.getCOSObject().setBoolean(COSName.getPDFName("SV"), true);
    }

    /**
     * 优化版批量骑缝章处理：
     * - 批量创建所有页的签名域
     * - 使用单次签名操作完成所有页面
     * - 优化图像切片和缓存
     */
    private byte[] signExternalPerPageIncrementalOptimized(
            PDDocument initialDoc,
            byte[] perforationImageBytes,
            byte[] firstPageSealImageBytes,
            String signingKeyId,
            String contact,
            String location,
            String reason,
            String hashAlgo,
            boolean tsaEnabled,
            String tsaUrl
    ) throws Exception {
        // 使用优化版本
        String useOptimized = getCfg("USE_OPTIMIZED_PERFORATION");
        if (useOptimized == null || !"false".equalsIgnoreCase(useOptimized)) {
            return signExternalPerPageIncrementalBatch(
                initialDoc, perforationImageBytes, firstPageSealImageBytes,
                signingKeyId, contact, location, reason, hashAlgo, tsaEnabled, tsaUrl
            );
        }
        // 保留原始实现作为后备
        return signExternalPerPageIncrementalOriginal(
            initialDoc, perforationImageBytes, firstPageSealImageBytes,
            signingKeyId, contact, location, reason, hashAlgo, tsaEnabled, tsaUrl
        );
    }

    /**
     * 批量处理版本：一次性处理所有骑缝章
     */
    private byte[] signExternalPerPageIncrementalBatch(
            PDDocument initialDoc,
            byte[] perforationImageBytes,
            byte[] firstPageSealImageBytes,
            String signingKeyId,
            String contact,
            String location,
            String reason,
            String hashAlgo,
            boolean tsaEnabled,
            String tsaUrl
    ) throws Exception {
        // 加载密钥材料
        KeyMaterial km = loadPfxMaterial(getCfg("DEFAULT_PFX_PATH"));
        PrivateKey privateKey = km.privateKey();
        Certificate[] chain = km.certificateChain();

        // 优化：缓存和重用图像
        String imgKey = "perf_" + perforationImageBytes.length;
        BufferedImage fullImg = imageCache.computeIfAbsent(imgKey, k -> {
            try {
                return loadBufferedImage(perforationImageBytes);
            } catch (IOException e) {
                throw new RuntimeException(e);
            }
        });

        int imgW = fullImg.getWidth();
        int imgH = fullImg.getHeight();
        float perforationStampHeightMm = 35.0f;
        try {
            String cfgSize = getCfg("PERFORATION_STAMP_HEIGHT_MM");
            if (cfgSize != null && !cfgSize.isBlank()) {
                perforationStampHeightMm = Float.parseFloat(cfgSize.trim());
            }
        } catch (Exception ignore) {}
        float totalStampHeightPt = (float) (perforationStampHeightMm * 2.83465);
        float totalStampWidthPt = totalStampHeightPt * ((float) imgW / (float) imgH);

        // 保存初始文档
        ByteArrayOutputStream bos = new ByteArrayOutputStream();
        initialDoc.save(bos);
        initialDoc.close();
        byte[] current = bos.toByteArray();

        // 处理首页盖章（如果有）
        if (firstPageSealImageBytes != null && firstPageSealImageBytes.length > 0) {
            current = addFirstPageSeal(current, firstPageSealImageBytes, contact, location,
                                      reason, privateKey, chain, hashAlgo, tsaEnabled, tsaUrl);
        }

        // 读取总页数
        int totalPages;
        try (PDDocument probe = Loader.loadPDF(current)) {
            totalPages = probe.getNumberOfPages();
        }

        // 批量处理骑缝章：先添加所有外观，然后一次签名
        if (totalPages <= 10) {
            // 小文档：使用原方法
            return signExternalPerPageIncrementalOriginal(
                Loader.loadPDF(current),
                perforationImageBytes, null, signingKeyId, contact, location,
                reason, hashAlgo, tsaEnabled, tsaUrl
            );
        }

        // 大文档：批量处理
        PDDocument doc = Loader.loadPDF(current);

        try {
            // 创建单个签名覆盖所有页面
            PDSignature signature = createDetachedSignature();
            PDAcroForm acroForm = ensureAcroForm(doc);
            attachPerforationFields(doc, acroForm, signature, fullImg, totalPages, totalStampWidthPt, totalStampHeightPt);

            // 添加签名
            ByteArrayOutputStream signedOut = new ByteArrayOutputStream();
            try (SignatureOptions sigOpts = new SignatureOptions()) {
                int reserved = getSignatureReservedSize();
                sigOpts.setPreferredSignatureSize(reserved);
                doc.addSignature(signature, sigOpts);
                normalizeSignatureAppearanceStates(doc);

                ExternalSigningSupport ext = doc.saveIncrementalForExternalSigning(signedOut);
                byte[] cms = new SimpleSignatureInterface(privateKey, chain, hashAlgo,
                                                         tsaEnabled, tsaUrl).sign(ext.getContent());
                ext.setSignature(cms);
            }

            log.info("Batch perforation signing done. Pages={}, Output bytes={}",
                    totalPages, signedOut.size());
            return signedOut.toByteArray();

        } finally {
            doc.close();
        }
    }

    private void attachPerforationFields(
            PDDocument doc,
            PDAcroForm acroForm,
            PDSignature signature,
            BufferedImage fullImg,
            int totalPages,
            float totalStampWidthPt,
            float totalStampHeightPt
    ) throws IOException {
        boolean useMultiLayerAppearance = isPerforationMultiStateEnabled();
        int imgW = fullImg.getWidth();
        int imgH = fullImg.getHeight();
        float sliceWidthPt = totalStampWidthPt / totalPages;

        for (int pageIndex = 0; pageIndex < totalPages; pageIndex++) {
            PDPage page = doc.getPage(pageIndex);
            PDRectangle box = page.getMediaBox();

            int endPixelX = Math.round(imgW * (pageIndex + 1) / (float) totalPages);
            int startPixelX = (pageIndex == 0) ? 0 : Math.round(imgW * pageIndex / (float) totalPages);
            int sliceWidthPx = Math.max(1, endPixelX - startPixelX);

            BufferedImage slice = fullImg.getSubimage(startPixelX, 0, sliceWidthPx, imgH);
            PDImageXObject sliceImage = LosslessFactory.createFromImage(doc, slice);
            sliceImage.getCOSObject().setItem(COSName.RESOURCES, new COSDictionary());

            float x = box.getWidth() - sliceWidthPt;
            float y = (box.getHeight() - totalStampHeightPt) / 2f;

            PDSignatureField field = new PDSignatureField(acroForm);
            field.setPartialName(randomFieldName());
            PDAnnotationWidget widget = field.getWidgets().get(0);
            widget.setRectangle(new PDRectangle(x, y, sliceWidthPt, totalStampHeightPt));
            widget.setPage(page);
            widget.setPrinted(true);

            applyCompliantSignatureAppearance(doc, widget, sliceImage, sliceWidthPt, totalStampHeightPt, useMultiLayerAppearance);

            page.getAnnotations().add(widget);
            acroForm.getFields().add(field);
            field.setValue(signature);
        }
    }

    /**
     * 添加首页盖章
     */
    private byte[] addFirstPageSeal(byte[] current, byte[] sealImageBytes, String contact,
                                   String location, String reason, PrivateKey privateKey,
                                   Certificate[] chain, String hashAlgo, boolean tsaEnabled,
                                   String tsaUrl) throws Exception {
        return addFrontSealSignatures(current, sealImageBytes, contact, location, reason,
                privateKey, chain, hashAlgo, tsaEnabled, tsaUrl);
    }

    private byte[] addFrontSealSignatures(byte[] current, byte[] sealImageBytes, String contact,
                                          String location, String reason, PrivateKey privateKey,
                                          Certificate[] chain, String hashAlgo, boolean tsaEnabled,
                                          String tsaUrl) throws Exception {
        if (sealImageBytes == null || sealImageBytes.length == 0) {
            return current;
        }

        byte[] updated = addFrontSealToSinglePage(current, sealImageBytes, contact, location,
                reason, privateKey, chain, hashAlgo, tsaEnabled, tsaUrl, 0, randomFieldName());

        // 如果PDF不足一页，直接返回原始结果
        if (updated == null) {
            updated = current;
        }

        // 判断是否需要在第三页添加同样的盖章
        try (PDDocument probe = Loader.loadPDF(updated)) {
            if (probe.getNumberOfPages() >= 3) {
                byte[] thirdPageResult = addFrontSealToSinglePage(updated, sealImageBytes,
                        contact, location, reason, privateKey, chain, hashAlgo,
                        tsaEnabled, tsaUrl, 2, randomFieldName());
                if (thirdPageResult != null) {
                    updated = thirdPageResult;
                }
            } else {
                log.info("Skipping third-page front seal: document has only {} pages",
                        probe.getNumberOfPages());
            }
        }

        return updated;
    }

    private byte[] addFrontSealToSinglePage(byte[] current, byte[] sealImageBytes, String contact,
                                            String location, String reason, PrivateKey privateKey,
                                            Certificate[] chain, String hashAlgo,
                                            boolean tsaEnabled, String tsaUrl, int pageIndex,
                                            String fieldName) throws Exception {
        try (PDDocument doc = Loader.loadPDF(current)) {
            if (doc.getNumberOfPages() <= pageIndex) {
                log.warn("Cannot add front seal to page {} (document has {} pages)",
                        pageIndex + 1, doc.getNumberOfPages());
                return current;
            }

            PDPage page = doc.getPage(pageIndex);
            PDRectangle box = page.getMediaBox();

            PDSignature signature = createDetachedSignature();

            try (SignatureOptions sigOpts = new SignatureOptions()) {
                sigOpts.setPreferredSignatureSize(getSignatureReservedSize());
                sigOpts.setPage(pageIndex);

                float stampSize = resolveFrontSealSizePt();
                float margin = (float) (10 * 2.83465);
                float[] offsets = getFrontSealOffsetsForPage(pageIndex);
                float[] position = computeFrontSealPosition(box, stampSize, margin, offsets[0], offsets[1]);
                float x = position[0];
                float y = position[1];

                InputStream template = createVisualSignatureTemplateStream(
                        box.getWidth(), box.getHeight(),
                        x, y, stampSize, stampSize,
                        sealImageBytes,
                        fieldName
                );
                sigOpts.setVisualSignature(template);

                doc.addSignature(signature, sigOpts);
                normalizeSignatureAppearanceStates(doc);

                ByteArrayOutputStream signedOut = new ByteArrayOutputStream();
                ExternalSigningSupport ext = doc.saveIncrementalForExternalSigning(signedOut);
                byte[] cms = new SimpleSignatureInterface(privateKey, chain, hashAlgo,
                        tsaEnabled, tsaUrl).sign(ext.getContent());
                ext.setSignature(cms);
                log.info("Added front seal on page {} at position ({}, {})",
                        pageIndex + 1, x, y);
                return signedOut.toByteArray();
            }
        }
    }

    private float resolveFrontSealSizePt() {
        float frontSealSizeMm = 35.0f;
        try {
            String cfgSize = getCfg("FRONT_SEAL_SIZE_MM");
            if (cfgSize != null && !cfgSize.isBlank()) {
                frontSealSizeMm = Float.parseFloat(cfgSize.trim());
            }
        } catch (Exception ignore) {}
        return (float) (frontSealSizeMm * 2.83465);
    }

    private float[] computeFrontSealPosition(PDRectangle box, float stampSize,
                                             float margin, float dx, float dy) {
        float x = box.getWidth() - stampSize - margin * 2f;
        float y = box.getHeight() * 0.17f;
        x -= dx;
        y += dy;
        x = Math.max(margin, Math.min(x, box.getWidth() - stampSize - margin));
        y = Math.max(margin, Math.min(y, box.getHeight() - stampSize - margin));
        return new float[] { x, y };
    }

    /**
     * 获取签名预留空间大小
     */
    private int getSignatureReservedSize() {
        try {
            int defaultSize = 512 * 1024;
            String reserved = System.getenv("SIGNATURE_RESERVED_SIZE");
            if (reserved != null && !reserved.isBlank()) {
                return Integer.parseInt(reserved);
            }
            return defaultSize;
        } catch (Exception e) {
            return 512 * 1024;
        }
    }

    private float[] getFrontSealOffsetsForPage(int pageIndex) {
        float defaultLeftMm = getConfiguredFloat("FRONT_SEAL_OFFSET_LEFT_MM", 40.0f);
        float defaultUpMm = getConfiguredFloat("FRONT_SEAL_OFFSET_UP_MM", 10.0f);

        float leftMm = defaultLeftMm;
        float upMm = defaultUpMm;

        if (pageIndex == 2) {
            leftMm = getConfiguredFloat("FRONT_SEAL_PAGE3_OFFSET_LEFT_MM", defaultLeftMm);
            upMm = getConfiguredFloat("FRONT_SEAL_PAGE3_OFFSET_UP_MM", defaultUpMm);
        }

        return new float[] {
            (float) (leftMm * 2.83465),
            (float) (upMm * 2.83465)
        };
    }

    private float getConfiguredFloat(String key, float fallback) {
        try {
            String value = getCfg(key);
            if (value != null && !value.isBlank()) {
                return Float.parseFloat(value.trim());
            }
        } catch (Exception ignore) {}
        return fallback;
    }

    /**
     * 原始逐页签名实现（保留作为后备）
     */
    private byte[] signExternalPerPageIncrementalOriginal(
            PDDocument initialDoc,
            byte[] perforationImageBytes,
            byte[] firstPageSealImageBytes,
            String signingKeyId,
            String contact,
            String location,
            String reason,
            String hashAlgo,
            boolean tsaEnabled,
            String tsaUrl
    ) throws Exception {
        // 1) 加载密钥材料（仅 PFX）
        KeyMaterial km2 = loadPfxMaterial(getCfg("DEFAULT_PFX_PATH"));
        PrivateKey privateKey = km2.privateKey();
        Certificate[] chain = km2.certificateChain();

        // 2) 计算骑缝章几何参数（总高默认35mm，可配置，宽度按原图等比）
        java.awt.image.BufferedImage fullImg = loadBufferedImage(perforationImageBytes);
        int imgW = fullImg.getWidth();
        int imgH = fullImg.getHeight();
        float perforationStampHeightMm = 35.0f;
        try {
            String cfgSize = getCfg("PERFORATION_STAMP_HEIGHT_MM");
            if (cfgSize != null && !cfgSize.isBlank()) {
                perforationStampHeightMm = Float.parseFloat(cfgSize.trim());
            }
        } catch (Exception ignore) {}
        float totalStampHeightPt = (float) (perforationStampHeightMm * 2.83465);
        float totalStampWidthPt = totalStampHeightPt * ((float) imgW / (float) imgH);

        // 3) 以字节为中间形态逐页增量签名
        ByteArrayOutputStream bos = new ByteArrayOutputStream();
        initialDoc.save(bos);
        initialDoc.close();
        byte[] current = bos.toByteArray();

        // 可选：先对首页盖章做一次增量签名（可见签名域）
        if (firstPageSealImageBytes != null && firstPageSealImageBytes.length > 0) {
            current = addFrontSealSignatures(current, firstPageSealImageBytes, contact,
                    location, reason, privateKey, chain, hashAlgo, tsaEnabled, tsaUrl);
        }

        try (PDDocument doc = Loader.loadPDF(current)) {
            int totalPages = doc.getNumberOfPages();
            PDSignature signature = createDetachedSignature();
            PDAcroForm acroForm = ensureAcroForm(doc);

            attachPerforationFields(doc, acroForm, signature, fullImg, totalPages, totalStampWidthPt, totalStampHeightPt);

            ByteArrayOutputStream signedOut = new ByteArrayOutputStream();
            try (SignatureOptions sigOpts = new SignatureOptions()) {
                try {
                    int defaultSize = 512 * 1024;
                    int reserved = Integer.parseInt(System.getenv().getOrDefault("SIGNATURE_RESERVED_SIZE", String.valueOf(defaultSize)));
                    sigOpts.setPreferredSignatureSize(reserved);
                } catch (Exception ignore) {}

                doc.addSignature(signature, sigOpts);

                ExternalSigningSupport ext = doc.saveIncrementalForExternalSigning(signedOut);
                byte[] cms = new SimpleSignatureInterface(privateKey, chain, hashAlgo, tsaEnabled, tsaUrl).sign(ext.getContent());
                ext.setSignature(cms);
            }
            current = signedOut.toByteArray();
        }

        log.info("Per-page shared signature done. Output bytes={}", current.length);
        return current;
    }

    private PDSignature createDetachedSignature() {
        PDSignature signature = new PDSignature();
        signature.setFilter(PDSignature.FILTER_ADOBE_PPKLITE);
        signature.setSubFilter(PDSignature.SUBFILTER_ADBE_PKCS7_DETACHED);
        signature.setSignDate(java.util.Calendar.getInstance());
        return signature;
    }

    private PDAcroForm ensureAcroForm(PDDocument doc) {
        PDAcroForm acroForm = doc.getDocumentCatalog().getAcroForm();
        if (acroForm == null) {
            acroForm = new PDAcroForm(doc);
            doc.getDocumentCatalog().setAcroForm(acroForm);
        }
        acroForm.setSignaturesExist(true);
        acroForm.setAppendOnly(true);
        acroForm.getCOSObject().setDirect(true);
        if (acroForm.getDefaultResources() == null) {
            acroForm.setDefaultResources(new PDResources());
        }
        return acroForm;
    }

    private void normalizeSignatureAppearanceStates(PDDocument doc) {
        PDAcroForm acroForm = doc.getDocumentCatalog().getAcroForm();
        if (acroForm == null) {
            return;
        }
        for (PDField field : acroForm.getFieldTree()) {
            if (field instanceof PDSignatureField sigField) {
                for (PDAnnotationWidget widget : sigField.getWidgets()) {
                    COSDictionary widgetDict = widget.getCOSObject();
                    COSDictionary ap = (COSDictionary) widgetDict.getDictionaryObject(COSName.AP);
                    if (ap == null) {
                        continue;
                    }
                    COSBase nEntry = ap.getDictionaryObject(COSName.N);
                    COSDictionary resources = null;
                    if (nEntry instanceof COSObject cosObj) {
                        nEntry = cosObj.getObject();
                    }
                    if (nEntry instanceof COSStream stream) {
                        resources = (COSDictionary) stream.getDictionaryObject(COSName.RESOURCES);
                    } else if (nEntry instanceof COSDictionary dict) {
                        resources = (COSDictionary) dict.getDictionaryObject(COSName.RESOURCES);
                    }
                    boolean hasValidState = false;
                    if (resources != null) {
                        COSDictionary xObjects = (COSDictionary) resources.getDictionaryObject(COSName.XOBJECT);
                        if (xObjects != null && xObjects.containsKey(COSName.getPDFName("n2"))) {
                            hasValidState = true;
                        }
                    }
                    if (hasValidState && widgetDict.getNameAsString(COSName.AS) == null) {
                        widgetDict.setName(COSName.AS, "n2");
                    }
                }
            }
        }
    }

    private PDAppearanceStream createBlankFormStream(PDDocument doc, PDRectangle bbox) {
        PDAppearanceStream stream = new PDAppearanceStream(doc);
        stream.setBBox(new PDRectangle(bbox.getWidth(), bbox.getHeight()));
        stream.getCOSObject().setName(COSName.TYPE, COSName.XOBJECT.getName());
        stream.getCOSObject().setName(COSName.SUBTYPE, COSName.FORM.getName());
        stream.setResources(new PDResources());
        return stream;
    }

    private PDAppearanceStream createStateStream(
            PDDocument doc,
            PDImageXObject image,
            float width,
            float height,
            boolean invalid
    ) throws IOException {
        PDAppearanceStream stream = new PDAppearanceStream(doc);
        stream.setBBox(new PDRectangle(width, height));
        stream.getCOSObject().setName(COSName.TYPE, COSName.XOBJECT.getName());
        stream.getCOSObject().setName(COSName.SUBTYPE, COSName.FORM.getName());
        PDResources resources = new PDResources();
        stream.setResources(resources);
        if (image != null) {
            COSName imgName = COSName.getPDFName("IMG");
            resources.put(imgName, image);
        }
        try (PDPageContentStream cs = new PDPageContentStream(doc, stream)) {
            if (image != null) {
                cs.drawImage(image, 0, 0, width, height);
            }
            if (invalid) {
                float base = Math.min(width, height);
                float thickness = Math.max(base * 0.18f, 1.2f);
                cs.setLineWidth(thickness);
                cs.setLineCapStyle(1); // round caps keep the stroke centered at the corners
                cs.setLineJoinStyle(1); // round joins avoid sharp spikes at the crossing
                cs.setStrokingColor(java.awt.Color.RED);
                float inset = thickness * 0.55f;
                float topY = Math.max(height - inset, inset);
                float bottomY = inset;
                float leftX = inset;
                float rightX = Math.max(width - inset, inset);
                cs.moveTo(leftX, topY);
                cs.lineTo(rightX, bottomY);
                cs.moveTo(rightX, topY);
                cs.lineTo(leftX, bottomY);
                cs.stroke();

                float borderThickness = Math.max(base * 0.05f, 0.8f);
                cs.setLineWidth(borderThickness);
                cs.setStrokingColor(java.awt.Color.BLACK);
                float borderInset = borderThickness * 0.5f;
                cs.addRect(borderInset, borderInset, width - borderInset * 2, height - borderInset * 2);
                cs.stroke();
            }
        }
        return stream;
    }

    private String randomFieldName() {
        return UUID.randomUUID().toString();
    }

    private String getCfg(String name) {
        String v = System.getenv(name);
        if (v == null || v.isBlank()) v = System.getProperty(name);
        return (v == null || v.isBlank()) ? null : v;
    }

    private boolean isPerforationMultiStateEnabled() {
        String raw = getCfg("PERFORATION_MULTI_STATE_APPEARANCE");
        if (raw == null) {
            return true;
        }
        String normalized = raw.trim();
        if (normalized.isEmpty()) {
            return true;
        }
        return !(normalized.equalsIgnoreCase("false")
                || normalized.equalsIgnoreCase("off")
                || normalized.equals("0"));
    }

    private static String resolveDefaultPfxPath() {
        File dockerPath = new File("/keys/signer.pfx");
        if (dockerPath.exists()) return dockerPath.getPath();
        File localPath = new File("keys/signer.pfx");
        if (localPath.exists()) return localPath.getPath();
        return "/keys/signer.pfx";
    }

    private KeyMaterial loadPfxMaterial(String pfxEnvPath) throws Exception {
        KeyStore ks = KeyStore.getInstance("PKCS12");
        String pfxPath = (pfxEnvPath != null && !pfxEnvPath.isBlank()) ? pfxEnvPath : resolveDefaultPfxPath();
        String pfxPass = getCfg("DEFAULT_PFX_PASS");
        if (pfxPass == null) pfxPass = "changeit";
        try (InputStream is = new FileInputStream(pfxPath)) {
            ks.load(is, pfxPass.toCharArray());
        }
        String alias = ks.aliases().nextElement();
        PrivateKey privateKey = (PrivateKey) ks.getKey(alias, pfxPass.toCharArray());
        Certificate[] chain = ks.getCertificateChain(alias);
        return new KeyMaterial(privateKey, chain);
    }

    private record KeyMaterial(PrivateKey privateKey, Certificate[] certificateChain) {}

    // 可见签名外观将于后续版本完善

    private File toTempFile(MultipartFile f) throws IOException {
        File tmp = File.createTempFile("pdf_input_", ".pdf");
        try (OutputStream os = new FileOutputStream(tmp)) {
            f.getInputStream().transferTo(os);
        }
        return tmp;
    }

    private java.io.InputStream createVisualSignatureTemplateStream(
            float pageWidth, float pageHeight,
            float x, float y, float w, float h,
            byte[] sigImageBytes) throws java.io.IOException {
        return createVisualSignatureTemplateStream(pageWidth, pageHeight, x, y, w, h, sigImageBytes, randomFieldName());
    }

    private java.io.InputStream createVisualSignatureTemplateStream(
            float pageWidth, float pageHeight,
            float x, float y, float w, float h,
            byte[] sigImageBytes,
            String fieldPartialName) throws java.io.IOException {

        PDDocument templateDoc = new PDDocument();
        try {
            PDPage templatePage = new PDPage(new PDRectangle(pageWidth, pageHeight));
            templateDoc.addPage(templatePage);

            PDAcroForm acroForm = new PDAcroForm(templateDoc);
            templateDoc.getDocumentCatalog().setAcroForm(acroForm);
            acroForm.setSignaturesExist(true);
            acroForm.setAppendOnly(true);
            acroForm.getCOSObject().setDirect(true);

            PDSignatureField signatureField = new PDSignatureField(acroForm);
            signatureField.setPartialName(fieldPartialName);
            PDAnnotationWidget widget = signatureField.getWidgets().get(0);
            widget.setRectangle(new PDRectangle(x, y, w, h));
            widget.setPage(templatePage);
            widget.setPrinted(true);

            PDImageXObject pdImage = null;
            if (sigImageBytes != null && sigImageBytes.length > 0) {
                BufferedImage img = ImageIO.read(new ByteArrayInputStream(sigImageBytes));
                if (img != null) {
                    pdImage = LosslessFactory.createFromImage(templateDoc, img);
                }
            }

            applyCompliantSignatureAppearance(templateDoc, widget, pdImage, w, h, true);

            templatePage.getAnnotations().add(widget);
            acroForm.getFields().add(signatureField);

            ByteArrayOutputStream baos = new ByteArrayOutputStream();
            templateDoc.save(baos);
            return new ByteArrayInputStream(baos.toByteArray());
        } finally {
            templateDoc.close();
        }
    }

    /**
     * 将证书查询二维码添加到PDF第一页右上角
     * @param doc PDF文档
     * @param qrCodeBytes 二维码图片字节数据
     * @return 更新后的PDF文档
     * @throws IOException
     */
    private PDDocument addQrCodeToFirstPage(PDDocument doc, byte[] qrCodeBytes) throws IOException {
        if (doc.getNumberOfPages() == 0) {
            log.warn("PDF has no pages, cannot add QR code");
            return doc;
        }

        PDPage firstPage = doc.getPage(0);
        PDRectangle pageBox = firstPage.getMediaBox();

        // 获取二维码配置参数
        float qrCodeSizeMm = getConfiguredFloat("QR_CODE_SIZE_MM", 15.6f); // 默认约56点（15.6mm）
        float marginMm = getConfiguredFloat("QR_CODE_MARGIN_MM", 10.0f); // 默认10mm边距
        float offsetLeftMm = getConfiguredFloat("QR_CODE_OFFSET_LEFT_MM", 0.0f); // 额外左偏移
        String offsetTopRaw = getCfg("QR_CODE_OFFSET_TOP_MM");
        boolean offsetProvided = offsetTopRaw != null && !offsetTopRaw.isBlank();
        float offsetTopMm = 0.0f;
        if (offsetProvided) {
            try {
                offsetTopMm = Float.parseFloat(offsetTopRaw.trim());
            } catch (NumberFormatException nfe) {
                log.warn("Invalid QR_CODE_OFFSET_TOP_MM value '{}', fallback to 0", offsetTopRaw);
                offsetTopMm = 0.0f;
                offsetProvided = false;
            }
        }

        float qrCodeSizePt = (float) (qrCodeSizeMm * 2.83465);
        float marginPt = (float) (marginMm * 2.83465);
        float offsetLeftPt = (float) (offsetLeftMm * 2.83465);
        float offsetTopPt = (float) (offsetTopMm * 2.83465);

        // 计算右上角位置（距离右边marginMm，垂直方向依据配置：默认顶部，配置后按距底部偏移）
        float x = pageBox.getWidth() - qrCodeSizePt - marginPt - offsetLeftPt;
        float y;
        if (offsetProvided) {
            y = marginPt + offsetTopPt;
            float maxY = pageBox.getHeight() - qrCodeSizePt - marginPt;
            if (y > maxY) {
                log.warn("QR code bottom offset {}pt exceeds available height, clamping to {}", y, maxY);
                y = maxY;
            }
        } else {
            y = pageBox.getHeight() - qrCodeSizePt - marginPt;
        }

        log.info("Adding QR code to first page at position: x={}, y={}, size={}pt ({}mm), bottomOffsetProvided={}",
                 x, y, qrCodeSizePt, qrCodeSizeMm, offsetProvided);

        try (PDPageContentStream cs = new PDPageContentStream(doc, firstPage, AppendMode.APPEND, true, true)) {
            // 加载二维码图片
            BufferedImage qrImage = ImageIO.read(new ByteArrayInputStream(qrCodeBytes));
            if (qrImage == null) {
                log.error("Failed to load QR code image");
                return doc;
            }

            PDImageXObject pdImage = LosslessFactory.createFromImage(doc, qrImage);
            // 关键修复：为Image XObject添加空的Resources字典，避免Acrobat报错
            pdImage.getCOSObject().setItem(COSName.RESOURCES, new COSDictionary());

            // 绘制二维码到PDF
            cs.drawImage(pdImage, x, y, qrCodeSizePt, qrCodeSizePt);

            log.info("QR code successfully added to first page");
        }

        return doc;
    }

    /**
     * 从PDF文件中提取完整的封面信息
     * @param pdfFile PDF文件
     * @return 封面信息，如果提取失败则返回null
     */
    private CoverExtractionResponse extractCoverFields(File pdfFile) {
        try {
            log.info("Extracting cover fields from PDF: {}", pdfFile.getAbsolutePath());

            // 使用PdfCoverExtractor提取封面信息
            try (java.io.FileInputStream fis = new java.io.FileInputStream(pdfFile)) {
                PdfCoverExtractor extractor = new PdfCoverExtractor();
                CoverExtractionResponse response = extractor.extract(fis);

                if (response.reportNumber() != null && !response.reportNumber().isBlank()) {
                    log.info("Successfully extracted cover fields: reportNumber={}, productName={}, modelSpecification={}, entrustCompany={}, testItems={}, reportDate={}",
                            response.reportNumber(), response.productName(), response.modelSpecification(),
                            response.entrustCompany(), response.testItems(), response.reportDate());
                    return response;
                } else {
                    log.info("No report number found in PDF cover");
                    return response; // 返回响应，即使为空也供metadata使用
                }
            }
        } catch (Exception e) {
            log.error("Failed to extract cover fields from PDF: {}", pdfFile.getAbsolutePath(), e);
            return null;
        }
    }

    /**
     * 将封面信息写入PDF metadata，供外部系统使用
     * @param doc PDF文档
     * @param coverFields 封面信息
     */
    private void writeCoverFieldsToMetadata(PDDocument doc, CoverExtractionResponse coverFields) {
        try {
            // 使用PDFBox的XMP metadata写入封面信息
            // 创建自定义metadata
            StringBuilder metadataXml = new StringBuilder();
            metadataXml.append("<?xml version=\"1.0\" encoding=\"UTF-8\"?>");
            metadataXml.append("<pdfCoverFields>");
            metadataXml.append("<reportNumber>").append(escapeXml(coverFields.reportNumber())).append("</reportNumber>");
            metadataXml.append("<productName>").append(escapeXml(coverFields.productName())).append("</productName>");
            metadataXml.append("<modelSpecification>").append(escapeXml(coverFields.modelSpecification())).append("</modelSpecification>");
            metadataXml.append("<entrustCompany>").append(escapeXml(coverFields.entrustCompany())).append("</entrustCompany>");
            metadataXml.append("<testItems>").append(escapeXml(coverFields.testItems())).append("</testItems>");
            metadataXml.append("<reportDate>").append(escapeXml(coverFields.reportDate())).append("</reportDate>");
            metadataXml.append("</pdfCoverFields>");

            // 写入到PDF的Info字典
            doc.getDocumentInformation().setCustomMetadataValue("CoverFields", metadataXml.toString());

            log.info("Cover fields written to PDF metadata");
        } catch (Exception e) {
            log.error("Failed to write cover fields to metadata", e);
            // 不抛出异常，继续处理
        }
    }

    /**
     * 转义XML特殊字符
     * @param str 原始字符串
     * @return 转义后的字符串
     */
    private String escapeXml(String str) {
        if (str == null) {
            return "";
        }
        return str.replace("&", "&amp;")
                  .replace("<", "&lt;")
                  .replace(">", "&gt;")
                  .replace("\"", "&quot;")
                  .replace("'", "&apos;");
    }

    /**
     * 使用ZXing生成二维码
     * @param data 要编码的数据
     * @param width 宽度
     * @param height 高度
     * @return PNG格式的二维码字节数组
     * @throws Exception
     */
    private byte[] generateQrCode(String data, int width, int height) throws Exception {
        log.info("Generating QR code for data: {} (size: {}x{})", data, width, height);

        try {
            com.google.zxing.qrcode.QRCodeWriter qrCodeWriter = new com.google.zxing.qrcode.QRCodeWriter();
            com.google.zxing.BarcodeFormat format = com.google.zxing.BarcodeFormat.QR_CODE;

            com.google.zxing.common.BitMatrix bitMatrix = qrCodeWriter.encode(
                data,
                format,
                width,
                height
            );

            // 转换为PNG
            java.io.ByteArrayOutputStream outputStream = new java.io.ByteArrayOutputStream();
            com.google.zxing.client.j2se.MatrixToImageWriter.writeToStream(bitMatrix, "PNG", outputStream);
            byte[] imageBytes = outputStream.toByteArray();

            log.info("QR code generated successfully, size: {} bytes", imageBytes.length);
            return imageBytes;
        } catch (Exception e) {
            log.error("Failed to generate QR code", e);
            throw new Exception("QR code generation failed: " + e.getMessage(), e);
        }
    }

    /**
     * 获取二维码生成的像素尺寸配置
     * @return 二维码像素尺寸数组 [width, height]
     */
    private int[] getQrCodePixelSize() {
        int defaultWidth = 200;
        int defaultHeight = 200;

        try {
            String widthStr = getCfg("QR_CODE_WIDTH_PX");
            String heightStr = getCfg("QR_CODE_HEIGHT_PX");

            int width = widthStr != null && !widthStr.isBlank()
                ? Integer.parseInt(widthStr.trim())
                : defaultWidth;

            int height = heightStr != null && !heightStr.isBlank()
                ? Integer.parseInt(heightStr.trim())
                : defaultHeight;

            // 确保尺寸为正数
            width = Math.max(width, 10);
            height = Math.max(height, 10);

            return new int[] { width, height };
        } catch (Exception e) {
            log.warn("Failed to parse QR_CODE pixel size from config, using defaults: {}x{}", defaultWidth, defaultHeight);
            return new int[] { defaultWidth, defaultHeight };
        }
    }

    /**
     * 清理PDF文档元信息
     * 删除或替换原始PDF中的元信息，避免使用上传文件的标题、作者等信息
     * @param doc PDF文档
     * @param coverFields 封面信息，用于生成合适的元信息
     */
    private void cleanPdfMetadata(PDDocument doc, CoverExtractionResponse coverFields) {
        try {
            var docInfo = doc.getDocumentInformation();

            // 保存原始信息用于日志
            String originalTitle = docInfo.getTitle();
            String originalAuthor = docInfo.getAuthor();
            String originalCreator = docInfo.getCreator();
            String originalProducer = docInfo.getProducer();

            // 清理或设置新的元信息
            if (coverFields != null && coverFields.reportNumber() != null && !coverFields.reportNumber().isBlank()) {
                // 如果有报告编号，使用它作为标题
                docInfo.setTitle(coverFields.reportNumber());
            } else {
                // 否则使用通用标题
                docInfo.setTitle("检测报告");
            }

            // 作者：设置为检测机构，而不是原始作者
            docInfo.setAuthor("中山市鑫达普检测技术有限公司");

            // 创建者：设置为系统名称，而不是原始创建者
            docInfo.setCreator("鑫达普LIMS系统");

            // 生产者：设置为系统信息
            docInfo.setProducer("鑫达普PDF处理服务");

            // 主题：设置为检测报告
            docInfo.setSubject("检测报告文件");

            // 关键词：设置为相关关键词
            docInfo.setKeywords("检测,报告,认证,质量");

            log.info("PDF metadata cleaned - Original: Title='{}', Author='{}', Creator='{}', Producer='{}'",
                    originalTitle, originalAuthor, originalCreator, originalProducer);
            log.info("PDF metadata cleaned - New: Title='{}', Author='{}', Creator='{}', Producer='{}'",
                    docInfo.getTitle(), docInfo.getAuthor(), docInfo.getCreator(), docInfo.getProducer());

        } catch (Exception e) {
            log.warn("Failed to clean PDF metadata", e);
            // 不抛出异常，继续PDF处理流程
        }
    }
}
