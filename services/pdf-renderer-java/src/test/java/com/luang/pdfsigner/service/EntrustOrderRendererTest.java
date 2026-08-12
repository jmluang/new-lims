package com.luang.pdfsigner.service;

import static org.assertj.core.api.Assertions.assertThat;

import com.luang.pdfsigner.dto.EntrustOrderPayload;
import com.fasterxml.jackson.databind.ObjectMapper;
import org.apache.pdfbox.Loader;
import org.apache.pdfbox.cos.COSName;
import org.apache.pdfbox.pdmodel.PDDocument;
import org.apache.pdfbox.text.PDFTextStripper;
import org.junit.jupiter.api.Test;

class EntrustOrderRendererTest {

    private final ObjectMapper mapper = new ObjectMapper();

    @Test
    void rendersCheckboxOptionsWithSelections() throws Exception {
        String payload = """
                {
                  \"base\": {
                    \"entrust_date\": \"2025-09-07\",
                    \"urgency\": { \"value\": \"urgent\", \"label\": \"加急\" },
                    \"urgency_options\": [
                      { \"value\": \"normal\", \"label\": \"常规\" },
                      { \"value\": \"urgent\", \"label\": \"加急\" }
                    ],
                    \"planned_end_date\": \"2025-09-21\",
                    \"entrust_number\": \"E20250907001\",
                    \"contract_number\": \"HT-2025-001\"
                  },
                  \"client\": {
                    \"company_name\": \"委托单位A\",
                    \"contact\": \"张三\",
                    \"phone\": \"13800000000\",
                    \"address\": \"广东省中山市东区\",
                    \"email\": \"client@example.com\"
                  },
                  \"manufacturer\": {
                    \"company_name\": \"制造商B\",
                    \"contact\": \"李四\",
                    \"phone\": \"13800000001\",
                    \"address\": \"深圳市南山区\",
                    \"email\": \"manufacturer@example.com\"
                  },
                  \"producer\": {
                    \"company_name\": \"生产厂C\",
                    \"contact\": \"王五\",
                    \"phone\": \"13800000002\",
                    \"address\": \"广州市天河区\",
                    \"email\": \"producer@example.com\"
                  },
                  \"requirements\": {
                    \"report_forms\": [
                      { \"value\": \"electronic\", \"label\": \"电子档\" }
                    ],
                    \"report_form_options\": [
                      { \"value\": \"electronic\", \"label\": \"电子档\" },
                      { \"value\": \"paper\", \"label\": \"纸本\" }
                    ],
                    \"sample_return\": { \"value\": \"return\", \"label\": \"返还\" },
                    \"sample_return_options\": [
                      { \"value\": \"return\", \"label\": \"返还\" },
                      { \"value\": \"keep\", \"label\": \"不返还\" }
                    ],
                    \"report_submission\": { \"value\": \"express\", \"label\": \"邮寄\" },
                    \"report_submission_options\": [
                      { \"value\": \"express\", \"label\": \"邮寄\" },
                      { \"value\": \"self_pickup\", \"label\": \"自取\" }
                    ],
                    \"allow_subcontract\": { \"value\": \"allow\", \"label\": \"允许\" },
                    \"allow_subcontract_options\": [
                      { \"value\": \"allow\", \"label\": \"允许\" },
                      { \"value\": \"deny\", \"label\": \"不允许\" }
                    ],
                    \"remarks\": \"需要在月底前完成\",
                    \"standards\": [
                      {
                        \"standard_code\": \"GB/T 1234-2020\",
                        \"qualification_requirement\": \"CMA\",
                        \"report_language\": \"中文\",
                        \"notes\": \"无\",
                        \"position\": 0
                      }
                    ]
                  },
                  \"sample\": {
                    \"name\": \"样品一号\",
                    \"model\": \"X1\",
                    \"voltage\": \"220V\",
                    \"current\": \"2A\",
                    \"power\": \"400W\",
                    \"frequency\": \"50Hz\",
                    \"quantity\": 2,
                    \"quantity_unit\": \"pcs\",
                    \"condition\": { \"value\": \"good\", \"label\": \"良好\" },
                    \"condition_note\": \"外观完好\",
                    \"remarks\": \"无特殊情况\"
                  },
                  \"logistics\": {
                    \"laboratory_name\": \"中山市鑫普达检测有限公司\",
                    \"laboratory_address\": \"广东省中山市横栏镇环镇北路52号\",
                    \"laboratory_contact\": \"赵六\",
                    \"laboratory_phone\": \"13800000003\",
                    \"shipping_notes\": \"需冷链运输\"
                  },
                  \"signatures\": {
                    \"client_signature_name\": \"客户签字\",
                    \"client_signed_at\": \"2025-09-07\",
                    \"lab_resource_confirmed_by\": \"综合部\",
                    \"lab_resource_confirmed_at\": \"2025-09-08\",
                    \"lab_reviewed_by\": \"检测部\",
                    \"lab_reviewed_at\": \"2025-09-09\"
                  },
                  \"meta\": {
                    \"status\": { \"value\": \"draft\", \"label\": \"草稿\" },
                    \"generated_at\": \"2025-09-07 10:00:00\"
                  }
                }
                """;

        EntrustOrderPayload data = mapper.readValue(payload, EntrustOrderPayload.class);
        EntrustOrderRenderer renderer = new EntrustOrderRenderer();

        byte[] pdf = renderer.render(data);
        assertThat(pdf).isNotEmpty();

        try (PDDocument document = Loader.loadPDF(pdf)) {
            PDFTextStripper stripper = new PDFTextStripper();
            String text = stripper.getText(document);
            assertThat(text).contains("□常规■加急");
            assertThat(text).contains("电话 13800000000");
            assertThat(text).contains("邮箱 client@example");
            assertThat(text).contains("报告形式 ■电子档□纸本");
            assertThat(text).contains("样品是否返还 ■返还□不返还");
            assertThat(text).contains("报告提交 ■邮寄□自取");
            assertThat(text).contains("准许检测分包 ■允许□不允许");
            assertThat(text).contains("日期 2025.9.7");
            assertThat(text).contains("实验室资源满足*");
            assertThat(text).contains("综合部");
            assertThat(text).contains("2025.9.8");
            assertThat(text).contains("客户要求的评审*");
            assertThat(text).contains("检测部");
            assertThat(text).contains("2025.9.9");
        }
    }

    @Test
    void embedsTheSongStyleFontForEntrustOrders() throws Exception {
        EntrustOrderPayload data = mapper.readValue("""
                {"samples":[{"name":"宋体样品"}]}
                """, EntrustOrderPayload.class);

        try (PDDocument document = Loader.loadPDF(new EntrustOrderRenderer().render(data))) {
            boolean hasSongStyleFont = false;
            for (COSName fontName : document.getPage(0).getResources().getFontNames()) {
                if (document.getPage(0).getResources().getFont(fontName).getName().contains("SourceHanSerifSC")) {
                    hasSongStyleFont = true;
                    break;
                }
            }

            assertThat(hasSongStyleFont).isTrue();
        }
    }

    @Test
    void rendersAllSamplesWithoutHardcodedFallbacks() throws Exception {
        String payload = """
                {
                  \"base\": {
                    \"entrust_date\": \"2026-05-08\",
                    \"urgency\": {\"value\":\"normal\",\"label\":\"常规\"},
                    \"urgency_options\": [
                      {\"value\":\"normal\",\"label\":\"常规\"},
                      {\"value\":\"urgent\",\"label\":\"加急\"},
                      {\"value\":\"critical\",\"label\":\"特急\"}
                    ],
                    \"planned_end_date\": \"2026-05-11\",
                    \"entrust_number\": \"2026050001\",
                    \"contract_number\": \"2026050001\"
                  },
                  \"client\": {\"company_name\":\"中山市铭宜镁照明科技有限公司\",\"address\":\"中山古镇曹兴西路117号\"},
                  \"requirements\": {
                    \"standards\": [
                      {\"standard_code\":\"GB/T 9468-2008 灯具分布光度测量的一般要求\",\"qualification_requirement\":\"CNAS,CMA\",\"report_language\":\"中文\",\"position\":0}
                    ],
                    \"sample_return\": {\"value\":\"return\",\"label\":\"是\"},
                    \"report_submission\": {\"value\":\"mail\",\"label\":\"邮寄\"},
                    \"allow_subcontract\": {\"value\":\"not_allowed\",\"label\":\"不允许\"}
                  },
                  \"samples\": [
                    {\"name\":\"LED模组路灯头\",\"model\":\"MYM-300\",\"voltage\":\"220V\",\"current\":\"1.3A\",\"power\":\"300W\",\"frequency\":\"50Hz\",\"quantity\":1,\"quantity_unit\":\"个\",\"condition\":{\"value\":\"good\",\"label\":\"完好\"}},
                    {\"name\":\"LED模组天花灯头\",\"model\":\"MYM-300\",\"voltage\":\"220V\",\"current\":\"1.3A\",\"power\":\"300W\",\"frequency\":\"50Hz\",\"quantity\":1,\"quantity_unit\":\"个\",\"condition\":{\"value\":\"abnormal\",\"label\":\"异常\"},\"condition_note\":\"外壳划痕\"}
                  ]
                }
                """;

        EntrustOrderPayload data = mapper.readValue(payload, EntrustOrderPayload.class);
        byte[] pdf = new EntrustOrderRenderer().render(data);
        String text = extractText(pdf);

        assertThat(text).contains("LED模组路灯头");
        assertThat(text).contains("LED模组天花灯头");
        assertThat(text).contains("■异常（外壳划痕）");
        assertThat(text).doesNotContain("物联网节能感应灯管");
        assertThat(text).doesNotContain("LK-ZMT8-180");
        assertThat(text).doesNotContain("中山市鑫达普检测服务有限公司");
        assertThat(text).doesNotContain("张丁浪");
    }

    @Test
    void createsAdditionalPagesWhenSamplesExceedFirstPage() throws Exception {
        EntrustOrderPayload data = mapper.readValue(largePayloadWithTwelveSamples(), EntrustOrderPayload.class);
        byte[] pdf = new EntrustOrderRenderer().render(data);

        try (PDDocument document = Loader.loadPDF(pdf)) {
            assertThat(document.getNumberOfPages()).isGreaterThan(1);
        }

        String text = extractText(pdf);
        assertThat(text).contains("Sample-12");
    }

    @Test
    void rendersPlainTextClientSignatureWithoutUrlFetchPlaceholder() throws Exception {
        String payload = """
                {
                  \"base\": {\"entrust_number\": \"2026050001\"},
                  \"samples\": [{\"name\":\"LED模组路灯头\"}],
                  \"signatures\": {\"client_signature_name\": \"张三\"}
                }
                """;

        EntrustOrderPayload data = mapper.readValue(payload, EntrustOrderPayload.class);
        byte[] pdf = new EntrustOrderRenderer().render(data);
        String text = extractText(pdf);

        assertThat(text).contains("张三");
        assertThat(text).doesNotContain("[签名地址无效]");
        assertThat(text).doesNotContain("[签名加载失败]");
    }

    private String extractText(byte[] pdf) throws Exception {
        try (PDDocument document = Loader.loadPDF(pdf)) {
            return new PDFTextStripper().getText(document);
        }
    }

    private String largePayloadWithTwelveSamples() {
        StringBuilder samples = new StringBuilder();
        for (int index = 1; index <= 12; index++) {
            if (index > 1) {
                samples.append(',');
            }
            samples.append("""
                    {
                      \"name\": \"Sample-%d\",
                      \"model\": \"Model-%d\",
                      \"voltage\": \"220V\",
                      \"current\": \"1.3A\",
                      \"power\": \"300W\",
                      \"frequency\": \"50Hz\",
                      \"quantity\": 1,
                      \"quantity_unit\": \"个\",
                      \"condition\": {\"value\": \"good\", \"label\": \"完好\"}
                    }
                    """.formatted(index, index));
        }

        return """
                {
                  \"base\": {
                    \"entrust_number\": \"2026050001\",
                    \"urgency\": {\"value\": \"critical\", \"label\": \"特急\"},
                    \"urgency_options\": [
                      {\"value\": \"normal\", \"label\": \"常规\"},
                      {\"value\": \"urgent\", \"label\": \"加急\"},
                      {\"value\": \"critical\", \"label\": \"特急\"}
                    ]
                  },
                  \"requirements\": {
                    \"standards\": [
                      {\"standard_code\":\"GB/T 9468-2008\",\"qualification_requirement\":\"CNAS,CMA\",\"report_language\":\"中文\",\"position\":0},
                      {\"standard_code\":\"GB/T 7922-2023\",\"qualification_requirement\":\"CNAS\",\"report_language\":\"中文\",\"position\":1},
                      {\"standard_code\":\"IEC 60598-1\",\"qualification_requirement\":\"CMA\",\"report_language\":\"英文\",\"position\":2}
                    ]
                  },
                  \"samples\": [%s],
                  \"signatures\": {\"client_signature_name\": \"张三\"}
                }
                """.formatted(samples);
    }
}
