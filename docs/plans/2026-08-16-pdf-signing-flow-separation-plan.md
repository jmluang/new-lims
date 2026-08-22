# PDF 签章与手写数字签名流程拆分实施方案

**版本：** v1 Draft  
**日期：** 2026-08-16  
**代码基线：** 本地 `main @ 88d0909`  
**远程基线：** `origin/main @ 786e3f8`  
**前置状态：** 工作区存在尚未提交的 operation 投递、运行时自检、错误提示和本地 Java 启动脚本改动；实施前必须先独立 review，不能在本方案提交中混入。  
**替代范围：** 本方案替代 v15 中“把 deferred 首页章纳入手写签名 Lean V1”的产品边界；不撤销已经确认必要的增量签名、安全边界和私钥调用保护。

---

## 一、结论

系统保留两套彼此独立的产品流程：

1. **PDF 签章（原流程）**：上传 PDF，选择声明页、首页章、骑缝章、功能章，执行一次组织证书签名并下载成品。
2. **手写数字签名（新流程）**：上传未签 PDF，在页面上规划任意数量的手写签名位置，按顺序由分配用户完成增量数字签名。

两套流程共享 Java 证书加载、HMAC、PDF inspection、签名验证、RFC 3161 客户端、私有存储和审计等底层设施，但不共享页面状态、业务任务、输入记录、权限或业务 API。

V1 明确不支持两套成品互相再次加工。手写签完后追加首页章属于后续 Cross-flow V2；V2 必须使用首签前预留字段和增量签名，不能把已签 PDF 重新送入旧 `/api/pdf/process`。

---

## 二、目标与非目标

### 2.1 V1 目标

- 恢复并证明原 `/pdf/signing` 页面和 `/api/pdf/signing/*` 流程可独立使用。
- 新 `/pdf/handwritten-signing` 页面只承担手写签名规划与签署。
- 手写页面支持任意数量签名位，不把“主检、审核、签发”写死为核心领域约束。
- “主检、审核、签发”作为默认模板，用户可增删、改名、调整顺序和分配人员。
- 第一次数字签名前一次性预建本次已确认的全部手写签名字段。
- 每次手写签署形成增量 revision，并重新验证所有历史签名。
- SHA-256 作为字节身份和审计信号，不作为全局业务唯一键。
- 旧流程和新流程分别暴露准确的 readiness，不再由一个总 health 互相拖累。
- 所有签名能力如实标记实际 PAdES/TSA 级别，禁止宣称未实现能力。

### 2.2 V1 非目标

- 手写签完后追加首页章、骑缝章或功能章。
- 把旧 PDF 签章输出继续送入手写流程。
- 把手写签名输出继续送入旧 PDF 签章流程。
- deferred 首页章 workflow、手工输入 workflow UUID、late-seal 发布流程。
- 历史 PDF 自动归并为新 document/revision 链。
- 多组织、多证书、多 TSA 策略管理后台。
- evidence retirement、法律保全、复杂人工裁决产品化。
- 在本轮重写已有文件台账、公开验证和下载体系。

---

## 三、产品信息架构

```text
PDF 防篡改
│
├─ PDF 签章
│  ├─ 上传 PDF
│  ├─ 可选声明页
│  ├─ 首页章 / 骑缝章 / 功能章
│  ├─ 一次组织证书签名
│  └─ 下载并登记旧签章台账
│
└─ 手写数字签名
   ├─ 上传未签 PDF
   ├─ 实时分页预览
   ├─ 添加、拖动、缩放 N 个签名位
   ├─ 分配人员与顺序
   ├─ 冻结全部签名字段
   ├─ 逐人手写并增量签名
   └─ 下载最终签署版本
```

### 3.1 原 PDF 签章页面

入口保持 `/pdf/signing`，权限保持 `pdf_signing.read/create`。

页面继续提供：

- 上传 PDF；
- 声明页模板；
- 首页章；
- 骑缝章；
- 首页功能章；
- 报告编号确认；
- 一次性处理、下载和台账登记。

该页面不显示手写签名任务、签名位置、workflow、request、operation UUID 或 revision chain。

### 3.2 手写数字签名页面

入口保持 `/pdf/handwritten-signing`，权限使用 `pdf.workflow.*`、`pdf.request.*` 和 `pdf.organization_key.use`。

```text
┌──────────────────────────────────────────────────────────────────────┐
│ 手写数字签名                                                        │
├──────────────┬───────────────────────────────────┬───────────────────┤
│ 页面缩略图    │ PDF 实时预览                       │ 签名位置          │
│ [01]         │                                   │ 1 主检  张三      │
│ [02]         │      ┌──────────────┐             │ 2 审核  李四      │
│ [03]         │      │ 可拖动/缩放   │             │ 3 签发  王五      │
│ ...          │      └──────────────┘             │ [+ 添加签名位置]  │
├──────────────┴───────────────────────────────────┴───────────────────┤
│ [保存草稿] [冻结位置并创建签署任务]                                 │
└──────────────────────────────────────────────────────────────────────┘
```

每个签名位包含：

- `label`：显示名称，例如主检、审核、签发、复核人；
- `sequence`：正整数且 workflow 内唯一；
- `assigned_user_id`；
- `page_index`；
- `normalized_rect`；
- `field_name`：冻结时由服务端生成，不由浏览器决定；
- `signature_role`：首签可以是 certification，后续为 approval；该密码学角色不能由普通用户任意覆盖。

默认模板生成主检、审核、签发三个位置，但用户可以删除、增加、改名、换人和调整顺序。至少 1 个位置，V1 上限建议 20 个。

### 3.3 首页章入口

从手写页面 V1 删除以下界面：

- “首页盖章（预留）”签名位；
- “启用已预留的首页章”；
- 手工输入 workflow UUID；
- 盖章操作人选择；
- late-seal workflow 状态。

旧 PDF 签章页面的首页章能力不删除、不迁移。

---

## 四、领域与数据边界

### 4.1 原 PDF 签章

继续使用现有：

- `digital_signatures`；
- `perforation_stamps`；
- `homepage_function_stamps`；
- `certificate_templates`；
- legacy `pdf_files` 台账字段。

原流程输出的 `pdf_files` 行允许 `document_id/revision_uuid/revision_number` 为 null，继续使用 `file_id`、旧下载和验证合同。

### 4.2 手写签名

保留当前新流程的核心实体，但收缩用途：

- `pdf_documents`：一份手写签署业务文档；
- `pdf_files` 新字段：该文档的不可变 revisions；
- `pdf_source_uploads`：临时输入；
- `pdf_signing_workflows`：一次签名位置计划；
- `pdf_signing_acts`：N 个通用手写签名行为；
- `pdf_signing_requests`：分配给具体用户的顺序任务；
- `pdf_signing_fields/pdf_signing_slots`：首签前冻结的 PDF 字段和位置；
- `pdf_signing_challenges/pdf_signing_operations`：再认证、幂等和不可逆调用边界。

V1 不新建“首页章 act”或“later seal workflow”。已经存在但未产生业务数据的 homepage-seal 专属代码和状态应删除，而不是隐藏在 UI 后继续维护。

`pdf_source_uploads` 增加上传幂等 scope/key；同一用户对 inspect 使用相同 `Idempotency-Key` 时返回同一 source。不能仅凭“用户相同且 SHA 相同”猜测两个上传具有同一业务意图。

### 4.3 从固定语义角色改为通用签名位

当前 `semantic_role=inspector/reviewer/issuer/homepage_seal` 改为通用模型：

- 新增或改用 `label` 保存业务显示名称；
- `sequence` 决定签署顺序；
- `act_type` V1 固定 `handwritten`；
- 删除对 inspector/reviewer/issuer 的服务端固定枚举依赖；
- certification/approval 属于密码学策略，由 sequence 和 policy 决定；
- 首页章不作为 V1 act type。

数据库 migration 已在 `.env` 指向的测试库执行，不能直接修改并假设旧 migration 从未运行。实施前先查询业务数据：

1. 若 workflow/request/act/field/operation 全部为 0，可新增前向 migration 调整列和约束；
2. 若已经存在测试数据，先输出精确清单并只清理明确的测试记录；
3. 若存在不可确认的数据，停止 schema 收缩，不得猜测删除；
4. 已推送或共享环境一律使用前向 migration，不重写已运行 migration。

---

## 五、SHA-256 与业务身份

### 5.1 正确语义

- SHA-256 表示“这些字节相同”。
- `source_uuid` 表示“一次上传输入”。
- `document_uuid` 和报告编号表示“业务文档身份”。
- `revision_uuid` 表示“业务文档的一个不可变版本”。
- idempotency key 表示“同一业务请求的重复提交”。

这些概念不能用一个全局 SHA unique 代替。

### 5.2 数据库调整

- 删除 `pdf_source_uploads.sha256` 的全局 unique；
- 改为普通索引；
- `pdf_files.sha256_hash` 保持可重复；
- 保留 `(organization_scope, normalized_report_number)` 的业务唯一约束；
- 保留 `revision_uuid` 唯一和 `(document_id, revision_number)` 唯一。

### 5.3 上传处理

同一 SHA 再次上传时：

1. 同一用户、同一 inspect scope 和相同 `Idempotency-Key`：返回原 source，保持幂等；
2. 新的 `Idempotency-Key`，无论用户或 SHA 是否相同：允许创建新 source；
3. SHA 已出现在 legacy `pdf_files` 或其他 document：允许上传，但记录 `duplicate_content_detected` 审计信息；
4. 相同报告编号已绑定其他 document：在 confirm 阶段按报告身份返回 409；
5. 过期、取消或失败 source 不得永久阻止相同字节重新上传。

source 清理任务只删除满足以下条件的临时输入：

- 未绑定有效 document；
- 不被非终态 operation 引用；
- 已过期；
- 不处于 manual review/evidence hold；
- 删除文件和更新状态均写审计。

---

## 六、Java 与运行时边界

### 6.1 共享但分能力的 Java 服务

继续使用一个 Java 进程，但 API 明确分区：

- legacy：`/api/pdf/process`；
- handwritten preparation：`/internal/pdf/signatures/inspect|finalize-unsigned|prepare`；
- handwritten irreversible signing：`/internal/pdf/signatures/sign-existing-field`；
- verification：独立验证接口。

新工作流永不调用 `/api/pdf/process`；旧流程永不调用 workflow execution ledger。

### 6.2 Readiness 拆分

Java 和 Laravel 的健康输出至少区分：

- `transport_ready`：loopback/HMAC/Redis nonce；
- `legacy_signing_ready`：transport + PKCS#12 + legacy policy；
- `handwritten_planning_ready`：transport + inspection/prepare；
- `handwritten_signing_ready`：transport + PKCS#12 + execution ledger + immutable policy + TSA + result storage；
- `verification_ready`：验证器和 trust bundle。

总 health 可以继续返回整体状态，但页面和自检命令必须读取对应 capability，不能用 `execution_database_ready=false` 阻断只需要 legacy 签章的页面。

### 6.3 HMAC fail-closed

- Java 启用 HMAC 且缺 key 时拒绝启动或明确 not-ready；
- Laravel 在 `PDF_SERVICE_ENABLED=true` 且启用 HMAC时，缺 active key/keyring 必须在启动自检、health 和 `pdf:check-runtime` 中失败；
- 不等待第一次用户上传才暴露配置错误；
- 一致性验证必须实际发送一笔无私钥副作用的签名认证请求，验证 MAC、key id 和 nonce；
- secret 不进入代码、日志、数据库、计划文档或 Git；
- `.env.local`、PFX 和生成结果目录必须在写入前加入 `.gitignore`。

### 6.4 队列与 outbox

operation 创建事务成功后直接异步投递对应 job；outbox 只负责崩溃恢复和漏投递补偿。

- 使用 `DB::afterCommit()` 或框架正式的 after-commit queue contract；
- 禁止 `afterResponse()`，因为它可能同步占用 PHP-FPM 进程执行 TSA/Java 调用；
- web 请求不消费共享队列；
- 本地手测必须显式运行专用 queue worker，或使用隔离队列名；
- reconciler/outbox 调度器是恢复路径，不是正常低延迟投递路径；
- operation status 的前端等待上限必须覆盖登记 deadline 和 Java execution deadline，并显示当前 stage，而不是统一报“等待超时”。

---

## 七、签名与 TSA 策略

### 7.1 原 PDF 签章

当前旧 `SimpleSignatureInterface` 没有实现 RFC 3161，虽然历史 Laravel 曾传 TSA 开关，Java 只保留 TODO。因此：

- 不恢复 `tsa_enabled/tsa_url/hash_algo` 请求参数；
- 算法、证书和 TSA 必须由服务端策略决定；
- V1 UI 和台账如实显示实际级别，未含可信时间戳时不得显示 PAdES-B-T；
- 实施前抽样验证历史成品，记录 CMS、ByteRange、证书链、签名级别和时间戳属性；
- 若原流程业务要求 B-T，作为独立任务接入已验证的 RFC 3161 client，并补旧流程专项阅读器验收。

### 7.2 手写数字签名

手写图只作为可见 appearance；数字签名由组织证书完成。

每次签署必须：

1. 绑定精确 source revision SHA；
2. 绑定精确字段、位置、appearance hash、用户和 policy version；
3. 当前密码再认证；
4. 填充已存在字段，不新增字段、不改页面内容；
5. 使用服务端不可变策略选择 SHA-256、证书、TSA 和 trust bundle；
6. 嵌入真实 RFC 3161 signature timestamp；
7. 保存增量 revision；
8. 验证所有历史签名和时间戳；
9. 验证失败时不发布、不覆盖父 revision、不盲目重签。

### 7.3 必须保留的不可逆边界

- 私钥调用前可以在证明安全时重试；
- 私钥调用后禁止 HTTP 自动重发；
- 响应丢失只能查询相同 operation 的 Java execution/result；
- idempotency key、operation UUID 和 input/policy/appearance hash 必须绑定；
- completed execution 的结果缺失不能触发第二次私钥调用；
- 取消和拒绝不能绕过进行中的不可逆 operation。

这些属于正确性和安全性，不因产品流程收缩而删除。

---

## 八、API 边界

### 8.1 原流程 API

保持：

- `GET /api/pdf/signing/options`；
- `GET /api/pdf/signing/certificate-templates/{id}/file`；
- `POST /api/pdf/signing/process`。

旧 API 的 request/response 和权限需要建立冻结的 contract test，防止手写功能再次无意修改。

### 8.2 手写 V1 API

保留并简化为：

- `GET /api/pdf/handwritten-signing/options`；
- `POST /api/pdf/signing-sources/inspect`，必须携带上传 `Idempotency-Key`；
- `POST /api/pdf/signing-sources/{source}/confirm`；
- `POST /api/pdf/signing-sources/{source}/finalize`；
- `POST /api/pdf/signing-workflows`；
- `POST /api/pdf/signing-workflows/{workflow}/prepare`；
- `POST /api/pdf/signing-workflows/{workflow}/cancel`；
- `GET /api/pdf/signing-workflows/{workflow}`；
- `GET /api/pdf/signing-requests`；
- `GET /api/pdf/signing-requests/{request}`；
- `POST /api/pdf/signing-requests/{request}/appearances`；
- `POST /api/pdf/signing-requests/{request}/challenge`；
- `POST /api/pdf/signing-requests/{request}/sign`；
- `POST /api/pdf/signing-requests/{request}/reject`；
- `GET /api/pdf/signing-operations/{operation}`；
- `GET /api/pdf/revisions/{revisionUuid}/download`。

从 V1 删除：

- `POST /api/pdf/signing-workflows/{workflow}/activate-homepage-seal`；
- homepage-seal request/act 创建逻辑；
- homepage-seal 专属 UI 和错误码；
- no-write bind deferred field 分支。

---

## 九、实施阶段

### Phase 0：冻结事实与保护当前工作区

1. 记录 `git status`、HEAD、当前未提交文件和 diff。
2. 对现有未提交的 operation dispatch、runtime inspector、错误映射和 `run-local.sh` 单独 review。
3. 只修复这批改动自身问题，运行测试后单独 commit；不得和流程拆分混成一个提交。
4. 读取 `.env` 只确认配置项存在，不输出 secret。
5. 只读统计远程数据库中的 source/document/workflow/request/operation 数量。
6. 有业务数据时先形成迁移清单；没有证据不得删除。

**Gate 0：** 当前工作区边界清楚，现有修复已独立 review，数据库现状有只读证据。

### Phase 1：建立旧流程回归基线

1. 使用 `origin/main @ 786e3f8` 或 `05371d7` 前基线列出旧页面控件和 API contract。
2. 在当前 main 上验证旧导航、权限、options、上传、各类印章、签名、下载和台账。
3. 比对输出 PDF 的页面数、印章位置、摘要、CMS、证书链和时间戳属性。
4. 明确旧流程当前真实签名级别。
5. 为旧 API 补 contract/regression tests。

**Gate 1：** 能独立完成一次旧 PDF 签章；输出与历史业务语义一致；没有虚假 TSA 声明。

### Phase 2：拆分运行时 capability

1. Laravel 配置和 runtime inspector 分别输出 legacy/handwritten readiness。
2. Java health 拆分能力，不让 execution ledger 阻断 legacy。
3. HMAC 缺失在启动、自检和 health 阶段暴露。
4. 双方执行真实 HMAC probe。
5. 页面按自身 capability 显示准确错误。

**Gate 2：** 故意移除 ledger 时旧签章仍可用；故意移除 HMAC/证书时对应能力稳定 fail-closed。

### Phase 3：修正 source SHA 语义

1. 新增前向 migration，删除 SHA 全局唯一约束并改普通索引。
2. inspect 改为按用户和有效状态复用或新建 source。
3. SHA 已出现在其他 document/legacy file 时允许上传并写审计。
4. confirm 继续使用报告编号约束业务身份。
5. 补失败、过期、取消后的重传测试。

**Gate 3：** 同一 PDF 可作为不同业务意图再次上传；相同业务请求仍保持幂等；同一报告编号冲突仍被拒绝。

### Phase 4：收缩手写领域模型和 UI

1. 删除 homepage-seal 专属路径。
2. acts 改为通用 N-slot 签名行为。
3. 前端提供模板、添加、删除、改名、换人、排序、拖动和缩放。
4. 服务端验证序号、用户、页面和矩形边界。
5. field name 完全由服务端生成。
6. prepare 一次性创建本次全部字段。
7. 签署页按分配用户显示当前可签任务。

**Gate 4：** 1、3、5、20 个签名位均可准备；首签后无法改变计划；后续只填既有字段。

### Phase 5：完整增量签名纵向验证

1. 使用测试 CA、组织证书和本地 RFC 3161 TSA 完成自动化集成测试。
2. 完成至少三人顺序签署。
3. 每次核对前缀保持、ByteRange、字段锁、DocMDP、CMS 和 timestamp。
4. 模拟 Java 超时、响应丢失、私钥前失败、私钥后结果未知和结果文件损坏。
5. 确认没有盲目重签。

**Gate 5：** 自动化、Acrobat、Foxit 和独立验证器对全部历史签名结论一致。

### Phase 6：Chrome 产品验收

分别验收两个入口，不以单元测试代替真实页面：

- 旧 PDF 签章全部控件和下载；
- 新手写页面分页预览；
- 添加/删除/排序签名位；
- 拖动、缩放和页码切换；
- 冻结后不可编辑；
- 不同账号只能看到自己的任务；
- 后端错误码显示准确中文；
- operation stage 和重试状态可理解；
- 页面上不存在 workflow UUID 输入和 deferred 首页章。

**Gate 6：** 两套页面在导航、权限、文案和业务结果上完全可区分。

---

## 十、测试矩阵

### 10.1 Laravel

- 原 `/api/pdf/signing/*` contract tests；
- HMAC 配置缺失 fail-fast；
- capability-specific health；
- operation after-commit dispatch；
- outbox 漏投递恢复；
- SHA 重传和跨 document 重用；
- 报告编号冲突；
- N-slot workflow 创建和 prepare；
- 顺序任务激活；
- 取消、拒绝和私钥边界；
- source 过期清理；
- legacy row 与 revision row 双读。

### 10.2 Frontend

- 两个导航入口分别按权限显示；
- 旧签章页面回归；
- 通用签名位 CRUD、排序和坐标编辑；
- PDF 预览和多页定位；
- 冻结状态；
- axios 后端错误码中文映射；
- operation polling/stage 展示；
- 无 homepage-seal/UUID 输入。

### 10.3 Java

- legacy process contract；
- legacy 拒绝已签输入和策略覆盖；
- HMAC 正向、篡改、重放、过期和未知 key；
- capability readiness；
- prepare N fields；
- certification + approval 增量链；
- RFC 3161 嵌入与 trust allowlist；
- execution ledger CAS；
- 私钥前后故障分类；
- result 完整性和重复读取；
- 历史签名全量验证。

### 10.4 文档阅读器

每个候选 exact SHA 至少验证：

- Acrobat；
- Foxit；
- Java 自有验证器；
- 独立 PAdES/CMS 验证器。

记录每个签名的 subject、时间戳、ByteRange、DocMDP/FieldMDP、修改允许性和最终结论。

---

## 十一、预期修改范围

### Backend

- `app/Http/Controllers/Pdf/PdfHandwrittenSigningController.php`
- `app/Services/Pdf/PdfSourceService.php`
- `app/Services/Pdf/PdfWorkflowService.php`
- `app/Services/Pdf/PdfWorkflowControlOperationService.php`
- `app/Services/Pdf/PdfSigningOperationService.php`
- `app/Services/Pdf/PdfRuntimeInspector.php`
- `app/Services/Pdf/PdfRendererClient.php`
- `app/Http/Controllers/System/PdfServiceHealthController.php`
- `app/Models/PdfSigningAct.php`
- `database/migrations/<forward-migration>.php`
- `routes/api.php`
- `routes/console.php`
- 对应 Feature/Unit tests

### Frontend

- `src/features/pdf/PdfHandwrittenSigningPage.tsx`
- `src/features/pdf/PdfPlacementWorkspace.tsx`
- `src/features/pdf/handwrittenApi.ts`
- `src/features/pdf/PdfSigningPage.tsx`（只允许回归修复）
- `src/features/system/utils.ts`
- `src/lib/zh.ts`
- navigation、route permission 和对应 tests

### Java

- capability health/readiness classes
- legacy `/api/pdf/process` contract tests
- incremental signing/verification tests
- HMAC configuration validation
- `run-local.sh` 和 README

---

## 十二、提交与发布策略

建议按以下顺序拆分提交：

1. `fix: dispatch PDF operations after commit`
2. `fix: fail fast on incomplete PDF runtime configuration`
3. `fix: surface PDF backend error codes`
4. `test: lock legacy PDF signing contract`
5. `refactor: split PDF runtime capability readiness`
6. `fix: allow repeated PDF source bytes`
7. `refactor: generalize handwritten signing slots`
8. `refactor: remove deferred homepage seal from handwritten V1`
9. `test: verify incremental handwritten signature chain`
10. `docs: record separated PDF signing acceptance`

每个提交只暂存任务文件，保留无关 untracked 文件。合并前执行：

- backend full tests；
- frontend unit tests、lint、typecheck、production build；
- Java full tests；
- `git diff --check`；
- 真实 Chrome 双页面验收；
- exact-head PDF 阅读器证据。

远程 migration 和数据清理必须单独授权、先只读取证、再执行可恢复变更。测试通过、migration dry-run 或本地 mock 都不等于生产发布完成。

---

## 十三、完成定义

只有同时满足以下条件，方案才算完成：

- 旧 `/pdf/signing` 可独立完成原有签章流程；
- 新 `/pdf/handwritten-signing` 可独立完成 N 个位置的顺序手写签名；
- 两套页面、权限、API、数据状态和 readiness 清晰分离；
- 同一 SHA 不再永久锁死后续业务上传；
- 新流程不包含首页章预留、late-seal workflow 或 UUID 手输；
- 旧流程没有虚假 TSA/PAdES-B-T 声明；
- 新流程实际嵌入并验证 RFC 3161 时间戳；
- 所有签名操作在私钥边界后没有盲目重试；
- backend/frontend/Java 全量测试通过；
- Chrome、Acrobat、Foxit 和独立验证器证据绑定 exact commit SHA；
- 状态文档记录 commit、migration、环境、证书/TSA 测试身份、风险和未交付项。

---

## 十四、Cross-flow V2 预留方向（不进入本轮实施）

当业务确认手写签完后必须追加首页章时，另立 Cross-flow V2 方案：

1. 对 Cross-flow V2 上线后新建的手写文档，在首签前可选预建首页章字段；
2. 手写 V1 已完成且未预建字段的历史文档不承诺可追加；
3. 独立页面列出“已发布、字段已预留、字段为空、没有 active operation”的文档；
4. 用户选择报告和印章素材，不输入 UUID；
5. 只填充预建字段并做增量组织证书签名；
6. 不调用 legacy `/api/pdf/process`；
7. 重新验证全部历史签名后生成新 revision；
8. 单独完成 Acrobat、Foxit 和独立验证器兼容矩阵。

Cross-flow V2 是两套独立产品之间的受控组合能力，不改变 V1 的独立边界。
