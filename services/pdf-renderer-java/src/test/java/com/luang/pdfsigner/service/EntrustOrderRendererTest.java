package com.luang.pdfsigner.service;

import static org.assertj.core.api.Assertions.assertThat;

import com.luang.pdfsigner.dto.EntrustOrderPayload;
import com.fasterxml.jackson.databind.ObjectMapper;
import org.apache.pdfbox.Loader;
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
                    \"laboratory_name\": \"中山市鑫达普检测服务有限公司\",
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
            assertThat(text).contains("报告形式 电⼦档");
            assertThat(text).contains("样品是否返还 返还");
            assertThat(text).contains("报告提交 邮寄");
            assertThat(text).contains("准许检测分包 允许");
        }
    }
}
