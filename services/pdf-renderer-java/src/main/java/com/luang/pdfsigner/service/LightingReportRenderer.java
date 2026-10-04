package com.luang.pdfsigner.service;

import com.luang.pdfsigner.dto.LightingReportPayload;
import java.awt.Color;
import java.io.ByteArrayOutputStream;
import java.io.IOException;
import java.io.OutputStream;
import java.nio.file.Files;
import java.nio.file.Path;
import java.util.ArrayList;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;
import org.apache.pdfbox.pdmodel.PDDocument;
import org.apache.pdfbox.pdmodel.PDPage;
import org.apache.pdfbox.pdmodel.PDPageContentStream;
import org.apache.pdfbox.pdmodel.common.PDRectangle;
import org.apache.pdfbox.pdmodel.font.PDFont;
import org.apache.pdfbox.pdmodel.font.PDType0Font;
import org.apache.pdfbox.pdmodel.graphics.image.PDImageXObject;
import org.apache.pdfbox.pdmodel.graphics.state.RenderingMode;
import org.springframework.stereotype.Service;

/** Draws the lighting report directly from values and image bytes, without a document converter. */
@Service
public final class LightingReportRenderer {
    private static final float WIDTH = PDRectangle.A4.getWidth();
    private static final float HEIGHT = PDRectangle.A4.getHeight();
    private static final float BODY_BOTTOM = 706;
    private static final float INFO_LEFT = 59.7f;
    private static final float LABEL_LEFT = 65.75f;
    private static final float VALUE_LEFT = 228.15f;
    private static final float INFO_WIDTH = 476;
    private static final float TABLE_LEFT = 56.7f;
    private static final float TABLE_WIDTH = 482;
    private static final Color BLUE = new Color(0, 112, 192);

    public byte[] render(LightingReportPayload payload) throws IOException {
        try (var output = new ByteArrayOutputStream()) {
            render(payload, output);
            return output.toByteArray();
        }
    }

    public void render(LightingReportPayload payload, OutputStream output) throws IOException {
        validate(payload);
        try (var document = new PDDocument(PdfFiles.streamCache())) {
            PDFont font = loadFont(document);
            Map<String, Integer> pages = new LinkedHashMap<>();
            drawCover(document, font, payload);
            document.addPage(new PDPage(PDRectangle.A4));
            drawInformation(document, font, payload, pages);
            drawParameters(document, font, payload, pages);
            drawPhotos(document, font, payload.photos(), pages);
            try (var canvas = new Canvas(document, document.getPage(1), font)) {
                drawContents(canvas, pages, loadHeadingFont(document, font));
            }
            for (int index = 0; index < document.getNumberOfPages(); index++) {
                try (var canvas = new Canvas(document, document.getPage(index), font)) {
                    drawHeaderFooter(canvas, payload.header(), index + 1, document.getNumberOfPages());
                }
            }
            document.getDocumentInformation().setTitle("Lighting Test Report " + safe(payload.cover().reportNumber()));
            document.getDocumentInformation().setCreator("New LIMS PDF Renderer");
            document.save(output);
        }
    }

    private static void validate(LightingReportPayload p) {
        if (p == null || p.header() == null || p.cover() == null || p.form() == null
                || p.testSetup() == null || p.electrical() == null || p.colorimetry() == null
                || p.signatures() == null) {
            throw new IllegalArgumentException("header, cover, form, testSetup, electrical, colorimetry and signatures are required");
        }
    }

    static PDFont loadFont(PDDocument document) throws IOException {
        String configured = System.getProperty("lighting.report.font");
        if (configured == null || configured.isBlank()) {
            configured = System.getenv("LIGHTING_REPORT_FONT_PATH");
        }
        if (configured != null && !configured.isBlank()) {
            try (var input = Files.newInputStream(Path.of(configured))) {
                return PDType0Font.load(document, input);
            }
        }
        return ContractPdfAssets.loadPrimaryFont(document);
    }

    static PDFont loadHeadingFont(PDDocument document, PDFont fallback) throws IOException {
        String configured = System.getProperty("lighting.report.heading.font");
        if (configured == null || configured.isBlank()) configured = System.getenv("LIGHTING_REPORT_HEADING_FONT_PATH");
        if (configured == null || configured.isBlank()) return fallback;
        try (var input = Files.newInputStream(Path.of(configured))) {
            return PDType0Font.load(document, input);
        }
    }

    private static void drawCover(PDDocument doc, PDFont font, LightingReportPayload p) throws IOException {
        var f = p.form();
        String[][] fields = {
                {"报告编号：", p.cover().reportNumber()}, {"申请人：", f.applicantName()},
                {"参考标准：", f.referenceStandard()}, {"产品名称：", f.productName()},
                {"型号：", f.model()}, {"接收日期：", p.cover().receivedDate()},
                {"签发日期：", p.cover().issuedDate()}
        };
        drawCover(doc, font, p.header().company(), fields);
    }

    static void drawCover(PDDocument doc, PDFont font, String company, String[][] fields) throws IOException {
        try (var c = new Canvas(doc, font)) {
            c.spacedCenter("检测报告", 36, 14.113f, WIDTH / 2, 236.02f);
            float[] valueTops = {319.77f, 345.87f, 365.38f, 399.57f, 425.67f, 451.77f, 477.87f};
            float extra = 0;
            float top = 319.77f;
            for (int index = 0; index < fields.length; index++) {
                String[] field = fields[index];
                float valueSize = index == 2 ? 12 : 11;
                List<String> lines = c.wrap(field[1], valueSize, 263);
                if ("参考标准：".equals(field[0]) && safe(field[1]).startsWith("Commission test  ")) {
                    lines = new ArrayList<>();
                    lines.add("Commission test");
                    lines.addAll(c.wrap(field[1].substring("Commission test  ".length()), 12, 263));
                }
                top = valueTops[index] + extra;
                float labelTop = index == 2 ? top + 7.99f : top;
                c.text(field[0], 11, 132.75f, labelTop);
                float valueTop = index == 2 && lines.size() == 1 ? labelTop - 0.5f : top;
                c.lines(lines, valueSize, 211.25f, valueTop, 13.8f, false, 263);
                int expectedLines = index == 2 ? 2 : 1;
                extra += Math.max(0, lines.size() - expectedLines) * 13.8f;
                top += lines.size() * 13.8f;
            }
            float companyTop = Math.max(598.7f, top + 48);
            if (companyTop + 28 > BODY_BOTTOM) {
                throw new IllegalArgumentException("Cover values exceed the report cover capacity");
            }
            c.center(safe(company), 15, WIDTH / 2, companyTop);
        }
    }

    private static void drawInformation(PDDocument doc, PDFont font, LightingReportPayload p,
                                        Map<String, Integer> pages) throws IOException {
        var f = p.form();
        String[][] fields = {
                {"申请人名称", f.applicantName()}, {"地址", f.applicantAddress()},
                {"制造商", f.manufacturerName()}, {"地址", f.manufacturerAddress()},
                {"产品名称", f.productName()}, {"型号", f.model()},
                {"参考标准", f.referenceStandard()}, {"品牌", f.brand()},
                {"样品数量", f.sampleQuantity()}, {"额定电压", f.ratedVoltage()},
                {"额定功率", f.ratedPower()}, {"标称光通量", f.nominalLuminousFlux()},
                {"标称色温", f.nominalCct()}, {"检测实验室", f.laboratoryName()},
                {"地址", f.laboratoryAddress()}, {"测试项目", f.testItem()},
                {"接收日期", f.receivedDate()}, {"测试周期", f.testPeriod()}
        };
        Canvas c = new Canvas(doc, font);
        try {
            float top = 91.4f;
            pages.put("general", doc.getNumberOfPages());
            pages.put("applicant", doc.getNumberOfPages());
            for (int index = 0; index < fields.length; index++) {
                List<String> lines = c.wrap(fields[index][1], 12, INFO_LEFT + INFO_WIDTH - VALUE_LEFT - 6);
                float rowHeight = Math.max(20.5f, lines.size() * 15 + 0.5f);
                if (rowHeight > BODY_BOTTOM - 91.4f) {
                    throw new IllegalArgumentException("Information value is too long for one report page");
                }
                if (top + rowHeight > BODY_BOTTOM) {
                    c.close(); c = new Canvas(doc, font); top = 91.4f;
                }
                if (index == 4) pages.put("product", doc.getNumberOfPages());
                if (index == 13) pages.put("laboratory", doc.getNumberOfPages());
                c.text(fields[index][0], 12, LABEL_LEFT, top + (rowHeight - 12) / 2 + 1.64f);
                c.text(":", 12, 195.05f, top + (rowHeight - 12) / 2 + 1.64f);
                c.lines(lines, 12, VALUE_LEFT, top + (rowHeight - lines.size() * 15) / 2 + 3.14f, 15, false, 0);
                c.rule(INFO_LEFT, INFO_LEFT + INFO_WIDTH, top + rowHeight, 0.5f, new Color(217, 217, 217));
                top += rowHeight;
            }
            var s = p.signatures();
            String[] roles = {"测试人", "审核人", "批准人"};
            String[] names = {s.testedBy(), s.reviewedBy(), s.approvedBy()};
            byte[][] images = {s.testedImage(), s.reviewedImage(), s.approvedImage()};
            List<List<String>> signerLines = new ArrayList<>();
            float[] gaps = {41.7f, 31.35f, 28.1f};
            float signatureHeight = 15;
            for (int index = 0; index < names.length; index++) {
                signerLines.add(c.wrap(names[index], 12, 97.75f));
                gaps[index] = Math.max(gaps[index], signerLines.get(index).size() * 15 + 9);
                signatureHeight += gaps[index];
            }
            if (signatureHeight > BODY_BOTTOM - 100) {
                throw new IllegalArgumentException("Signer names exceed the signing section capacity");
            }
            float signatureTop = Math.max(535.84f, top + 43);
            if (signatureTop + signatureHeight > BODY_BOTTOM) {
                c.close(); c = new Canvas(doc, font); signatureTop = 100;
            }
            pages.put("approval", doc.getNumberOfPages());
            float[] offsets = {0, gaps[0], gaps[0] + gaps[1]};
            float[] imageWidths = {96.25f, 53.95f, 75.1f};
            float[] imageHeights = {25.3f, 24.65f, 24.15f};
            for (int index = 0; index < roles.length; index++) {
                float y = signatureTop + offsets[index];
                c.text(roles[index], 12, LABEL_LEFT, y);
                c.text("........................", 12, 103, y);
                c.lines(signerLines.get(index), 12, VALUE_LEFT, y, 15, false, 0);
                c.image(images[index], 333.9f, y - 11.3f, imageWidths[index], imageHeights[index]);
            }
            c.text("日期", 12, LABEL_LEFT, signatureTop + gaps[0] + gaps[1] + gaps[2]);
            c.text("...............................", 12, 94, signatureTop + gaps[0] + gaps[1] + gaps[2]);
            c.text(s.date(), 12, VALUE_LEFT, signatureTop + gaps[0] + gaps[1] + gaps[2]);
        } finally {
            c.close();
        }
    }

    private static void drawParameters(PDDocument doc, PDFont font, LightingReportPayload p,
                                       Map<String, Integer> pages) throws IOException {
        var f = p.form(); var t = p.testSetup(); var e = p.electrical(); var h = p.colorimetry();
        String[] headings = {"测试设置", "样品信息", "测试条件", "电参数", "色度测试"};
        String[][][] groups = {
                {{"C 角度间隔", t.cInterval(), "C 范围", t.cRange()},
                 {"γ 角度间隔", t.gammaInterval(), "γ 范围", t.gammaRange()},
                 {"测量距离", t.distance(), "角度精度", t.angularAccuracy()},
                 {"C 平面数", t.cPlanes(), "γ 点数", t.gammaPoints()}},
                {{"产品名称", f.productName(), "型号", f.model()},
                 {"样品描述", f.sampleDescription(), "序列号", f.serialNumber()},
                 {"额定功率", unit(f.ratedPower(), "W"), "标称色温", unit(f.nominalCct(), "K")},
                 {"额定电压", unit(safe(f.ratedVoltage()).replaceFirst("^AC\\s+", ""), "V"), "标称光通量", f.nominalLuminousFlux()}},
                {{"环境温度", f.ambientTemperature(), "相对湿度", f.relativeHumidity()},
                 {"气流条件", f.airVelocity(), "测试方向", f.testDirection()},
                 {"稳定时间", f.stabilizationTime(), "", ""}},
                {{"输入电压", e.inputVoltage(), "输入电流", e.inputCurrent()},
                 {"输入功率", e.inputPower(), "功率因数", e.powerFactor()},
                 {"电流谐波失真 (THD)", f.currentThd(), "频率", e.frequency()},
                 {"电压调节率", f.voltageRegulation(), "电源波形 THD", f.supplyThd()}},
                {{"相关色温 CCT", h.cct(), "Duv", h.duv()},
                 {"显色指数 Ra", h.ra(), "显色指数 R9", h.r9()},
                 {"色坐标 (x, y)", h.xy(), "色坐标 (u', v')", h.uv()},
                 {"TM-30 Rf", h.rf(), "TM-30 Rg", h.rg()}}
        };
        Canvas c = new Canvas(doc, font);
        try {
            float top = 94.68f;
            pages.put("parameters", doc.getNumberOfPages());
            for (int group = 0; group < groups.length; group++) {
                float[] heights = new float[groups[group].length];
                float tableHeight = 0;
                for (int row = 0; row < heights.length; row++) {
                    int lines = 1;
                    for (String value : groups[group][row]) lines = Math.max(lines, c.wrap(value, 9, TABLE_WIDTH / 4 - 8).size());
                    heights[row] = Math.max(24.5f, lines * 12 + 4.5f);
                    tableHeight += heights[row];
                }
                if (top > 94.68f && top + 15.27f + tableHeight > BODY_BOTTOM) {
                    c.close(); c = new Canvas(doc, font); top = 94.68f;
                }
                pages.put("parameter" + group, doc.getNumberOfPages());
                c.center(headings[group], 9, WIDTH / 2, top);
                top += 15.27f;
                for (int row = 0; row < heights.length; row++) {
                    if (heights[row] > BODY_BOTTOM - 110) {
                        throw new IllegalArgumentException("Parameter value is too long for one report page");
                    }
                    if (top + heights[row] > BODY_BOTTOM) {
                        c.close(); c = new Canvas(doc, font); top = 94.68f;
                        c.center(headings[group], 9, WIDTH / 2, top); top += 15.27f;
                    }
                    for (int col = 0; col < 4; col++) {
                        float x = TABLE_LEFT + col * TABLE_WIDTH / 4;
                        c.rect(x, top, TABLE_WIDTH / 4, heights[row], 0.5f);
                        List<String> lines = c.wrap(groups[group][row][col], 9, TABLE_WIDTH / 4 - 8);
                        float textTop = top + (heights[row] - ((lines.size() - 1) * 12 + 9)) / 2 + 1.5f;
                        c.lines(lines, 9, x, textTop, 12, true, TABLE_WIDTH / 4);
                    }
                    top += heights[row];
                }
                top += 15.23f;
            }
        } finally {
            c.close();
        }
    }

    private static void drawPhotos(PDDocument doc, PDFont font, List<byte[]> photos,
                                   Map<String, Integer> pages) throws IOException {
        if (photos == null || photos.isEmpty()) return;
        pages.put("photos", doc.getNumberOfPages() + 1);
        for (int start = 0; start < photos.size(); start += 2) {
            try (var c = new Canvas(doc, font)) {
                c.center("灯具图片", 9, WIDTH / 2, 93.23f);
                int count = Math.min(2, photos.size() - start);
                c.rect(54.7f, 114, 486, count * 283.95f, 0.5f);
                if (count == 2) c.rule(54.7f, 540.7f, 397.95f, 0.5f, Color.BLACK);
                for (int slot = 0; slot < count; slot++) {
                    if (photos.get(start + slot) == null || photos.get(start + slot).length == 0) {
                        throw new IllegalArgumentException("Photo bytes must not be empty");
                    }
                    c.image(photos.get(start + slot), 109.7f, 114 + slot * 283.95f, 376.45f, 283.45f);
                }
            }
        }
    }

    private static void drawContents(Canvas c, Map<String, Integer> pages, PDFont headingFont) throws IOException {
        c.heading("CONTENTS", 16, WIDTH / 2, 93.53f, headingFont, true);
        c.heading("Cover page", 12, 83.4f, 165.38f, headingFont, false);
        c.heading("Contents", 12, 83.4f, 187.03f, headingFont, false);
        String[][] entries = {
                {"1", "General information", "general"},
                {"1.1", "Applicant and Manufacturer", "applicant"},
                {"1.2", "Product Information", "product"},
                {"1.3", "Laboratory and Test Information", "laboratory"},
                {"1.4", "Testing Review and Approval", "approval"},
                {"2", "Test settings and results", "parameters"},
                {"2.1", "Test Settings", "parameter0"},
                {"2.2", "Sample Information", "parameter1"},
                {"2.3", "Ambient and Test Conditions", "parameter2"},
                {"2.4", "Electrical Property Parameters", "parameter3"},
                {"2.5", "Color Parameters", "parameter4"},
                {"3", "Photos of Sample", "photos"}
        };
        float top = 220.68f;
        for (String[] entry : entries) {
            if (!pages.containsKey(entry[2])) continue;
            boolean sub = entry[0].contains(".");
            if (!sub && top > 220.68f) top += 4;
            float x = sub ? 74.25f : 68.25f;
            String text = entry[0] + "   " + entry[1];
            c.text(text, 12, x, top);
            float leaderStart = x + c.measure(text, 12) + 3;
            String page = pages.get(entry[2]).toString();
            c.text(page, 12, 539 - c.measure(page, 12), top);
            c.dotted(leaderStart, 531, top + 10);
            top += 21.8f;
        }
    }

    static void drawHeaderFooter(Canvas c, LightingReportPayload.Header h, int page, int total) throws IOException {
        c.coloredCenter(h.company(), 18, WIDTH / 2, 43.6f, BLUE);
        c.text("文件编号:" + safe(h.fileNumber()), 9, 435, 43.6f);
        c.text("文件版本:" + safe(h.version()), 9, 435, 53.95f);
        c.rule(34, 561.25f, 71.75f, 1.125f, new Color(26, 57, 107));
        c.coloredCenter(h.company(), 12, 246.85f, 739.69f, BLUE);
        c.center("第 " + page + " 页  共 " + total + " 页", 9, 490, 740.78f);
        boolean contacts = !safe(h.website()).isBlank() || !safe(h.email()).isBlank();
        var addressLines = c.wrap(h.address(), 9, contacts ? 172 : 464);
        if (addressLines.size() > 4) throw new IllegalArgumentException("Footer address exceeds page capacity");
        c.lines(addressLines, 9, 70.2f, 752.45f, 11, false, 0);
        if (!safe(h.website()).isBlank()) c.text("URL：" + h.website(), 9, 251.65f, 752.45f);
        if (!safe(h.email()).isBlank()) c.text("E-mail：" + h.email(), 9, 408.2f, 752.45f);
    }

    private static String safe(String value) { return value == null ? "" : value; }

    private static String unit(String value, String suffix) {
        return safe(value).replaceFirst("\\s*" + suffix + "$", " " + suffix);
    }

    static final class Canvas implements AutoCloseable {
        private final PDDocument document;
        private final PDFont font;
        private final PDPageContentStream stream;

        Canvas(PDDocument document, PDFont font) throws IOException {
            this(document, addPage(document), font);
        }

        Canvas(PDDocument document, PDPage page, PDFont font) throws IOException {
            this.document = document;
            this.font = font;
            stream = new PDPageContentStream(document, page, PDPageContentStream.AppendMode.APPEND, true, true);
        }

        private static PDPage addPage(PDDocument doc) {
            var page = new PDPage(PDRectangle.A4); doc.addPage(page); return page;
        }

        float measure(String text, float size) throws IOException {
            return font.getStringWidth(safe(text)) / 1000 * size;
        }

        void text(String text, float size, float x, float top) throws IOException {
            stream.setNonStrokingColor(Color.BLACK);
            rawText(text, size, x, top);
        }

        private void rawText(String text, float size, float x, float top) throws IOException {
            float descent = font.getFontDescriptor().getDescent() / 1000;
            stream.beginText();
            stream.setFont(font, size);
            stream.newLineAtOffset(x, HEIGHT - top - size * (1 + descent));
            stream.showText(safe(text));
            stream.endText();
        }

        void spacedCenter(String text, float size, float spacing, float centerX, float top) throws IOException {
            float width = measure(text, size) + spacing * (text.codePointCount(0, text.length()) - 1);
            stream.saveGraphicsState(); stream.setNonStrokingColor(Color.BLACK);
            stream.beginText(); stream.setFont(font, size); stream.setCharacterSpacing(spacing);
            float descent = font.getFontDescriptor().getDescent() / 1000;
            stream.newLineAtOffset(centerX - width / 2, HEIGHT - top - size * (1 + descent));
            stream.showText(text); stream.endText(); stream.restoreGraphicsState();
        }

        void heading(String text, float size, float x, float top, PDFont headingFont, boolean centered) throws IOException {
            float startX = centered ? x - headingFont.getStringWidth(text) / 1000 * size / 2 : x;
            stream.saveGraphicsState();
            stream.setNonStrokingColor(Color.BLACK);
            if (headingFont == font) {
                stream.setStrokingColor(Color.BLACK);
                stream.setLineWidth(0.2f);
                stream.setRenderingMode(RenderingMode.FILL_STROKE);
            }
            stream.beginText(); stream.setFont(headingFont, size);
            float descent = headingFont.getFontDescriptor().getDescent() / 1000;
            stream.newLineAtOffset(startX, HEIGHT - top - size * (1 + descent));
            stream.showText(text); stream.endText(); stream.restoreGraphicsState();
        }

        void center(String text, float size, float centerX, float top) throws IOException {
            text(text, size, centerX - measure(text, size) / 2, top);
        }

        void coloredCenter(String text, float size, float centerX, float top, Color color) throws IOException {
            stream.setNonStrokingColor(color);
            rawText(text, size, centerX - measure(text, size) / 2, top);
        }

        void lines(List<String> lines, float size, float x, float top, float leading,
                   boolean centered, float width) throws IOException {
            for (String line : lines) {
                if (centered) center(line, size, x + width / 2, top);
                else text(line, size, x, top);
                top += leading;
            }
        }

        List<String> wrap(String value, float size, float width) throws IOException {
            List<String> lines = new ArrayList<>();
            for (String paragraph : safe(value).split("\\R", -1)) {
                StringBuilder current = new StringBuilder();
                for (int code : paragraph.codePoints().toArray()) {
                    String next = new String(Character.toChars(code));
                    if (!current.isEmpty() && measure(current + next, size) > width) {
                        int space = current.lastIndexOf(" ");
                        int hyphen = current.lastIndexOf("-");
                        if (hyphen > space && hyphen > 0) {
                            lines.add(current.substring(0, hyphen + 1).strip());
                            current = new StringBuilder(current.substring(hyphen + 1));
                        } else if (space > 0) {
                            lines.add(current.substring(0, space).strip());
                            current = new StringBuilder(current.substring(space + 1));
                        } else {
                            lines.add(current.toString()); current.setLength(0);
                        }
                    }
                    current.append(next);
                }
                lines.add(current.toString().strip());
            }
            return lines;
        }

        void rule(float left, float right, float top, float stroke, Color color) throws IOException {
            stream.saveGraphicsState();
            stream.setStrokingColor(color); stream.setLineWidth(stroke);
            stream.moveTo(left, HEIGHT - top); stream.lineTo(right, HEIGHT - top); stream.stroke();
            stream.restoreGraphicsState();
        }

        void dotted(float left, float right, float top) throws IOException {
            if (right <= left) return;
            stream.saveGraphicsState(); stream.setLineDashPattern(new float[] {1, 2}, 0);
            rule(left, right, top, 0.8f, Color.BLACK); stream.restoreGraphicsState();
        }

        void rect(float x, float top, float width, float height, float stroke) throws IOException {
            stream.saveGraphicsState(); stream.setStrokingColor(Color.BLACK); stream.setLineWidth(stroke);
            stream.addRect(x, HEIGHT - top - height, width, height); stream.stroke(); stream.restoreGraphicsState();
        }

        void image(byte[] bytes, float x, float top, float width, float height) throws IOException {
            if (bytes == null || bytes.length == 0) return;
            PDImageXObject image;
            try {
                image = PDImageXObject.createFromByteArray(document, bytes, "report-image");
            } catch (IOException exception) {
                throw new IllegalArgumentException("Report images must contain valid PNG or JPEG bytes", exception);
            }
            float scale = Math.min(width / image.getWidth(), height / image.getHeight());
            float drawnWidth = image.getWidth() * scale;
            float drawnHeight = image.getHeight() * scale;
            stream.drawImage(image, x + (width - drawnWidth) / 2,
                    HEIGHT - top - (height + drawnHeight) / 2, drawnWidth, drawnHeight);
        }

        PDPageContentStream stream() { return stream; }

        @Override public void close() throws IOException { stream.close(); }
    }
}
