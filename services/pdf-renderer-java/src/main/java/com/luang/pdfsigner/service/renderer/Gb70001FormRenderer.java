package com.luang.pdfsigner.service.renderer;

import com.luang.pdfsigner.service.ContractPdfAssets;
import com.luang.pdfsigner.service.ContractPdfPayload;
import java.io.IOException;
import java.util.Map;
import org.apache.pdfbox.pdmodel.PDDocument;
import org.apache.pdfbox.pdmodel.PDPageContentStream;

/**
 * 渲染 GB 7000.1-2015 2 参数页（单页）。
 */
public class Gb70001FormRenderer {

    public void render(PDDocument document,
                       ContractPdfPayload payload,
                       Map<String, Object> templateData,
                       ContractPdfPageContext pageContext,
                       ContractPdfDrawUtils utils) throws IOException {
        ContractPdfPageContext.BodyCanvas canvas = pageContext.newPage();
        PDPageContentStream content = canvas.content();

        // 与“实验仪器设备清单”保持相同起始位置，保证标题对齐
        float cursorY = 780f - 45f;
        utils.drawCenteredText(content, utils.font(), 18f, ContractPdfAssets.PAGE_WIDTH / 2f, cursorY, "测试报告");
        cursorY -= 26f;

        cursorY = drawElectrical(content, utils, templateData, cursorY);
        cursorY -= 12f;
        cursorY = drawChroma(content, utils, templateData, cursorY);
        cursorY -= 12f;
        drawPhotometry(content, utils, templateData, cursorY);

        content.close();
    }

    private float drawElectrical(PDPageContentStream content,
                                 ContractPdfDrawUtils utils,
                                 Map<String, Object> data,
                                 float topY) throws IOException {
        String[][] headers = new String[][] {
                {"电压(V)", "电流(A)", "功率(W)", "功率因数", "备注"},
        };
        String[] values = new String[] {
                text(data, "electrical.voltage"),
                text(data, "electrical.current"),
                text(data, "electrical.power"),
                text(data, "electrical.powerFactor"),
                text(data, "electrical.remark"),
        };
        return drawSimpleTable(content, utils, "电参数", headers, values, topY);
    }

    private float drawChroma(PDPageContentStream content,
                             ContractPdfDrawUtils utils,
                             Map<String, Object> data,
                             float topY) throws IOException {
        String[][] headers = new String[][] {
                {"序号", "项目", "数据", "备注"},
        };
        String[][] rows = new String[][] {
                {"1", "色温(K)", text(data, "chroma.cct"), ""},
                {"2", "显色指数", text(data, "chroma.cri"), ""},
                {"3", "红色饱和 R9", text(data, "chroma.r9"), ""},
                {"4", "色度坐标 x", text(data, "chroma.cx"), ""},
                {"", "色度坐标 y", text(data, "chroma.cy"), ""},
                {"5", "色容差(SDCM)", text(data, "chroma.sdcm"), text(data, "chroma.remark")},
        };
        return drawStackedTable(content, utils, "色度参数", headers, rows, topY);
    }

    private float drawPhotometry(PDPageContentStream content,
                                 ContractPdfDrawUtils utils,
                                 Map<String, Object> data,
                                 float topY) throws IOException {
        String[][] headers = new String[][] {
                {"序号", "项目", "数据", "备注"},
        };
        String[][] rows = new String[][] {
                {"1", "光通量(LM)", text(data, "photometry.lumen"), ""},
                {"2", "灯具光效(LM/W)", text(data, "photometry.efficiency"), ""},
                {"3", "峰值光强(cd)", text(data, "photometry.peak"), ""},
                {"4", "光束角(50%)", "", ""},
                {"", "C0/C180", text(data, "photometry.beamC0"), ""},
                {"", "C90/C270", text(data, "photometry.beamC90"), ""},
                {"", "平均值", text(data, "photometry.beamAvg"), text(data, "photometry.remark")},
        };
        return drawStackedTable(content, utils, "光度参数", headers, rows, topY);
    }

    private float drawSimpleTable(PDPageContentStream content,
                                  ContractPdfDrawUtils utils,
                                  String title,
                                  String[][] headers,
                                  String[] values,
                                  float topY) throws IOException {
        float startX = 60f;
        float tableWidth = ContractPdfAssets.PAGE_WIDTH - 120f;
        float rowHeight = 26f;
        float headerHeight = 30f;

        utils.drawCenteredText(content, utils.font(), 14f, ContractPdfAssets.PAGE_WIDTH / 2f, topY, title);
        topY -= 16f;

        // Header
        float cursorY = topY;
        int cols = headers[0].length;
        float colWidth = tableWidth / cols;
        for (int i = 0; i < cols; i++) {
            float x = startX + i * colWidth;
            utils.drawTableCell(content, utils.font(), 11f, headers[0][i], x, cursorY - headerHeight, colWidth, headerHeight, true);
        }
        cursorY -= headerHeight;

        // Values (single row)
        for (int i = 0; i < cols; i++) {
            float x = startX + i * colWidth;
            String text = i < values.length ? values[i] : "";
            utils.drawTableCell(content, utils.font(), 11f, text, x, cursorY - rowHeight, colWidth, rowHeight, false);
        }

        return cursorY - rowHeight - 10f;
    }

    private float drawStackedTable(PDPageContentStream content,
                                   ContractPdfDrawUtils utils,
                                   String title,
                                   String[][] headers,
                                   String[][] rows,
                                   float topY) throws IOException {
        float startX = 60f;
        float tableWidth = ContractPdfAssets.PAGE_WIDTH - 120f;
        float rowHeight = 24f;
        float headerHeight = 28f;

        utils.drawCenteredText(content, utils.font(), 14f, ContractPdfAssets.PAGE_WIDTH / 2f, topY, title);
        topY -= 16f;

        // Header
        float cursorY = topY;
        int cols = headers[0].length;
        float colWidth = tableWidth / cols;
        for (int i = 0; i < cols; i++) {
            float x = startX + i * colWidth;
            utils.drawTableCell(content, utils.font(), 11f, headers[0][i], x, cursorY - headerHeight, colWidth, headerHeight, true);
        }
        cursorY -= headerHeight;

        for (String[] row : rows) {
            int effectiveCols = Math.min(cols, row.length);
            float height = rowHeight;
            for (int i = 0; i < effectiveCols; i++) {
                float x = startX + i * colWidth;
                String text = row[i] == null ? "" : row[i];
                utils.drawTableCell(content, utils.font(), 11f, text, x, cursorY - height, colWidth, height, false);
            }
            cursorY -= height;
        }

        return cursorY - 10f;
    }

    private String text(Map<String, Object> data, String path) {
        if (data == null || path == null) {
            return "";
        }
        String[] segments = path.split("\\.");
        Object current = data;
        for (String segment : segments) {
            if (!(current instanceof Map)) {
                return "";
            }
            current = ((Map<?, ?>) current).get(segment);
            if (current == null) {
                return "";
            }
        }
        return current.toString();
    }
}
