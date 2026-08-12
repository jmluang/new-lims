package com.luang.pdfsigner.service.renderer;

import com.luang.pdfsigner.service.ContractPdfAssets;
import java.awt.Color;
import java.io.IOException;
import java.util.ArrayList;
import java.util.List;
import org.apache.pdfbox.pdmodel.PDDocument;
import org.apache.pdfbox.pdmodel.PDPageContentStream;
import org.apache.pdfbox.pdmodel.font.PDFont;

public class ContractPdfDrawUtils {

    private final PDDocument document;
    private final PDFont primaryFont;

    public ContractPdfDrawUtils(PDDocument document, PDFont primaryFont) {
        this.document = document;
        this.primaryFont = primaryFont;
    }

    public PDDocument document() {
        return document;
    }

    public PDFont font() {
        return primaryFont;
    }

    public void showText(PDPageContentStream content, PDFont font, float fontSize, float x, float y, String text) throws IOException {
        content.setNonStrokingColor(Color.BLACK);
        content.beginText();
        content.setFont(font != null ? font : primaryFont, fontSize);
        content.newLineAtOffset(x, y);
        content.showText(text == null ? "" : text);
        content.endText();
    }

    public void drawRightAlignedText(PDPageContentStream content, PDFont font, float fontSize, float rightEdge, float y, String text) throws IOException {
        float width = measureText(font != null ? font : primaryFont, fontSize, text);
        float startX = rightEdge - width;
        showText(content, font, fontSize, startX, y, text);
    }

    public float measureText(PDFont font, float fontSize, String text) throws IOException {
        if (text == null || text.isEmpty()) {
            return 0f;
        }
        return (font != null ? font : primaryFont).getStringWidth(text) / 1000f * fontSize;
    }

    /**
     * 绘制带边框的表格单元格，文本水平居中、垂直居中。
     */
    public void drawTableCell(PDPageContentStream content,
                              PDFont font,
                              float fontSize,
                              String text,
                              float x,
                              float y,
                              float width,
                              float height,
                              boolean bold) throws IOException {
        content.saveGraphicsState();
        content.setLineWidth(0.5f);
        content.addRect(x, y, width, height);
        content.stroke();
        content.restoreGraphicsState();

        String safe = text == null ? "" : text;
        float textWidth = measureText(font != null ? font : primaryFont, fontSize, safe);
        float textX = x + Math.max((width - textWidth) / 2f, 2f);
        float textY = y + (height - fontSize) / 2f + 2f;

        content.beginText();
        content.setNonStrokingColor(Color.BLACK);
        content.setFont(font != null ? font : primaryFont, fontSize);
        content.newLineAtOffset(textX, textY);
        content.showText(safe);
        content.endText();
    }

    public void drawHorizontalRule(PDPageContentStream content, float startX, float endX, float y, float strokeWidth, Color color) throws IOException {
        content.saveGraphicsState();
        content.setLineWidth(strokeWidth);
        content.setStrokingColor(color != null ? color : ContractPdfAssets.BRAND_BLUE);
        content.moveTo(startX, y);
        content.lineTo(endX, y);
        content.stroke();
        content.restoreGraphicsState();
    }

    public void drawDottedLeader(PDPageContentStream content, float startX, float endX, float y) throws IOException {
        content.saveGraphicsState();
        content.setLineWidth(0.8f);
        content.setLineDashPattern(new float[] { 2.2f, 2.2f }, 0);
        content.moveTo(startX, y);
        content.lineTo(endX, y);
        content.stroke();
        content.restoreGraphicsState();
    }

    public void drawRectangle(PDPageContentStream content, float x, float y, float width, float height, float strokeWidth, Color color) throws IOException {
        content.saveGraphicsState();
        content.setLineWidth(strokeWidth);
        content.setStrokingColor(color != null ? color : Color.DARK_GRAY);
        content.addRect(x, y, width, height);
        content.stroke();
        content.restoreGraphicsState();
    }

    public void drawCenteredText(PDPageContentStream content, PDFont font, float fontSize, float centerX, float y, String text) throws IOException {
        float textWidth = measureText(font != null ? font : primaryFont, fontSize, text);
        float startX = centerX - textWidth / 2f;
        showText(content, font, fontSize, startX, y, text);
    }

    public static float mm(float value) {
        return ContractPdfAssets.mmToPt(value);
    }

    public List<String> wrapText(PDFont font, float fontSize, String text, float maxWidth) throws IOException {
        List<String> lines = new ArrayList<>();
        if (text == null || text.isBlank()) {
            lines.add("");
            return lines;
        }

        StringBuilder current = new StringBuilder();
        for (char ch : text.toCharArray()) {
            current.append(ch);
            if (measureText(font, fontSize, current.toString()) > maxWidth) {
                if (current.length() > 1) {
                    char last = current.charAt(current.length() - 1);
                    current.deleteCharAt(current.length() - 1);
                    lines.add(current.toString());
                    current = new StringBuilder().append(last);
                } else {
                    lines.add(current.toString());
                    current = new StringBuilder();
                }
            }
        }

        if (!current.isEmpty()) {
            lines.add(current.toString());
        }
        return lines;
    }
}
