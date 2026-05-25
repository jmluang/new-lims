package com.luang.pdfsigner.service;

import com.luang.pdfsigner.service.renderer.ContractPdfDrawUtils;
import com.luang.pdfsigner.service.renderer.ContractPdfPageContext;
import com.luang.pdfsigner.service.renderer.PageFiveRenderer;
import com.luang.pdfsigner.service.renderer.PageFourRenderer;
import com.luang.pdfsigner.service.renderer.PageOneRenderer;
import com.luang.pdfsigner.service.renderer.PageSevenRenderer;
import com.luang.pdfsigner.service.renderer.PageSixRenderer;
import com.luang.pdfsigner.service.renderer.PageThreeRenderer;
import com.luang.pdfsigner.service.renderer.PageTwoRenderer;
import java.io.ByteArrayOutputStream;
import java.io.IOException;
import java.io.InputStream;
import java.nio.file.Files;
import java.nio.file.Path;
import java.nio.file.Paths;
import java.net.URL;
import java.util.Map;
import org.apache.pdfbox.Loader;
import org.apache.pdfbox.multipdf.PDFMergerUtility;
import org.apache.pdfbox.pdmodel.PDDocument;
import org.apache.pdfbox.pdmodel.PDPage;
import org.apache.pdfbox.pdmodel.PDPageContentStream;
import org.apache.pdfbox.pdmodel.PDPageContentStream.AppendMode;
import org.apache.pdfbox.pdmodel.font.PDFont;
import org.slf4j.Logger;
import org.slf4j.LoggerFactory;
import org.springframework.stereotype.Service;
import com.luang.pdfsigner.service.renderer.Gb70001FormRenderer;

@Service
public class ContractPdfRenderer {

    private static final Logger log = LoggerFactory.getLogger(ContractPdfRenderer.class);

    private static final float PAGE_WIDTH = ContractPdfAssets.PAGE_WIDTH;
    private static final float PAGE_HEIGHT = ContractPdfAssets.PAGE_HEIGHT;
    private static final float CONTENT_TOP = PAGE_HEIGHT - ContractPdfAssets.mmToPt(55);
    private static final float CONTENT_BOTTOM = ContractPdfAssets.mmToPt(38);

    private static final float HEADER_COMPANY_X = 76.26f;
    private static final float HEADER_COMPANY_Y = 785.34f;
    private static final float HEADER_CODE_X = 442.20f;
    private static final float HEADER_CODE_Y = 785.02f;
    private static final float HEADER_BADGE_X = 478.74f;
    private static final float HEADER_BADGE_Y = 648.30f;

    private static final float FOOTER_COMPANY_X = 199.68f;
    private static final float FOOTER_COMPANY_Y = 45.38f;
    private static final float FOOTER_PAGE_INFO_Y = 44.23f;
    private static final float FOOTER_ADDRESS_X = 72f;
    private static final float FOOTER_ADDRESS_Y = 30.23f;
    private static final float FOOTER_WEBSITE_X = 260f;
    private static final float FOOTER_WEBSITE_Y = 30.23f;
    private static final float FOOTER_PHONE_Y = 30.23f;
    private static final float FOOTER_CODE_Y = 16.61f;

    private static final float HEADER_RULE_START_X = 60f;
    private static final float HEADER_RULE_END_X = PAGE_WIDTH - 60f;
    private static final float HEADER_RULE_Y = 780f;

    private static final float FOOTER_RULE_START_X = 60f;
    private static final float FOOTER_RULE_END_X = PAGE_WIDTH - 60f;
    private static final float FOOTER_RULE_Y = 60f;

    private static final float HEADER_COMPANY_FONT_SIZE = 16f;
    private static final float HEADER_CODE_FONT_SIZE = 14f;
    private static final float HEADER_BADGE_FONT_SIZE = 12f;
    private static final float FOOTER_FONT_SIZE = 10.5f;
    private static final float FOOTER_PAGE_INFO_FONT_SIZE = 9f;

    private final PageOneRenderer pageOneRenderer = new PageOneRenderer();
    private final PageTwoRenderer pageTwoRenderer = new PageTwoRenderer();
    private final PageThreeRenderer pageThreeRenderer = new PageThreeRenderer();
    private final PageFourRenderer pageFourRenderer = new PageFourRenderer();
    private final PageFiveRenderer pageFiveRenderer = new PageFiveRenderer();
    private final PageSixRenderer pageSixRenderer = new PageSixRenderer();
    private final PageSevenRenderer pageSevenRenderer = new PageSevenRenderer();
    private final Gb70001FormRenderer gb70001FormRenderer = new Gb70001FormRenderer();

    public byte[] render(ContractPdfPayload payload) throws IOException {
        ContractPdfPayload safePayload = payload != null ? payload : ContractPdfPayload.sample();
        ContractPdfPayload sample = ContractPdfPayload.sample();

        ContractPdfPayload.PageOne cover = safePayload.page1() != null
                ? safePayload.page1().withDefaults()
                : ContractPdfPayload.PageOne.sample().withDefaults();
        ContractPdfPayload.PageTwo toc = resolvePageTwo(safePayload);
        ContractPdfPayload.PageThree page3 = safePayload.page3() != null ? safePayload.page3() : sample.page3();
        ContractPdfPayload.PageFour page4 = safePayload.page4() != null
                ? safePayload.page4().withDefaults()
                : sample.page4().withDefaults();
        ContractPdfPayload.PageFive page5 = safePayload.page5() != null ? safePayload.page5() : sample.page5();
        ContractPdfPayload.PageSix page6 = safePayload.page6() != null ? safePayload.page6() : sample.page6();
        ContractPdfPayload.PageSeven page7 = safePayload.page7() != null ? safePayload.page7() : sample.page7();
        var templates = safePayload.templates();
        var templateData = safePayload.templateData();

        try (PDDocument document = new PDDocument()) {
            PDFont font = ContractPdfAssets.loadPrimaryFont(document);
            ContractPdfDrawUtils utils = new ContractPdfDrawUtils(document, font);
            ContractPdfPageContext pageContext = new ContractPdfPageContext(document, CONTENT_TOP, CONTENT_BOTTOM);

            pageOneRenderer.render(document, safePayload, cover, pageContext, utils);
            pageTwoRenderer.render(document, safePayload, toc, pageContext, utils);
            pageThreeRenderer.render(document, safePayload, page3, pageContext, utils);
            pageFourRenderer.render(document, safePayload, page4, pageContext, utils);
            pageSixRenderer.render(document, safePayload, page6, pageContext, utils, page7);

            // 只有当有真实图片时才渲染样品照片页面
            if (hasRealImages(page5)) {
                pageFiveRenderer.render(document, safePayload, page5, pageContext, utils);
            }

        if (templates != null) {
            for (var tpl : templates) {
                if (renderCustomTemplate(document, tpl, templateData, safePayload, pageContext, utils)) {
                    continue;
                }
                applyTemplate(document, tpl);
                }
            }

            int finalPages = document.getNumberOfPages();
            ContractPdfPayload.PageOne headerWithTotal = cover.withTotalPages(finalPages);

            applyHeaderFooter(document, utils, headerWithTotal);

            // 设置PDF元信息
            setPdfMetadata(document, headerWithTotal);

            ByteArrayOutputStream out = new ByteArrayOutputStream();
            document.save(out);
            return out.toByteArray();
        }
    }

    private ContractPdfPayload.PageTwo resolvePageTwo(ContractPdfPayload payload) {
        if (payload.page2() != null) {
            return payload.page2().withDefaults();
        }
        return new ContractPdfPayload.PageTwo(payload.toc()).withDefaults();
    }

    private void applyTemplate(PDDocument document, ContractPdfPayload.Template template) throws IOException {
        if (template == null || template.type() == null) {
            return;
        }

        String type = template.type().trim().toLowerCase();
        if ("pdf_append".equals(type)) {
            String pdfPath = resolveTemplatePath(template);
            if (pdfPath == null) {
                log.warn("Template type pdf_append configured but pdf path missing for {}", template.code());
                return;
            }

            PDDocument extra = loadExternalPdf(pdfPath);
            if (extra == null) {
                log.warn("Template pdf could not be loaded for {}", template.code());
                return;
            }

            try {
                extra.setAllSecurityToBeRemoved(true);
                PDFMergerUtility merger = new PDFMergerUtility();
                merger.appendDocument(document, extra);
                log.info("Appended {} pages from template {}", extra.getNumberOfPages(), template.code());
            } finally {
                extra.close();
            }
        }
    }

    private String resolveTemplatePath(ContractPdfPayload.Template template) {
        String userProvided = template.pdfPath();
        if (userProvided != null && !userProvided.isBlank()) {
            return userProvided;
        }
        String configured = template.configPdfPath();
        if (configured != null && !configured.isBlank()) {
            return configured;
        }
        if (template.pdfUrl() != null && !template.pdfUrl().isBlank()) {
            return template.pdfUrl();
        }
        if (template.data() != null) {
            Object url = template.data().get("pdf_url");
            if (url instanceof String && !((String) url).isBlank()) {
                return (String) url;
            }
        }
        return null;
    }

    private PDDocument loadExternalPdf(String location) throws IOException {
        if (location == null || location.isBlank()) {
            return null;
        }
        if (location.startsWith("http://") || location.startsWith("https://")) {
            try (InputStream stream = new URL(location).openStream()) {
                byte[] bytes = stream.readAllBytes();
                if (bytes.length == 0) {
                    return null;
                }
                return Loader.loadPDF(bytes);
            }
        }

        Path path = Paths.get(location);
        if (!Files.exists(path)) {
            return null;
        }
        return Loader.loadPDF(path.toFile());
    }

    private boolean renderCustomTemplate(PDDocument document,
                                         ContractPdfPayload.Template template,
                                         Map<String, Object> templateData,
                                         ContractPdfPayload payload,
                                         ContractPdfPageContext pageContext,
                                         ContractPdfDrawUtils utils) throws IOException {
        if (template == null || template.type() == null) {
            return false;
        }
        String key = template.rendererKey() != null ? template.rendererKey().toLowerCase() : "";
        if ("gb70001-2015-2".equals(key)) {
            Map<String, Object> perTemplate = null;
            if (templateData != null && template.code() != null) {
                Object node = templateData.get(template.code());
                if (node instanceof Map<?, ?> map) {
                    perTemplate = (Map<String, Object>) map;
                }
            }
            gb70001FormRenderer.render(document, payload, perTemplate, pageContext, utils);
            String pdfPath = resolveTemplatePath(template);
            if (pdfPath != null) {
                PDDocument extra = loadExternalPdf(pdfPath);
                if (extra != null) {
                    try {
                        extra.setAllSecurityToBeRemoved(true);
                        PDFMergerUtility merger = new PDFMergerUtility();
                        merger.appendDocument(document, extra);
                        log.info("Appended GB70001-2015-2 extra pdf pages {}", extra.getNumberOfPages());
                    } finally {
                        extra.close();
                    }
                }
            }
            return true;
        }
        return false;
    }

    private void applyHeaderFooter(PDDocument document, ContractPdfDrawUtils utils, ContractPdfPayload.PageOne headerData) throws IOException {
        PDFont font = utils.font();
        int totalPages = document.getNumberOfPages();
        int desiredTotal = headerData.totalPages() != null && headerData.totalPages() > 0
                ? Math.max(headerData.totalPages(), totalPages)
                : totalPages;

        for (int i = 0; i < totalPages; i++) {
            PDPage page = document.getPage(i);
            try (PDPageContentStream content = new PDPageContentStream(document, page, AppendMode.APPEND, true, true)) {
                drawHeader(content, font, headerData);
                drawFooter(content, font, headerData, i + 1, desiredTotal);
            }
        }
    }

    private void drawHeader(PDPageContentStream content, PDFont font, ContractPdfPayload.PageOne data) throws IOException {
        // 页眉白色遮罩，防止外部模板内容干扰
        content.saveGraphicsState();
        content.setNonStrokingColor(1f);
        float maskHeight = 120f;
        float maskBottomY = HEADER_RULE_Y - 20f;
        content.addRect(0, maskBottomY, PAGE_WIDTH, maskHeight);
        content.fill();
        content.restoreGraphicsState();

        showText(content, font, HEADER_COMPANY_FONT_SIZE, HEADER_COMPANY_X, HEADER_COMPANY_Y, data.headerCompanyName());
        drawRightAlignedText(content, font, HEADER_CODE_FONT_SIZE, HEADER_RULE_END_X, HEADER_CODE_Y, data.headerReportNumber());
        // Badge removed per latest spec

        drawHorizontalRule(content, HEADER_RULE_START_X, HEADER_RULE_END_X, HEADER_RULE_Y);
    }

    private void drawFooter(PDPageContentStream content, PDFont font, ContractPdfPayload.PageOne data, int currentPage, int totalPages) throws IOException {
        // 底部白色遮罩，防止覆盖外部模板内容
        content.saveGraphicsState();
        content.setNonStrokingColor(1f); // 白色
        content.addRect(0, 0, PAGE_WIDTH, FOOTER_RULE_Y + 20f);
        content.fill();
        content.restoreGraphicsState();

        drawHorizontalRule(content, FOOTER_RULE_START_X, FOOTER_RULE_END_X, FOOTER_RULE_Y);

        showText(content, font, FOOTER_FONT_SIZE, FOOTER_COMPANY_X, FOOTER_COMPANY_Y, data.footerCompanyName());
        drawRightAlignedText(content, font, FOOTER_PAGE_INFO_FONT_SIZE, FOOTER_RULE_END_X, FOOTER_PAGE_INFO_Y,
                String.format("第 %d 页 共 %d 页", currentPage, totalPages));

        // Address + Website + Phone dynamic layout
        String address = safeText(data.footerAddress());
        String website = safeText(data.footerWebsite());
        String phone = safeText(data.footerPhone());

        showText(content, font, FOOTER_FONT_SIZE, FOOTER_ADDRESS_X, FOOTER_ADDRESS_Y, address);

        float addressEndX = FOOTER_ADDRESS_X + measureText(font, FOOTER_FONT_SIZE, address);
        float phoneWidth = measureText(font, FOOTER_FONT_SIZE, phone);
        float phoneStartX = FOOTER_RULE_END_X - phoneWidth;
        drawRightAlignedText(content, font, FOOTER_FONT_SIZE, FOOTER_RULE_END_X, FOOTER_PHONE_Y, phone);

        if (!website.isEmpty()) {
            float websiteWidth = measureText(font, FOOTER_FONT_SIZE, website);
            float gap = 10f;
            float websiteX;
            float websiteY;

            boolean canShareLine = addressEndX + gap + websiteWidth <= phoneStartX - gap;
            if (canShareLine) {
                websiteX = Math.max(addressEndX + gap, FOOTER_ADDRESS_X);
                websiteY = FOOTER_ADDRESS_Y;
            } else {
                websiteX = FOOTER_ADDRESS_X;
                websiteY = FOOTER_ADDRESS_Y - (FOOTER_FONT_SIZE + 2f);
            }

            showText(content, font, FOOTER_FONT_SIZE, websiteX, websiteY, website);
        }

        drawRightAlignedText(content, font, FOOTER_FONT_SIZE, FOOTER_RULE_END_X, FOOTER_CODE_Y, data.footerRecordCode());
    }

    private void drawHorizontalRule(PDPageContentStream content, float startX, float endX, float y) throws IOException {
        content.saveGraphicsState();
        content.setLineWidth(ContractPdfAssets.RULE_STROKE_WIDTH);
        content.setStrokingColor(ContractPdfAssets.BRAND_BLUE);
        content.moveTo(startX, y);
        content.lineTo(endX, y);
        content.stroke();
        content.restoreGraphicsState();
    }

    private void showText(PDPageContentStream content, PDFont font, float fontSize, float x, float y, String text) throws IOException {
        content.beginText();
        content.setFont(font, fontSize);
        content.newLineAtOffset(x, y);
        content.showText(text == null ? "" : text);
        content.endText();
    }

    private void drawRightAlignedText(PDPageContentStream content, PDFont font, float fontSize, float rightEdge, float y, String text) throws IOException {
        if (text == null) {
            text = "";
        }
        float width = measureText(font, fontSize, text);
        float startX = rightEdge - width;
        showText(content, font, fontSize, startX, y, text);
    }

    private float measureText(PDFont font, float fontSize, String text) throws IOException {
        if (text == null || text.isEmpty()) {
            return 0f;
        }
        return font.getStringWidth(text) / 1000f * fontSize;
    }

    private String safeText(String value) {
        return value == null ? "" : value;
    }

    /**
     * 检查PageFive是否包含真实的图片（非占位符）
     */
    private boolean hasRealImages(ContractPdfPayload.PageFive page5) {
        if (page5 == null || page5.images() == null || page5.images().isEmpty()) {
            return false;
        }

        // 检查是否有任何一个ImageSlot包含有效的图片路径
        return page5.images().stream()
                .anyMatch(slot -> slot.path() != null && !slot.path().trim().isEmpty());
    }

    /**
     * 设置PDF文档元信息
     * 标题：使用合同编号
     * 作者：使用检测机构
     * 其他元信息：清理或使用合理默认值
     */
    private void setPdfMetadata(PDDocument document, ContractPdfPayload.PageOne headerData) {
        try {
            var docInfo = document.getDocumentInformation();

            // 标题：使用合同编号（报告编号）
            String reportNumber = safeText(headerData.headerReportNumber());
            if (!reportNumber.isEmpty()) {
                docInfo.setTitle(reportNumber);
            } else {
                docInfo.setTitle("检测合同");
            }

            // 作者：使用检测机构名称
            String companyName = safeText(headerData.headerCompanyName());
            if (!companyName.isEmpty()) {
                docInfo.setAuthor(companyName);
            } else {
                docInfo.setAuthor("检测机构");
            }

            // 清理其他元信息，避免使用上传文件的元信息
            docInfo.setCreator("中山市鑫达普检测技术有限公司");
            docInfo.setProducer("鑫达普LIMS系统");

            // 主题设置为合同类型
            docInfo.setSubject("检测合同文件");

            // 清理可能存在的敏感或无关的自定义元信息
            docInfo.setKeywords("检测,合同,认证");

            log.info("PDF metadata set - Title: {}, Author: {}, Creator: {}, Producer: {}",
                    docInfo.getTitle(), docInfo.getAuthor(), docInfo.getCreator(), docInfo.getProducer());

        } catch (Exception e) {
            log.warn("Failed to set PDF metadata", e);
            // 不抛出异常，继续PDF生成流程
        }
    }

    public static void main(String[] args) throws IOException {
        ContractPdfRenderer renderer = new ContractPdfRenderer();
        ContractPdfPayload payload = ContractPdfPayload.sample();
        byte[] pdf = renderer.render(payload);

        Path output = args != null && args.length > 0
                ? Path.of(args[0])
                : Path.of("contract-preview.pdf");

        Files.write(output, pdf);
        log.info("Contract sample written to {}", output.toAbsolutePath());
    }
}
