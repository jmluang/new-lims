# F2 Busy 修复与原13页报告回归完成

结论：本次Busy传输契约修复及正常输出不退化回归PASS。原报告性能与合成3页结果严格分开。

F2源码SHA256：ec71288388d0d6db6f8af4910c76012472c9427933fdcebdb059955a3286623e
二进制SHA256：9f415a329b6f62646778847dc9f87cc0ffb7cc4647aa637f66d3622b922fe283
实际运行：lims-rust-accept-7da8b46b416849ce

|项目|实测|
|---|---|
|配置|2CPU / 2GiB / swap0 / capacity3 / clients5|
|三批成功|9/15；另6客户端有限重试后规范503 PDF_BUSY|
|Busy响应|24；与Java完整JSON、content-type、Retry-After=1一致|
|传输错误|0；F91基线BrokenPipe问题不再出现|
|成功HTTP RTT中位数|8.666599936秒|
|接收阶段有效吞吐|0.289663163请求/秒|
|cgroup生命周期峰值|118079488字节|
|swap / OOM / 采样缺口|0 / 0 / 0|
|正常输出配对|9/9完整配对PASS；13页、每份16签数学有效|

三个新增回归及既有Busy测试4/4实际PASS。旧失败断言仅错误假设JSON字段顺序，原失败记录保留。新测试比较完整解析JSON，不放宽字段和值、503、Retry-After或TCP无中断要求。

RTT仅成功HTTP接收时间；排除预热和离线验证。吞吐含有限Busy重试的批接收阶段。峰值含启动/预热/三批，不能当单请求RSS。capacity3下合理503不是OOM，也不是5个业务槽位全部成功的保证。

Java/F91原报告六档：b1e59b39ddc0481b。F2仅目标Rust5复测，Java5数据复用并记录来源，不重跑整套UI。81fe采样超时仅INCOMPLETE，现场和原数据保留；7da完整观测通过。

部署交付为三份sourcepatch和准确身份说明；生产必须ledger=true、同LaravelDB/schema、持久可写结果/spool、共享Redis nonce及真实签署/TSA/pins。测试ledger=false和测试CA不构成生产配置或公共阅读器信任。没有push、merge或生产部署。
