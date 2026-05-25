package com.luang.pdfsigner.service.renderer;

import com.luang.pdfsigner.service.ContractPdfAssets;
import com.luang.pdfsigner.service.ContractPdfPayload;
import com.luang.pdfsigner.service.ContractPdfPayload.PageSix.DeviceEntry;
import com.luang.pdfsigner.service.ContractPdfPayload.PageSeven;
import java.io.IOException;
import java.util.Collections;
import java.util.List;
import org.apache.pdfbox.pdmodel.PDDocument;
import org.apache.pdfbox.pdmodel.PDPageContentStream;
import org.slf4j.Logger;
import org.slf4j.LoggerFactory;

public class PageSixRenderer implements ContractPdfPageRenderer<ContractPdfPayload.PageSix> {

    private static final Logger log = LoggerFactory.getLogger(PageSixRenderer.class);
    private static final float TABLE_LEFT = 60f;
    // 总宽度: 40+135+95+105+60+50 = 485，适配页内可视宽度
    private static final float[] COLUMN_WIDTHS = new float[] { 38f, 134f, 92f, 104f, 66f, 50f };
    private static final float HEADER_HEIGHT = 32f;
    private static final float BASE_ROW_HEIGHT = 24f;
    private static final float FONT_SIZE = 10f;
    private static final float CELL_PADDING = 6f;

    private static final String[][] HEADER_LABELS = new String[][] {
            { "序号" },
            { "名称" },
            { "型号" },
            { "仪器编号" },
            { "校准", "有效期" },
            { "本次使用", "(√)" }
    };

    @Override
    public void render(PDDocument document,
                       ContractPdfPayload payload,
                       ContractPdfPayload.PageSix pageData,
                       ContractPdfPageContext pageContext,
                       ContractPdfDrawUtils utils) throws IOException {
        render(document, payload, pageData, pageContext, utils, null);
    }

    public void render(PDDocument document,
                       ContractPdfPayload payload,
                       ContractPdfPayload.PageSix pageData,
                       ContractPdfPageContext pageContext,
                       ContractPdfDrawUtils utils,
                       PageSeven pageSeven) throws IOException {
        log.debug("Page Six renderer invoked for contract {}", payload != null && payload.meta() != null ? payload.meta().contractId() : null);
        ContractPdfPayload.PageSix data = pageData != null ? pageData : ContractPdfPayload.sample().page6();
        List<DeviceEntry> entries = data.devices() != null ? data.devices() : Collections.emptyList();

        ContractPdfPageContext.BodyCanvas canvas = pageContext.newPage();
        PDPageContentStream content = canvas.content();
        float cursorY = 780f - 45f;
        utils.drawCenteredText(content, utils.font(), 18f, ContractPdfAssets.PAGE_WIDTH / 2f, cursorY, "实验仪器设备清单");
        cursorY -= 24f;
        drawHeader(content, utils, cursorY);
        cursorY -= HEADER_HEIGHT;

        int index = 0;
        for (DeviceEntry entry : entries) {
            float rowHeight = computeRowHeight(utils, entry);
            if (cursorY - rowHeight < canvas.contentBottom() + 60f) {
                content.close();
                canvas = pageContext.newPage();
                content = canvas.content();
                cursorY = canvas.contentTop() - 40f;
                drawHeader(content, utils, cursorY);
                cursorY -= HEADER_HEIGHT;
            }
            drawDeviceRow(content, utils, entry, cursorY, index + 1, rowHeight);
            cursorY -= rowHeight;
            index++;
        }

        // 可选备注
        if (data.notes() != null) {
            cursorY -= 16f;
            for (String note : data.notes()) {
                utils.showText(content, utils.font(), FONT_SIZE, TABLE_LEFT, cursorY, note);
                cursorY -= 14f;
            }
        }

        // 继续渲染第七页内容在当前页内（仅文本块）
        if (pageSeven != null) {
            cursorY -= 20f;
            cursorY = drawPageSevenInline(content, utils, pageSeven, cursorY, canvas.contentBottom());
        }
        content.close();
    }

    private void drawHeader(PDPageContentStream content, ContractPdfDrawUtils utils, float topY) throws IOException {
        float x = TABLE_LEFT;
        for (int i = 0; i < COLUMN_WIDTHS.length; i++) {
            float width = COLUMN_WIDTHS[i];
            content.saveGraphicsState();
            content.setLineWidth(0.5f);
            content.addRect(x, topY - HEADER_HEIGHT, width, HEADER_HEIGHT);
            content.stroke();
            content.restoreGraphicsState();

            String[] lines = HEADER_LABELS[i];
            float lineHeight = FONT_SIZE + 2f;
            float blockHeight = lines.length * lineHeight;
            float startY = topY - (HEADER_HEIGHT - blockHeight) / 2f - FONT_SIZE;
            for (int j = 0; j < lines.length; j++) {
                String text = lines[j];
                float textWidth = utils.measureText(utils.font(), FONT_SIZE, text);
                float baseline = startY - j * lineHeight;
                float startX = x + (width - textWidth) / 2f;
                utils.showText(content, utils.font(), FONT_SIZE, startX, baseline, text);
            }
            x += width;
        }
    }

    private void drawDeviceRow(PDPageContentStream content, ContractPdfDrawUtils utils, DeviceEntry entry, float topY, int order, float rowHeight) throws IOException {
        float x = TABLE_LEFT;
        String[] values = new String[] {
                order + ".",
                entry.name(),
                entry.model(),
                entry.deviceNo(),
                entry.calibrationDue(),
                entry.isUsed() != null && entry.isUsed() ? "√" : ""
        };
        for (int i = 0; i < COLUMN_WIDTHS.length; i++) {
            float width = COLUMN_WIDTHS[i];
            content.saveGraphicsState();
            content.setLineWidth(0.5f);
            content.addRect(x, topY - rowHeight, width, rowHeight);
            content.stroke();
            content.restoreGraphicsState();
            String text = values[i] == null ? "" : values[i];
            List<String> lines = utils.wrapText(utils.font(), FONT_SIZE, text, width - CELL_PADDING * 2);
            float lineHeight = FONT_SIZE + 2f;
            float blockHeight = lines.size() * lineHeight;
            float startY = topY - (rowHeight - blockHeight) / 2f - FONT_SIZE;
            for (int j = 0; j < lines.size(); j++) {
                String line = lines.get(j);
                float textWidth = utils.measureText(utils.font(), FONT_SIZE, line);
                float baseline = startY - j * lineHeight;
                float startX = x + (width - textWidth) / 2f;
                utils.showText(content, utils.font(), FONT_SIZE, startX, baseline, line);
            }
            x += width;
        }
    }

    private float computeRowHeight(ContractPdfDrawUtils utils, DeviceEntry entry) throws IOException {
        float lineHeight = FONT_SIZE + 2f;
        float maxLines = 1f;
        String[] values = new String[] {
                entry.name(),
                entry.model(),
                entry.deviceNo(),
                entry.calibrationDue()
        };
        int[] columns = new int[] { 1, 2, 3, 4 }; // columns to wrap check
        for (int colIndex : columns) {
            float width = COLUMN_WIDTHS[colIndex];
            String value = values[colIndex - 1] == null ? "" : values[colIndex - 1];
            List<String> lines = utils.wrapText(utils.font(), FONT_SIZE, value, width - CELL_PADDING * 2);
            maxLines = Math.max(maxLines, lines.size());
        }
        return Math.max(BASE_ROW_HEIGHT, maxLines * lineHeight + 6f);
    }

    private float drawPageSevenInline(PDPageContentStream content,
                                      ContractPdfDrawUtils utils,
                                      PageSeven pageSeven,
                                      float cursorY,
                                      float bottomLimit) throws IOException {
        List<String> points = pageSeven.bulletPoints();
        if (points != null && !points.isEmpty()) {
            // 标题
            if (cursorY - BASE_ROW_HEIGHT < bottomLimit + 40f) {
                return cursorY;
            }
            utils.showText(content, utils.font(), FONT_SIZE, TABLE_LEFT, cursorY - FONT_SIZE, "测试条件：");
            cursorY -= BASE_ROW_HEIGHT;

            for (int i = 0; i < points.size(); i++) {
                if (cursorY - BASE_ROW_HEIGHT < bottomLimit + 40f) {
                    break;
                }
                String text = String.format("%d. %s", i + 1, points.get(i));
                utils.showText(content, utils.font(), FONT_SIZE, TABLE_LEFT, cursorY - FONT_SIZE, text);
                cursorY -= BASE_ROW_HEIGHT;
            }
        }

        // 如果没有 bulletPoints，则尝试渲染声明行作为文本块
        else if (pageSeven.statement() != null && pageSeven.statement().lines() != null) {
            for (String line : pageSeven.statement().lines()) {
                if (cursorY - BASE_ROW_HEIGHT < bottomLimit + 40f) {
                    break;
                }
                utils.showText(content, utils.font(), FONT_SIZE, TABLE_LEFT, cursorY - FONT_SIZE, line);
                cursorY -= BASE_ROW_HEIGHT;
            }
        }
        return cursorY;
    }
}
