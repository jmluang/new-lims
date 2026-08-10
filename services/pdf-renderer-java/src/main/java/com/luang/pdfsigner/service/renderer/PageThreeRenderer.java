package com.luang.pdfsigner.service.renderer;

import com.luang.pdfsigner.service.ContractPdfAssets;
import com.luang.pdfsigner.service.ContractPdfPayload;
import com.luang.pdfsigner.service.ContractPdfPayload.PageThree.Declaration;
import com.luang.pdfsigner.service.ContractPdfPayload.PageThree.LabeledValue;
import com.luang.pdfsigner.service.ContractPdfPayload.PageThree.Paragraph;
import com.luang.pdfsigner.service.ContractPdfPayload.PageThree.SignatureSlot;
import com.luang.pdfsigner.service.ContractPdfPayload.PageThree.StandardEntry;
import java.io.IOException;
import java.util.ArrayList;
import java.util.Collections;
import java.util.List;
import java.util.Objects;
import java.util.Set;
import org.apache.pdfbox.pdmodel.PDDocument;
import org.apache.pdfbox.pdmodel.PDPageContentStream;
import org.slf4j.Logger;
import org.slf4j.LoggerFactory;

public class PageThreeRenderer implements ContractPdfPageRenderer<ContractPdfPayload.PageThree> {

    private static final Logger log = LoggerFactory.getLogger(PageThreeRenderer.class);
    private static final float TITLE_FONT_SIZE = 20f;
    private static final float BODY_FONT_SIZE = 11.5f;
    private static final float PANEL_LEFT = 65f;
    private static final float PANEL_RIGHT = ContractPdfAssets.PAGE_WIDTH - 65f;
    private static final float PANEL_PADDING_X = 10f;
    private static final float ROW_HEIGHT = 22f;
    private static final float SECTION_GAP = 10f;
    private static final float SIGNATURE_SECTION_WIDTH_RATIO = 0.82f; // 签名区域占整段宽度的比例
    private static final float SIGNATURE_LEFT_SHIFT = 10f; // 左侧签名字段整体左移量
    private static final float STAMP_RIGHT_SHIFT = 20f;   // 盖章文本右移量
    private static final float SIGNATURE_HORIZONTAL_PADDING = 2f; // 签名区域左右内边距
    private static final float SIGNATURE_ROLE_OFFSET = -26f;      // 编制/审核等角色向左偏移
    private static final Set<String> ROLE_LEFT_SHIFT_LABELS = Set.of("编制", "审核", "批准");
    private static final float MIN_SIGNATURE_BLOCK_HEIGHT_PT = 120f; // 收紧签章区高度，约 80pt
    private static final float SIGNATURE_FONT_SIZE = 11f;  // 左列字体
    private static final float STAMP_FONT_SIZE = 11f; // 右列“公司（盖章）”字体
    private static final float STAMP_VERTICAL_SHIFT = -18f; // 盖章文字整体向下偏移
    private static final float LABEL_GAP = 60f; // 各标签之间的水平间距

    // 内容与边框的统一间距
    private static final float CONTENT_TOP_MARGIN = 3f;     // 内容顶部与边框的间距
    private static final float CONTENT_BOTTOM_MARGIN = 0f;  // 内容底部与边框的间距

    // 各区域的专用间距
    private static final float TABLE_TOP_MARGIN = 2f;        // 表格顶部间距（顶部间距的一半）
    private static final float TABLE_BOTTOM_MARGIN = 2f;     // 表格底部间距（底部间距的一半）
    private static final float PARAGRAPH_BOTTOM_MARGIN = 2f;  // 段落底部间距（底部间距的1/3）
    private static final float SIGNATURE_BOTTOM_MARGIN = -8f; // 签名区域底部间距（底部间距的1.5倍）

    @Override
    public void render(PDDocument document,
                       ContractPdfPayload payload,
                       ContractPdfPayload.PageThree pageData,
                       ContractPdfPageContext pageContext,
                       ContractPdfDrawUtils utils) throws IOException {
        log.debug("Page Three renderer invoked for contract {}", payload != null && payload.meta() != null ? payload.meta().contractId() : null);
        ContractPdfPayload.PageThree data = pageData != null ? pageData : ContractPdfPayload.sample().page3();

        ContractPdfPageContext.BodyCanvas canvas = pageContext.newPage();
        try (PDPageContentStream content = canvas.content()) {
            // 调整起始位置到页眉下方（页眉分割线在780，内容应从分割线下方开始）
            float headerBottomY = 780f - 45f; // 页眉分割线下方留出更少空间
            float cursorY = headerBottomY; // 直接使用页眉下方的位置
            log.debug("Using cursorY = {} (headerBottomY)", cursorY);
            utils.drawCenteredText(content, utils.font(), TITLE_FONT_SIZE, ContractPdfAssets.PAGE_WIDTH / 2f, cursorY, "报告首页");
            cursorY -= 30f;

            float panelTop = cursorY;
            float panelWidth = PANEL_RIGHT - PANEL_LEFT;
            cursorY -= CONTENT_TOP_MARGIN;

            cursorY = drawSectionHeadingWithBox(content, utils, "申请检测产品基础情况：", cursorY - 6f);
            boolean hasBasicInfo = data.basicInfo() != null && !data.basicInfo().isEmpty();
            if (hasBasicInfo) {
                cursorY = drawBasicInfoGrid(content, utils, data.basicInfo(), cursorY);
            } else {
                cursorY -= SECTION_GAP; // 无数据时只保留标题，收紧间距
            }
            cursorY = drawDivider(content, cursorY);

            cursorY = drawParagraphList(content, utils, data.descriptions(), cursorY);
            cursorY = drawDivider(content, cursorY);

            // cursorY = drawSectionHeading(content, utils, "本申请单元所覆盖的其他产品型号规格及相关情况说明：", cursorY);
            cursorY = drawParagraphList(content, utils, data.modelInfo(), cursorY);
            cursorY -= 30f;
            cursorY = drawDivider(content, cursorY);

            cursorY = drawSectionHeading(content, utils, "测试依据标准", cursorY - 10f);
            boolean hasStandards = data.standards() != null && !data.standards().isEmpty();
            if (hasStandards) {
                cursorY = drawStandards(content, utils, data.standards(), cursorY);
            } else {
                cursorY -= SECTION_GAP; // 无数据时仅保留标题
            }
            cursorY = drawDivider(content, cursorY);

            cursorY -= 6f;
            String conclusionRaw = data.conclusion();
            boolean hasConclusion = conclusionRaw != null && !conclusionRaw.isBlank();
            String conclusionText = "测试结论：" + (hasConclusion ? conclusionRaw.trim() : "");
            utils.showText(content, utils.font(), BODY_FONT_SIZE, PANEL_LEFT + PANEL_PADDING_X, cursorY, conclusionText);
            cursorY -= hasConclusion ? ROW_HEIGHT : ROW_HEIGHT / 2f;

            float signatureDividerTopY = cursorY + 14f;
            cursorY = drawDivider(content, signatureDividerTopY);
            cursorY = drawSectionHeading(content, utils, "", cursorY);
            SignatureAreaResult signatureResult = drawSignatureArea(content, utils, data.signatures(), cursorY);
            cursorY = signatureResult.cursorY();
            float signatureDividerBottomY = cursorY + 8f;
            boolean hasRemark = data.remark() != null && !data.remark().isBlank();
            cursorY = hasRemark ? drawDivider(content, signatureDividerBottomY) : signatureDividerBottomY;
            if (signatureResult.hasContent()) {
                drawSignatureVerticalDivider(content, signatureResult.dividerX(), signatureDividerTopY, signatureDividerBottomY);
            }

            if (hasRemark) {
                cursorY -= (BODY_FONT_SIZE - 2f);
                String remarkText = data.remark().trim();
                utils.showText(content, utils.font(), BODY_FONT_SIZE, PANEL_LEFT + PANEL_PADDING_X, cursorY, "备注：" + remarkText);
                cursorY -= (ROW_HEIGHT - 12f);
            }

            // 始终保留外框，内部 remark 区域可为空
            float panelBottom = cursorY - CONTENT_BOTTOM_MARGIN;
            if (panelTop > panelBottom + 2f) {
                content.saveGraphicsState();
                content.setLineWidth(0.5f);
                content.addRect(PANEL_LEFT, panelBottom, panelWidth, panelTop - panelBottom);
                content.stroke();
                content.restoreGraphicsState();
            }
        }
    }

    private float drawSectionHeadingWithBox(PDPageContentStream content, ContractPdfDrawUtils utils, String title, float cursorY) throws IOException {
        // 计算线条间距，让文字居中
        float lineSpacing = BODY_FONT_SIZE + 6f; // 总的线条间距
        float textY = cursorY - lineSpacing / 2f; // 文字在两条线中间

        // 绘制标题文字
        utils.showText(content, utils.font(), BODY_FONT_SIZE + 0.5f, PANEL_LEFT + PANEL_PADDING_X, textY, title);

        // 绘制连接到左右大边框的下划线
        content.saveGraphicsState();
        content.setLineWidth(1.0f);
        content.saveGraphicsState();
        content.setLineWidth(0.5f);
        content.moveTo(PANEL_LEFT, cursorY - lineSpacing);  // 从左边框开始
        content.lineTo(PANEL_RIGHT, cursorY - lineSpacing); // 连接到右边框
        content.stroke();
        content.restoreGraphicsState();
        content.restoreGraphicsState();

        return cursorY - lineSpacing;
    }

    private float drawSectionHeading(PDPageContentStream content, ContractPdfDrawUtils utils, String title, float cursorY) throws IOException {
        utils.showText(content, utils.font(), BODY_FONT_SIZE + 0.5f, PANEL_LEFT + PANEL_PADDING_X, cursorY, title);
        return cursorY - (BODY_FONT_SIZE + 6f);
    }

    private float drawBasicInfoGrid(PDPageContentStream content, ContractPdfDrawUtils utils, List<LabeledValue> info, float cursorY) throws IOException {
        List<LabeledValue> entries = info != null ? info : Collections.emptyList();
        List<String[]> rows = new ArrayList<>();
        for (int i = 0; i < entries.size(); i += 2) {
            String left = formatPair(entries.get(i));
            String right = i + 1 < entries.size() ? formatPair(entries.get(i + 1)) : "";
            rows.add(new String[] { left, right });
        }

        float startYCursor = cursorY;
        cursorY -= (TABLE_TOP_MARGIN - 4f); // 使用表格专用的顶部间距
        float leftColumnX = PANEL_LEFT + PANEL_PADDING_X;
        float midX = PANEL_LEFT + (PANEL_RIGHT - PANEL_LEFT) / 2f;
        float rightColumnX = midX + 8f;

        for (String[] row : rows) {
            // 直接将文字基线设在行中心位置（视觉居中）
            float baseline = cursorY - ROW_HEIGHT / 2f - 8f;
            utils.showText(content, utils.font(), BODY_FONT_SIZE, leftColumnX, baseline, row[0]);
            if (!row[1].isEmpty()) {
                utils.showText(content, utils.font(), BODY_FONT_SIZE, rightColumnX, baseline, row[1]);
            }
            cursorY -= ROW_HEIGHT;
        }

        float endCursorY = cursorY - TABLE_BOTTOM_MARGIN - 4f; // 使用表格专用的底部间距

        // 垂直分割线
        float dividerTop = startYCursor;
        content.saveGraphicsState();
        content.setLineWidth(0.5f);
        content.moveTo(midX, dividerTop);
        content.lineTo(midX, endCursorY);
        content.stroke();
        content.restoreGraphicsState();

        return endCursorY;
    }

    private String formatPair(LabeledValue pair) {
        if (pair == null) {
            return "";
        }
        return (pair.label() == null ? "" : pair.label()) + "：" + (pair.value() == null ? "" : pair.value());
    }

    private float drawParagraphList(PDPageContentStream content, ContractPdfDrawUtils utils, List<Paragraph> paragraphs, float cursorY) throws IOException {
        if (paragraphs == null || paragraphs.isEmpty()) {
            return cursorY;
        }
        float width = PANEL_RIGHT - PANEL_LEFT - PANEL_PADDING_X * 2;
        for (Paragraph paragraph : paragraphs) {
            List<String> lines = utils.wrapText(utils.font(), BODY_FONT_SIZE, paragraph.text(), width);
            for (String line : lines) {
                float baseline = cursorY - BODY_FONT_SIZE;
                utils.showText(content, utils.font(), BODY_FONT_SIZE, PANEL_LEFT + PANEL_PADDING_X, baseline, line);
                cursorY -= (BODY_FONT_SIZE + 6f);
            }
        }
        return cursorY - PARAGRAPH_BOTTOM_MARGIN; // 使用段落专用的底部间距
    }

    private float drawStandards(PDPageContentStream content, ContractPdfDrawUtils utils, List<StandardEntry> standards, float cursorY) throws IOException {
        List<StandardEntry> entries = standards != null ? standards : Collections.emptyList();
        if (log.isDebugEnabled()) {
            for (StandardEntry entry : entries) {
                log.debug("测试依据标准条目 -> order: {}, code: {}, title: {}, reference: {}",
                        entry.order(), entry.code(), entry.title(), entry.reference());
            }
        }
        int index = 1;
        for (StandardEntry entry : entries) {
            String code = Objects.toString(entry.code(), "").trim();
            String title = Objects.toString(entry.title(), "").trim();
            String reference = Objects.toString(entry.reference(), "").trim();
            List<String> parts = new ArrayList<>();
            if (!code.isEmpty()) {
                parts.add(code);
            }
            if (!title.isEmpty()) {
                parts.add(title);
            }
            String combined = String.join(" · ", parts);
            String label = combined.isEmpty() ? reference : combined;
            if (label.isEmpty()) {
                label = "-";
            }

            String text = String.format("%d. %s", entry.order() != null ? entry.order() : index, label);
            utils.showText(content, utils.font(), BODY_FONT_SIZE, PANEL_LEFT + PANEL_PADDING_X, cursorY, text);
            cursorY -= 15f;
            index++;
        }
        return cursorY - TABLE_BOTTOM_MARGIN; // 标准列表使用表格间距
    }

    private float drawDeclaration(PDPageContentStream content, ContractPdfDrawUtils utils, Declaration declaration, float cursorY) throws IOException {
        if (declaration == null || declaration.lines() == null) {
            return cursorY;
        }
        float width = PANEL_RIGHT - PANEL_LEFT - PANEL_PADDING_X * 2;
        for (String line : declaration.lines()) {
            List<String> wrapped = utils.wrapText(utils.font(), BODY_FONT_SIZE, line, width);
            for (String piece : wrapped) {
                utils.showText(content, utils.font(), BODY_FONT_SIZE, PANEL_LEFT + PANEL_PADDING_X, cursorY, piece);
                cursorY -= 15f;
            }
        }
        return cursorY - PARAGRAPH_BOTTOM_MARGIN; // 声明使用段落间距
    }

    private SignatureAreaResult drawSignatureArea(PDPageContentStream content, ContractPdfDrawUtils utils, List<SignatureSlot> slots, float cursorY) throws IOException {
        List<SignatureSlot> entries = slots != null ? slots : Collections.emptyList();
        if (entries.isEmpty()) {
            return new SignatureAreaResult(cursorY, (PANEL_LEFT + PANEL_RIGHT) / 2f, false);
        }

        float fontSize = SIGNATURE_FONT_SIZE;
        float fullSectionWidth = (PANEL_RIGHT - PANEL_LEFT) * SIGNATURE_SECTION_WIDTH_RATIO;
        float sectionLeft = PANEL_LEFT + (PANEL_RIGHT - PANEL_LEFT - fullSectionWidth) / 2f;
        float sectionRight = sectionLeft + fullSectionWidth;
        float innerLeft = sectionLeft + SIGNATURE_HORIZONTAL_PADDING - SIGNATURE_LEFT_SHIFT;
        float innerRight = sectionRight - SIGNATURE_HORIZONTAL_PADDING;
        float availableWidth = innerRight - innerLeft;
        float leftColumnWidth = availableWidth * 0.65f;
        float rightColumnWidth = availableWidth - leftColumnWidth;
        float dividerX = innerLeft + leftColumnWidth;

        float rowGap = 30f;

        float rowsHeight = entries.size() * rowGap;
        float rightColumnHeight = (STAMP_FONT_SIZE * 2.1f); // 右栏两行高度估算
        float padding = 1f;
        float blockHeight = Math.max(Math.max(rowsHeight + padding, rightColumnHeight + padding), MIN_SIGNATURE_BLOCK_HEIGHT_PT);
        float blockTop = cursorY + 24f; // 收紧与上方段落的间距
        float blockBottom = blockTop - blockHeight;

        float availableHeight = blockHeight - padding;
        float extraSpace = Math.max(0f, availableHeight - rowsHeight);
        cursorY = blockTop - padding / 2f - extraSpace / 2f;
        for (SignatureSlot slot : entries) {
            String roleRaw = Objects.toString(slot.role(), "");
            String roleText = roleRaw.isBlank() ? "" : String.format("%s：", roleRaw);
            String dateLabel = Objects.toString(slot.dateLabel(), "日期");
            float baseline = cursorY - ROW_HEIGHT / 2f + fontSize / 4f;

            boolean shouldShift = ROLE_LEFT_SHIFT_LABELS.contains(roleRaw.trim());
            float roleX = innerLeft + (shouldShift ? SIGNATURE_ROLE_OFFSET : 0f);
            utils.showText(content, utils.font(), fontSize, roleX, baseline, roleText);

            float roleWidth = utils.measureText(utils.font(), fontSize, roleText);
            String signatureLabel = "签名：";
            float signatureLabelX = roleX + roleWidth + LABEL_GAP;
            utils.showText(content, utils.font(), fontSize, signatureLabelX, baseline, signatureLabel);

            float signatureWidth = utils.measureText(utils.font(), fontSize, signatureLabel);
            String dateText = String.format("%s：", dateLabel);
            float dateLabelX = signatureLabelX + signatureWidth + LABEL_GAP;
            utils.showText(content, utils.font(), fontSize, dateLabelX, baseline, dateText);

            cursorY -= rowGap;
        }

        float rightColumnCenter = dividerX + rightColumnWidth / 2f + STAMP_RIGHT_SHIFT;
        float blockCenterY = blockBottom + blockHeight / 2f;
        float stampFirstLineY = blockCenterY + (STAMP_FONT_SIZE / 2f) + STAMP_VERTICAL_SHIFT;
        float stampSecondLineY = stampFirstLineY - (STAMP_FONT_SIZE);

        utils.drawCenteredText(content, utils.font(), STAMP_FONT_SIZE, rightColumnCenter, stampFirstLineY, "中山市鑫普达检测有限公司");
        utils.drawCenteredText(content, utils.font(), STAMP_FONT_SIZE, rightColumnCenter, stampSecondLineY, "（盖章）");

        return new SignatureAreaResult(blockBottom - SIGNATURE_BOTTOM_MARGIN, dividerX, true);
    }

    private float drawDivider(PDPageContentStream content, float cursorY) throws IOException {
        content.saveGraphicsState();
        content.setLineWidth(0.5f);
        content.moveTo(PANEL_LEFT, cursorY);
        content.lineTo(PANEL_RIGHT, cursorY);
        content.stroke();
        content.restoreGraphicsState();
        return cursorY - SECTION_GAP;
    }

    private void drawSignatureVerticalDivider(PDPageContentStream content, float dividerX, float topY, float bottomY) throws IOException {
        content.saveGraphicsState();
        content.setLineWidth(0.5f);
        content.moveTo(dividerX, topY);
        content.lineTo(dividerX, bottomY);
        content.stroke();
        content.restoreGraphicsState();
    }

    private record SignatureAreaResult(float cursorY, float dividerX, boolean hasContent) {}
}
