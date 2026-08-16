# PDF 手写数字签名实施状态与自审记录

**记录时间：** 2026-08-16
**当前分支：** `main`
**功能提交：** `05371d7`
**本地合并提交：** `4789480`
**基线：** `786e3f83940ed695ee83c25914d1d7b6f36aed4e`
**执行方案：** `2026-08-15-pdf-handwritten-digital-signature-plan-final-v15.md`
**发布结论：** **NOT READY**。代码纵向切片与自动化证据已形成，但 Gate S、Gate 0A、Gate 0B 和 Phase 2 仍缺真实环境证据，不能上线或宣称具有正式法律效力。

## 1. 已实现范围

### 页面与交互

- 新增独立页面 `/pdf/handwritten-signing`，支持 PDF.js 页面预览、缩略图、选页、多个签名框、拖动、缩放和实时签名外观预览。
- 手写板支持鼠标、触控和手写笔，输出 canonical PNG；页面提供签署任务、密码 challenge、operation 轮询、拒签和 deferred 首页章激活入口。
- 已接入 `pdf.handwritten-sign`、`pdf.workflow.plan`、`pdf.request.reject`、`pdf.homepage-seal.activate` 等权限和导航控制。

### Laravel 业务与证据链

- documents/revisions/workflow/request/field/slot/appearance/challenge/operation/outbox/execution/publication 数据链已落地。
- 首签前预创建全部签名字段；主检、审核、签发按顺序激活；首页章复用既有 deferred field，不修改已有签名 revision 的字段结构。
- operation 冻结 source、policy、certificate、appearance、field lock 等摘要；取消、拒绝、事务 B 和人工复核使用 operation→execution→scope rows 的锁序。
- Java completed result 每次读取都核对 ledger SHA/size；事务 B 再验签、验字段、物化 immutable revision 并推进 publication pointer。
- Laravel revision 晋升已改为 `staging/{operation}/{lease_epoch}` → `promoting` → atomic rename + file/directory fsync → 独立提交 `promoted/committing` ledger → transaction B。B 事务失败不会删除 final；promoted 重放直接复验并登记同一 final bytes，不再请求 Java status/result。
- 新增定时 operation reconciler：只重投 `pending` claimed outbox，cancelled/dispatched outbox 永不复活；过期 processing/promoted operation 按固定锁序领取新 lease/epoch，经 durable outbox 恢复，active lease、document evidence hold 和 cancelled outbox 均保持不动。promoted final 通过本地 exact path/SHA/size 重新验收后只重放 transaction B，不再依赖 Java status/result；final 缺失或损坏直接进入 manual review，禁止重建。
- 新增 operation orphan file reconciler：每 10 分钟扫描 operation staging/final path，先排除 current fence、takeover recovery candidate、正式 revision/source、appearance/result exact retirement path 和所有 manual/legal/integrity hold；真正 orphan 经过 append-only intent → 同盘 rename/chmod → 源/目标目录 fsync → append-only completion 后进入普通 quarantine。未知 operation 使用确定性 quarantine path 收敛并只写安全日志，不创建 revision 或其他业务记录。
- published revision 下载和定时 sweeper 会按 exact path/SHA/size 检查 immutable bytes；缺失或破坏时在固定锁域内将 revision 置 `unavailable`、document 增加 publication-integrity bit、撤销全部相关 result retirement authorization、保护 execution/appearance bytes 并写 `integrity_withdrawn`。只有可信备份恢复同一路径合同和 SHA，且 Java 对 unsigned/signed revision 完成相应全量结构/签名重验后，受权管理员才能写 `integrity_restored`。多个受损 revision 和人工证据保全可并存，最后一个 owner 释放前不会误清共享 bit。
- 人工复核支持：
  - `adopt_completed`：重新 GET 同一 Java result，校验 SHA/size、PAdES 当前状态、签名数量和目标字段，只投递 `ResumePdfOperationFromJavaResult`；
  - `confirmed_no_private_key`：只在无私钥/结果/promoted 证据时恢复同一 workflow；
  - `confirmed_no_usable_result`：保持 Java 历史终态不变，业务投影为 `irreversible_failed`，要求新 generation。
- appearance/result/document 使用独立 bit mask；人工结案只清本次拥有的 bit。appearance retirement 已实现 `stage_intent → staged → purge_intent → retired`，宽限期内新增 hold 会立即恢复 exact canonical bytes。

### Java PAdES 与安全边界

- Docker 仅绑定 `127.0.0.1:8080:8081`；Laravel 客户端拒绝非 loopback Java URL。
- 内部 PDF 请求使用固定十行 `PDF-HMAC-V1`；metadata/part manifest 使用受限 RFC 8785 JCS，JSON 与 multipart 都绑定精确语义 part，operation 绑定 lease、manifest/input/policy/config 及 policy ID/UUID。nonce 以 Redis `SET ... NX EX 300` fail-closed 并用 AOF 持久化；body 接收固定 120 秒；默认 PKCS#12 密码已删除。
- 签名算法、PAdES-B-T、TSA、证书 trust roots 和 field lock policy 由服务端冻结，不接受请求自由指定。
- 实现首个 certification signature、DocMDP P=2、include-self-only field lock、后继 approval signature、RFC 3161 timestamp 和增量写入。
- execution ledger 覆盖 claim、pre-key retry、private-key boundary、known post-key failure、outcome unknown、completed result、同 descriptor 读取和四阶段 result retirement。
- deadline recovery 不再直接判定 unknown：先查唯一 canonical/temp 持久化结果，按冻结预算读取并验签；有效结果原子晋升并幂等完成 execution，无证据或身份歧义才进入人工复核。
- result 已 staged 后新增 evidence hold 会恢复 exact bytes、递增 retirement epoch 并阻止 purge。
- 文档级 evidence hold 现在按 operation ID 升序锁定全部历史 operation/execution，再锁 document/workflow/request/revision/appearance；同一事务设置 operation 私钥栅栏、execution/appearance hold、撤销 result retirement authorization，并写 manifest hash 与 publication audit。
- Java 首次 execution claim 和私钥边界都会检查 operation 文档保全栅栏；已有 execution 还要二次检查自身 hold mask/legal deadline。hold 先赢时不会创建 execution 或触发私钥。
- 文档 hold 在固定 DB 锁域内通过 HMAC 只读 Java probe 核对 result 的 exact UUID/epoch/phase/SHA/size 及 canonical/staged 路径；probe 不读取 execution DB，避免反向锁。`missing/duplicate/breached` 会使整笔 hold 回滚，`purge_intent + missing` 稳定返回 already-retired。
- result GET 在锁内打开 verified descriptor 后释放 DB 锁；自动化已证明后续 stage rename 与最终 unlink 不会改变该响应读取的 inode 和原始字节。
- appearance arbiter 提供相同的锁内 verified descriptor 原语；自动化已覆盖 descriptor 跨越 stage、hold restore、再次 stage 和最终 purge 后仍读取 exact canonical 笔迹字节，未新增敏感图片公开接口。
- 同一签署行为已支持一个 signature field 绑定多个 widget；测试覆盖两个页面的两个 widget、一次 CMS、后续三次增量签名及全部历史签名有效。
- Java verifier 不再把 `laterRevisionPermission` 固定写成 valid：逐个重建 signed revision 边界，要求最终签名覆盖当前全部字节，验证唯一 certification DocMDP P=2、每个 signature 的 self-only FieldMDP/field lock、预建 target field、稳定 widget geometry，以及 xref 中只有目标 field/AP/signature/必要 AcroForm 资源发生允许的增量变化；未签追加和“篡改后再用有效组织证书补签”均 fail-closed。
- 修复 PDFBox `addSignature` 会把第一个 widget 改成零矩形并覆盖 AP 的行为：签名前冻结全部 widget 矩形，注册 signature 后恢复 geometry，再写 canonical 手写 AP；multi-widget 回归现在同时验证 AP 和非零矩形。

## 2. 本轮自审发现并已修复

1. **result hold 到达 staged/purge 阶段后没有恢复路径**：新增 Java `applyRetirementRestore` 与回归，恢复 canonical bytes 后再提交 available/phase none。
2. **私钥边界后 deadline 直接标记 outcome unknown**：新增 canonical/temp recovery、大小预算、签名存在性校验、原子 promotion 和 completed CAS 幂等。
3. **无签名 PDF 可能被 verifier 的空集合逻辑视为 valid**：生成结果与恢复结果现在同时要求 `documentCurrentState=valid` 且至少一个签名。
4. **`adopt_completed` 复用了普通 execute job**：新增 result-only job，并在 job 入口强制 `java_polling + completed`，代码路径不允许 POST/私钥调用。
5. **人工采用后的 appearance 仍为 quarantined，事务 B 必然失败**：采用时恢复为 claimed，但 manual/quarantine hold 保持到事务 B 成功后才按位释放。
6. **document manual-review 使用了错误的 integrity bit**：改为 bit 3 (`8`)；每次 set/release 同时递增 `integrity_version`。
7. **appearance/result hold 原因会互相覆盖或无审计**：改为 bitwise OR/AND NOT，execution hold 变化写 append-only event。
8. **appearance 在 staged 宽限期内收到 hold 不会立即恢复**：增加 held retirement sweep，并为 claimed artifact 补齐 operation→execution→document→workflow→request→revision→artifact 锁序。
9. **已永久 retired 的历史证据会被文档 hold 静默跳过**：安装前在同一锁域检查 result/appearance 永久终态；发现任一已退休证据整笔回滚并稳定返回 `PDF_DOCUMENT_EVIDENCE_ALREADY_RETIRED`，不会形成名不副实的 active hold。
10. **叠加 legal hold 后释放会破坏原 deadline，且 UTC manifest 回写 SQL timestamp 会偏移 8 小时**：manifest 记录每个目标的原始 deadline，释放时只恢复本次安装前值，并显式转换回应用时区。
11. **文档 hold 无法阻止尚未创建 execution 的 Java claim，已有 execution 也可能越过私钥边界**：在 operation 增加 document evidence fence 投影；Java claim/private-key gate 原子检查 operation fence，私钥 CAS 另检查 execution hold/legal deadline，并补两条无私钥副作用回归。
12. **Java 全量测试被两个不可复现/过期断言阻塞**：确认 sample 无真实图片时 renderer 按产品逻辑跳过空照片页，测试改为精确验证 6 页及关键章节；`Gb70001ParserTest` 不再读取仓库外 `../../extra.pdf`，改读版本控制内 classpath fixture。未改产品 renderer，Java 全量恢复绿色。
13. **`purge_intent` 已 unlink 但 DB 未提交时，Laravel 仅看数据库会落出假的 active document hold**：新增固定路径 retirement evidence probe；Laravel 持有 operation/execution 锁时要求 Java 文件系统返回 exact canonical 或 staged 证据，missing/duplicate/breached/身份回显不一致全部回滚。测试覆盖五种文件状态与 purge-intent missing 原子失败。
14. **result GET 与 retirement 并发只停留在代码推断**：新增同一已验证 descriptor 跨越 rename、unlink 后继续读取 exact bytes 的回归，证明响应不重新按路径打开文件。
15. **appearance preview/manual-review 缺少同一 descriptor 读取合同**：在统一 appearance retirement arbiter 增加锁内打开、regular-file/SHA 验证和 rewind 原语；测试证明 stage/restore/purge 不改变已打开 descriptor 的原笔迹字节，同时不扩大 HTTP 暴露面。
16. **retirement 文件动作先于数据库提交时缺少真实进程崩溃证据，重复副本还可能被误判为成功**：result 与 appearance 回归现在分别启动独立 JVM/PHP worker，在 rename/restore/unlink 与目录 fsync 完成后以 signal 9 / `halt(137)` 终止；父进程账本仍停在旧 intent/state，后续 sweep 可幂等完成。Java stage 发现 canonical+staged 双副本、purge 发现 canonical 副本时改为 fail-closed 并保持原 DB 状态，不删除歧义证据。
17. **appearance purge 的仲裁和审计弱于 result**：purger 过去可能在 canonical 仍存在时删除 staged 并提交 retired，且五类状态转换没有 append-only 事件。现在 canonical/符号链接等残留一律 fail-closed；stage/purge 重复副本回归确认 DB 保持旧 phase。`stage_intent/staged/purge_intent/restored/retired` 全部在相同事务写既有 `activity_log`，失败转换不会留下伪事件，未新增业务表。
18. **Laravel transaction B 直接写 final，rename 后 DB commit 失败不可重放**：`PdfImmutableFileStore` 现在强制 staging file `fflush/fsync`、只读权限、atomic rename、父目录 fsync、descriptor SHA/size 复核、symlink 拒绝和未晋升 temp 清理。operation 先持久化 `staging/verifying/promoting`，final 落盘后另一个事务记录 `promoted/committing + path/SHA/size`，transaction B 只登记 exact final。SQLite trigger 连续拒绝 revision INSERT 后，账本和 final 均保持，移除故障后同一 job 无新签名 POST完成提交。
19. **manual adoption 与 queue duplicate 没有真正满足 lease/result-only 合同**：人工采用现在写显式 `manual_adoption_result_only` 投影、领取新 lease，只允许精确 manual-review/quarantine bits 保留到 transaction B；Resume job 不再调用 status或签名 POST，只 GET completed result。Java completed result read 接受 current operation lease但不重写原 `authorized_lease_epoch`，旧/过期 lease 回归被拒绝。重复 queue delivery 只有赢得 `java_request_started_at` CAS 的 worker 可以 POST，其余只 polling。
20. **无 lease/过期 lease 没有定时收敛，promoted 重放仍错误依赖 Java**：新增每分钟 reconciler 与命令，覆盖 pending claimed 重投、expired processing/promoted 接管、manual-adoption result-only 恢复、有效租约/文档保全/cancelled outbox 跳过，并写 hash-chain recovery event。promoted operation 现在只从已晋升 final 的 exact descriptor 读取并复验，Java result 已 missing/breached 时仍可用可信 final 完成 transaction B，同时写 append-only fallback event；final 不存在或摘要不符则 fail-closed。Java status read 接受 current takeover lease，但 execution 的私钥授权 epoch 不改写；测试证明新 lease 可读 executing 状态却不能跨越旧 execution 私钥边界，completed operation 也拒绝伪造的 lease epoch。
21. **complete staging 与 orphan file 没有形成同一套崩溃恢复合同**：worker 现在会在 Java result unavailable 时，从 operation/revision 身份目录中使用同一 descriptor 查找唯一 exact staging/final；唯一副本迁入当前 lease fence 后继续验证、晋升和 transaction B，多份 exact 副本、符号链接或身份越界全部 fail-closed。orphan reconciler 使用 operation event intent/completion 记录文件动作，独立 PHP 进程已在 rename + 双目录 fsync 后被 SIGKILL，下一轮从“source missing + quarantine exact”补齐权限/fsync和 completion。current/takeover/manual-review/retirement hold 证据不会被误隔离，unregistered orphan 只进入普通 quarantine，测试确认不会增加 `pdf_files`。
22. **已 published revision 的存储损坏只在下载时抛异常，没有形成撤回/恢复状态机**：新增下载 fail-closed、每 10 分钟 sweeper、automated evidence hold manifest、`unavailable/hold/integrity_withdrawn` 和受权恢复命令；恢复前强制 exact bytes 与 unsigned/signed 全量重验，成功后才写 `ready/integrity_restored`。两个历史 revision 同时损坏时，先恢复一个不会提前解除 document hold。
23. **revision integrity 与人工 document hold 共享 retirement-integrity bit 时，旧布尔 `preexisting` 无法表达多个 owner**：两类 manifest 均记录安装时的 owner scope；释放时同时检查其他 active owner 和已记录 owner 是否仍存活。自动化覆盖“先人工 hold 后 revision withdraw”和“先 withdraw 后人工 hold”两种相反释放顺序，均不会误清或永久残留 operation/execution/appearance bit。
24. **Java `laterRevisionPermission` 是硬编码 valid，且 PDFBox 会清空首个 widget 的可视 geometry/AP**：新增 signed-revision/xref 权限验证器，逐次验证 DocMDP、FieldMDP、field lock、target field/widget、对象变化 allowlist 和最终签名覆盖；无签追加以及篡改后合法补签都返回稳定 invalid。签名生成改为 PDFBox 注册后恢复所有 widget 矩形并重建 AP，测试明确要求 multi-widget 的 AP 存在且宽高非零。
25. **finalize/prepare 原先在 HTTP 请求内直接改字节与 revision**：改为独立 control operation + outbox + durable promotion + transaction B，网页轮询 operation 终态后才使用 finalized/planning bytes。
26. **workflow/request/field 仅有单列外键，无法由 DB 阻断跨 workflow/act 绑定**：新增 predecessor、request/act 和 source-field/act 复合外键；坐标在前后端统一为六位小数字符串。
27. **late seal no-write bind 只核对字段名，不足以证明已签 bytes 的原字段结构未变**：现在先打开 exact published bytes 并由 Java inspect，核对三个历史签名、DocMDP P=2、unsigned deferred field、field/widget/AP object refs 和六位几何，manifest 冻结后才做 DB-only bind。
28. **HMAC 实现仍是旧七行 content digest，multipart 验证散落且 `extract-cover` 可漏验 body**：完成十行合同、单一 PHP/Java 共享正反向向量、规范 RFC 3986 request target、固定 300 秒 nonce、120 秒接收门限和中央 multipart interceptor；重复/未知/缺失/多余 part 在 controller 前 fail closed。
29. **legacy `/api/pdf/process` 仍可接收已签 PDF**：在任何 legacy writer/私钥路径前检查 signature dictionary 和 `/Perms/DocMDP`，已签输入稳定拒绝。
30. **source 只按权限校验，其他有权用户可抢先 confirm/finalize；且存在计划外 operation cancel/sign 别名**：在持锁 source/document 上强制 owner 一致，并删除别名，只保留 v15 的 workflow cancel 和 request sign 精确路由。
31. **HMAC metadata 虽包含 policy UUID，Java execution body/DB policy 查询未消费该值**：`ExecutionOperation`、operation claim、immutable policy lookup 与 HMAC headers 现在必须同时匹配 policy ID/UUID/hash/config，任一不一致都在 execution claim 前拒绝。
32. **HMAC key rotation 只有实现和运维说明，没有双方可执行回归**：Laravel 现在验证从包含旧/新 key 的 keyring 选择新 active key 并使用其 secret 计算 MAC；Java 验证 active key 已切换时旧/新 key 可在轮换窗口并存，未知 key 会在 nonce claim 和业务链路之前稳定拒绝。
33. **Java 本地直跑默认监听所有网卡，与 loopback 边界不一致**：Spring 现在默认绑定 `127.0.0.1`；Docker Compose 才显式绑定容器内 `0.0.0.0`，宿主发布端口仍固定为 `127.0.0.1:8080:8081`。本地进程已通过 `lsof` 证明只监听 `127.0.0.1:8081`。

## 3. 当前验证证据

| 验证项 | 结果 | 说明 |
|---|---:|---|
| Laravel full test | PASS | 246 tests / 1925 assertions |
| Frontend full test | PASS | 52 files / 165 tests |
| Frontend ESLint | PASS | 无错误 |
| Frontend production build | PASS | 两套 Vite build 完成；仅有既有 chunk-size warning |
| Java focused execution/retirement tests | PASS | deadline recovery、hold restore、四阶段 retirement、document-hold claim/private-key fence、retirement evidence probe、GET descriptor-vs-unlink、文件动作先于 DB commit 的幂等恢复与重复副本 fail-closed 均通过 |
| Java package (`-DskipTests`) | PASS | JAR 可构建 |
| Java full test | PASS | 72 tests：67 passed、5 skipped、0 failures、0 errors |
| PHP Pint | PASS | 本轮任务文件通过 |
| `git diff --check` | PASS | 无 whitespace error |
| 真实 Chrome 基础 UI | PASS（部分） | 已验证页面、规划器、deferred seal、拒签面板和真实鼠标手写；浏览器自动化尚未完成真实 file chooser 上传后的全流程 |

## 4. Phase / Gate 证据矩阵

| 阶段 | 当前状态 | 已有证据 | 未闭合门禁 |
|---|---|---|---|
| Phase S / Gate S | PARTIAL | loopback、十行 HMAC、PHP/Java 共享 vectors、tamper/replay/expired、unknown key、双 key rotation 回归、中央 multipart allowlist、120s receive fence、Redis fail-closed + AOF nonce、无默认密码、固定 policy | 真实 Redis 故障演练、真实 key rotation smoke、真实容器网络和私钥“不被触发”观测 |
| Phase 0A / Gate 0A | BLOCKED | 测试 CA/TSA 下单字段 PAdES-B-T、DocMDP P=2、self-only FieldMDP/field lock、ByteRange、signed-revision/xref 增量权限、未签追加/合法补签篡改拒绝、multi-widget AP/geometry 单测 | 真实组织 PKCS#12、真实 TSA、20 MiB 边界测量、Acrobat、Foxit、独立验证器矩阵 |
| Phase 1 | PARTIAL | 单人 API/UI/ledger/recovery/manual-review 代码和自动化测试 | 真实 source→challenge→Java→immutable revision→download/verify 纵向运行；真实最小权限 MariaDB grants 验收 |
| Phase 0B / Gate 0B | PARTIAL | 测试 CA/TSA 下三次顺序 CMS、deferred field、一个 field 两页双 widget、一次 CMS 与历史签名自动验证 | Acrobat/Foxit/独立验证器的 multi-widget 兼容性；真实 later-seal revision |
| Phase 2 | PARTIAL | 三人状态机、publication、public verify、published revision exact-byte sweeper/withdraw/restore、多 revision 与多 owner hold、manual review、result/appearance retirement 与 append-only audit、文档级多历史 operation 原子 hold、stage restore/takeover、old-epoch exact candidate adoption、orphan intent/quarantine、retired stable conflict、purge-intent exact 文件 probe、result/appearance descriptor-vs-unlink、独立进程在 rename/restore/unlink + directory fsync 后被 SIGKILL 的恢复、两侧重复证据 fail-closed、Laravel final promotion + 独立 promoted ledger + transaction B rollback/replay、pending-only outbox 重投、expired lease takeover、promoted-only transaction B、result-only 新 lease adoption、单 POST CAS、Java claim/private-key fence、基础 Chrome UI | 真实 MariaDB commit/restart 故障注入、真实 Chrome 完整流程 |

## 5. 数据库事故与恢复阻塞

2026-08-16 约 11:15 CST 曾误执行 `php artisan migrate:fresh --env=testing --force`；当时仓库没有安全的 `.env.testing`，命令命中了远程共享 MariaDB `10.21.1.227/new-lims`。只读取证显示业务表已被清空重建，`log_bin=OFF`，未找到数据库备份；NAS `/vol1` 为 Btrfs，优先从事故前 snapshot 恢复。

事故后本地已新增 `backend/.env.testing`，强制测试使用 SQLite `:memory:`。用户随后明确选择使用目录 `.env` 指向的 MySQL 进行本地手测。2026-08-16 晚间只执行普通 `migrate --force`，第一条 pending migration 在首个 `CREATE TABLE pdf_documents` 因表已存在而立即失败，没有继续后续 DDL。

只读对账确认：`users/test_orders/pdf_files` 均为 0；新 PDF 表均为 0 行；`pdf_signing_operation_events` 与 `pdf_document_evidence_holds` 缺失；`migrations` 表没有两条新 migration 记录。该状态属于 MariaDB 非事务 DDL 留下的空半成品 schema。

随后执行的受控修复先验证所有目标表和 `pdf_files` 均无业务行、目标 migration 记录不存在且新增列集合完整，再调用修正后的 migration `down()` 清理半成品。第一次清理暴露 `pdf_files_document_revision_unique` 必须在两个外键之后删除，已修正回滚顺序；第二次清理通过。之后普通 `migrate --force` 成功运行两条 pending migration，`migrate:status` 显示两者均为 batch 2。`CanonicalAcceptanceSeeder` 已生成 9 个验收用户和 8 个角色，并额外创建 1 条 `local-manual-v1` B-T 规划策略；当前 `pdf_documents` 与 `pdf_files` 仍为 0 行。

本地手测运行时使用 `QUEUE_CONNECTION=sync`，只同步处理当前页面触发的任务，没有消费远程共享 `default` 队列。前端 `127.0.0.1:5173` 与 Laravel `127.0.0.1:8000` 返回 200；Java 结构检查、未签名定稿和字段预创建接口运行于 `127.0.0.1:8081`。由于尚未装入真实单位 PKCS#12、RFC 3161 TSA 信任配置和 Java 最小权限 execution-ledger 账号，Java health 对完整签名能力返回 503；不得把当前本地手测解释为最终 PAdES-B-T 或法律效力验收。

## 6. 下一实施门禁

1. 先完成远程数据库恢复/重建决策与取证；恢复前保持只读。
2. 准备专用组织签名证书、完整链、TSA、Redis 和最小权限 Java DB 账号，执行 Gate S 与 Gate 0A。
3. 用 13 页和 20 MiB 边界 PDF 冻结真实 generated revision / increment budgets，并保留至少 20% headroom。
4. 完成 Acrobat、Foxit、Java 和独立 PAdES verifier 结论矩阵；不以测试 CA/TSA 代替。
5. 在真实 Chrome 完成上传、规划、三人顺序手写签署、位置调整、实时预览、发布、deferred 首页章及公开验证。
6. 数据库恢复后在隔离 MariaDB 补 commit/restart 故障注入；现有自动化已覆盖独立进程在 rename/restore/unlink + directory fsync 后被终止，以及 SQLite transaction B 强制回滚后的 promoted final 重放。

## 7. 版本控制状态

- 功能提交 `05371d7` 已通过 merge commit `4789480` 合入本地 `main`；当前尚未 push 或创建 PR。
- 本次 follow-up 修正 migration 回滚顺序并收紧 Java 本地监听边界；提交完成后 `main` 将相对 `origin/main` ahead 3。
- 两个被 v15 取代的旧方案草稿保持 untracked，未删除、未暂存。
- 已按用户明确要求使用 `backend/.env` 的 MySQL 连接完成空库迁移与验收数据初始化；未执行远程应用部署、证书导入或私钥调用。
