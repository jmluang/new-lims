package com.luang.pdfsigner.service;

import com.luang.pdfsigner.dto.EntrustOrderPayload;
import com.luang.pdfsigner.dto.EntrustOrderPayload.EnumValue;
import com.luang.pdfsigner.dto.EntrustOrderPayload.Standard;
import java.awt.Color;
import java.io.ByteArrayOutputStream;
import java.io.InputStream;
import java.io.IOException;
import java.net.HttpURLConnection;
import java.net.URI;
import java.net.URL;
import java.time.LocalDate;
import java.time.format.DateTimeFormatter;
import java.util.List;
import java.util.Set;
import java.util.function.Function;
import java.util.stream.Collectors;
import org.apache.pdfbox.pdmodel.PDDocument;
import org.apache.pdfbox.pdmodel.PDPage;
import org.apache.pdfbox.pdmodel.PDPageContentStream;
import org.apache.pdfbox.pdmodel.PDPageContentStream.AppendMode;
import org.apache.pdfbox.pdmodel.common.PDRectangle;
import org.apache.pdfbox.pdmodel.font.PDFont;
import org.apache.pdfbox.pdmodel.graphics.image.PDImageXObject;
import org.springframework.stereotype.Service;
import org.slf4j.Logger;
import org.slf4j.LoggerFactory;

@Service
public class EntrustOrderRenderer {
    private static final Logger log = LoggerFactory.getLogger(EntrustOrderRenderer.class);

    private static final float MM_TO_PT = 72f / 25.4f;
    private static final float PAGE_MARGIN_MM = 15f;
    private static final DateTimeFormatter DATE_FORMATTER = DateTimeFormatter.ofPattern("yyyy.M.d");

    // Table layout constants
    private static final float CELL_PADDING = mm(1.5f);
    private static final float ROW_HEIGHT = mm(6.5f);
    private static final float SIGNATURE_DECLARATION_HEIGHT = mm(20.5f);
    private static final float HEADER_FONT_SIZE = 14f;
    private static final float TITLE_FONT_SIZE = 16f;
    private static final float NORMAL_FONT_SIZE = 10f;
    private static final float SMALL_FONT_SIZE = 9f;

    private static final class PageCursor implements AutoCloseable {
        private final PDDocument document;
        private final PDFont font;
        private final float margin;
        private final float contentWidth;
        private PDPage page;
        private PDPageContentStream content;
        private float y;

        private PageCursor(PDDocument document, PDFont font, float margin) throws IOException {
            this.document = document;
            this.font = font;
            this.margin = margin;
            this.contentWidth = PDRectangle.A4.getWidth() - margin * 2;
            newPage();
        }

        private PDDocument document() {
            return document;
        }

        private PDFont font() {
            return font;
        }

        private float margin() {
            return margin;
        }

        private float contentWidth() {
            return contentWidth;
        }

        private PDPageContentStream content() {
            return content;
        }

        private float y() {
            return y;
        }

        private void moveTo(float nextY) {
            this.y = nextY;
        }

        private void ensureSpace(float requiredHeight) throws IOException {
            if (y - requiredHeight < margin) {
                newPage();
            }
        }

    private void newPage() throws IOException {
            if (content != null) {
                content.close();
            }
            page = new PDPage(PDRectangle.A4);
            document.addPage(page);
            content = new PDPageContentStream(document, page, AppendMode.APPEND, true, true);
            content.setNonStrokingColor(Color.BLACK);
            y = page.getMediaBox().getHeight() - margin;
        }

        @Override
        public void close() throws IOException {
            if (content != null) {
                content.close();
                content = null;
            }
        }
    }

    private void beginBlackText(PDPageContentStream content) throws IOException {
        content.setNonStrokingColor(Color.BLACK);
        content.beginText();
    }

    public byte[] render(EntrustOrderPayload payload) throws IOException {
        try (PDDocument document = new PDDocument()) {
            PDFont font = resolveFont(document);

            try (PageCursor cursor = new PageCursor(document, font, mm(PAGE_MARGIN_MM))) {
                cursor.moveTo(drawHeader(cursor.content(), font, cursor.margin(), cursor.y(), cursor.contentWidth(), payload));
                cursor.moveTo(drawTitle(cursor.content(), font, cursor.margin(), cursor.y(), cursor.contentWidth()));
                drawMainContent(cursor, payload);
                drawFooter(cursor.content(), font, cursor.margin(), cursor.y(), cursor.contentWidth());
            }

            ByteArrayOutputStream output = new ByteArrayOutputStream();
            document.save(output);
            return output.toByteArray();
        }
    }

    private float drawHeader(PDPageContentStream content, PDFont font, float margin, float cursorY, float contentWidth, EntrustOrderPayload payload) throws IOException {
        float leftX = margin;
        float rightX = margin + contentWidth;
        float headerHeight = ROW_HEIGHT * 2;
        float topY = cursorY;
        float bottomY = cursorY - headerHeight;

        // Draw border
        drawRectangle(content, leftX, bottomY, contentWidth, headerHeight);

        // Company area (left)
        float formBoxWidth = mm(40f);
        float companyBoxWidth = contentWidth - formBoxWidth;
        drawCenteredText(content, font, HEADER_FONT_SIZE, leftX, bottomY, companyBoxWidth, headerHeight,
                logisticsField(payload.logistics(), EntrustOrderPayload.Logistics::laboratoryName, ""));

        // Form info box (right)
        float formBoxHeight = headerHeight;
        float formBoxX = rightX - formBoxWidth;

        // Draw vertical separator
        drawLine(content, formBoxX, topY, formBoxX, bottomY);

        float formRowHeight = formBoxHeight / 2;
        float topRowBottom = cursorY - formRowHeight;

        // Draw horizontal separator inside form box
        drawLine(content, formBoxX, topRowBottom, rightX, topRowBottom);

        drawCenteredText(content, font, SMALL_FONT_SIZE, formBoxX, topRowBottom, formBoxWidth, formRowHeight,
                "表单编号：FO-12-01");
        drawCenteredText(content, font, SMALL_FONT_SIZE, formBoxX, bottomY, formBoxWidth, formRowHeight,
                "版本：v1.1");

        return bottomY - mm(3);
    }

    private float drawTitle(PDPageContentStream content, PDFont font, float margin, float cursorY, float contentWidth) throws IOException {
        String title = sanitizeText("实验委托单");
        beginBlackText(content);
        content.setFont(font, TITLE_FONT_SIZE);
        float titleWidth = font.getStringWidth(title) / 1000 * TITLE_FONT_SIZE;
        content.newLineAtOffset(margin + (contentWidth - titleWidth) / 2, cursorY - mm(8));
        content.showText(title);
        content.endText();
        return cursorY - mm(12);
    }

    private void drawMainContent(PageCursor cursor, EntrustOrderPayload payload) throws IOException {
        cursor.ensureSpace(ROW_HEIGHT * 3 + mm(2));
        cursor.moveTo(drawBasicInfoSection(cursor.content(), cursor.font(), cursor.margin(), cursor.y(), cursor.contentWidth(), payload));

        cursor.ensureSpace(ROW_HEIGHT * 7 + mm(2));
        cursor.moveTo(drawClientSection(cursor.content(), cursor.font(), cursor.margin(), cursor.y(), cursor.contentWidth(), payload));

        cursor.ensureSpace(standardsSectionHeight(payload));
        cursor.moveTo(drawStandardsSection(cursor.content(), cursor.font(), cursor.margin(), cursor.y(), cursor.contentWidth(), payload));

        drawSampleSection(cursor, payload);

        cursor.ensureSpace(ROW_HEIGHT * 6 + mm(2));
        cursor.moveTo(drawLogisticsSection(cursor.content(), cursor.font(), cursor.margin(), cursor.y(), cursor.contentWidth(), payload));

        cursor.ensureSpace(SIGNATURE_DECLARATION_HEIGHT + ROW_HEIGHT * 3 + mm(4));
        cursor.moveTo(drawSignatureSection(cursor, payload));
    }

    private float drawBasicInfoSection(PDPageContentStream content, PDFont font, float margin, float cursorY, float contentWidth, EntrustOrderPayload payload) throws IOException {
        float sectionHeight = ROW_HEIGHT * 3;

        // Draw outer border
        drawRectangle(content, margin, cursorY - sectionHeight, contentWidth, sectionHeight);

        // First row
        float col1Width = contentWidth * 0.15f;
        float col2Width = contentWidth * 0.35f;
        float col3Width = contentWidth * 0.23f;
        float col4Width = contentWidth * 0.27f;

        float y = cursorY;

        String urgencyText = "";
        if (payload.base() != null) {
            urgencyText = renderSingleSelect(payload.base().urgencyOptions(), payload.base().urgency());
        }
        if (urgencyText.isEmpty()) {
            urgencyText = renderCheckbox(false, "常规") + "" + renderCheckbox(false, "加急（加收50%费用）");
        }

        // Row 1
        drawLabelCell(content, font, margin, y - ROW_HEIGHT, col1Width, ROW_HEIGHT, "委托日期", false);
        drawCell(content, font, margin + col1Width, y - ROW_HEIGHT, col2Width, ROW_HEIGHT,
                formatDate(payload.base() != null ? payload.base().entrustDate() : null), false);
        drawLabelCell(content, font, margin + col1Width + col2Width, y - ROW_HEIGHT, col3Width, ROW_HEIGHT, "紧急程度", false);
        drawCell(content, font, margin + col1Width + col2Width + col3Width, y - ROW_HEIGHT, col4Width, ROW_HEIGHT,
                urgencyText, false);

        // Row 2
        y -= ROW_HEIGHT;
        drawLabelCell(content, font, margin, y - ROW_HEIGHT, col1Width, ROW_HEIGHT, "计划结束时间", false);
        drawCell(content, font, margin + col1Width, y - ROW_HEIGHT, col2Width, ROW_HEIGHT,
                formatDate(payload.base() != null ? payload.base().plannedEndDate() : null), false);
        drawCell(content, font, margin + col1Width + col2Width, y - ROW_HEIGHT, col3Width, ROW_HEIGHT, "", false);
        drawCell(content, font, margin + col1Width + col2Width + col3Width, y - ROW_HEIGHT, col4Width, ROW_HEIGHT, "", false);

        // Row 3
        y -= ROW_HEIGHT;
        drawLabelCell(content, font, margin, y - ROW_HEIGHT, col1Width, ROW_HEIGHT, "委托编号", false);
        drawCell(content, font, margin + col1Width, y - ROW_HEIGHT, col2Width, ROW_HEIGHT,
                payload.base() != null && payload.base().entrustNumber() != null ? payload.base().entrustNumber() : "", false);
        drawLabelCell(content, font, margin + col1Width + col2Width, y - ROW_HEIGHT, col3Width, ROW_HEIGHT, "合同编号", false);
        drawCell(content, font, margin + col1Width + col2Width + col3Width, y - ROW_HEIGHT, col4Width, ROW_HEIGHT,
                payload.base() != null && payload.base().contractNumber() != null ? payload.base().contractNumber() : "", false);

        return cursorY - sectionHeight - mm(2);
    }

    private float drawClientSection(PDPageContentStream content, PDFont font, float margin, float cursorY, float contentWidth, EntrustOrderPayload payload) throws IOException {
        drawSectionHeader(content, font, margin, cursorY, contentWidth, "委托单位信息");
        cursorY -= ROW_HEIGHT;

        float y = cursorY;
        y = drawPartyInfoBlock(content, font, margin, y, contentWidth, "委托单位", true, payload.client());
        y = drawPartyInfoBlock(content, font, margin, y, contentWidth, "制造商", true, payload.manufacturer());
        y = drawPartyInfoBlock(content, font, margin, y, contentWidth, "生产厂", false, payload.producer());

        return y - mm(2);
    }

    private float drawPartyInfoBlock(PDPageContentStream content, PDFont font, float margin, float cursorY, float contentWidth,
                                     String label, boolean required, EntrustOrderPayload.Party party) throws IOException {
        float labelWidth = contentWidth * 0.15f;
        float companyWidth = contentWidth * 0.4f;
        float fieldLabelWidth = contentWidth * 0.07f;
        float remaining = contentWidth - labelWidth - companyWidth - fieldLabelWidth * 2;
        float fieldValueWidth = Math.max(remaining / 2f, mm(15f));
        float adjustment = contentWidth - labelWidth - companyWidth - fieldLabelWidth * 2 - fieldValueWidth * 2;
        if (Math.abs(adjustment) > 0.01f) {
            fieldValueWidth += adjustment / 2f;
        }

        float y = cursorY;
        String labelText = required ? label + "*" : label;

        drawLabelCell(content, font, margin, y - ROW_HEIGHT, labelWidth, ROW_HEIGHT, labelText, required);
        drawCell(content, font, margin + labelWidth, y - ROW_HEIGHT, companyWidth, ROW_HEIGHT,
                partyField(party, EntrustOrderPayload.Party::companyName), false);
        float contactLabelX = margin + labelWidth + companyWidth;
        drawCell(content, font, contactLabelX, y - ROW_HEIGHT, fieldLabelWidth, ROW_HEIGHT, "联系人", false);
        drawCell(content, font, contactLabelX + fieldLabelWidth, y - ROW_HEIGHT, fieldValueWidth, ROW_HEIGHT,
                partyField(party, EntrustOrderPayload.Party::contact), false);
        float phoneLabelX = contactLabelX + fieldLabelWidth + fieldValueWidth;
        drawCell(content, font, phoneLabelX, y - ROW_HEIGHT, fieldLabelWidth, ROW_HEIGHT, "电话", false);
        drawCell(content, font, phoneLabelX + fieldLabelWidth, y - ROW_HEIGHT, fieldValueWidth, ROW_HEIGHT,
                partyField(party, EntrustOrderPayload.Party::phone), false);

        y -= ROW_HEIGHT;
        String addressLabel = required ? "地址*" : "地址";
        float emailLabelWidth = fieldLabelWidth;
        float addressWidth = companyWidth + fieldValueWidth;
        float emailValueWidth = contentWidth - labelWidth - addressWidth - emailLabelWidth;
        drawLabelCell(content, font, margin, y - ROW_HEIGHT, labelWidth, ROW_HEIGHT, addressLabel, required);
        drawCell(content, font, margin + labelWidth, y - ROW_HEIGHT, addressWidth, ROW_HEIGHT,
                partyField(party, EntrustOrderPayload.Party::address), false);
        float emailLabelX = margin + labelWidth + addressWidth;
        drawCell(content, font, emailLabelX, y - ROW_HEIGHT, emailLabelWidth, ROW_HEIGHT, "邮箱", false);
        drawCell(content, font, emailLabelX + emailLabelWidth, y - ROW_HEIGHT, emailValueWidth, ROW_HEIGHT,
                partyField(party, EntrustOrderPayload.Party::email), false);

        y -= ROW_HEIGHT;
        return y - mm(1f);
    }

    private float drawStandardsSection(PDPageContentStream content, PDFont font, float margin, float cursorY, float contentWidth, EntrustOrderPayload payload) throws IOException {
        drawSectionHeader(content, font, margin, cursorY, contentWidth, "检测要求以及报告要求");
        cursorY -= ROW_HEIGHT;

        // Standards table headers
        float col1Width = contentWidth * 0.60f;
        float col2Width = contentWidth * 0.20f;
        float col3Width = contentWidth * 0.20f;

        float y = cursorY;
        drawHeaderCell(content, font, margin, y - ROW_HEIGHT, col1Width, "标准号及版本");
        drawHeaderCell(content, font, margin + col1Width, y - ROW_HEIGHT, col2Width, "资质要求");
        drawHeaderCell(content, font, margin + col1Width + col2Width, y - ROW_HEIGHT, col3Width, "报告语言");

        // Standard rows
        y -= ROW_HEIGHT;
        if (payload.requirements() != null && payload.requirements().standards() != null && !payload.requirements().standards().isEmpty()) {
            for (Standard standard : payload.requirements().standards()) {
                String standardText = standard.standardCode() != null ? standard.standardCode() : "";
                if (standard.notes() != null && !standard.notes().isBlank()) {
                    standardText = standardText.isBlank() ? standard.notes() : standardText + " " + standard.notes();
                }
                drawCell(content, font, margin, y - ROW_HEIGHT, col1Width, ROW_HEIGHT, standardText, false);
                drawCenteredCell(content, font, margin + col1Width, y - ROW_HEIGHT, col2Width, ROW_HEIGHT,
                        standard.qualificationRequirement() != null ? standard.qualificationRequirement() : "");
                drawCenteredCell(content, font, margin + col1Width + col2Width, y - ROW_HEIGHT, col3Width, ROW_HEIGHT,
                        standard.reportLanguage() != null ? standard.reportLanguage() : "");
                y -= ROW_HEIGHT;
            }
        } else {
            drawCell(content, font, margin, y - ROW_HEIGHT, col1Width, ROW_HEIGHT, "", false);
            drawCenteredCell(content, font, margin + col1Width, y - ROW_HEIGHT, col2Width, ROW_HEIGHT, "");
            drawCenteredCell(content, font, margin + col1Width + col2Width, y - ROW_HEIGHT, col3Width, ROW_HEIGHT, "");
            y -= ROW_HEIGHT;
        }

        // Report form and options
        float halfWidth = contentWidth * 0.5f;
        float reportFormsGroupWidth = contentWidth * 0.65f;
        EntrustOrderPayload.Requirements requirements = payload.requirements();
        String reportFormsText = requirements != null ? renderMultiSelect(requirements.reportFormOptions(), requirements.reportForms()) : "";

        String sampleReturnText = requirements != null ? renderSingleSelectOrSelected(requirements.sampleReturnOptions(), requirements.sampleReturn()) : "";

        drawCell(content, font, margin, y - ROW_HEIGHT, labelWidth(contentWidth), ROW_HEIGHT, "报告形式", false);
        drawCell(content, font, margin + labelWidth(contentWidth), y - ROW_HEIGHT, reportFormsGroupWidth - labelWidth(contentWidth), ROW_HEIGHT,
                reportFormsText, false);
        drawCell(content, font, margin + reportFormsGroupWidth, y - ROW_HEIGHT, labelWidth(contentWidth), ROW_HEIGHT,
                "样品是否返还", false);
        drawCell(content, font, margin + reportFormsGroupWidth + labelWidth(contentWidth), y - ROW_HEIGHT, contentWidth - reportFormsGroupWidth - labelWidth(contentWidth), ROW_HEIGHT,
                sampleReturnText, false);
        y -= ROW_HEIGHT;

        String submissionText = requirements != null ? renderSingleSelectOrSelected(requirements.reportSubmissionOptions(), requirements.reportSubmission()) : "";

        String subcontractText = requirements != null ? renderSingleSelectOrSelected(requirements.allowSubcontractOptions(), requirements.allowSubcontract()) : "";

        drawCell(content, font, margin, y - ROW_HEIGHT, labelWidth(contentWidth), ROW_HEIGHT, "报告提交", false);
        drawCell(content, font, margin + labelWidth(contentWidth), y - ROW_HEIGHT, halfWidth - labelWidth(contentWidth), ROW_HEIGHT,
                submissionText, false);
        drawCell(content, font, margin + halfWidth, y - ROW_HEIGHT, labelWidth(contentWidth), ROW_HEIGHT,
                "准许检测分包", false);
        drawCell(content, font, margin + halfWidth + labelWidth(contentWidth), y - ROW_HEIGHT, halfWidth - labelWidth(contentWidth), ROW_HEIGHT,
                subcontractText, false);
        y -= ROW_HEIGHT;

        // Notes
        drawCell(content, font, margin, y - ROW_HEIGHT, labelWidth(contentWidth), ROW_HEIGHT, "备注", false);
        String remarks = requirements != null ? requirements.remarks() : "";
        drawCell(content, font, margin + labelWidth(contentWidth), y - ROW_HEIGHT, contentWidth - labelWidth(contentWidth), ROW_HEIGHT,
                remarks != null ? remarks : "", false);

        return y - ROW_HEIGHT - mm(2);
    }

    private void drawSampleSection(PageCursor cursor, EntrustOrderPayload payload) throws IOException {
        cursor.ensureSpace(ROW_HEIGHT + sampleBlockHeight());
        drawSectionHeader(cursor.content(), cursor.font(), cursor.margin(), cursor.y(), cursor.contentWidth(), "*样品信息");
        cursor.moveTo(cursor.y() - ROW_HEIGHT);

        List<EntrustOrderPayload.Sample> samples = payload.effectiveSamples();
        if (samples.isEmpty()) {
            samples = List.of(new EntrustOrderPayload.Sample("", "", "", "", "", "", null, "", null, "", ""));
        }

        for (EntrustOrderPayload.Sample sample : samples) {
            cursor.ensureSpace(sampleBlockHeight());
            cursor.moveTo(drawSingleSampleBlock(cursor.content(), cursor.font(), cursor.margin(), cursor.y(), cursor.contentWidth(), sample));
        }

        cursor.moveTo(cursor.y() - mm(2));
    }

    private float drawSingleSampleBlock(PDPageContentStream content, PDFont font, float margin, float cursorY, float contentWidth,
                                        EntrustOrderPayload.Sample sample) throws IOException {
        float col1Width = contentWidth * 0.13f;
        float col2Width = contentWidth * 0.23f;
        float col3Width = contentWidth * 0.12f;
        float col4Width = contentWidth * 0.12f;
        float col5Width = contentWidth * 0.15f;
        float col6Width = contentWidth - (col1Width + col2Width + col3Width + col4Width + col5Width);
        float y = cursorY;

        // Sample name
        drawLabelCell(content, font, margin, y - ROW_HEIGHT, col1Width, ROW_HEIGHT, "名称*", true);
        drawCell(content, font, margin + col1Width, y - ROW_HEIGHT, col2Width, ROW_HEIGHT,
                sampleField(sample, EntrustOrderPayload.Sample::name, ""), false);
        drawLabelCell(content, font, margin + col1Width + col2Width, y - ROW_HEIGHT, col3Width, ROW_HEIGHT, "额定电流", false);
        drawCell(content, font, margin + col1Width + col2Width + col3Width, y - ROW_HEIGHT, col4Width, ROW_HEIGHT,
                sampleField(sample, EntrustOrderPayload.Sample::current, ""), false);
        drawLabelCell(content, font, margin + col1Width + col2Width + col3Width + col4Width, y - ROW_HEIGHT, col5Width, ROW_HEIGHT, "状态", false);
        drawCell(content, font, margin + col1Width + col2Width + col3Width + col4Width + col5Width, y - ROW_HEIGHT, col6Width, ROW_HEIGHT,
                renderSampleStatus(sample), false);

        // Model
        y -= ROW_HEIGHT;
        drawLabelCell(content, font, margin, y - ROW_HEIGHT, col1Width, ROW_HEIGHT, "型号*", true);
        drawCell(content, font, margin + col1Width, y - ROW_HEIGHT, col2Width, ROW_HEIGHT,
                sampleField(sample, EntrustOrderPayload.Sample::model, ""), false);
        drawLabelCell(content, font, margin + col1Width + col2Width, y - ROW_HEIGHT, col3Width, ROW_HEIGHT, "额定功率*", true);
        drawCell(content, font, margin + col1Width + col2Width + col3Width, y - ROW_HEIGHT, col4Width, ROW_HEIGHT,
                sampleField(sample, EntrustOrderPayload.Sample::power, ""), false);
        drawLabelCell(content, font, margin + col1Width + col2Width + col3Width + col4Width, y - ROW_HEIGHT, col5Width, ROW_HEIGHT, "样品数量", false);
        drawCell(content, font, margin + col1Width + col2Width + col3Width + col4Width + col5Width, y - ROW_HEIGHT, col6Width, ROW_HEIGHT,
                renderSampleQuantity(sample), false);

        // Voltage
        y -= ROW_HEIGHT;
        drawLabelCell(content, font, margin, y - ROW_HEIGHT, col1Width, ROW_HEIGHT, "额定电压*", true);
        drawCell(content, font, margin + col1Width, y - ROW_HEIGHT, col2Width, ROW_HEIGHT,
                sampleField(sample, EntrustOrderPayload.Sample::voltage, ""), false);
        drawLabelCell(content, font, margin + col1Width + col2Width, y - ROW_HEIGHT, col3Width, ROW_HEIGHT, "额定频率", false);
        drawCell(content, font, margin + col1Width + col2Width + col3Width, y - ROW_HEIGHT, col4Width, ROW_HEIGHT,
                sampleField(sample, EntrustOrderPayload.Sample::frequency, ""), false);
        drawCell(content, font, margin + col1Width + col2Width + col3Width + col4Width, y - ROW_HEIGHT, col5Width, ROW_HEIGHT, "", false);
        drawCell(content, font, margin + col1Width + col2Width + col3Width + col4Width + col5Width, y - ROW_HEIGHT, col6Width, ROW_HEIGHT, "", false);

        // Notes
        y -= ROW_HEIGHT;
        drawLabelCell(content, font, margin, y - ROW_HEIGHT, col1Width, ROW_HEIGHT, "备注", false);
        drawCell(content, font, margin + col1Width, y - ROW_HEIGHT, contentWidth - col1Width, ROW_HEIGHT,
                sampleField(sample, EntrustOrderPayload.Sample::remarks, ""), false);

        return y - ROW_HEIGHT;
    }

    private float drawLogisticsSection(PDPageContentStream content, PDFont font, float margin, float cursorY, float contentWidth, EntrustOrderPayload payload) throws IOException {
        drawSectionHeader(content, font, margin, cursorY, contentWidth, "*样品寄送地址");
        cursorY -= ROW_HEIGHT;

        float labelWidth = contentWidth * 0.15f;
        float infoWidth = contentWidth * 0.60f;
        float remarksWidth = contentWidth - (labelWidth + infoWidth);

        float y = cursorY;
        float remarksX = margin + labelWidth + infoWidth;
        drawLabelCell(content, font, margin, y - ROW_HEIGHT, labelWidth, ROW_HEIGHT, "实验室名称", false);
        drawCell(content, font, margin + labelWidth, y - ROW_HEIGHT, infoWidth, ROW_HEIGHT,
                logisticsField(payload.logistics(), EntrustOrderPayload.Logistics::laboratoryName, ""), false);

        content.setNonStrokingColor(0.9f, 0.9f, 0.9f);
        content.addRect(remarksX, y - ROW_HEIGHT, remarksWidth, ROW_HEIGHT);
        content.fill();
        content.setNonStrokingColor(Color.BLACK);
        drawRectangle(content, remarksX, y - ROW_HEIGHT, remarksWidth, ROW_HEIGHT);
        beginBlackText(content);
        content.setFont(font, SMALL_FONT_SIZE);
        content.newLineAtOffset(remarksX + CELL_PADDING, y - ROW_HEIGHT + CELL_PADDING);
        content.showText("特别说明");
        content.endText();

        y -= ROW_HEIGHT;
        float remainingHeight = ROW_HEIGHT * 4;
        drawRectangle(content, remarksX, y - remainingHeight, remarksWidth, remainingHeight);
        String remarks = logisticsField(payload.logistics(), EntrustOrderPayload.Logistics::shippingNotes, "");
        if (!remarks.isBlank()) {
            beginBlackText(content);
            content.setFont(font, SMALL_FONT_SIZE);
            float remarkTextMaxWidth = remarksWidth - CELL_PADDING * 2;
            String remarkText = truncateText(font, remarks, SMALL_FONT_SIZE, remarkTextMaxWidth);
            content.newLineAtOffset(remarksX + CELL_PADDING, y - SMALL_FONT_SIZE - CELL_PADDING);
            content.showText(remarkText);
            content.endText();
        }

        content.setNonStrokingColor(0.9f, 0.9f, 0.9f);
        content.addRect(margin, y - ROW_HEIGHT * 2, labelWidth, ROW_HEIGHT * 2);
        content.fill();
        content.setNonStrokingColor(Color.BLACK);
        drawRectangle(content, margin, y - ROW_HEIGHT * 2, labelWidth, ROW_HEIGHT * 2);
        drawRectangle(content, margin + labelWidth, y - ROW_HEIGHT * 2, infoWidth, ROW_HEIGHT * 2);
        float labelBaseline = (y - ROW_HEIGHT * 2) + ((ROW_HEIGHT * 2 - SMALL_FONT_SIZE) / 2);
        beginBlackText(content);
        content.setFont(font, SMALL_FONT_SIZE);
        content.newLineAtOffset(margin + CELL_PADDING, labelBaseline);
        content.showText("实验室地址");
        content.endText();

        String address = sanitizeText(logisticsField(payload.logistics(), EntrustOrderPayload.Logistics::laboratoryAddress, ""));
        beginBlackText(content);
        content.setFont(font, SMALL_FONT_SIZE);
        content.newLineAtOffset(margin + labelWidth + CELL_PADDING, y - SMALL_FONT_SIZE - CELL_PADDING);
        content.showText(address);
        content.endText();

        y -= ROW_HEIGHT * 2;
        drawLabelCell(content, font, margin, y - ROW_HEIGHT, labelWidth, ROW_HEIGHT, "联系人", false);
        drawCell(content, font, margin + labelWidth, y - ROW_HEIGHT, infoWidth, ROW_HEIGHT,
                logisticsField(payload.logistics(), EntrustOrderPayload.Logistics::laboratoryContact, ""), false);

        y -= ROW_HEIGHT;
        drawLabelCell(content, font, margin, y - ROW_HEIGHT, labelWidth, ROW_HEIGHT, "联系电话", false);
        drawCell(content, font, margin + labelWidth, y - ROW_HEIGHT, infoWidth, ROW_HEIGHT,
                logisticsField(payload.logistics(), EntrustOrderPayload.Logistics::laboratoryPhone, ""), false);

        return y - ROW_HEIGHT - mm(2);
    }

    private float drawSignatureSection(PageCursor cursor, EntrustOrderPayload payload) throws IOException {
        PDDocument document = cursor.document();
        PDPageContentStream content = cursor.content();
        PDFont font = cursor.font();
        float margin = cursor.margin();
        float cursorY = cursor.y();
        float contentWidth = cursor.contentWidth();

        // Highlight row
        float highlightY = cursorY;
        float declarationHeight = SIGNATURE_DECLARATION_HEIGHT;
        float declarationBottom = highlightY - declarationHeight;

        drawRectangle(content, margin, declarationBottom, contentWidth, declarationHeight);

        beginBlackText(content);
        content.setFont(font, TITLE_FONT_SIZE);
        float declarationBaseline = declarationBottom + declarationHeight / 2 - NORMAL_FONT_SIZE / 2;
        content.newLineAtOffset(margin + CELL_PADDING + mm(5f), declarationBaseline);
        content.showText("委托单位声明：上述提供资料正确无误！");
        content.endText();
        content.setNonStrokingColor(Color.BLACK);

        beginBlackText(content);
        content.setFont(font, NORMAL_FONT_SIZE);
        content.newLineAtOffset(margin + contentWidth * 0.6f, declarationBaseline);
        content.showText("委托人（客户）签字");
        content.endText();

        String clientSignedAt = payload.signatures() != null ? formatDate(payload.signatures().clientSignedAt()) : "";
        if (!clientSignedAt.isBlank()) {
            beginBlackText(content);
            content.setFont(font, NORMAL_FONT_SIZE);
            content.newLineAtOffset(margin + contentWidth * 0.6f, declarationBottom + mm(4f));
            content.showText("日期 " + clientSignedAt);
            content.endText();
        }

        String signature = payload.signatures() != null ? payload.signatures().clientSignatureName() : null;
        if (signature != null && !signature.isBlank()) {
            float signatureAreaX = margin + contentWidth * 0.8f;
            float signatureAreaWidth = contentWidth * 0.2f;
            float signatureAreaY = declarationBottom + mm(2);
            float signatureAreaHeight = declarationHeight - mm(4);
            if (isHttpUrl(signature)) {
                drawSignatureImage(document, content, signature, signatureAreaX, signatureAreaY, signatureAreaWidth, signatureAreaHeight);
            } else {
                drawCenteredText(content, font, NORMAL_FONT_SIZE, signatureAreaX, signatureAreaY, signatureAreaWidth, signatureAreaHeight, signature);
            }
        }

        cursorY = declarationBottom - mm(2);

        // Signature rows
        float y = cursorY;
        float colWidth = contentWidth / 5f;
        float rowHeight = ROW_HEIGHT * 1.5f;
        EntrustOrderPayload.Signatures signatures = payload.signatures();

        drawHeaderCell(content, font, margin, y - rowHeight, colWidth, "实验室资源满足*", rowHeight);
        drawHeaderCell(content, font, margin + colWidth, y - rowHeight, colWidth, "综合部确认", rowHeight);
        drawCenteredCell(content, font, margin + colWidth * 2, y - rowHeight, colWidth, rowHeight,
                signatures != null ? nullSafe(signatures.labResourceConfirmedBy()) : "");
        drawHeaderCell(content, font, margin + colWidth * 3, y - rowHeight, colWidth, "日期", rowHeight);
        drawCenteredCell(content, font, margin + colWidth * 4, y - rowHeight, colWidth, rowHeight,
                signatures != null ? formatDate(signatures.labResourceConfirmedAt()) : "");

        y -= rowHeight;
        drawHeaderCell(content, font, margin, y - rowHeight, colWidth, "客户要求的评审*", rowHeight);
        drawHeaderCell(content, font, margin + colWidth, y - rowHeight, colWidth, "检测部确认", rowHeight);
        drawCenteredCell(content, font, margin + colWidth * 2, y - rowHeight, colWidth, rowHeight,
                signatures != null ? nullSafe(signatures.labReviewedBy()) : "");
        drawHeaderCell(content, font, margin + colWidth * 3, y - rowHeight, colWidth, "日期", rowHeight);
        drawCenteredCell(content, font, margin + colWidth * 4, y - rowHeight, colWidth, rowHeight,
                signatures != null ? formatDate(signatures.labReviewedAt()) : "");

        return y - rowHeight - mm(2);
    }

    private void drawSignatureImage(PDDocument document, PDPageContentStream content, String signatureUrl,
                                    float x, float y, float width, float height) throws IOException {
        try {
            URL imageUrl = URI.create(signatureUrl).toURL();
            log.info("Requesting URL: {}", imageUrl);

            HttpURLConnection connection = (HttpURLConnection) imageUrl.openConnection();
            connection.setRequestMethod("GET");
            connection.setConnectTimeout(5000);
            connection.setReadTimeout(10000);

            int responseCode = connection.getResponseCode();
            log.info("Response Code: {}", responseCode);

            if (responseCode != HttpURLConnection.HTTP_OK) {
                log.warn("Failed to load client signature. Server returned non-OK response code: {}", responseCode);
                return;
            }

            try (InputStream in = connection.getInputStream()) {
                PDImageXObject pdImage = PDImageXObject.createFromByteArray(document, in.readAllBytes(), imageUrl.toString());

                float imageAspectRatio = (float) pdImage.getWidth() / (float) pdImage.getHeight();
                float areaAspectRatio = width / height;

                float drawWidth;
                float drawHeight;
                if (imageAspectRatio > areaAspectRatio) {
                    drawWidth = width;
                    drawHeight = drawWidth / imageAspectRatio;
                } else {
                    drawHeight = height;
                    drawWidth = drawHeight * imageAspectRatio;
                }

                float drawX = x + (width - drawWidth) / 2;
                float drawY = y + (height - drawHeight) / 2;

                content.drawImage(pdImage, drawX, drawY, drawWidth, drawHeight);
            }
        } catch (IllegalArgumentException e) {
            log.warn("Invalid client signature URL: {}", e.getMessage(), e);
        } catch (IOException e) {
            log.warn("Failed to load client signature image: {}", e.getMessage(), e);
        }
    }

    private boolean isHttpUrl(String value) {
        return value.startsWith("http://") || value.startsWith("https://");
    }

    private void drawFooter(PDPageContentStream content, PDFont font, float margin, float cursorY, float contentWidth) throws IOException {
        // Footer would be at bottom of page if needed
    }

    private float standardsSectionHeight(EntrustOrderPayload payload) {
        int standardRows = 1;
        if (payload.requirements() != null
                && payload.requirements().standards() != null
                && !payload.requirements().standards().isEmpty()) {
            standardRows = payload.requirements().standards().size();
        }
        return ROW_HEIGHT * (1 + 1 + standardRows + 3) + mm(2);
    }

    private float sampleBlockHeight() {
        return ROW_HEIGHT * 4;
    }

    private void drawSectionHeader(PDPageContentStream content, PDFont font, float x, float y, float width, String title) throws IOException {
        // Draw gray background
        content.setNonStrokingColor(0.9f, 0.9f, 0.9f);
        content.addRect(x, y - ROW_HEIGHT, width, ROW_HEIGHT);
        content.fill();
        content.setNonStrokingColor(Color.BLACK);

        // Draw border
        drawRectangle(content, x, y - ROW_HEIGHT, width, ROW_HEIGHT);

        // Draw title
        String sanitizedTitle = sanitizeText(title);
        beginBlackText(content);
        content.setFont(font, NORMAL_FONT_SIZE);
        content.newLineAtOffset(x + CELL_PADDING, y - ROW_HEIGHT + CELL_PADDING);
        content.showText(sanitizedTitle);
        content.endText();
    }

    private void drawCenteredText(PDPageContentStream content, PDFont font, float fontSize,
                                  float x, float y, float width, float height, String text) throws IOException {
        if (text == null || text.isEmpty()) {
            return;
        }

        String displayText = sanitizeText(text);
        float availableWidth = width - CELL_PADDING * 2;
        float textWidth = font.getStringWidth(displayText) / 1000 * fontSize;
        if (textWidth > availableWidth) {
            displayText = truncateText(font, displayText, fontSize, availableWidth);
            textWidth = font.getStringWidth(displayText) / 1000 * fontSize;
        }

        float startX = x + (width - textWidth) / 2;
        if (startX < x + CELL_PADDING) {
            startX = x + CELL_PADDING;
        }

        float baseline = y + (height - fontSize) / 2;

        beginBlackText(content);
        content.setFont(font, fontSize);
        content.newLineAtOffset(startX, baseline);
        content.showText(displayText);
        content.endText();
    }

    private void drawCenteredCell(PDPageContentStream content, PDFont font, float x, float y,
                                   float width, float height, String text) throws IOException {
        drawRectangle(content, x, y, width, height);
        if (text == null || text.isEmpty()) {
            return;
        }
        String sanitizedText = sanitizeText(text);
        float textWidth = font.getStringWidth(sanitizedText) / 1000 * SMALL_FONT_SIZE;
        float startX = x + (width - textWidth) / 2;
        float baseline = y + (height - SMALL_FONT_SIZE) / 2;
        beginBlackText(content);
        content.setFont(font, SMALL_FONT_SIZE);
        content.newLineAtOffset(startX, baseline);
        content.showText(sanitizedText);
        content.endText();
    }

    private void drawHeaderCell(PDPageContentStream content, PDFont font, float x, float y, float width, String title) throws IOException {
        drawHeaderCell(content, font, x, y, width, title, ROW_HEIGHT);
    }

    private void drawHeaderCell(PDPageContentStream content, PDFont font, float x, float y, float width, String title, float height) throws IOException {
        content.setNonStrokingColor(0.9f, 0.9f, 0.9f);
        content.addRect(x, y, width, height);
        content.fill();
        content.setNonStrokingColor(Color.BLACK);
        drawRectangle(content, x, y, width, height);
        drawCenteredText(content, font, SMALL_FONT_SIZE, x, y, width, height, title);
    }

    private void drawLabelCell(PDPageContentStream content, PDFont font, float x, float y,
                               float width, float height, String text, boolean isRedAsterisk) throws IOException {
        content.setNonStrokingColor(0.9f, 0.9f, 0.9f);
        content.addRect(x, y, width, height);
        content.fill();
        content.setNonStrokingColor(Color.BLACK);
        drawCell(content, font, x, y, width, height, text, isRedAsterisk);
    }

    private void drawCell(PDPageContentStream content, PDFont font, float x, float y, float width, float height, String text, boolean isRedAsterisk) throws IOException {
        // Draw border
        drawRectangle(content, x, y, width, height);

        // Draw text
        if (text != null && !text.isEmpty()) {
            // Sanitize text first
            String sanitizedText = sanitizeText(text);
            beginBlackText(content);
            content.setFont(font, SMALL_FONT_SIZE);

            // Handle red asterisk
            if (isRedAsterisk && sanitizedText.endsWith("*")) {
                String mainText = sanitizedText.substring(0, sanitizedText.length() - 1);
                content.newLineAtOffset(x + CELL_PADDING, y + height / 2 - SMALL_FONT_SIZE / 2);
                content.showText(mainText);

                // Keep every printable character black, including required marks.
                float mainWidth = font.getStringWidth(mainText) / 1000 * SMALL_FONT_SIZE;
                content.endText();

                content.setNonStrokingColor(Color.BLACK);
                beginBlackText(content);
                content.setFont(font, SMALL_FONT_SIZE);
                content.newLineAtOffset(x + CELL_PADDING + mainWidth, y + height / 2 - SMALL_FONT_SIZE / 2);
                content.showText("*");
                content.endText();
                content.setNonStrokingColor(Color.BLACK);
            } else {
                content.newLineAtOffset(x + CELL_PADDING, y + height / 2 - SMALL_FONT_SIZE / 2);

                // Truncate text if too long
                float maxWidth = width - CELL_PADDING * 2;
                String displayText = truncateText(font, sanitizedText, SMALL_FONT_SIZE, maxWidth);
                content.showText(displayText);
                content.endText();
            }
        }
    }

    /**
     * Sanitizes text to remove/replace characters that might not be supported by the font
     * @param text the input text
     * @return sanitized text with tabs replaced by spaces and control characters removed
     */
    private String sanitizeText(String text) {
        if (text == null) {
            return "";
        }
        // Replace tab characters with spaces and remove other control characters
        return text.replace('\t', ' ')
                  .replaceAll("\\p{C}", "")
                  .trim();
    }

    private String truncateText(PDFont font, String text, float fontSize, float maxWidth) throws IOException {
        // Sanitize text: replace tabs with spaces and remove other control characters
        String sanitizedText = sanitizeText(text);

        float textWidth = font.getStringWidth(sanitizedText) / 1000 * fontSize;
        if (textWidth <= maxWidth) {
            return sanitizedText;
        }

        String truncated = sanitizedText;
        while (truncated.length() > 0 && font.getStringWidth(truncated + "...") / 1000 * fontSize > maxWidth) {
            truncated = truncated.substring(0, truncated.length() - 1);
        }
        return truncated + "...";
    }

    private String partyField(EntrustOrderPayload.Party party, Function<EntrustOrderPayload.Party, String> extractor) {
        if (party == null) {
            return "";
        }
        String value = extractor.apply(party);
        return value != null ? value : "";
    }

    private String sampleField(EntrustOrderPayload.Sample sample, Function<EntrustOrderPayload.Sample, String> extractor, String fallback) {
        if (sample == null) {
            return fallback;
        }
        String value = extractor.apply(sample);
        if (value == null || value.isBlank()) {
            return fallback;
        }
        return value;
    }

    private String labelValue(String label, String value) {
        if (value == null || value.isBlank()) {
            return "";
        }
        return label + "：" + value;
    }

    private String nullSafe(String value) {
        return value != null ? value : "";
    }

    private String renderSampleStatus(EntrustOrderPayload.Sample sample) {
        String goodLabel = "完好";
        String conditionNote = sampleField(sample, EntrustOrderPayload.Sample::conditionNote, "");
        String abnormalLabel = conditionNote.isBlank() ? "异常（____）" : "异常（" + conditionNote + "）";

        boolean isGood = false;
        boolean isAbnormal = false;
        if (sample != null && sample.condition() != null) {
            String key = checkboxKey(sample.condition());
            String label = displayLabel(sample.condition());
            if (key != null) {
                isGood = key.equalsIgnoreCase("good") || key.contains("好");
                isAbnormal = key.contains("异常") || key.equalsIgnoreCase("abnormal");
            }
            if (!isGood && label != null && label.contains("好")) {
                isGood = true;
            }
            if (!isAbnormal && label != null && label.contains("异常")) {
                isAbnormal = true;
            }
        }

        StringBuilder builder = new StringBuilder();
        builder.append(renderCheckbox(isGood, goodLabel)).append(' ');
        builder.append(renderCheckbox(isAbnormal, abnormalLabel));
        return builder.toString();
    }

    private String renderSampleQuantity(EntrustOrderPayload.Sample sample) {
        if (sample == null) {
            return "";
        }
        Integer quantity = sample.quantity();
        String unit = sample.quantityUnit();
        if (quantity == null && (unit == null || unit.isBlank())) {
            return "";
        }
        StringBuilder builder = new StringBuilder();
        if (quantity != null) {
            builder.append(quantity);
        }
        if (unit != null && !unit.isBlank()) {
            if (builder.length() > 0) {
                builder.append(' ');
            }
            builder.append(unit);
        }
        return builder.toString();
    }

    private String renderSingleSelect(List<EnumValue> options, EnumValue selected) {
        if (options == null || options.isEmpty()) {
            return "";
        }
        String selectedKey = checkboxKey(selected);
        StringBuilder builder = new StringBuilder();
        for (EnumValue option : options) {
            if (option == null) {
                continue;
            }

            boolean checked = selectedKey != null && selectedKey.equals(checkboxKey(option));
            builder.append(renderCheckbox(checked, displayLabel(option)));
        }
        return builder.toString();
    }

    private String renderSingleSelectOrSelected(List<EnumValue> options, EnumValue selected) {
        String checkboxText = renderSingleSelect(options, selected);
        return checkboxText.isBlank() ? renderSelectedLabel(selected) : checkboxText;
    }

    private String renderMultiSelect(List<EnumValue> options, List<EnumValue> selectedValues) {
        if (options == null || options.isEmpty()) {
            return renderSelectedLabels(selectedValues);
        }

        Set<String> selectedKeys = selectedValues == null
                ? Set.of()
                : selectedValues.stream()
                        .map(this::checkboxKey)
                        .filter(key -> key != null && !key.isBlank())
                        .collect(Collectors.toSet());

        StringBuilder builder = new StringBuilder();
        for (EnumValue option : options) {
            if (option == null) {
                continue;
            }

            String optionKey = checkboxKey(option);
            builder.append(renderCheckbox(optionKey != null && selectedKeys.contains(optionKey), displayLabel(option)));
        }
        return builder.toString();
    }

    private String renderCheckbox(boolean checked, String label) {
        String symbol = checked ? "■" : "□";
        if (label == null || label.isBlank()) {
            return symbol;
        }
        return symbol + label;
    }

    private String renderSelectedLabels(List<EnumValue> values) {
        if (values == null || values.isEmpty()) {
            return "";
        }
        return values.stream()
                .map(this::displayLabel)
                .filter(label -> label != null && !label.isBlank())
                .collect(Collectors.joining("、"));
    }

    private String renderSelectedLabel(EnumValue value) {
        return value != null ? displayLabel(value) : "";
    }

    private String logisticsField(EntrustOrderPayload.Logistics logistics,
                                   Function<EntrustOrderPayload.Logistics, String> extractor,
                                   String fallback) {
        if (logistics == null) {
            return fallback;
        }
        String value = extractor.apply(logistics);
        if (value == null || value.isBlank()) {
            return fallback;
        }
        return value;
    }

    private String displayLabel(EnumValue option) {
        if (option == null) {
            return "";
        }
        if (option.label() != null && !option.label().isBlank()) {
            return option.label();
        }
        return option.value() != null ? option.value() : "";
    }

    private String checkboxKey(EnumValue value) {
        if (value == null) {
            return null;
        }
        if (value.value() != null && !value.value().isBlank()) {
            return value.value();
        }
        return value.label();
    }

    private void drawRectangle(PDPageContentStream content, float x, float y, float width, float height) throws IOException {
        content.setStrokingColor(Color.BLACK);
        content.setLineWidth(0.5f);
        content.addRect(x, y, width, height);
        content.stroke();
    }

    private void drawLine(PDPageContentStream content, float x1, float y1, float x2, float y2) throws IOException {
        content.setStrokingColor(Color.BLACK);
        content.setLineWidth(0.5f);
        content.moveTo(x1, y1);
        content.lineTo(x2, y2);
        content.stroke();
    }

    private float labelWidth(float contentWidth) {
        return contentWidth * 0.15f;
    }

    private String formatDate(String date) {
        if (date == null || date.isEmpty()) {
            return "";
        }
        try {
            LocalDate localDate = LocalDate.parse(date);
            return localDate.format(DATE_FORMATTER);
        } catch (Exception e) {
            return date;
        }
    }

    private String formatEnum(EnumValue enumValue) {
        if (enumValue == null || enumValue.label() == null) {
            return "";
        }
        return enumValue.label();
    }

    private static float mm(float millimeters) {
        return millimeters * MM_TO_PT;
    }

    private PDFont resolveFont(PDDocument document) throws IOException {
        // 统一复用合同 PDF 的字体加载与缓存策略，优先使用宋体风格字体。
        return ContractPdfAssets.loadPrimaryFont(document);
    }
}
