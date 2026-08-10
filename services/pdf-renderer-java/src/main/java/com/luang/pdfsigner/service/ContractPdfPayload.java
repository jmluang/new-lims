package com.luang.pdfsigner.service;

import com.fasterxml.jackson.annotation.JsonAlias;
import com.fasterxml.jackson.annotation.JsonProperty;
import java.time.LocalDate;
import java.util.List;
import java.util.Map;

public record ContractPdfPayload(
        Meta meta,
        List<TocEntry> toc,
        PageOne page1,
        PageTwo page2,
        PageThree page3,
        PageFour page4,
        PageFive page5,
        PageSix page6,
        PageSeven page7,
        List<Template> templates,
        @JsonProperty("template_data")
        @JsonAlias({"templateData"})
        Map<String, Object> templateData
) {
    public record Meta(
            Long contractId,
            String uuid,
            Long entrustOrderId,
            Long standardId,
            Long primarySectionId,
            String status
    ) {
    }

    public record TocEntry(String title, int page, int level) {
    }

    public record PageTwo(List<TocEntry> entries) {
        public PageTwo withDefaults() {
            List<TocEntry> safeEntries = entries == null || entries.isEmpty()
                    ? List.of(new TocEntry("检测报告", 1, 1), new TocEntry("目录", 2, 1))
                    : entries;
            return new PageTwo(safeEntries);
        }
    }

    public record PageOne(
            String headerCompanyName,
            String headerReportNumber,
            String headerBadgeLabel,
            String title,
            String reportNumber,
            String productName,
            String modelSpecification,
            String entrustCompany,
            String testItems,
            String reportDate,
            String institutionName,
            Integer currentPage,
            Integer totalPages,
            String footerCompanyName,
            String footerAddress,
            String footerPhone,
            String footerRecordCode,
            String footerWebsite
    ) {
        private static final String DEFAULT_COMPANY = "中山市鑫普达检测有限公司";

        public PageOne withDefaults() {
            return new PageOne(
                    defaultString(headerCompanyName, DEFAULT_COMPANY),
                    defaultString(headerReportNumber, ""),
                    defaultString(headerBadgeLabel, "公众号"),
                    defaultString(title, "检测报告"),
                    defaultString(reportNumber, ""),
                    defaultString(productName, ""),
                    defaultString(modelSpecification, ""),
                    defaultString(entrustCompany, ""),
                    defaultString(testItems, ""),
                    defaultString(reportDate, ""),
                    defaultString(institutionName, DEFAULT_COMPANY),
                    coalesce(currentPage, 1),
                    coalesce(totalPages, 1),
                    defaultString(footerCompanyName, DEFAULT_COMPANY),
                    defaultString(footerAddress, "广东省中山市横栏镇环镇北路52号第1栋201房和301房 http://www.zsxdp.com"),
                    defaultString(footerPhone, "4000905661"),
                    defaultString(footerRecordCode, "FO-22-03-3001"),
                    defaultString(footerWebsite, "http://www.zsxdp.com")
            );
        }

        private static String defaultString(String value, String fallback) {
            return value == null || value.isBlank() ? fallback : value;
        }

        private static Integer coalesce(Integer value, Integer fallback) {
            return value == null || value <= 0 ? fallback : value;
        }

        public PageOne withTotalPages(Integer total) {
            Integer desired = coalesce(total, this.totalPages);
            return new PageOne(
                    headerCompanyName,
                    headerReportNumber,
                    headerBadgeLabel,
                    title,
                    reportNumber,
                    productName,
                    modelSpecification,
                    entrustCompany,
                    testItems,
                    reportDate,
                    institutionName,
                    coalesce(currentPage, 1),
                    desired,
                    footerCompanyName,
                    footerAddress,
                    footerPhone,
                    footerRecordCode,
                    footerWebsite
            );
        }

        public static PageOne sample() {
            return new PageOne(
                    DEFAULT_COMPANY,
                    "XDP2025090050",
                    "公众号",
                    "检测报告",
                    "XDP2025090050",
                    "物联网节能感应灯管",
                    "SDG-AI018C-PRO",
                    "浙江和网信息技术有限公司",
                    "委托测试",
                    LocalDate.of(2025, 9, 15).toString(),
                    DEFAULT_COMPANY,
                    1,
                    7,
                    DEFAULT_COMPANY,
                    "广东省中山市横栏镇环镇北路52号第1栋201房和301房 http://www.zsxdp.com",
                    "4000905661",
                    "FO-22-03-3001",
                    "http://www.zsxdp.com"
            );
        }
    }

    public record PageThree(
        List<LabeledValue> basicInfo,
        List<Paragraph> descriptions,
        List<Paragraph> modelInfo,
        List<StandardEntry> standards,
        Declaration declaration,
        List<SignatureSlot> signatures,
        String remark,
        String conclusion
    ) {
        public record LabeledValue(String label, String value) {
        }

        public record Paragraph(String text) {
        }

        public record StandardEntry(Integer order, String code, String title, String reference) {
        }

        public record Declaration(String title, List<String> lines) {
        }

        public record SignatureSlot(String role, String signerName, String dateLabel, String dateValue) {
        }
    }

    public record PageFour(
            List<String> lampTypes,
            String lampTypeOther,
            Rated rated,
            String dimensions,
            String luminousPortArea,
            List<String> lightSourceTypes,
            String lightSourceOther,
            String detectionRemark,
            List<String> testItems,
            String testItemsOther,
            String remarks
    ) {
        public PageFour withDefaults() {
            return new PageFour(
                    lampTypes != null ? lampTypes : List.of(),
                    defaultString(lampTypeOther, ""),
                    rated != null ? rated.withDefaults() : Rated.defaults(),
                    defaultString(dimensions, "--"),
                    defaultString(luminousPortArea, "--"),
                    lightSourceTypes != null ? lightSourceTypes : List.of(),
                    defaultString(lightSourceOther, ""),
                    defaultString(detectionRemark, ""),
                    testItems != null ? testItems : List.of(),
                    defaultString(testItemsOther, ""),
                    defaultString(remarks, "--")
            );
        }

        private static String defaultString(String value, String fallback) {
            return value == null || value.isBlank() ? fallback : value;
        }

        public record Rated(String voltage, String frequency, String power, String cct) {
            private static Rated defaults() {
                return new Rated("--", "--", "--", "--");
            }

            private Rated withDefaults() {
                Rated d = defaults();
                return new Rated(
                        defaultString(voltage, d.voltage),
                        defaultString(frequency, d.frequency),
                        defaultString(power, d.power),
                        defaultString(cct, d.cct)
                );
            }
        }
    }

    public record PageFive(List<ImageSlot> images) {
        public record ImageSlot(String path, String caption, Integer slot, Integer pageIndex) {
        }
    }

    public record PageSix(List<DeviceEntry> devices, List<String> notes) {
        public record DeviceEntry(
                Long deviceId,
                String deviceNo,
                String name,
                String placement,
                String status,
                Boolean isUsed,
                String remarks,
                String model,
                String calibrationDue
        ) {
        }
    }

    public record PageSeven(List<LabeledValue> keyValues, List<String> bulletPoints, Declaration statement) {
        public record LabeledValue(String label, String value) {
        }

        public record Declaration(String title, List<String> lines) {
        }
    }

    public record Template(
            @com.fasterxml.jackson.annotation.JsonProperty("code") String code,
            @com.fasterxml.jackson.annotation.JsonProperty("display_name") String displayName,
            @com.fasterxml.jackson.annotation.JsonProperty("type") String type,
            @com.fasterxml.jackson.annotation.JsonProperty("renderer_key") String rendererKey,
            @com.fasterxml.jackson.annotation.JsonProperty("placement") String placement,
            @com.fasterxml.jackson.annotation.JsonProperty("config_pdf_path") String configPdfPath,
            @com.fasterxml.jackson.annotation.JsonProperty("pdf_path") String pdfPath,
            @com.fasterxml.jackson.annotation.JsonProperty("pdf_url") String pdfUrl,
            @com.fasterxml.jackson.annotation.JsonProperty("data") Map<String, Object> data
    ) {
    }

    public static ContractPdfPayload sample() {
        return new ContractPdfPayload(
                new Meta(
                        1L,
                        "11111111-2222-3333-4444-555555555555",
                        1001L,
                        2001L,
                        3001L,
                        "draft"
                ),
                List.of(
                        new TocEntry("检测报告", 1, 1),
                        new TocEntry("目录", 2, 1),
                        new TocEntry("报告首页", 3, 1),
                        new TocEntry("样品描述", 4, 1),
                        new TocEntry("样品照片", 5, 1),
                        new TocEntry("实验仪器设备清单", 6, 1),
                        new TocEntry("测试条件", 6, 1),
                        new TocEntry("声明", 7, 1)
                ),
                PageOne.sample(),
                new PageTwo(null).withDefaults(),
                new PageThree(
                        List.of(
                                new PageThree.LabeledValue("商标", "/"),
                                new PageThree.LabeledValue("收样日期", LocalDate.now().toString()),
                                new PageThree.LabeledValue("样品状况", "完好"),
                                new PageThree.LabeledValue("测试开始日期", LocalDate.now().toString()),
                                new PageThree.LabeledValue("数量", "1 只"),
                                new PageThree.LabeledValue("测试完成日期", LocalDate.now().plusDays(2).toString()),
                                new PageThree.LabeledValue("样品来源", "送样"),
                                new PageThree.LabeledValue("测试样品状态", "送样")
                        ),
                        List.of(
                                new PageThree.Paragraph("委托单位：浙江和网信息技术有限公司"),
                                new PageThree.Paragraph("委托单位地址：浙江省宁波象保合作区航天大道99号12幢721D室"),
                                new PageThree.Paragraph("制造商：中山市睿者照明科技有限公司"),
                                new PageThree.Paragraph("制造商地址：中山市古镇镇中兴大道103号第3层之5"),
                                new PageThree.Paragraph("生产厂：中山市睿者照明科技有限公司"),
                                new PageThree.Paragraph("生产厂地址：中山市古镇镇中兴大道103号第3层之5")
                        ),
                        List.of(
                                new PageThree.Paragraph("本申请单元所覆盖的其他产品型号规格及相关情况说明："),
                                new PageThree.Paragraph("1. 本申请单元所覆盖的型号规格："),
                                new PageThree.Paragraph("   主检型号：SDG-AI018C-PRO")
                        ),
                        List.of(
                                new PageThree.StandardEntry(1, "GB/T 1234-2022", "灯具分布光度测量的一般要求", "GB/T 1234-2022 灯具分布光度测量的一般要求"),
                                new PageThree.StandardEntry(2, "Q/XDP 001-2024", "企业标准", "企业标准 Q/XDP 001-2024")
                        ),
                        new PageThree.Declaration(
                                "本报告声明",
                                List.of(
                                        "本报告仅对送检样品负责。",
                                        "未经书面批准不得复制。"
                                )
                        ),
                        List.of(
                                new PageThree.SignatureSlot("编制", "张三", "日期", LocalDate.now().toString()),
                                new PageThree.SignatureSlot("审核", "李四", "日期", LocalDate.now().toString()),
                                new PageThree.SignatureSlot("批准", "王五", "日期", LocalDate.now().toString())
                        ),
                        "",
                        "测试结论：本次委托检测项目符合标准要求。"
                ),
                new PageFour(
                        List.of("indoor"),
                        "",
                        new PageFour.Rated("220V", "50Hz", "5W", "--"),
                        "直径26mm×长1200mm",
                        "--",
                        List.of("led"),
                        "",
                        "",
                        List.of("electrical", "cct", "luminous-flux", "cri", "efficacy", "chromaticity", "distribution", "color-deviation", "peak-intensity", "beam-angle"),
                        "",
                        "--"
                ),
                new PageFive(List.of(
                        new PageFive.ImageSlot("storage/sample-top.jpg", "样品外观", 0, 1),
                        new PageFive.ImageSlot("storage/sample-bottom.jpg", "样品细节", 1, 1)
                )),
                new PageSix(
                        List.of(
                                new PageSix.DeviceEntry(1L, "XDP-001", "积分球光谱仪", "光学实验室", "在检", true, "校准有效", "HAAS-2000", "2025/09/24"),
                                new PageSix.DeviceEntry(2L, "XDP-002", "功率分析仪", "实验室A", "在检", true, "", "PF210", "2025/09/24")
                        ),
                        List.of("以上设备均在检定有效期内。")
                ),
                new PageSeven(
                        List.of(
                                new PageSeven.LabeledValue("环境温度", "25℃"),
                                new PageSeven.LabeledValue("相对湿度", "60%"),
                                new PageSeven.LabeledValue("供电电压", "220V/50Hz")
                        ),
                        List.of("测试过程中如遇特殊情况将另行备注。"),
                        new PageSeven.Declaration(
                                "声明",
                                List.of(
                                        "本报告未经本公司书面批准不得部分复制。",
                                        "任何异议请于报告发出之日起十五日内提出。"
                                )
                        )
                ),
                List.of(),
                Map.of()
        );
    }
}
