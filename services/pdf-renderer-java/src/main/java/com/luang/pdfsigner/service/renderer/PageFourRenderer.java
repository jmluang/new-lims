package com.luang.pdfsigner.service.renderer;

import com.luang.pdfsigner.service.ContractPdfAssets;
import com.luang.pdfsigner.service.ContractPdfPayload;
import com.luang.pdfsigner.service.ContractPdfPayload.PageFour;
import java.io.IOException;
import java.util.HashSet;
import java.util.List;
import java.util.Set;
import org.apache.pdfbox.pdmodel.PDDocument;
import org.apache.pdfbox.pdmodel.PDPageContentStream;
import org.slf4j.Logger;
import org.slf4j.LoggerFactory;

public class PageFourRenderer implements ContractPdfPageRenderer<ContractPdfPayload.PageFour> {

    private static final Logger log = LoggerFactory.getLogger(PageFourRenderer.class);

    private static final float PAGE_LEFT = 60f;
    private static final float PAGE_RIGHT = ContractPdfAssets.PAGE_WIDTH - 60f;
    private static final float PAGE_WIDTH = PAGE_RIGHT - PAGE_LEFT;
    private static final float TITLE_FONT_SIZE = 20f;
    private static final float LABEL_FONT_SIZE = 10f;
    private static final float VALUE_FONT_SIZE = 10f;
    private static final float ROW_HEIGHT = 26f;
    private static final float CHECKBOX_LINE_HEIGHT = 18f;
    private static final float CHECKBOX_SIZE = 10f;
    private static final float CHECKBOX_GAP = 4f;
    private static final float CHECKBOX_SPACING = 16f;
    private static final float LABEL_CELL_WIDTH = 90f;
    private static final float DOUBLE_COL_WIDTH = PAGE_WIDTH / 2f;
    private static final float SECTION_GAP = 60f;

    private static final List<Option> LAMP_TYPE_OPTIONS = List.of(
            new Option("indoor", "室内灯具"),
            new Option("outdoor", "室外灯具"),
            new Option("road", "道路灯具"),
            new Option("flood", "投光灯具"),
            new Option("module", "模块"),
            new Option("other", "其他")
    );

    private static final List<Option> LIGHT_SOURCE_OPTIONS = List.of(
            new Option("led", "LED"),
            new Option("low-pressure-mercury", "低压汞灯"),
            new Option("fluorescent", "荧光灯"),
            new Option("high-pressure-sodium", "高压钠灯"),
            new Option("metal-halide", "金卤灯"),
            new Option("high-pressure-mercury", "高压汞灯"),
            new Option("halogen", "卤素灯"),
            new Option("other", "其他"),
            new Option("tungsten", "钨丝灯")
    );

    private static final List<Option> TEST_ITEM_OPTIONS = List.of(
            new Option("electrical", "电参数"),
            new Option("cct", "色温"),
            new Option("luminous-flux", "光通量"),
            new Option("cri", "显色指数"),
            new Option("efficacy", "灯具光效"),
            new Option("chromaticity", "色坐标"),
            new Option("distribution", "配光曲线"),
            new Option("color-deviation", "色容差"),
            new Option("peak-intensity", "峰值光强"),
            new Option("beam-angle", "光束角"),
            new Option("other", "其他")
    );

    @Override
    public void render(PDDocument document,
                       ContractPdfPayload payload,
                       PageFour pageData,
                       ContractPdfPageContext pageContext,
                       ContractPdfDrawUtils utils) throws IOException {
        log.debug("Page Four renderer invoked for contract {}", payload != null && payload.meta() != null ? payload.meta().contractId() : null);
        PageFour data = pageData != null ? pageData.withDefaults() : ContractPdfPayload.sample().page4().withDefaults();

        ContractPdfPageContext.BodyCanvas canvas = pageContext.newPage();
        PDPageContentStream content = canvas.content();

        float headerBottomY = 780f - 45f; // 页眉分割线下方留出更少空间
        float cursorY = headerBottomY;

        utils.drawCenteredText(content, utils.font(), TITLE_FONT_SIZE, ContractPdfAssets.PAGE_WIDTH / 2f, cursorY, "样品描述");
        cursorY -= 20f;

        cursorY = drawSampleDescription(content, utils, data, cursorY);
        cursorY -= SECTION_GAP;
        cursorY = drawTestItems(content, utils, data, cursorY);

        content.close();
    }

    private float drawSampleDescription(PDPageContentStream content, ContractPdfDrawUtils utils, PageFour data, float topY) throws IOException {
        float y = topY;

        // 行 1：灯具类型（复选）
        float lampRowHeight = computeCheckboxBlockHeight(utils, LAMP_TYPE_OPTIONS, data.lampTypes(), PAGE_WIDTH - LABEL_CELL_WIDTH, 0);
        drawLabeledRowBorder(content, PAGE_LEFT, y, PAGE_WIDTH, lampRowHeight);
        drawLabelCell(content, utils, "灯具类型：", PAGE_LEFT, y, LABEL_CELL_WIDTH, lampRowHeight);
        drawCheckboxBlock(content, utils, LAMP_TYPE_OPTIONS, data.lampTypes(), PAGE_LEFT + LABEL_CELL_WIDTH + 6f, y - 4f, PAGE_WIDTH - LABEL_CELL_WIDTH - 12f, lampRowHeight, 0);
        y -= lampRowHeight;

        // 行 2：额定电压 / 额定频率
        drawDualValueRow(content, utils, "额定电压：", ensureUnit(safe(data.rated().voltage()), "V"), "额定频率：", ensureUnit(safe(data.rated().frequency()), "Hz"), y);
        y -= ROW_HEIGHT;

        // 行 3：额定功率 / 额定色温
        drawDualValueRow(content, utils, "额定功率：", ensureUnit(safe(data.rated().power()), "W"), "额定色温：", safe(data.rated().cct()), y);
        y -= ROW_HEIGHT;

        // 行 4：外观尺寸（与上方列宽保持一致）
        drawDualValueRow(content, utils, "外观尺寸：", safe(data.dimensions()), "", "", y);
        y -= ROW_HEIGHT;

        // 行 5：发光口面积（单独一行，保持同宽）
        drawDualValueRow(content, utils, "发光口面积：", safe(data.luminousPortArea()), "", "", y);
        y -= ROW_HEIGHT;

        // 行 6：光源类型（复选）
        float lightRowHeight = computeCheckboxBlockHeight(utils, LIGHT_SOURCE_OPTIONS, data.lightSourceTypes(), PAGE_WIDTH - LABEL_CELL_WIDTH, 2);
        drawLabeledRowBorder(content, PAGE_LEFT, y, PAGE_WIDTH, lightRowHeight);
        drawLabelCell(content, utils, "光源类型：", PAGE_LEFT, y, LABEL_CELL_WIDTH, lightRowHeight);
        drawCheckboxBlock(content, utils, LIGHT_SOURCE_OPTIONS, data.lightSourceTypes(), PAGE_LEFT + LABEL_CELL_WIDTH + 6f, y, PAGE_WIDTH - LABEL_CELL_WIDTH - 12f, lightRowHeight, 2);
        y -= lightRowHeight;

        // 行 7：备注（整行，无竖线分割）
        drawPlainRow(content, utils, "备注：" + safe(data.remarks()), y);
        y -= ROW_HEIGHT;

        // 外框
        float totalHeight = topY - y;
        drawOuterBorder(content, PAGE_LEFT, y, PAGE_WIDTH, totalHeight);

        return y;
    }

    private float drawTestItems(PDPageContentStream content, ContractPdfDrawUtils utils, PageFour data, float topY) throws IOException {
        float y = topY;

        utils.drawCenteredText(content, utils.font(), TITLE_FONT_SIZE, ContractPdfAssets.PAGE_WIDTH / 2f, y, "检测项目");
        y -= 20f;
        float panelTop = y;

        float checkboxStartX = PAGE_LEFT + LABEL_CELL_WIDTH + 6f;
        float checkboxWidth = PAGE_WIDTH - LABEL_CELL_WIDTH - 12f;
        float checkboxHeight = computeCheckboxBlockHeight(utils, TEST_ITEM_OPTIONS, data.testItems(), checkboxWidth, 2);
        drawRowBorder(content, PAGE_LEFT, y, PAGE_WIDTH, checkboxHeight);
        drawCheckboxBlock(content, utils, TEST_ITEM_OPTIONS, data.testItems(), checkboxStartX, y, checkboxWidth, checkboxHeight, 2);
        y -= checkboxHeight;

        String testRemark = !safe(data.detectionRemark()).isBlank() ? data.detectionRemark()
                : (!safe(data.testItemsOther()).isBlank() ? data.testItemsOther() : data.remarks());
        drawPlainRow(content, utils, "备注：" + safe(testRemark), y);
        y -= ROW_HEIGHT;

        float totalHeight = panelTop - y;
        drawOuterBorder(content, PAGE_LEFT, y, PAGE_WIDTH, totalHeight);
        return y;
    }

    private void drawDualValueRow(PDPageContentStream content,
                                  ContractPdfDrawUtils utils,
                                  String label1,
                                  String value1,
                                  String label2,
                                  String value2,
                                  float topY) throws IOException {
        // 左列
        drawLabelCell(content, utils, label1, PAGE_LEFT, topY, LABEL_CELL_WIDTH, ROW_HEIGHT);
        drawValueCell(content, utils, value1, PAGE_LEFT + LABEL_CELL_WIDTH, topY, DOUBLE_COL_WIDTH - LABEL_CELL_WIDTH, ROW_HEIGHT);

        // 右列
        float rightColX = PAGE_LEFT + DOUBLE_COL_WIDTH;
        drawLabelCell(content, utils, label2, rightColX, topY, LABEL_CELL_WIDTH, ROW_HEIGHT);
        drawValueCell(content, utils, value2, rightColX + LABEL_CELL_WIDTH, topY, DOUBLE_COL_WIDTH - LABEL_CELL_WIDTH, ROW_HEIGHT);
    }

    private void drawSingleValueRow(PDPageContentStream content,
                                    ContractPdfDrawUtils utils,
                                    String label,
                                    String value,
                                    float topY) throws IOException {
        drawLabelCell(content, utils, label, PAGE_LEFT, topY, LABEL_CELL_WIDTH, ROW_HEIGHT);
        drawValueCell(content, utils, value, PAGE_LEFT + LABEL_CELL_WIDTH, topY, PAGE_WIDTH - LABEL_CELL_WIDTH, ROW_HEIGHT);
    }

    private void drawPlainRow(PDPageContentStream content,
                              ContractPdfDrawUtils utils,
                              String value,
                              float topY) throws IOException {
        drawRowBorder(content, PAGE_LEFT, topY, PAGE_WIDTH, ROW_HEIGHT);
        float baseline = centeredBaseline(topY, ROW_HEIGHT, VALUE_FONT_SIZE);
        utils.showText(content, utils.font(), VALUE_FONT_SIZE, PAGE_LEFT + 8f, baseline, value);
    }

    private void drawFourCellRow(PDPageContentStream content,
                                 ContractPdfDrawUtils utils,
                                 String[] texts,
                                 float topY) throws IOException {
        float cellWidth = PAGE_WIDTH / 4f;
        for (int i = 0; i < 4; i++) {
            float x = PAGE_LEFT + i * cellWidth;
            drawRowBorder(content, x, topY, cellWidth, ROW_HEIGHT);
            String text = texts != null && i < texts.length ? safe(texts[i]) : "";
            float baseline = centeredBaseline(topY, ROW_HEIGHT, VALUE_FONT_SIZE);
            float textWidth = utils.measureText(utils.font(), VALUE_FONT_SIZE, text);
            float startX = x + (cellWidth - textWidth) / 2f;
            utils.showText(content, utils.font(), VALUE_FONT_SIZE, startX, baseline, text);
        }
    }

    private void drawCheckboxBlock(PDPageContentStream content,
                                   ContractPdfDrawUtils utils,
                                   List<Option> options,
                                   List<String> selected,
                                   float startX,
                                   float topY,
                                   float width,
                                   float blockHeight,
                                   int columns) throws IOException {
        Set<String> selectedSet = new HashSet<>(selected != null ? selected : List.of());
        if (columns > 1) {
            int rows = Math.max(1, (int) Math.ceil(options.size() / (float) columns));
            float columnWidth = (width - (columns - 1) * CHECKBOX_SPACING) / columns;
            for (int index = 0; index < options.size(); index++) {
                Option option = options.get(index);
                int col = index / rows;
                int row = index % rows;

                float x = startX + col * (columnWidth + CHECKBOX_SPACING);
                float lineCenter = topY - CHECKBOX_LINE_HEIGHT / 2f - 2f - row * CHECKBOX_LINE_HEIGHT;
                drawCheckbox(content, utils, selectedSet.contains(option.value), option.label, x, lineCenter);
            }
            return;
        }

        float x = startX;
        float lineCenter = topY - 10f;
        for (Option option : options) {
            float textWidth = utils.measureText(utils.font(), VALUE_FONT_SIZE, option.label);
            float totalWidth = CHECKBOX_SIZE + CHECKBOX_GAP + textWidth;
            if (x + totalWidth > startX + width) {
                x = startX;
                lineCenter -= CHECKBOX_LINE_HEIGHT;
            }
            drawCheckbox(content, utils, selectedSet.contains(option.value), option.label, x, lineCenter);
            x += totalWidth + CHECKBOX_SPACING;
        }
    }

    private float computeCheckboxBlockHeight(ContractPdfDrawUtils utils,
                                             List<Option> options,
                                             List<String> selected,
                                             float availableWidth,
                                             int columns) throws IOException {
        if (columns > 1) {
            int rows = Math.max(1, (int) Math.ceil(options.size() / (float) columns));
            return Math.max(ROW_HEIGHT, rows * CHECKBOX_LINE_HEIGHT + 8f);
        }
        float x = 0f;
        int lines = 1;
        for (Option option : options) {
            float textWidth = utils.measureText(utils.font(), VALUE_FONT_SIZE, option.label);
            float totalWidth = CHECKBOX_SIZE + CHECKBOX_GAP + textWidth;
            if (x + totalWidth > availableWidth) {
                lines++;
                x = 0f;
            }
            x += totalWidth + CHECKBOX_SPACING;
        }
        return Math.max(ROW_HEIGHT, lines * CHECKBOX_LINE_HEIGHT + 12f);
    }

    private void drawCheckbox(PDPageContentStream content,
                              ContractPdfDrawUtils utils,
                              boolean checked,
                              String label,
                              float x,
                              float lineCenterY) throws IOException {
        float boxY = lineCenterY - CHECKBOX_SIZE / 2f;
        content.saveGraphicsState();
        content.setLineWidth(0.5f);
        content.addRect(x, boxY, CHECKBOX_SIZE, CHECKBOX_SIZE);
        content.stroke();
        content.restoreGraphicsState();
        if (checked) {
            drawCheckMark(content, x, boxY);
        }
        float textBaseline = lineCenterY - VALUE_FONT_SIZE / 2f + 1.5f;
        utils.showText(content, utils.font(), VALUE_FONT_SIZE, x + CHECKBOX_SIZE + CHECKBOX_GAP, textBaseline, label);
    }

    private void drawCheckMark(PDPageContentStream content, float x, float boxY) throws IOException {
        // 使用直线绘制勾，无需字体字形
        content.moveTo(x + 2f, boxY + 5f);
        content.lineTo(x + 4.5f, boxY + 2f);
        content.lineTo(x + CHECKBOX_SIZE - 2f, boxY + CHECKBOX_SIZE - 2.5f);
        content.stroke();
    }

    private void drawLabelCell(PDPageContentStream content,
                               ContractPdfDrawUtils utils,
                               String text,
                               float x,
                               float topY,
                               float width,
                               float height) throws IOException {
        content.saveGraphicsState();
        content.setLineWidth(0.5f);
        content.addRect(x, topY - height, width, height);
        content.stroke();
        content.restoreGraphicsState();
        String safeText = safe(text);
        float baseline = centeredBaseline(topY, height, LABEL_FONT_SIZE);
        float textWidth = utils.measureText(utils.font(), LABEL_FONT_SIZE, safeText);
        float startX = x + (width - textWidth) / 2f;
        utils.showText(content, utils.font(), LABEL_FONT_SIZE, startX, baseline, safeText);
    }

    private void drawValueCell(PDPageContentStream content,
                               ContractPdfDrawUtils utils,
                               String text,
                               float x,
                               float topY,
                               float width,
                               float height) throws IOException {
        content.saveGraphicsState();
        content.setLineWidth(0.5f);
        content.addRect(x, topY - height, width, height);
        content.stroke();
        content.restoreGraphicsState();
        String safeText = safe(text);
        float baseline = centeredBaseline(topY, height, VALUE_FONT_SIZE);
        float textWidth = utils.measureText(utils.font(), VALUE_FONT_SIZE, safeText);
        float startX = x + (width - textWidth) / 2f;
        utils.showText(content, utils.font(), VALUE_FONT_SIZE, startX, baseline, safeText);
    }

    private void drawRowBorder(PDPageContentStream content, float x, float topY, float width, float height) throws IOException {
        content.saveGraphicsState();
        content.setLineWidth(0.5f);
        content.addRect(x, topY - height, width, height);
        content.stroke();
        content.restoreGraphicsState();
    }

    private void drawLabeledRowBorder(PDPageContentStream content, float x, float topY, float width, float height) throws IOException {
        drawRowBorder(content, x, topY, width, height);
        // 内部竖线在 label 右侧
        content.saveGraphicsState();
        content.setLineWidth(0.5f);
        content.moveTo(x + LABEL_CELL_WIDTH, topY);
        content.lineTo(x + LABEL_CELL_WIDTH, topY - height);
        content.stroke();
        content.restoreGraphicsState();
    }

    private void drawOuterBorder(PDPageContentStream content, float x, float bottomY, float width, float height) throws IOException {
        content.saveGraphicsState();
        content.setLineWidth(0.5f);
        content.addRect(x, bottomY, width, height);
        content.stroke();
        content.restoreGraphicsState();
    }

    private String safe(String value) {
        return value == null ? "" : value;
    }

    private String ensureUnit(String value, String unit) {
        if (value == null || value.isBlank()) {
            return value;
        }
        String trimmed = value.trim();
        return trimmed.endsWith(unit) ? trimmed : trimmed + unit;
    }

    private float centeredBaseline(float topY, float height, float fontSize) {
        return topY - (height / 2f) - (fontSize / 2f) + 2f;
    }

    private record Option(String value, String label) {
    }
}
