package com.luang.pdfsigner.service.renderer;

import com.luang.pdfsigner.service.ContractPdfAssets;
import com.luang.pdfsigner.service.ContractPdfPayload;
import java.io.IOException;
import org.apache.pdfbox.pdmodel.PDDocument;
import org.apache.pdfbox.pdmodel.PDPageContentStream;
import org.slf4j.Logger;
import org.slf4j.LoggerFactory;

public class PageOneRenderer implements ContractPdfPageRenderer<ContractPdfPayload.PageOne> {

    private static final Logger log = LoggerFactory.getLogger(PageOneRenderer.class);

    private static final float TITLE_BASELINE_Y = 561.44f;
    private static final float CONTENT_LEFT_X = 117f;
    private static final float CONTENT_FIRST_LINE_Y = 466.38f;
    private static final float CONTENT_LINE_STEP = 31.2f;
    private static final float INSTITUTION_LABEL_X = 117f;
    private static final float INSTITUTION_LABEL_Y = 216.77f;
    private static final float INSTITUTION_VALUE_X = 200.04f;
    private static final float INSTITUTION_VALUE_Y = 217.06f;
    private static final float TITLE_FONT_SIZE = 60f;
    private static final float BODY_FONT_SIZE = 18f;
    private static final float INSTITUTION_FONT_SIZE = 18f;

    @Override
    public void render(PDDocument document,
                       ContractPdfPayload payload,
                       ContractPdfPayload.PageOne pageData,
                       ContractPdfPageContext pageContext,
                       ContractPdfDrawUtils utils) throws IOException {
        log.debug("Page One renderer invoked for contract {}", payload != null && payload.meta() != null ? payload.meta().contractId() : null);
        ContractPdfPayload.PageOne data = pageData != null ? pageData.withDefaults() : ContractPdfPayload.PageOne.sample().withDefaults();

        try (ContractPdfPageContext.BodyCanvas canvas = pageContext.newPage()) {
            PDPageContentStream content = canvas.content();
            drawTitle(content, utils, data);
            drawSummary(content, utils, data);
            drawInstitution(content, utils, data);
        }
    }

    private void drawTitle(PDPageContentStream content, ContractPdfDrawUtils utils, ContractPdfPayload.PageOne data) throws IOException {
        float textWidth = utils.measureText(utils.font(), TITLE_FONT_SIZE, data.title());
        float x = Math.max((ContractPdfAssets.PAGE_WIDTH - textWidth) / 2f, 0f);
        utils.showText(content, utils.font(), TITLE_FONT_SIZE, x, TITLE_BASELINE_Y, data.title());
    }

    private void drawSummary(PDPageContentStream content, ContractPdfDrawUtils utils, ContractPdfPayload.PageOne data) throws IOException {
        float y = CONTENT_FIRST_LINE_Y;
        utils.showText(content, utils.font(), BODY_FONT_SIZE, CONTENT_LEFT_X, y, "报告编号：" + safe(data.reportNumber()));
        y -= CONTENT_LINE_STEP;
        utils.showText(content, utils.font(), BODY_FONT_SIZE, CONTENT_LEFT_X, y, "产品名称：" + safe(data.productName()));
        y -= CONTENT_LINE_STEP;
        utils.showText(content, utils.font(), BODY_FONT_SIZE, CONTENT_LEFT_X, y, "型号规格：" + safe(data.modelSpecification()));
        y -= CONTENT_LINE_STEP;
        utils.showText(content, utils.font(), BODY_FONT_SIZE, CONTENT_LEFT_X, y, "委托单位：" + safe(data.entrustCompany()));
        y -= CONTENT_LINE_STEP;
        utils.showText(content, utils.font(), BODY_FONT_SIZE, CONTENT_LEFT_X, y, "检测项目：" + safe(data.testItems()));
        y -= CONTENT_LINE_STEP;
        utils.showText(content, utils.font(), BODY_FONT_SIZE, CONTENT_LEFT_X, y, "报告日期：" + safe(data.reportDate()));
    }

    private void drawInstitution(PDPageContentStream content, ContractPdfDrawUtils utils, ContractPdfPayload.PageOne data) throws IOException {
        utils.showText(content, utils.font(), INSTITUTION_FONT_SIZE, INSTITUTION_LABEL_X, INSTITUTION_LABEL_Y, "检测机构：");
        utils.showText(content, utils.font(), INSTITUTION_FONT_SIZE, INSTITUTION_VALUE_X, INSTITUTION_VALUE_Y, safe(data.institutionName()));
    }

    private String safe(String value) {
        return value == null ? "" : value;
    }
}
