package com.luang.pdfsigner.service.renderer;

import com.luang.pdfsigner.service.ContractPdfAssets;
import com.luang.pdfsigner.service.ContractPdfPayload;
import com.luang.pdfsigner.service.ContractPdfPayload.PageSeven.Declaration;
import com.luang.pdfsigner.service.ContractPdfPayload.PageSeven.LabeledValue;
import java.io.IOException;
import java.util.Collections;
import java.util.List;
import org.apache.pdfbox.pdmodel.PDDocument;
import org.apache.pdfbox.pdmodel.PDPageContentStream;
import org.slf4j.Logger;
import org.slf4j.LoggerFactory;

public class PageSevenRenderer implements ContractPdfPageRenderer<ContractPdfPayload.PageSeven> {

    private static final Logger log = LoggerFactory.getLogger(PageSevenRenderer.class);
    private static final float TITLE_FONT_SIZE = 22f;
    private static final float LABEL_FONT_SIZE = 12f;
    private static final float LEFT_COLUMN_X = 80f;
    private static final float RIGHT_COLUMN_X = 320f;
    private static final float ROW_HEIGHT = 24f;

    @Override
    public void render(PDDocument document,
                       ContractPdfPayload payload,
                       ContractPdfPayload.PageSeven pageData,
                       ContractPdfPageContext pageContext,
                       ContractPdfDrawUtils utils) throws IOException {
        log.debug("Page Seven renderer invoked for contract {}", payload != null && payload.meta() != null ? payload.meta().contractId() : null);
        ContractPdfPayload.PageSeven data = pageData != null ? pageData : ContractPdfPayload.sample().page7();

        ContractPdfPageContext.BodyCanvas canvas = pageContext.newPage();
        PDPageContentStream content = canvas.content();
        float cursorY = canvas.contentTop() - 40f;
        utils.drawCenteredText(content, utils.font(), TITLE_FONT_SIZE, ContractPdfAssets.PAGE_WIDTH / 2f, cursorY, "测试条件");
        cursorY -= 40f;

        List<LabeledValue> pairs = data.keyValues() != null ? data.keyValues() : Collections.emptyList();
        for (int i = 0; i < pairs.size(); i += 2) {
            if (cursorY < canvas.contentBottom() + 120f) {
                break;
            }
            LabeledValue left = pairs.get(i);
            LabeledValue right = (i + 1) < pairs.size() ? pairs.get(i + 1) : null;
            drawKeyValue(content, utils, left, LEFT_COLUMN_X, cursorY);
            if (right != null) {
                drawKeyValue(content, utils, right, RIGHT_COLUMN_X, cursorY);
            }
            cursorY -= ROW_HEIGHT;
        }

        cursorY -= 10f;
        if (data.bulletPoints() != null) {
            for (String point : data.bulletPoints()) {
                utils.showText(content, utils.font(), LABEL_FONT_SIZE, LEFT_COLUMN_X, cursorY, "• " + point);
                cursorY -= 18f;
            }
        }

        cursorY -= 20f;
        drawStatement(content, utils, data.statement(), cursorY);
        content.close();
    }

    private void drawKeyValue(PDPageContentStream content, ContractPdfDrawUtils utils, LabeledValue value, float x, float y) throws IOException {
        if (value == null) {
            return;
        }
        String text = value.label() + "：" + (value.value() == null ? "" : value.value());
        utils.showText(content, utils.font(), LABEL_FONT_SIZE, x, y, text);
    }

    private void drawStatement(PDPageContentStream content, ContractPdfDrawUtils utils, Declaration declaration, float y) throws IOException {
        if (declaration == null) {
            return;
        }
        utils.showText(content, utils.font(), LABEL_FONT_SIZE, LEFT_COLUMN_X, y, declaration.title());
        y -= 18f;
        if (declaration.lines() != null) {
            for (String line : declaration.lines()) {
                utils.showText(content, utils.font(), LABEL_FONT_SIZE, LEFT_COLUMN_X + 12f, y, line);
                y -= 16f;
            }
        }
    }
}
