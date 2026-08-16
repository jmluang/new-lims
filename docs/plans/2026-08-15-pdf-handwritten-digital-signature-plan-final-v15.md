# PDF 手写可视签名与增量数字签名 Lean V1 实施方案

**版本：** v15 Final Lean（在 v14 基础上补齐证据删除仲裁、可撤销退休暂存及 result 同一文件身份读取合同）

**状态：** **v15 静态方案复审 GO，No Findings。** Phase S 可立即实施；Phase 0A 通过后进入 Phase 1；Phase 0B 通过后进入三人顺序签署与 deferred 首页章。Gate S、Gate 0、真实 Chrome、Acrobat、Foxit、独立 PAdES 验证和故障注入仍是实现与发布证据门禁，不代表这些运行时证据已经取得。

**仓库基线：** `main @ 786e3f83940ed695ee83c25914d1d7b6f36aed4e`

**目标页面：** `/pdf/handwritten-signing`

**首发模式：** 组织证书签名；操作用户通过登录会话、任务授权和当前密码再认证确认签署。三个 CMS 的证书主体均为组织，不冒充三份个人证书签名。

---

## 一、最终执行结论

采用三层架构，但按纵向切片实施，不先建设完整“签名平台底座”。

```text
Browser (React + pdf.js)
  └─ finalized PDF preview / placement / handwriting
       ↓ authenticated business API
Laravel
  └─ document / workflow / request / challenge / operation / revision
       ↓ loopback + HMAC internal API
Java PDF service
  └─ inspect / finalize / prepare / incremental PAdES-B-T / verify
```

### 1.1 首发必须交付

1. 只接收**本系统新生成、尚未数字签名的 PDF**进入新流程。
2. 最终成文后重新 inspection，用户才能规划签名位置。
3. 主检、审核、签发三个顺序任务，各产生一个组织证书 PAdES-B-T CMS。
4. 一个签署行为可以对应一个或多个 widget；是否开放跨页 multi-widget 由 Gate 0B 决定。
5. 首签前预建全部本期需要的字段；后续只填已有字段。
6. 首发预建一个 deferred 首页章字段；后续以新 workflow 填写同一字段。
7. 每次成功签署产生不可变 revision，`ready` 与 `published` 分离。
8. 挑战绑定精确 revision、字段计划、规范化笔迹和不可变签名策略。
9. 单 Java signer 实例；签名 POST 一旦开始发送，禁止自动重发。
10. 响应丢失时查询 Java execution 状态；请求登记和 execution 分别受不可变 deadline 约束，deadline 前持续有界轮询，不立即转人工复核。
11. 私钥调用后的“明确失败”与“结果未知”必须分开：前者稳定失败，后者才进入人工复核；二者都禁止盲目重签。
12. workflow 取消/拒绝必须先与 Java 在同一 `operation → execution` 锁序上仲裁；私钥边界已经开始时不能清 active pointer 或伪装取消成功。
13. Java `completed` 只表示密码学执行已终态；result bytes 仍必须处于 `available` 且重新核对路径、SHA 和大小后才能被 Laravel 采用。缺失或损坏不得触发重签。
14. 公开验证区分“服务器登记状态”和“用户上传文件的实际字节验证”。
15. 旧 `/api/pdf/process` 立即完成 loopback、HMAC、默认密码和策略收口。

### 1.2 明确延后，不进入 Lean V1

以下能力保留为目标架构方向，但不得阻塞首发纵向链路：

- 外部已签 PDF 的导入与继续签署；
- recovery upload、候选集消歧、相同字节多 document 策略；
- identity registry、alias、历史身份归并和双人 adjudication；
- 功能章、骑缝章的完整产品化；
- PAdES-B-LT、B-LTA、DSS/VRI、DocTimeStamp 和 validation evidence bundle；
- Java 多实例、HSM/远程签名、多故障域自动恢复；
- S3 execution result ledger、跨实例 winner protocol；
- 产品化双人 quarantine 登记/销毁流程；
- 个人 CA、UKey、CSC 或远程个人签名；
- 任意第三方 PDF 的完整 forensic 兼容承诺。

### 1.3 不可降级原则

1. **签名按签署行为建模，不按坐标建模。** 一个行为对应一个字段、一个签名字典和一个 CMS。
2. **最终成文后才规划。** 声明页、二维码、元数据和其他页面内容先完成，再重新获取几何。
3. **首签前预建字段。** 已签文件上不动态创建受控签名字段。
4. **每次签署形成不可变 revision。** revision UUID 永远映射同一字节和 SHA-256。
5. **文件与数据库不宣称原子。** 采用事务 A、staging、验证、同盘晋升、事务 B 和 reconciler。
6. **组织证书主体与操作人分离。** 手写姓名不是数字证书 subject。
7. **挑战绑定实际 appearance。** challenge 后不能替换笔迹 PNG。
8. **签名 POST 不盲目重试。** 只有账本证明尚未越过私钥边界时，才允许同 operation 的受控重试；私钥后的明确失败稳定终止，结果未知才人工处置。
9. **取消也必须经过不可逆边界仲裁。** 任何 controller、定时器或管理员命令都不能绕过 operation/execution 锁直接清除 active workflow 或 request。
10. **execution 终态与 result bytes 完整性分离。** `completed` 永不回退，也不能因结果文件缺失而再次调用私钥；结果缺失/损坏走完整性事件和人工复核。
11. **公开版本只由发布指针决定。** 不能按最后一个 `ready` revision 推导。
12. **真实阅读器证据不可省略。** 单元测试全绿不能替代 Acrobat、Foxit 和独立验证器。

---

## 二、Lean V1 产品边界

### 2.1 输入边界

V1 只允许：

```text
本系统生成的未签 PDF
  → 上传一次
  → 安全 inspection
  → 用户确认报告编号
  → 创建逻辑 document 和 immutable source
```

V1 拒绝：

- 已包含数字签名的 PDF；
- 加密、损坏或无法安全解析的 PDF；
- 与现有 document 报告编号冲突的 PDF；
- 已登记相同 SHA-256、但不是当前 document 正常重试来源的 PDF；
- 需要导入第三方历史签名、动态补字段或改变已签页面内容的 PDF。

拒绝不是功能缺陷，而是首发范围控制。外部已签输入进入 Phase 4。

### 2.2 三人顺序流程

```text
prepared revision
  → 主检 request：CMS #1
  → 审核 request：CMS #2
  → 签发 request：CMS #3
  → published revision
```

规则：

- 后继任务只在前驱成功并完成全量验证后进入 `available`；
- 每位用户只能签署分配给自己的 request；
- 主检是 prepared revision 上的第一个且唯一 certification signature，固定 DocMDP `P=2`；审核、签发和 deferred 首页章均为 approval signature，不得再次写 `/Perms/DocMDP`；
- 每个签名字段的 `/Lock`/FieldMDP 只锁当前字段自身，禁止 `All` 或包含任何后继/首页章字段；这样 P=2 允许后续填写预建字段，同时已签字段不能被改写；
- 每次签署前显示当前 revision SHA-256、页数、目标字段和证书主体；
- 每次都进行当前密码再认证，不宣称 MFA；
- 三个 CMS 均使用组织证书，但审计记录三位实际操作用户。

### 2.3 deferred 首页章

首个 workflow 在第一个数字签名前预建首页章字段，但不创建当前签署 request：

```text
first workflow
  → approval fields: actionable
  → homepage-seal field: deferred
  → prepare all fields once
  → complete three approval requests
  → publish while homepage-seal field remains empty

later seal workflow
  → base = current published revision
  → no-write bind existing homepage-seal field
  → create one seal request
  → fill existing field incrementally
  → verify all historical signatures
  → publish new revision
```

V1 只产品化一个 deferred 首页章。功能章和骑缝章可以在数据枚举中预留类型，但不进入首发 UI、验收和迁移范围。

### 2.4 multi-widget 降级策略

Gate 0B 验证一个 field 多 widget 的阅读器兼容性：

- 通过：V1 允许同一签署行为多个位置，只产生一个 CMS；
- 未通过：V1 限制为一个签署行为一个 widget；
- 禁止以普通 annotation `/AP` 作为“受控兼容”兜底；
- 降级只减少外观位置，不改变签名、修订和权限合同。

---

## 三、当前仓库基线与迁移原则

当前仓库已有 PDF 上传、Java PDFBox/Bouncy Castle 签名、私有文件存储、摘要台账、公开验证和审计能力，但仍有以下旧约束：

- `pdf_files.file_id` 直接承载业务文件编号；
- 下载和审计仍依赖 `file_id`；
- 旧公开验证主要按最终 SHA/MD5 台账判断；
- `/api/pdf/process` 可触发私钥，并允许调用方提交策略参数；
- Java 上传上限与 Laravel 不一致；
- 当前只有默认组织 PFX，没有个人密钥闭环。

迁移原则：

1. 新 revision 使用 `revision_uuid`，旧 `file_id` 不改写。
2. 旧文件默认只读、可下载、可验证，但**不直接进入新签署 workflow**。
3. V1 不做历史 document 身份归并；重复报告编号不自动合并。
4. 新流程永不调用旧 `/api/pdf/process`。
5. Phase S 安全措施永不因业务回滚而撤销。

---

## 四、不可变来源与最终成文

### 4.1 `pdf_source_uploads`

V1 使用简单 immutable source，不建设 candidate intake/resolve 平台。

建议字段：

- `id`, `source_uuid UNIQUE`；
- nullable `document_id`；
- `stored_path`, `sha256`, `file_size`, `page_count`；
- `inspection_manifest`, `inspection_manifest_hash`；
- `created_by_id`, `expires_at`, `consumed_at`, `deleted_at`；
- `status`: `uploaded` / `inspected` / `bound` / `consumed` / `expired` / `quarantined`。

流程：

1. 浏览器上传一次，Laravel 流式落受限临时目录并计算 SHA-256；
2. Java inspection 验证结构、加密状态、数字签名、页数和几何；
3. 已签或不可安全解析的输入立即拒绝；
4. 用户确认报告编号；服务端使用 versioned normalizer 执行 Unicode NFKC、去除首尾 Unicode whitespace、ASCII 字母大写，拒绝 control、空值和超过 128 code points 的值；内部字符不折叠；
5. `organization_scope` 只能由服务端从当前组织上下文派生，浏览器不得提交或覆盖；Laravel 以 `(organization_scope, normalized_report_number)` 唯一键在同一事务创建 `pdf_documents` 并绑定 source；
6. document 同时保存用户确认的展示值和 normalized value；绑定后不可改 document、报告编号 identity、路径、SHA 或 inspection manifest；finalizer、二维码和公开 resolver 只读取该冻结 identity；
7. 未绑定 source 到期清理，已绑定 source 按审计保留策略清理字节但永久保留摘要和 manifest。

首发不提供 duplicate-create。发现相同报告编号或已登记相同 SHA 时，返回稳定冲突，要求用户进入已有 document 或联系管理员。任何报告编号更正必须取消未开始的流程并重新确认 source；不得在已有修订链上就地改 identity。

### 4.2 `finalized_unsigned` planning revision

顺序固定为：

```text
immutable source
  → unsigned finalization
  → finalized_unsigned revision
  → re-inspect final geometry
  → placement planning
  → workflow freeze
  → prepare fields only
```

unsigned finalization 可以执行：

- 声明页合并；
- 逻辑 document 二维码；
- 受控元数据；
- 已确认的光度或图像处理；
- 固定模板与字体渲染。

`finalization_manifest` 至少冻结：

- source UUID/SHA；
- 报告编号；
- 模板、字体、图片和资源摘要；
- 声明页位置；
- 二维码目标；
- 元数据与图像处理策略；
- 产出 revision UUID/SHA；
- finalizer version。

finalization 后必须重新 inspection。placement 只能绑定该 revision 的 SHA 和 geometry hash。prepare 阶段严禁再增加页面、二维码、元数据或改变页面几何。

---

## 五、Lean V1 持久化模型

正式流程全部持久化。UI 未冻结草稿可以使用 Cache；冻结后不得依赖 Cache 作为事实来源。

### 5.1 `pdf_documents`

建议字段：

- `id`, `document_uuid`, `document_public_id`；
- `authoritative_report_number`, `normalized_report_number`；
- nullable `active_workflow_id`, nullable `active_operation_id`, nullable `published_revision_id`；
- `publication_version BIGINT UNSIGNED DEFAULT 0`, `integrity_version BIGINT UNSIGNED DEFAULT 0`；
- `integrity_hold_mask BIGINT UNSIGNED NOT NULL DEFAULT 0`：bit 0=`published_revision_integrity`、bit 1=`signature_validation`、bit 2=`storage_recovery`、bit 3=`publication_manual_review`、bit 4=`administrator`；`integrity_state` 为 generated/checked projection：mask=0 时 `ok`，否则 `hold`；nullable `integrity_hold_started_at`, nullable `integrity_hold_released_at`；
- `next_revision_number`；
- `status`: `draft` / `signing` / `issued` / `revoked`；
- `created_by_id`, timestamps。

约束：

- `document_public_id` 为高熵随机值，不使用报告编号；
- `(organization_scope, normalized_report_number)` 唯一；
- `active_workflow_id` 与 document-scope `active_operation_id` 不能同时非空；
- 同一 document 同时最多一个非终态 workflow；
- `integrity_state=hold` 时禁止下载、签署和发布指针推进；
- 每次 integrity hold bit 增减、revision unavailable/restored 都在同一事务递增 `integrity_version` 并写 publication event；每个结案只能清自己拥有的 bit，其他并存原因保留，只有 mask 归零才能回到 `ok`。document-wide evidence hold 是保留义务，不自动中断公开下载，但它的安装/释放同样递增 `integrity_version` 并写独立审计，以立即失效旧 result retirement authorization。任何文档级 integrity/evidence hold 必须先按 5.15 锁定所有仍有 result/appearance bytes 或 retirement intent 的相关 operation/execution，再锁 document并统一阻止/撤销 retirement；
- 公众当前版本只读取 `published_revision_id`；
- 发布事务必须 CAS `publication_version` 和原 `published_revision_id`。

#### 5.1.1 `pdf_document_publication_events`

- `event_uuid`, `document_id`, `revision_id`；
- `event_type`: `published` / `superseded` / `revoked` / `integrity_withdrawn` / `integrity_restored` / `integrity_hold_added` / `integrity_hold_released`；
- `reason_code`, nullable `actor_user_id`, `occurred_at`, `audit_context_hash`；
- nullable `previous_published_revision_id`, nullable `related_event_id`。

只有曾产生 `published` event 的 revision 才能进入公开 exact-revision API。发现 published 文件缺失或哈希异常时，事务将 document 置 integrity hold、revision 置 unavailable 并写 `integrity_withdrawn`；从可信备份恢复**同一 UUID、路径合同和 SHA**并完成全量重验后，才可写 `integrity_restored`。业务撤销使用独立 `revoked` event，不能把暂时存储故障冒充法律撤销。

### 5.2 扩展 `pdf_files` 为 revisions

保留原表和原 `file_id`，新增：

- `document_id`, `revision_uuid`；
- nullable `parent_pdf_file_id`；
- `revision_number`；
- `revision_role`: `finalized_unsigned` / `prepared` / `approval_signature` / `organization_seal` / `legacy_signed_output`；
- `revision_created_at`, nullable `signed_at`；
- `file_path`, `sha256_hash`, nullable `md5_hash`, `file_size`；
- `revision_manifest`, `revision_manifest_hash`；
- `integrity_state`: `ready` / `quarantined` / `unavailable`；
- `disposition`: `active` / `abandoned` / `superseded` / `published`；
- nullable `first_published_at`。

规则：

- 选择 materialized-only revision：文件晋升并验证成功后才插入行；
- `revision_uuid → file_path + sha256_hash` 永久不可变；
- `ready` 只表示字节完整；公开还必须为 `published` 且被 document 指针引用；
- finalized/prepared 的 `signed_at=null`；
- 新 revision 的 legacy-compatible `file_id=REV-<revision_uuid>`；
- 下载前必须重新核对文件存在和 SHA-256。

### 5.3 `pdf_signing_workflows`

建议字段：

- `id`, `workflow_uuid`, `document_id`, `workflow_generation`；
- `base_revision_id`, `planning_revision_id`, nullable `prepared_revision_id`, `current_revision_id`；
- `publication_base_revision_id`, `expected_publication_version`；
- `placement_plan`, `placement_plan_hash`, `field_plan_hash`；
- nullable `active_operation_id`；
- `status`: `draft` / `preparing` / `ready` / `signing` / `completed` / `rejected` / `cancelled` / `failed` / `manual_review`；
- `created_by_id`, timestamps。

规则：

- workflow 激活时锁定 document 并占用 `active_workflow_id`；
- 同一 workflow 同时最多一个 active operation；
- `current_revision_id` 只通过事务 B 推进；
- 最终发布必须确认 document 发布基线和版本未变化；
- 失败、取消或人工复核时不得自动开放后继任务。

### 5.4 `pdf_signing_acts`

保留一个轻量 document-level act 表，避免复杂 binding 层：

- `id`, `logical_act_uuid`, `document_id`, `plan_generation`；
- `semantic_role`: `inspector` / `reviewer` / `issuer` / `homepage_seal`；
- `pdf_signature_role`: `certification_p2` / `approval`；首个 inspector 固定为 certification_p2，其余固定为 approval；
- `sequence`, `field_name`；
- `status`: `planned` / `deferred` / `completed` / `permanently_skipped` / `cancelled`；
- nullable `completed_revision_id`, timestamps。

规则：

- 当前三个人的 act 在首个 workflow 中 actionable；
- 首页章 act 在 prepare 成功后为 `deferred`；
- deferred 不是 skipped，后续可以激活；
- 只有成功事务 B 才能将 act 置 `completed`；
- 业务重规划生成新的 plan generation，不修改旧 act 历史。

### 5.5 `pdf_signing_requests`

- `id`, `request_uuid`, `workflow_id`, `signing_act_id`；
- `sequence`, nullable `predecessor_request_id`；
- `request_type`: `handwritten` / `homepage_seal`；
- `assigned_user_id`, `signing_policy_version_id`；
- `status`: `pending` / `available` / `signing` / `signed` / `rejected` / `failed` / `cancelled` / `manual_review`；
- nullable `expected_source_revision_id`, `expected_source_sha256`；
- nullable `completed_revision_id`, timestamps。

规则：

- prepared-deferred act 不创建 request；
- 第一个 request 在 prepare 完成后进入 `available`；
- 后继 request 只在前驱事务 B 中绑定新 revision 后激活；
- request 进入 `available` 后，expected source 和 policy 不可修改；
- 同一 act 在同一 workflow 只能有一个 request。

### 5.6 `pdf_signing_fields`

- `id`, `field_uuid`, `workflow_id`, `signing_act_id`；
- nullable `request_id`；
- nullable `source_field_id`：late-seal no-write mapping 指向首签前的 prepared field；
- `field_name`, `field_type`；
- `activation_mode`: `current` / `deferred`；
- `binding_mode`: `created_before_first_signature` / `rebound_existing`；
- `lock_policy`：V1 固定 `include_self_only`；prepare 生成 `/Lock /Action /Include /Fields [current fully-qualified field name]`，禁止 All/Exclude 和引用其他字段；
- nullable `prepared_revision_id`, nullable `prepared_object_ref`；
- `status`: `planned` / `prepared` / `signed` / `cancelled`。

受控约束：

- actionable act 恰好一个 request 和一个 field；
- deferred act 有 field/slot，但 `request_id=null`；
- later-seal workflow 新建 mapping row，引用原 field，不修改 PDF 结构；
- 一个成功签署只给目标 field 写一次 `/V`。

### 5.7 `pdf_signing_slots`

- `id`, `slot_uuid`, `field_id`；
- `page_index`, `widget_index`, `placement_type`；
- `normalized_rect`, `geometry_hash`；
- nullable `prepared_widget_object_ref`, nullable `prepared_appearance_object_refs`；
- `status`: `planned` / `prepared` / `rendered` / `cancelled`。

坐标使用六位小数字符串，不使用 JSON 浮点数参与 hash。

### 5.8 `pdf_signature_appearance_artifacts`

- `id`, `appearance_uuid`, `request_id`, `created_by_id`；
- `artifact_type`: `handwriting` / `homepage_seal`；
- `canonical_image_sha256`, `appearance_manifest_hash`；
- `slot_manifest`, `width`, `height`, `crop_box`, `renderer_version`；
- `state`: `available` / `claimed` / `consumed` / `quarantined` / `expired`；
- `evidence_hold_mask BIGINT UNSIGNED NOT NULL DEFAULT 0`：bit 0=`manual_review`、bit 1=`irreversible_failure`、bit 2=`quarantine`、bit 3=`retirement_integrity`、bit 4=`legal_hold`；`evidence_hold_state` 为 generated/checked projection：mask=0 时 `none`，否则 `active`；
- `retirement_state`: `none` / `stage_intent` / `staged` / `purge_intent` / `retired`，`retirement_epoch BIGINT UNSIGNED NOT NULL DEFAULT 0`，`lock_version BIGINT UNSIGNED NOT NULL DEFAULT 0`；
- nullable `claimed_by_operation_id`, `retention_until`, `legal_hold_until`, `hold_started_at`, `hold_released_at`；
- nullable `retirement_staged_path`, `retirement_staged_at`, `retirement_purge_not_before`, `deleted_at`, `file_path`, timestamps。

规范化流程：

```text
raw image
  → MIME/pixel validation
  → decode
  → normalize color/alpha/orientation
  → crop + fixed padding
  → canonical PNG
  → immutable artifact + manifest hash
```

challenge 创建后只接受 `appearance_uuid`，不再接收替换图片。canonical PNG 原子落盘并校验后设为只读，后续禁止 in-place write；预览、retirement stage、hold restore 和 purge 只能在 artifact 行锁下使用同一 descriptor或原子 rename/unlink。

创建 challenge 和事务 A claim appearance 都必须锁 artifact，并要求 `state=available`、`evidence_hold_state=none`、`retirement_state=none`、`deleted_at IS NULL` 且 canonical hash/size 正确；challenge 创建成功后，任何 sweeper 都把该未消费 challenge 视为活跃引用。

保留与清理合同：

1. 新建且从未 claim 的 appearance 写 `retention_until=created_at+24h`；每次创建有效 challenge 可延长到至少 challenge expiry。普通成功或可证明发生在私钥前的终态失败只把 `retention_until` 重算为 `terminal_at+24h`，不得清除任何既有 hold bit；
2. `failed_after_private_key_known`、`outcome_unknown/manual_review` 或关联 quarantine 产物必须在同一终态事务以 bitwise OR 增加自己拥有的 hold bit并写 hold start；结案前不得自动复用或删除；
3. 人工结案事务只能 bitwise clear 本次 resolution 明确拥有的 reason bit；其他 bit 保留。`hold_started_at` 记录 mask 从 0 变非零的时刻，单个 reason 的增删写 append-only audit；只有 mask 变为 0 才写最终 `hold_released_at` 并从 release 时刻重算至少 24 小时保留期；任何处置失败都不得清 bit；
4. sweeper 只有在 `evidence_hold_state=none`、`retention_until IS NOT NULL AND retention_until<=now()`、`legal_hold_until IS NULL OR legal_hold_until<=now()`，且不存在指向该 artifact 的未过期/未消费 challenge、非终态 operation、manual review 或 quarantine 引用时，才能通过 5.15 的统一仲裁把文件暂存到同盘 retirement quarantine；不得在资格检查后直接 unlink；
5. 暂存分成两个数据库可恢复阶段：先在持锁事务中递增 `retirement_epoch`、冻结 intended staged path/固定 hash并提交 `stage_intent`，不移动文件；再由 exact epoch worker 重新持锁、复核无 hold 后执行同盘原子 rename和目录 `fsync`，提交 `staged` 与至少 24 小时的 `retirement_purge_not_before`。崩溃后由 `stage_intent`、canonical/staged 两个固定路径和 hash 唯一决定完成或撤销；
6. 宽限期内新增 hold 必须赢得相同锁：`stage_intent` 下 canonical 尚在时直接撤销，若 rename 已发生但 staged 尚未提交则从 exact staged path 恢复；`staged` 或尚未 unlink 的 `purge_intent` 同样把 staged 文件原子恢复到 canonical path并提交 `retirement_state=none`；
7. 宽限期结束后先在持锁事务提交 `purge_intent`，仍不删除；exact epoch purger 再次持锁复核 staged path/hash 和全部 hold 后，才执行最终 unlink、目录 `fsync`并提交 `retired`。hold 先赢则恢复并令 purger 退出；purger 先赢并完成 unlink 后，迟到 hold 返回稳定 `409 EVIDENCE_ALREADY_RETIRED`，不得伪装保全成功；
8. 删除成功后写 `deleted_at/state=expired, retirement_state=retired`；`purge_intent` 下 staged 文件仍在表示可重试或可被 hold 恢复，文件已不存在表示只能补交 retired；无法唯一判断时进入人工复核，不能把文件缺失误报为正常过期；
9. 长期审计保留 appearance hash、尺寸、规范化版本、operation 归属和成品/隔离 PDF，不能因隐私清理提前破坏人工复核证据。

### 5.9 `pdf_signing_challenges`

- `challenge_uuid`, `request_id`, `user_id`；
- `source_revision_id`, `source_sha256`；
- `field_plan_hash`, `appearance_artifact_id`, `appearance_manifest_hash`, `intent`；
- `signing_policy_version_id`, `policy_hash`, `expected_certificate_fingerprint`；
- `auth_context_id`, `password_changed_at_snapshot`, `reauthenticated_at`；
- `expires_at`, `consumed_at`, `cancelled_at`。

规则：

- 当前密码只用于即时 `Hash::check`，不落库、不进日志；
- challenge 有效期 5 分钟且一次性；
- 只能由创建 challenge 的同一 Sanctum token 使用；
- 密码变化、账户禁用、token revoke、任务归属变化立即使其无效；
- `field_plan_hash` 必须覆盖 act `pdf_signature_role`、field fully-qualified name、include-self-only lock policy、全部 slot 几何和 prepared object refs；
- operation 的 `challenge_id` 唯一，防止并发重复消费。

### 5.10 `pdf_signing_policy_versions`

V1 只需组织 PAdES-B-T policy：

- `id`, `version_uuid`, `policy_hash`, `immutable_at`；
- `pades_profile=B-T`；
- digest/signature algorithm OID；
- organization certificate/chain fingerprints；
- signing material version、key locator；
- TSA URL set、policy OID、timeout；
- TSA/signing trust bundle hash；
- revocation policy；
- reserved size；
- `pre_private_key_max_attempts`：V1 固定为 3；
- `pre_private_key_retry_backoff_seconds`：版本化有界序列，例如 `[2, 5]`；
- `pre_private_key_retryable_error_codes`：只允许可证明发生在私钥前的瞬时基础设施错误；输入、权限、policy、摘要和结构校验错误必须为不可重试；
- `java_execution_registration_timeout_seconds`：从签名 POST body 开始发送到 execution row 应可见的最大时间，覆盖 HMAC、body 接收、manifest 校验和 ledger claim；
- `java_execution_timeout_seconds`：从 execution `claimed→executing` 起覆盖 preflight、私钥、TSA 和结果落盘的硬 deadline；
- `java_status_poll_policy`：初始 2 秒、指数退避、单次最大 10 秒；
- `java_result_min_bytes_per_second` 与 `java_result_read_timeout_seconds`：Gate 0A 按 loopback 压测冻结，且必须满足 `timeout >= ceil(generated_revision_max_bytes/min_bytes_per_second)+30s`，共同约束同一 descriptor result GET；
- `source_max_bytes=20 MiB`；`generated_revision_max_bytes` 和每次 `max_signature_increment_bytes` 由 Gate 0A 使用 20 MiB 边界 source、真实 finalization/field plan、四次真实证书链/TSA 增量测量后冻结，并至少保留 20% headroom。任一 projected/current budget 超限都在私钥前 fail closed；
- `retirement_authorization_ttl_seconds`：V1 固定为 300 秒；`evidence_retirement_grace_seconds`：V1 固定为 86400 秒；
- `policy_manifest`：上述非秘密配置的 versioned JCS 文档；
- `config_bundle_hash`：`policy_manifest` 的 SHA-256。

任何轮换创建新 version，活动 version 不就地修改；`immutable_at` 非空后禁止 UPDATE/DELETE，只能新增版本。Java 通过只读权限按 `signing_policy_version_id` 读取该 immutable policy row，重新计算 `policy_hash/config_bundle_hash` 并与 operation direct snapshot 完全比对，再从该 row 取得算法、key locator、TSA 和 deadline；Laravel→Java 请求只携带 policy ID/UUID 与 hashes，不得携带可覆盖服务端 policy 的算法、TSA URL、证书路径或别名。policy row 缺失、hash 不一致或 Java 不支持该 version 时 fail closed，且发生在私钥边界前。

`java_execution_registration_timeout_seconds` 必须大于内部 HMAC body receive deadline 并留安全余量；Laravel 在开始发送 body 时一次性计算 registration deadline。`java_execution_timeout_seconds` 必须覆盖 preflight、私钥、TSA 和结果持久化并留安全余量；`execution_deadline_at` 由 Java 在每次 `claimed → executing` 成功时根据该版本一次性计算，覆盖整次 attempt，worker 和人工任务不得自行延长或在进入私钥边界时重新起算。

### 5.11 `pdf_signing_operations`

- `operation_uuid`, `idempotency_key`, `idempotency_scope_key`；
- `scope_type`: `document` / `workflow` / `request`；
- `actor_user_id`, `document_id`；
- nullable `workflow_id`, nullable `request_id`, nullable `challenge_id`；
- `action`: `unsigned_finalize` / `prepare_fields` / `fill_signature_field` / `bind_deferred_field`；
- `input_fingerprint`, `operation_input_manifest_hash`；
- nullable `expected_source_revision_id`, nullable `expected_source_sha256`；
- nullable `signing_policy_version_id`, nullable `policy_hash`, nullable `config_bundle_hash`, nullable `expected_certificate_fingerprint`, nullable `appearance_manifest_hash`, nullable `pdf_signature_role`, nullable `field_lock_policy_hash`；`fill_signature_field` 全部必填，非签署 action 按 action-specific rule 禁止无关字段；
- nullable `result_revision_uuid`, nullable `result_revision_id`；revision-producing action 必填，DB-only action 必须为空；
- `state`: `claimed` / `processing` / `promoted` / `completed` / `failed` / `irreversible_failed` / `manual_review` / `cancelled`；
- `stage`: `awaiting_dispatch` / `db_only` / `java_call` / `java_polling` / `staging` / `verifying` / `promoting` / `committing` / `done`；
- `lease_owner`, `lease_epoch`, `lease_expires_at`, `heartbeat_at`, `java_gate_version BIGINT UNSIGNED NOT NULL DEFAULT 0`；
- nullable `java_request_started_at`, nullable `java_execution_registration_deadline_at`, nullable `java_execution_state`, nullable `java_execution_deadline_at`, nullable `next_java_poll_at`, `java_poll_count UNSIGNED NOT NULL DEFAULT 0`；
- nullable `promoted_file_path`, `result_sha256`, `result_size`；
- nullable `response_fingerprint`, nullable `error_code`；
- nullable `error_retryability`: `same_operation_pre_key` / `new_generation_only` / `none`；
- nullable `cancellation_requested_at`, nullable `cancelled_at`, nullable `cancellation_reason_code`, nullable `cancelled_by_id`；
- nullable `result_retirement_not_before`, nullable `result_retirement_authorized_at`, nullable `result_retirement_authorization_expires_at`, nullable `result_retirement_authorization_manifest`, nullable `result_retirement_authorization_hash`；manifest 为 RFC 8785 JCS，包含下文全部 snapshot；这些字段只能由 Laravel retention projector 写入/撤销；
- immutable `audit_context`, `audit_context_hash`, timestamps。

幂等规则：

- 同 key、同 fingerprint 返回原状态或结果；
- 同 key、不同 fingerprint 返回 409；
- completed 不重新检查已消费 challenge；
- `irreversible_failed` 对同 fingerprint 稳定返回原错误，禁止在同 workflow/operation 重试；技术重做必须从可信父 revision 创建新 generation；
- `cancelled` 对同 fingerprint 稳定返回取消结果，不允许恢复或重新 dispatch 原 operation；
- `processing/java_polling` 必须返回 202 和同一 status URL，不能被 UI 解释为人工复核；
- manual review 不允许自动创建新签名 operation；只有 7.6.2 的管理员裁决可受控恢复**同一 operation**，且不得再次调用私钥；
- 每个 workflow 同时只能持有一个非终态 operation；workflow/request 的取消或拒绝只能通过 5.14 的仲裁服务清 active pointer，禁止 controller 直接更新。

### 5.12 `pdf_operation_outbox`

- `operation_id UNIQUE`, `job_type`, `payload_hash`；
- `state`: `pending` / `dispatched` / `cancelled`；
- `available_at`, `dispatched_at`, `attempt_count`, `last_error`。

operation 和 outbox 必须在同一事务创建，避免 commit 后尚未投队列的丢单窗口。dispatcher 只投递 `state=pending` 且 operation 仍为 `claimed/processing` 的行；对候选 ID 的处理固定先锁 operation、后锁 outbox，禁止 outbox→operation 的反向锁序。取消仲裁在同一事务按 operation→execution→scope→outbox 顺序把未完成 outbox 置 `cancelled`。已经投递但尚未执行的 job 仍必须先读 operation，看到 cancelled 即无副作用退出。

### 5.13 `pdf_java_signing_executions`

V1 使用单实例、持久化本地结果，不建设多实例 S3 winner protocol。

- `operation_uuid UNIQUE`；
- `operation_input_manifest_hash`, `input_fingerprint`, `policy_hash`, `authorized_lease_epoch`；
- `state`: `claimed` / `executing` / `completed` / `failed_before_private_key` / `failed_after_private_key_known` / `outcome_unknown`；
- `attempt_count UNSIGNED NOT NULL DEFAULT 0`, `max_attempts UNSIGNED NOT NULL`, `lock_version UNSIGNED NOT NULL DEFAULT 0`, nullable `retryability`: `same_operation` / `none`, nullable `next_retry_at`, nullable `retry_exhausted_at`；
- nullable `execution_started_at`, `private_key_started_at`, `execution_deadline_at`, `completed_at`, `terminal_at`；
- nullable `result_path`, `result_sha256`, `result_size`, `validation_report_hash`；V1 path 固定为 persistent bind mount 下 `java-results/{operation_uuid}/result.pdf`，不使用容器 writable layer 或 `/tmp`；
- `result_integrity_state`: `not_applicable` / `available` / `retiring` / `missing` / `breached` / `retired`；
- `retirement_epoch BIGINT UNSIGNED NOT NULL DEFAULT 0`，`retirement_phase`: `none` / `stage_intent` / `staged` / `purge_intent` / `retired`；
- `evidence_hold_mask BIGINT UNSIGNED NOT NULL DEFAULT 0`：与 appearance 使用相同 bit registry；`evidence_hold_state` 为 mask 的 generated/checked projection；nullable `result_last_verified_at`, nullable `result_integrity_error_code`, nullable `retention_until`, nullable `legal_hold_until`；
- nullable `retirement_staged_path`, nullable `retirement_started_at`, nullable `retirement_purge_not_before`, nullable `bytes_deleted_at`；
- nullable `error_code`, timestamps。

合同：

所有 Java execution 状态、result integrity 和 retirement phase CAS 都必须校验读取时的 exact `lock_version`、`input_fingerprint` 和 `policy_hash`，成功时原子递增 `lock_version`；影响行数不是 1 即退出。每次 attempt 的开始、失败、重试授权、私钥边界、终态、result integrity 变化和 retirement intent/apply 都在**同一数据库事务**写 append-only 审计事件，事件 INSERT 失败必须回滚对应状态 CAS；至少记录 attempt number、old/new state/phase、old/new lock version、authorized lease/retirement epoch、错误码和时间，避免单行更新覆盖历史证据。

每个不可逆签名 POST 的 HMAC metadata 必须包含 `operation_uuid`、current `lease_epoch`、`operation_input_manifest_hash`、`input_fingerprint` 和 `policy_hash`。Java 的所有安全关键事务都必须先对 `pdf_signing_operations` 执行唯一的 **gate CAS**：只更新专用 `java_gate_version=java_gate_version+1`，并在 `WHERE` 中校验读取到的旧 gate version及该用途所需的 direct immutable snapshot。gate 谓词按用途固定，禁止复用宽松谓词授权私钥：

- `SIGN_AUTHORIZE`（首次 claim、pre-key retry、写 `private_key_started_at`）：要求 `state=processing`、`stage IN (java_call,java_polling)`、`action=fill_signature_field`、current lease 有效，且全部 manifest/input/policy/config/certificate/appearance hashes 精确匹配；
- `RESULT_READ_OR_INTEGRITY`：要求 execution 已 `completed`，operation UUID/action/input snapshot 匹配，operation 可处于 `processing/promoted/manual_review/completed`；该 gate 只能读取或推进 result integrity，绝不能创建 attempt、重置 execution 或写私钥边界；GET 必须按第 9 条在锁内打开并验证一次，提交后继续使用同一个已打开 descriptor，不能重新按路径打开；
- `RESULT_RETIRE_STAGE_INTENT`：要求 operation.state=`completed`、execution.state=`completed`、result integrity=`available`，并核对 Laravel 写入的未撤销、未过期 retirement authorization hash/not-before 与 result metadata 完全匹配、`evidence_hold_state=none` 且无有效 legal hold；Java 不直接读取 revision/document 表；
- `RESULT_RETIRE_STAGE_APPLY`：只接受 operation.state=`completed`、execution.state=`completed`、result integrity=`retiring`、`retirement_phase=stage_intent`，核对 exact epoch/intended path/hash、`evidence_hold_state=none` 且无有效 legal hold，用于第二事务的 rename，不重新依赖可能已经过期的 stage authorization；
- `RESULT_RETIRE_PURGE_INTENT`：只接受 operation.state=`completed`、execution.state=`completed`、result integrity=`retiring`、`retirement_phase=staged`，核对 exact epoch/path/hash、宽限期已过、`evidence_hold_state=none` 且无有效 legal hold，只提交 purge intent，不删除；
- `RESULT_RETIRE_PURGE_APPLY`：只接受 operation.state=`completed`、execution.state=`completed`、result integrity=`retiring`、`retirement_phase=purge_intent`，再次核对 exact epoch/path/file identity/hash、`evidence_hold_state=none` 且无有效 legal hold，才允许最终 unlink。四类 retirement gate 必须使用不同 service/test vector，禁止用 intent gate直接执行文件动作。

该 UPDATE 在事务中取得 operation 行排他锁；CAS 成功后再锁 `pdf_java_signing_executions`，固定锁序仍为 `operation → execution`。Java **不读取 workflow/request 行，也不自行判断 active pointer**；Laravel 的集中 domain service 保证任何取消、拒绝或 pointer 清理都必须先锁同一 operation 行并通过 5.14 终止 operation。对 `SIGN_AUTHORIZE` 而言，operation 已进入 failed/irreversible_failed/manual_review/completed/cancelled 或 lease 已被接管时，gate CAS 影响行数为 0，Java 必须在私钥前拒绝；因此延迟到达的 stale-lease POST 不能在取消或人工复核之后才创建 execution 或跨越私钥边界。

1. Java 在私钥调用前按 `operation_uuid` 加锁；同 operation 已 completed 时，只有 `result_integrity_state=available` 且路径、SHA、大小复核一致才返回同一结果；`retiring/missing/breached/retired` 均不得再次签名。终态输入 fingerprint/policy 不一致返回 409；
2. 新 execution 以 `state=claimed, attempt_count=0, max_attempts=policy.pre_private_key_max_attempts, authorized_lease_epoch=request.lease_epoch, result_integrity_state=not_applicable` 创建；
3. 每次实际执行由同时校验 immutable input/policy 与当前 `lock_version` 的唯一 CAS `claimed → executing` 赢得，并原子令 `attempt_count=attempt_count+1, lock_version=lock_version+1`，写 `execution_started_at=now()` 与 `execution_deadline_at=execution_started_at+policy.java_execution_timeout_seconds`；`executing` 此时仍可能尚未越过私钥边界，但已经拥有不可变 deadline；
4. 私钥前失败只有在 `private_key_started_at IS NULL` 时，才可 CAS `executing → failed_before_private_key`。错误码命中 immutable `pre_private_key_retryable_error_codes` 时写 `retryability=same_operation` 和按策略计算的 `next_retry_at`；输入、权限、policy、摘要、结构、取消或其他确定性错误写 `retryability=none, next_retry_at=NULL`，并由 Laravel 稳定终止 operation，不进行无意义重试。若 worker 在 `executing` 但尚未写私钥边界时崩溃，恢复器只有在持久证据确认 `private_key_started_at IS NULL`、无 CMS/TSA/result 字节且旧 lease 已失效后，才能执行同一 CAS；
5. 受控重试必须使用唯一 CAS：

```sql
UPDATE pdf_java_signing_executions
SET state='claimed', authorized_lease_epoch=?, next_retry_at=NULL, retry_exhausted_at=NULL, error_code=NULL, lock_version=lock_version+1
WHERE operation_uuid=?
  AND input_fingerprint=?
  AND policy_hash=?
  AND state='failed_before_private_key'
  AND retryability='same_operation'
  AND lock_version=?
  AND private_key_started_at IS NULL
  AND attempt_count < max_attempts
  AND next_retry_at <= CURRENT_TIMESTAMP;
```

影响行数必须为 1；调用方必须使用读取到的 exact `lock_version`。执行该 CAS 前必须先以 operation `java_gate_version` 的条件 UPDATE 赢得 gate、确认新的 current lease、operation state 和 direct immutable snapshot，再把该 lease 写入 `authorized_lease_epoch`，随后再次竞争 `claimed → executing`。这允许同 operation 的显式 pre-key 重试，但不允许 HTTP client 对不确定传输做自动 retry；多个 worker 只能一个成功；
6. `retryability=none` 或 `attempt_count >= max_attempts` 时，execution 保持 `failed_before_private_key`，并在耗尽时写 `retry_exhausted_at`；Laravel 按 5.14/7.6 的集中事务将 operation 置 `failed`、清 active operation、request 恢复 `available`，原 challenge 已消费、appearance 进入 quarantined，用户需创建新的 appearance/challenge/operation。确定性错误必须返回稳定错误码，不能通过新 operation 绕过相同输入/policy 校验；
7. 真正调用私钥前，Java 必须再次以 `SIGN_AUTHORIZE` gate CAS 校验 Laravel operation 仍是同一 `processing`、`stage IN (java_call,java_polling)`、current lease 等于 `authorized_lease_epoch`，随后锁 execution，并以 CAS 在 `state=executing AND private_key_started_at IS NULL AND now()<execution_deadline_at` 条件下写 `private_key_started_at`；operation gate 与 execution CAS/event INSERT 位于同一事务。只有该事务成功后才能调用私钥/TSA，且不得重置或延长 attempt deadline。Java 不读取 workflow/request 表；取消仲裁若先赢，operation 已为 `cancelled`，gate CAS 必须失败；Java 边界若先赢，取消事务随后必须拒绝；
8. 成功结果先写持久化 bind volume 的 execution-scoped 临时文件，执行 file `fsync`、同盘原子 rename、parent directory `fsync`，随后重新打开并流式核对 SHA/size；只有读回一致时，才在同一 CAS 写 result metadata、`result_integrity_state=available` 并将 execution `executing → completed`；
9. completed result 每次供 Laravel 取回前都必须先赢得 `RESULT_READ_OR_INTEGRITY` gate CAS，再锁 execution。V1 为避免另建 read-lease 实体，使用**同一打开文件身份读取**：Java 在持有 operation→execution 行锁的事务中，以只读、拒绝 symlink 的方式打开 canonical result 一次，记录 device/inode/file-key 与 size，使用该 descriptor 完成 SHA/size 全量校验并 rewind；只有验证成功才写 `result_last_verified_at` 并提交事务。事务提交后继续持有并仅从**同一个 descriptor**向 loopback Laravel 流式响应，结束或中断后关闭；不得“校验后关闭，再按路径重开”。result 文件完成原子 rename 后立即设为只读，Java 后续只允许在持锁事务中 rename/unlink，禁止 in-place write；因此 retirement/restore 即使在事务提交后发生，也只改变目录项，不会改变已打开 descriptor 指向的已验证字节。响应仍受 policy 总 deadline/最低吞吐约束以限制 descriptor 占用。仅在 `result_integrity_state=available` 时允许读取；文件不存在置 `missing`，file identity、SHA 或 size 不一致置 `breached`，提交该完整性状态后返回无 body 错误。execution 仍保持 `completed`，以永久阻止重复私钥调用；两种状态都禁止重新签名，并触发第 7.7 节的下游副本恢复、人工复核或完整性处置；所有状态变化写入现有 append-only execution audit；
10. Java execution 完成事务先写 `retention_until >= execution.completed_at + 7 days`，因为此时 Laravel operation 可能尚未完成；事务 B 将 operation 置 completed 时，再把 retention 原子延长到 `max(existing_retention_until, operation.completed_at + 7 days)`。manual review、quarantine、调查或 legal hold 中以 bitwise OR 增加各自 evidence hold bit，法律时限另写/延长 `legal_hold_until`；每个 bit 只能由对应结案事务清除，不能因时间经过自动消失。Java 无 revision/document 读取权限，因此**不能自行判断正式 revision 是否可替代 execution result**：Laravel `AuthorizeJavaResultRetirementJob` 必须按 operation→execution→document→revision 锁序，重新打开正式 revision、核对 UUID/SHA/签名和当前 `integrity_version`，确认 operation completed、保留期已到、hold mask=0、无有效 legal hold且无非终态引用后，才在 operation 写一次性且仅在 policy `retirement_authorization_ttl_seconds` 内有效的 JCS authorization manifest、not-before/authorized-at/expiration及 manifest hash。manifest 覆盖 operation UUID、execution result SHA/size、正式 revision UUID/SHA、document integrity version、not-before 和 expiration；后续 publication pointer 正常前进不影响旧 immutable revision 的替代证据资格。retirement 不暴露 HTTP endpoint，因此这里没有第二份 HMAC 请求来源；Java scheduled sweeper 只能从 operation 白名单读取由 Laravel 账号写入、Java 账号只读的 manifest/hash，重算 JCS hash、校验时效与 execution result metadata 后才能启动 stage。任何新增 legal/integrity/manual-review hold 或相关 integrity version 变化都必须按同一锁序清空 manifest/hash/时间字段；

    Java result sweeper 是 Java 内部 scheduled worker，不暴露新的 HTTP retirement endpoint；Laravel 只负责持久化短时 authorization manifest。sweeper 使用四阶段协议。阶段一以 `RESULT_RETIRE_STAGE_INTENT` gate 和相同锁序核对未过期授权、execution metadata及当前 hold，在纯数据库事务中递增 `retirement_epoch`、冻结 epoch-scoped staged path/固定 hash/purge-not-before并提交 `result_integrity_state=retiring, retirement_phase=stage_intent`，此时不移动文件。阶段二由 exact epoch worker 以 `RESULT_RETIRE_STAGE_APPLY` gate 再次锁 operation→execution，复核 phase/hold/canonical path/hash，执行同盘原子 rename 与两侧目录 `fsync`，提交 `retirement_phase=staged`；崩溃时 `stage_intent + canonical/staged 两路径 + hash` 足以唯一完成或撤销，不需要隐含文件 journal。宽限期内新增 hold 取得相同锁：canonical 尚在的 stage_intent 直接撤销；rename 已发生的 stage_intent、staged 或文件仍在的 purge_intent 原子恢复同一字节到 canonical path，再递增 epoch、清 retirement authorization并提交 `retiring→available, phase=none`，同时 OR 请求对应的 hold bit。若 exact staged bytes 仍存在但恢复 canonical path 失败，事务仍须清授权并 OR `retirement_integrity` 及请求对应的 hold bits，保留当前 retirement phase、禁止 purge并进入 manual review；结案不得清 retirement-integrity bit，直到 exact bytes 已恢复且 phase=none。只有 staged/canonical 都不存在、即最终 unlink 已发生时才不得把请求 hold bit写成成功。

    宽限期结束后，阶段三以 `RESULT_RETIRE_PURGE_INTENT` gate 在纯数据库事务提交 exact epoch 的 `retirement_phase=purge_intent`，不删除文件；该 gate 只校验 staged phase/path/hash、宽限期和当前 hold，不再要求已经过期的 stage authorization。阶段四由 exact epoch purger 以 `RESULT_RETIRE_PURGE_APPLY` gate 再次持有 operation→execution 锁，复核 staged 文件 identity/hash 和全部 hold 后，才执行最终 unlink、目录 `fsync` 并提交 `retiring→retired, retirement_phase=retired, bytes_deleted_at`。hold 先取得锁且文件仍在则恢复并使 purger 失败；purger 先取得锁并完成 unlink 后，迟到 hold 必须看到 retired 或 `purge_intent + staged missing`，补交 retired并返回 `409 EVIDENCE_ALREADY_RETIRED`。`purge_intent` 下 staged 文件仍在表示可安全重试或恢复；文件已不存在表示只能补交 retired；其他组合进入人工复核。由此数据库锁顺序定义唯一赢家，但不宣称文件系统与数据库原子；
11. 已越过私钥边界且结果**明确不可发布**时，以 CAS `executing → failed_after_private_key_known`。至少包括：CMS 已生成但 TSA 明确拒绝或已有确定性失败证据、`/Contents` 预留不足、签名结果结构或对象差异验证明确失败。TSA timeout、连接中断或响应归属不明不能归入该状态，必须继续恢复并在无法确定时进入 `outcome_unknown`。该状态是稳定终态，不得在同 operation 或同 lineage 自动重试；
12. `executing` 且 `now < execution_deadline_at` 是正常运行状态：status 返回 202，Laravel 按 policy 有界轮询并维持 operation lease/heartbeat，不得立即转 manual review；
13. 到达 deadline 后，Java 启动恢复先检查 `private_key_started_at`、持久 temp/result 与验证证据：若 `private_key_started_at IS NULL`，只能 CAS `executing → failed_before_private_key` 并按剩余次数进入 retry/exhausted；若已越过私钥边界，可证明完成则按第 8 条 durable contract CAS completed，可证明明确失败则 CAS `failed_after_private_key_known`，仍无法判断私钥/TSA outcome 时才 CAS `executing → outcome_unknown`；
14. 若 completed/known-failure 已先写入，deadline recovery 的 CAS 必须失败并采用已有终态；若 outcome_unknown 先赢，迟到结果只能进入 Java quarantine；
15. 签名 POST body 开始发送后，Laravel HTTP client 禁止自动 transport retry。只有第 5 条账本 CAS 明确授权的 pre-key retry，Laravel 才能以同 operation、新 HMAC nonce显式发送下一次请求；
16. `failed_after_private_key_known` 投影为 Laravel operation=`irreversible_failed`、request/workflow=`failed`；`outcome_unknown` 才投影为 operation/request/workflow=`manual_review`；completed 但 result `missing/breached` 在正式 revision 尚未物化时也投影为 manual review；这些状态均不产生新的 ready revision或私钥调用；
17. completed result 至少保留到 Laravel operation 完成后 7 天；manual-review result/temp 与 appearance 均受 hold，结案后再按审计策略清理；
18. V1 部署固定 Java replicas=1；result root 必须是容器生命周期外的 Linux persistent bind volume，只有 Java 服务账号拥有目录管理权限，completed result 文件本身只读，Laravel 容器不得挂载或直接读取该目录，Java 的所有 result path rename/restore/purge 均先取得本文 operation→execution 锁且禁止 in-place write；启动 health 执行 create/fsync/rename/read-back、打开 descriptor 后 rename/unlink仍可读取同一 inode，以及 stable file-key 探针，任一失败时签名 readiness fail closed；增加多实例或非 POSIX 存储前必须升级到后续完整执行账本/读取租约方案。

#### 5.13.1 `pdf_java_signing_execution_events`

为落实 append-only attempt 审计，增加轻量事件表：

- `id`, `operation_uuid`, `attempt_number`, `event_type`；
- `old_state`, `new_state`, nullable `old_retirement_phase`, nullable `new_retirement_phase`, `old_lock_version`, `new_lock_version`, nullable `authorized_lease_epoch`, nullable `retirement_epoch`；
- nullable `error_code`, `event_at`, `event_hash`；
- unique `(operation_uuid, attempt_number, event_type, new_lock_version)`。

事件只记录历史，不参与授权；状态权威仍是 execution 行。Java 账号只允许 `INSERT`，不得 UPDATE/DELETE。

### 5.14 取消/拒绝与私钥边界仲裁

取消、拒绝和管理员终止不能直接更新 workflow/request 指针。所有已创建 operation 的后置状态推进采用统一锁序：

```text
pdf_signing_operations
  → nullable pdf_java_signing_executions
  → pdf_documents
  → pdf_signing_workflows
  → pdf_signing_requests
  → revisions/appearance as needed
  → pdf_operation_outbox
```

合同：

1. workflow 没有 active operation 时，取消/拒绝事务锁定 document/workflow/request 后直接进入对应终态，同时取消所有未消费 challenge；未被 operation claim 的 appearance 按普通未使用保留策略清理；
2. workflow 有 active operation 时，取消服务先 `SELECT ... FOR UPDATE` 锁 operation，再锁 execution（若已创建）。只有 operation 仍为 `claimed/processing`、execution 不为 `completed/failed_after_private_key_known/outcome_unknown`，且 `private_key_started_at IS NULL` 时，取消才可赢得仲裁；
3. 取消赢时，继续按固定顺序锁定 document/workflow/request、必要的 revision/appearance，最后锁 outbox；随后在**同一事务**完成：operation 置 `cancelled/stage=done`、写 cancellation metadata、递增 `lease_epoch` 并清 lease；execution 若为 `claimed|executing` 且 `private_key_started_at IS NULL`，CAS 到 `failed_before_private_key, retryability=none, error_code=WORKFLOW_CANCELLED_BEFORE_PRIVATE_KEY`，若已是 `failed_before_private_key`，则在 exact `lock_version` 条件下只把 `retryability` 置 `none`、清 `next_retry_at` 并写同一取消错误码；execution 更新与 append-only 取消事件必须同事务提交；清 active operation/workflow pointer并写 cancelled/rejected 状态和审计；未完成 outbox 置 `cancelled`；原 challenge 保持 consumed，已 claim appearance 置 quarantined，本次取消不新增 hold bit且不得清除其他既有 bit，`retention_until=cancelled_at+24h`。这样已排队的 pre-key retry、outbox job 和迟到 worker 都只能无副作用退出；
4. Java 若先持有 operation 锁并成功写入 `private_key_started_at`，随后取消事务必须返回 `409 SIGNING_IRREVERSIBLE_IN_PROGRESS`，不得清 active pointer、不得把 request/workflow 标为 cancelled，也不得影响 execution deadline/recovery；
5. 取消若先提交，迟到 Java 请求取得 operation 锁后看到 `state=cancelled`，必须在私钥前拒绝。由此取消和私钥边界只有一个赢家，不存在“UI 已取消但私钥随后启动”的状态；
6. `promoted/completed/irreversible_failed/manual_review` operation 不允许用普通取消接口改写；分别按事务 B、稳定失败或人工复核合同完成；
7. controller、scheduler 和 CLI 只能调用集中 `CancelPdfWorkflowService`/`RejectPdfRequestService`，禁止直接清 `active_operation_id` 或 `active_workflow_id`。并发与故障测试必须覆盖取消先赢、Java 先赢、取消在 execution 尚未创建时进入、取消与 pre-key retry CAS 竞争。

### 5.15 evidence hold 与不可逆删除仲裁

result 与 appearance 的自动清理必须复用一个 `EvidenceRetirementArbiter` 协议，不能由各自 sweeper 先查后删。它不是跨进程共享代码：Laravel 实现 appearance/hold/authorization，Java 实现 result 文件动作；双方共享同一状态表、锁序、phase/epoch 语义和故障测试向量。统一合同：

1. 所有 hold 安装、hold 释放、retirement stage、retirement restore 和最终 purge 都先锁 owning operation（若存在），再锁 nullable Java execution，随后按 document→workflow→request→revision→appearance/artifact 固定顺序取得所需行；未被 operation claim 的独立 appearance 直接锁 artifact 行，但一旦发现并发 claim 就回滚并改走完整锁序。文档级 hold 先快照全部相关 operation IDs，再按 ID 升序锁全部 operation及各自 execution，之后才锁 document/revisions/artifacts并重验快照，禁止 document→operation 反向锁；若无法在受控事务期限内锁全，整个 hold 安装失败并告警，不得分批写出“部分 active”状态。任何 legal/integrity/manual-review hold 安装都以 bitwise OR 增加自己的 reason bit，legal deadline 另写 `legal_hold_until`；deadline 到达不自动清 legal bit，必须由结案事务显式清除自己的 bit；
2. eligibility check 只授权**可撤销暂存**，不授权最终 unlink。stage 先以纯数据库事务递增 artifact/execution 的 `retirement_epoch`，冻结 epoch-scoped 同盘路径/hash并提交 `stage_intent`；旧 epoch worker 看到 epoch 不匹配只能退出；
3. exact epoch worker 在第二个持锁事务内完成 rename + directory `fsync` 并提交 `staged`。`stage_intent` 崩溃恢复只读取数据库意图、canonical/staged 两个固定路径和冻结 hash：canonical-only 可撤销或重试，staged-only 可补交 staged，二者都有且 hash 相同则保留 canonical并隔离 staged，其他组合进入人工复核；任何撤销、隔离或人工接管都递增 epoch，使旧 worker 永久失效；
4. `stage_intent/staged/purge_intent` 且最终 unlink 尚未发生时允许新 hold 撤销退休。撤销者持锁原子恢复同一 hash 字节、目录 `fsync`、递增 epoch并清授权；路径冲突、hash 不一致或恢复失败时，只要 exact staged/canonical bytes 仍存在，就必须 OR `retirement_integrity` 与请求对应的 reason bits、保留当前 phase并禁止 purge，再进入 manual review，绝不能因恢复失败丢掉 hold或覆盖文件；retirement-integrity bit 在 exact bytes 恢复且 phase 回到 none 前禁止清除；
5. 最终 purge 同样先以纯数据库事务提交 `purge_intent`，再由 exact epoch purger 在第二个持锁事务核对 canonical path 不存在、staged path/identity/hash、宽限期、所有 hold 和引用，然后执行 unlink + directory `fsync`并提交 retired/expired。`purge_intent + staged exists` 可重试或被 hold 恢复；`purge_intent + staged missing` 只能补交 retired；其他组合进入人工复核；
6. hold 与 purge 的数据库锁提交顺序定义唯一赢家：hold 先赢则 purge 无副作用退出；purge 先赢并完成 unlink 时，迟到 hold 返回稳定 `409 EVIDENCE_ALREADY_RETIRED` 并记录审计，不能写出一个实际没有证据字节的 active hold；合规策略若要求“hold 永不允许失败”，必须把对应对象放入禁止 purge 的外部 WORM/法务保全范围，不能依赖并发请求逆转已经完成的 unlink；
7. result GET 在 operation→execution 锁内打开并验证 descriptor 后即可提交并释放数据库锁，但必须保持同一只读 descriptor 到响应结束；stage/purge/restore 可以随后改变目录项，却不能 in-place 修改已打开 inode。appearance 预览/人工复核读取采用同样的“锁内打开验证、锁外保持同一只读 descriptor”合同；
8. crash recovery 必须覆盖 intent commit 前后、rename 前、rename 后/staged commit 前、restore 前后、purge intent 前后、unlink 后/retired commit 前。任何无法由 phase、epoch、两个路径及固定 hash 唯一判断的状态进入 manual review，禁止猜测删除或覆盖；
9. 并发验收至少覆盖 hold-first、stage-first 后撤销、purge-first、GET/preview-vs-stage/purge/restore、restore-vs-stale-purger、每个文件系统/数据库故障点，以及超时消费者关闭 descriptor且不泄漏资源。

appearance 的 hold/retirement 每次转换同样必须校验并递增 artifact `lock_version`，同时写现有系统 append-only audit；result 转换写 `pdf_java_signing_execution_events`。任何 event/audit 写入失败都回滚对应数据库状态，文件动作失败则保持 intent phase 由 reconciler 收敛。

### 5.16 MySQL 关键约束

- document/revision/workflow/act/request/field/slot/appearance/challenge/operation/policy UUID 均唯一；
- unique `(organization_scope, normalized_report_number)`；
- unique `(document_id, revision_number)`，`revision_uuid UNIQUE`；
- unique `(document_id, workflow_generation)`；
- document 的 active workflow/operation pointer 必须引用同一 document；
- unique `(document_id, plan_generation, semantic_role, sequence)` for acts；
- unique `(workflow_id, sequence)` 与 unique `(workflow_id, signing_act_id)` for requests；
- predecessor request 使用 `(predecessor_request_id, workflow_id)` 复合 FK，冻结时验证 sequence 单调增加；
- unique `(workflow_id, signing_act_id)` 与 unique `(workflow_id, field_name)` for fields；
- deferred field 允许 `request_id IS NULL`，current/rebound field 要求 request 非空；`rebound_existing` 必须具有 `source_field_id`，且 source field 与 mapping field 属于同一 document、同一 logical act，source field 已 prepared、目标 PDF object ref 完全一致；其他 binding mode 禁止 source field；
- unique `(field_id, widget_index)`；
- challenge UUID 唯一，成功 operation 的 `challenge_id UNIQUE`；
- challenge 创建和 operation claim appearance 都要求 artifact `retirement_state=none/deleted_at NULL`；appearance `stage_intent/staged/purge_intent` 必须具有非零 retirement epoch、固定 staged path/hash 和相应时间，`retired` 必须 `state=expired/deleted_at NOT NULL`，active evidence/legal hold 使 stage/purge CAS 失败；
- appearance/execution `evidence_hold_mask` 只允许已登记的 5 个 bit；`evidence_hold_state` 必须等价于 `mask=0 ? none : active`。安装使用 bitwise OR，释放使用受控 bitwise AND NOT 且只能清当前 resolution 拥有的 bit；legal bit 清除与 `legal_hold_until` 更新位于同一事务；
- document `integrity_hold_mask` 只允许已登记的 5 个 publication-integrity bit；`integrity_state` 必须等价于 `mask=0 ? ok : hold`。任何 bit 增减、revision unavailable/restored 或 document-wide evidence-hold set 变化都必须递增 `integrity_version`；结案只能清自己拥有的 integrity/evidence bit。evidence-only legal hold 不得误把公开 revision 标为损坏，publication-integrity bit 未清零时也不能提前恢复下载、签署或发布；
- operation unique `(idempotency_scope_key, idempotency_key)`；
- `fill_signature_field` operation 必须具有 policy/config/certificate/appearance direct snapshot，且其 hash 必须同时被 `operation_input_manifest_hash` 覆盖；Java 不得从可变 request/policy 关系临时补值；
- 每个 workflow 只有 sequence=1 inspector act 可为 `certification_p2`；该 operation 必须验证输入 revision 不含任何签名和 `/Perms/DocMDP`。其他 act 只能为 approval，必须验证已有唯一 DocMDP P=2 且不得创建/替换 `/Perms/DocMDP`；所有 field lock policy 固定 include-self-only并纳入 operation manifest；
- operation `java_gate_version` 只能由 Java 账号对该专用列执行条件 UPDATE；`SIGN_AUTHORIZE`、`RESULT_READ_OR_INTEGRITY` 和四个 `RESULT_RETIRE_*` gate predicate 必须使用互不复用的服务方法和测试向量，结果读取/退休 gate 永远不得授权创建 attempt 或写私钥边界；stage-intent gate 验证 Laravel authorization/expiration/result snapshot，stage-apply gate验证 exact retirement epoch/phase/path/hash和当前 hold，两个 purge gate再额外验证宽限期；后三者不得错误依赖已过期的 stage authorization；Java 不得读取 revision/document 自行推导；Laravel 取消/事务 B/人工处置和 evidence hold 锁定同一 operation 行，形成统一 operation→execution 仲裁；
- Java execution `operation_uuid UNIQUE`；状态转换只允许本文列出的 CAS；`failed_before_private_key` 必须满足 `private_key_started_at IS NULL`，`completed/failed_after_private_key_known/outcome_unknown` 必须满足 `private_key_started_at IS NOT NULL`，`attempt_count <= max_attempts`；`authorized_lease_epoch` 只能在 execution 创建或私钥前的受控 retry CAS 中更新；
- execution `completed` 必须具有 result metadata，且 `result_integrity_state` 只能为 `available/retiring/missing/breached/retired`；非 completed execution 必须为 `not_applicable`。`available/missing/breached` 要求 `retirement_phase=none`，`retiring` 要求 phase 为 `stage_intent/staged/purge_intent`，`retired` 要求 phase=`retired` 且为永久终态；任一 `evidence_hold_state=active` 或尚有效 legal hold 都使四类 retirement gate 失败，retirement-integrity hold 在 exact bytes 恢复且 phase=none 前禁止释放；`missing/breached` 只有受控管理员按 5.15 锁序从可信备份恢复同一 immutable result path、SHA、size并写独立完整性审计后才能 CAS 回 available；`retiring` 只能由 exact retirement epoch 的授权 sweeper暂存、撤销或终结；V1 不自动重建 execution result；
- operation=`cancelled` 必须 `stage=done`、具有 cancellation metadata、无有效 lease，且关联 outbox 不得保持 pending/dispatched 可执行状态；关联 execution 若存在，必须为 `failed_before_private_key`、`retryability=none`、`private_key_started_at IS NULL`。取消、拒绝、事务 B、manual review 投影和任何清 active pointer 的路径均遵守 operation→execution→scope rows 的集中锁序；
- Java 数据库账号只授予 `pdf_signing_operations` 白名单安全快照列的 `SELECT`，以及专用列 `java_gate_version` 的**列级 UPDATE**；Java 以 gate CAS 获取 operation 行锁，不依赖纯 SELECT 权限执行 `SELECT ... FOR UPDATE`。另授予 immutable `pdf_signing_policy_versions` 的 `SELECT`、`pdf_java_signing_executions` 的 `SELECT/INSERT/UPDATE` 和 `pdf_java_signing_execution_events` 的 `INSERT`；policy/event 表禁止 Java UPDATE/DELETE，不得读取或修改 workflow/request/document/revision/publication 表；
- appearance sweeper 必须同时满足 `evidence_hold_state=none`、retention、legal hold 和无非终态引用，并经过 5.15 的 staged→grace→purge 仲裁；禁止仅按创建时间删除或资格检查后直接 unlink；
- document/workflow active-operation CAS、revision insertion、request transition、取消/拒绝和 publication pointer update 只能通过集中 domain service 完成，不散落在 controller。

---

## 六、坐标与页面合同

前端只提交相对于 pdf.js 旋转后可视 `CropBox` 的规范化值：

```json
{
  "page_index": "2",
  "x": "0.327100",
  "y": "0.734200",
  "width": "0.148000",
  "height": "0.042000",
  "geometry_hash": "<64-char-lowercase-sha256>"
}
```

规则：

- `page_index` 为无前导零字符串；
- 坐标固定六位小数，拒绝科学计数、负零和超范围值；
- `x + width <= 1`，`y + height <= 1`；
- inspection 返回 MediaBox、有效 CropBox、rotation、UserUnit 和 pdf.js view；
- 唯一 `PlacementCoordinateMapper` 转换到 PDF 默认用户空间；
- CSS viewport 与 canvas DPR 分离；
- golden tests 覆盖 A4、Letter、横向、非零 CropBox、90/180/270 度、UserUnit、缩放和 DPR；
- 最终位置误差不超过 1 mm。

---

## 七、可恢复提交协议

### 7.1 事务入口

1. 按 scope + actor + idempotency key 查询 operation；
2. completed + 同 fingerprint：返回原结果；
3. claimed/processing/promoted + 同 fingerprint：返回 202 和原 status URL；
4. failed + 同 fingerprint：返回原错误；只有 Java 账本明确处于可重试的 `failed_before_private_key` 且同 operation CAS 成功时，才继续原 operation；
5. irreversible_failed + 同 fingerprint：稳定返回已知不可逆错误，不得进入事务 A；
6. fingerprint 冲突：409；
7. manual review：返回稳定人工处置状态；
8. cancelled：返回稳定取消结果，禁止重新 dispatch 原 operation；
9. 仅 operation 不存在时进入事务 A。

### 7.2 数据库事务 A

对 revision-producing action：

1. 锁定 document、workflow、request 和 current revision；
2. 验证 workflow 是 document 唯一 active workflow；
3. 验证没有其他 active operation；
4. 签署时核对 request=`available`、expected revision、challenge、appearance 和 policy；
5. 条件消费 challenge；锁 appearance 并再次验证 `state=available/evidence_hold_state=none/retirement_state=none/deleted_at IS NULL` 与 canonical hash/size 后 claim；
6. 创建 operation，预留 `result_revision_uuid`；
7. workflow 写 `active_operation_id`，request 进入 `signing`；
8. 同事务写 outbox；
9. 提交并返回 202。

`unsigned_finalize` 在 document 上使用相同 active-operation 互斥；`bind_deferred_field` 是 DB-only no-write operation。

### 7.3 worker fencing

- worker 领取 lease 时原子递增 `lease_epoch`；
- operation 创建后的所有状态推进、取消、事务 B 与人工处置都先锁 operation，再锁 nullable execution，随后才锁 document/workflow/request；禁止反向持锁后再等待 operation；
- staging：`staging/{operation_uuid}/{lease_epoch}/candidate.pdf`；
- final：`revisions/{result_revision_uuid}/{operation_uuid}/{lease_epoch}/document.pdf`；
- 验证、rename、fsync、promoted 和事务 B 前都校验 operation id、lease owner、epoch、state、stage；
- fence 丢失的 worker只能清理自己的 staging，不能推进状态；
- reconciler 只接管无 lease 或 lease 过期的 operation。

### 7.4 Java 签署调用

对 `fill_signature_field`：

1. Laravel 写 `java_request_started_at`，HTTP client 明确关闭自动 transport retry；
2. 首次向 Java 发送签名 POST 时携带 current lease epoch 与冻结 input/policy hashes，并在发送第一个 body byte 前写不可变 `java_execution_registration_deadline_at=now()+policy.java_execution_registration_timeout_seconds`。Java 在创建 execution 和进入私钥边界前只核对 Laravel operation 的 authoritative current state/stage/lease 与 immutable input/policy snapshot，不读取 workflow/request 表；取消或拒绝必须先通过 5.14 把 operation 终态化。若 HTTP client 能证明在发送任何 body byte 前失败，可重新执行本步骤；一旦 body 可能已发送而 Java execution 尚不可见，operation 保持 `processing/java_polling`，在 registration deadline 前只查询 execution，不重发 POST；超过 deadline 仍不可见且 delivery 无法证明时才进入 manual review；
3. registration deadline 前 execution 尚不可见属于受控观察状态：status 返回 202，Laravel 按 `java_status_poll_policy` 轮询并 heartbeat；execution 一旦可见即转入其 claimed/executing/terminal 状态处理。正常响应或 status 查询取得 completed result 后，只能通过第 5.13 条的同一 descriptor GET：Java 在锁内打开/验证后提交，再保持该 descriptor 流式返回；Laravel 同时写入当前 fence staging、计算自身 SHA/size并在完整响应结束后复核；任一侧 deadline、中断或摘要不符都删除当前 staging且不推进 operation。`missing/breached/retiring/retired` 不得进入 staging 或触发重签；
4. Java=`failed_before_private_key` 且未耗尽次数时，Laravel 等待 `next_retry_at`，由 reconciler/worker 竞争第 5.13 节的 CAS。只有 CAS 成功者可对**同 operation**显式发送下一次 POST；这是账本授权的业务重试，不是 HTTP 自动重试；
5. `failed_before_private_key` 已耗尽时，operation=`failed`，request 恢复 `available`，用户重新提交 appearance/challenge 后创建新 operation；
6. Java=`executing` 且 deadline 未到时，operation 保持 `processing/java_polling`，写 `next_java_poll_at/java_poll_count`；Laravel 按 2s 起步、最大 10s 的有界退避持续轮询并 heartbeat，不进入 manual review；
7. deadline 到达后只调用 Java recovery/status，不重新 POST。若 `private_key_started_at IS NULL`，Java 只能分类为 `failed_before_private_key` 并进入 retry/exhausted；若已越过私钥边界，才分类为 completed、`failed_after_private_key_known` 或 `outcome_unknown`；
8. `failed_after_private_key_known` → operation=`irreversible_failed`、request/workflow=`failed`，不产生 revision；经审计确认后如需重做，从可信父 revision 创建新 workflow generation；
9. `outcome_unknown` → operation/request/workflow=`manual_review`，保留 active workflow、appearance 和所有 execution 证据，禁止自动重签；
10. 任何状态查询都不得触发私钥、TSA 或新的 PDF writer。

### 7.5 文件验证与晋升

1. 仅从 `result_integrity_state=available`、由锁内打开/验证后持续持有的同一 descriptor 完整传输且 Laravel 已再次核对 SHA/size 的 Java result，或当前 worker 的确定性非签名输出写入 staging；
2. `fsync(staging_file)`；
3. 计算 SHA-256、大小和兼容期 MD5；
4. Java 与 Laravel 双重验证 PDF、签名、policy、证书和对象差异；
5. CAS 进入 `promoting`；
6. 同盘 rename 到不可变 final path；
7. `fsync(final_parent_directory)`；
8. operation 置 `promoted/committing`，记录路径、摘要和 manifest。

路径已存在、摘要不一致或 fence 丢失时，移动到：

```text
quarantine/{operation_uuid}/{lease_epoch}/
```

并在同一事务将 operation/request/workflow 投影为 `manual_review`、以 bitwise OR 给 appearance 增加 `manual_review/quarantine` hold bits并写 start。V1 不提供双人裁决 UI，由受控管理员脚本和审计记录处理；隔离文件永不公开或作为 workflow base。

### 7.6 数据库事务 B

1. 以当前 fence 按统一锁序锁定 operation、nullable Java execution、document、workflow、request 和父 revision；
2. 再次确认 workflow current revision 与 operation expected source 一致；
3. 核对 final 文件、SHA、大小和 manifest；
4. 锁定 document 分配 `revision_number`；
5. 插入 materialized `pdf_files(integrity=ready)`；
6. request 置 signed，act 置 completed，appearance 置 consumed；
7. workflow 推进 current revision，激活下一 request；
8. 最终 request 完成时，以 MySQL NULL-safe equality 校验 `published_revision_id <=> publication_base_revision_id` 并同时 CAS `expected_publication_version`，成功才发布、递增 version、workflow 置 completed并清 document `active_workflow_id`；
9. 非最终 request 保持 workflow signing；
10. 写 publication event 和关键审计；
11. operation completed，清 active operation；若存在 completed Java execution，同事务将其 retention 延长到 `max(existing, operation.completed_at+7d)`，但不在此时授权删除；
12. 提交。

任何 CAS 失败都不能覆盖新状态；已晋升文件转 quarantine，并在同一事务将 operation/request/workflow 进入 manual review、设置 appearance evidence hold。

#### 7.6.1 action-specific effects

| Action | 事务 B 或 DB-only 结果 |
|---|---|
| `unsigned_finalize` | 插入 finalized revision，source 置 consumed，清 document active operation |
| `prepare_fields` | 插入 prepared revision，workflow 置 ready/signing，首页章 act 置 deferred，第一个 request 置 available |
| `fill_signature_field` | 插入签名 revision，当前 request/act completed，激活下一 request；最终 request 才发布 |
| `bind_deferred_field` | 不生成 PDF；验证 current published、目标 act=deferred、字段为空且权限允许，创建 later-seal request/field mapping并置 available |

`bind_deferred_field` 必须是 no-write：Java 只允许 inspection，不允许调用 PDF save、创建字段、改变 widget/AP 或生成 revision。

领域状态推进：

| Event | Request | Workflow | Document |
|---|---|---|---|
| first workflow activated | first available，其余 pending | signing | active_workflow_id=W，status=signing |
| non-final request signed | current signed，next available | signing | 保持 |
| final request signed | signed | completed | 发布 pointer，清 active_workflow_id，status=issued |
| late-seal workflow activated | seal request available | signing | 保持 issued，active_workflow_id=W |
| pre-key retry scheduled | signing | signing | 保持 active workflow；同 operation 重试 |
| pre-key retries exhausted | available；需新 appearance/challenge | signing | 保持 active workflow |
| known failure after private key | failed | failed | 原子清 active workflow/active operation；旧 published 不变；operation irreversible_failed |
| outcome unknown | manual_review | manual_review | 保留 active workflow，禁止新 workflow |
| request rejected/cancelled，且无 active operation | rejected/cancelled | rejected/cancelled | 锁 scope 后清 active workflow；旧 published 不变 |
| request rejected/cancelled，active operation 尚未越过私钥边界 | 由 5.14 仲裁后 rejected/cancelled | rejected/cancelled | operation=`cancelled` 后原子清 active operation/workflow；旧 published 不变 |
| request rejected/cancelled，Java 已写私钥边界 | 保持 signing，返回 409 | 保持 signing | 不清任何 active pointer；等待 completed/known-failure/outcome-unknown 终态 |
| integrity hold | 不推进 | 暂停 | 禁止下载、签署和发布 |

#### 7.6.2 manual-review / irreversible-failure 最小处置合同

V1 不建设双人裁决产品，但必须提供受控 CLI/后台命令，用于 `manual_review`、`irreversible_failed`，以及 operation 已 completed 但 result/appearance 存在 `retirement_integrity` hold 的证据处置：

```text
php artisan pdf:review-operation <operation_uuid>
```

命令只能由具备 `pdf.manual_review.resolve` 的管理员执行，并写完整审计：

1. 查询并锁定 Laravel operation、Java execution、staging/final/quarantine 文件和 appearance hold；
2. 若 Java execution=completed、`result_integrity_state=available` 且 result SHA/size/validation 全部匹配，管理事务记录 `resolution=adopt_completed`，CAS operation `manual_review→processing`、stage=`java_polling`，清旧 lease并写专用 `ResumePdfOperationFromJavaResult` outbox；后续 worker 领取新 lease/epoch，只允许 GET 同一 Java result 并继续 staging/事务 B，禁止进入签名 POST 分支。appearance/result 的 manual-review/quarantine bits 保持到事务 B 成功后才清；legal/其他 bit 不受影响；
3. 若 Java execution=completed 但 result 为 `missing/breached`，不得把 execution 改为 outcome unknown，也不得再次调用私钥。若当前 operation 已有经过 SHA/size/manifest 全量验证的 Laravel staging 或 promoted 文件，可记录 append-only `execution_result_integrity` 事件并继续使用该下游不可变副本完成事务 B；若没有可信下游副本且正式 revision 尚未物化，operation/request/workflow 保持 manual review并设置 appearance hold，管理员只能从可信备份恢复**同一 result bytes/hash/path contract**后重新验收，或裁决为无可用结果并走新 generation；若正式 revision 已经 ready（无论 active 或 published）且自身复核通过，只记录 execution-result integrity 事件，业务 operation 保持 completed；正式 revision 也失败时才触发 document integrity hold；
4. 若 completed result 为 `retiring` 且 evidence hold mask 包含 `retirement_integrity`，管理员只能按 5.15 检查 exact epoch、canonical/staged path 和冻结 hash：字节仍在时恢复 canonical、递增 epoch并 CAS 为 `available/phase=none`，但保留其他 hold bits；原 operation 为 manual_review 时随后回到第 2 条，原 operation 已 completed 且正式 revision 仍验证通过时只记录恢复审计、保持 completed并按结案策略仅清本次已解决的 retirement-integrity bit。两个路径都不存在且 `purge_intent` 已证明 unlink 完成时补交 retired并拒绝 hold/adopt；其他组合保持原业务状态并进入 evidence manual review。禁止直接从 retirement quarantine 发布；
5. 若 Java execution=`failed_after_private_key_known`，核对稳定错误证据、确认不存在 promoted/ready 可用结果，投影并保持 operation=`irreversible_failed`、workflow/request=`failed`，清 active operation/workflow；不得伪装为 outcome unknown、completed 或同 operation 可重试；
6. 若 manual review 最终能证明未调用私钥、没有 CMS/TSA/result 字节且没有 promoted 文件，CAS operation `manual_review→failed`，清 active operation，将原 request 恢复 `available`、workflow 恢复 `signing`；原 challenge 已消费且原 appearance 结案后释放/隔离，用户必须创建新 appearance、challenge 和 operation，但可以继续同一 workflow，无需新 generation。若 Java execution 先前已经记为 `outcome_unknown`，该行保持历史终态不重分类，管理员 resolution event 明确记录“后续证据证明未越过边界”；新 operation 使用新的 UUID，不能复用旧 execution；
7. 若 Java state=`outcome_unknown` 且仍无法证明结果归属，继续保持 manual review。只有双人或受控管理员裁决形成 `confirmed_no_usable_result`，并确认不存在可采用的 completed/promoted/ready 结果后，才可把 operation 投影为 `irreversible_failed`、request/workflow=`failed`、清 active operation/workflow；后续重做必须从可信父 revision 创建新 generation，绝不能按第 6 条恢复同一 request；
8. terminal 结案事务只能清除 appearance/result 上由该结案拥有的 hold bits；result retirement-integrity bit 只有在 exact bytes 已恢复且 `retirement_phase=none` 时才能清除。采用 completed result 时由成功事务 B 清 manual-review/quarantine bits，但 legal/其他并存 bits 必须保留；只有 mask 变为 0 才写 `hold_released_at` 和新的 `retention_until`。sweeper 在所有 bit 清空前不得删除；
9. 所有 manual-review 状态迁移均要求管理员 resolution fingerprint 和完整审计；任何处置都不得把 quarantine 文件直接改名为 published revision。

### 7.7 reconciler

| 状态 | 恢复动作 |
|---|---|
| claimed + no lease | 仅当 outbox=pending 且 operation 非 cancelled 时重投；cancelled outbox 永不投递 |
| processing + valid lease | 跳过 |
| processing before Java request + expired lease | 接管同 operation |
| Java execution 不存在 + 可证明 body 未发送 | 接管同 operation并发送首次 POST |
| Java execution 不存在 + body 可能已发送 + registration deadline 前 | operation=`processing/java_polling`，只查询 execution 和 heartbeat，不重发 POST |
| Java execution 不存在 + registration deadline 已过 + delivery unknown | operation/request/workflow manual_review，设置 appearance hold；禁止猜测未到达后重发 |
| Java `executing` + `private_key_started_at IS NULL` + 旧 lease 失效 | 仅在无 CMS/TSA/result 证据时 CAS 为 failed_before_private_key，再按重试合同处理 |
| Java `failed_before_private_key` + retryable | 到 `next_retry_at` 后竞争唯一 CAS；成功者显式重试同 operation |
| Java `failed_before_private_key` + exhausted | operation failed，request available；新 appearance/challenge/operation |
| Java `executing` + before deadline | operation=`processing/java_polling`，按 `next_java_poll_at` 继续 status polling 和 heartbeat，不进 manual review |
| Java `executing` + deadline reached | 触发 Java recovery；未进入私钥边界则转 failed_before_private_key，已进入边界才分类 completed / failed_after_private_key_known / outcome_unknown；绝不盲目重发 POST |
| Java completed + result `available` | 重新核对 path/SHA/size 后取回同一 result，继续 staging |
| Java completed + result `missing/breached`，存在 verified staging/promoted 副本 | 记录 append-only execution-result integrity 事件，继续从该不可变副本验证/晋升；禁止重签 |
| Java completed + result `missing/breached`，无可信下游副本且业务 revision 尚未物化 | operation/request/workflow manual_review，设置 appearance hold；禁止重签，只允许同一结果恢复或人工裁决 |
| Java completed + result `missing/breached`，正式 revision 已 ready（active/published）且复核通过 | operation 保持 completed，记录 append-only execution-result integrity 事件；只有正式 revision 自身失败才触发 document integrity hold |
| Java completed + result `retiring` | 只允许 exact retirement epoch 的授权 sweeper或 hold 撤销者处理；宽限期内 hold 可恢复为 available，宽限期后按 5.15 仲裁最终 purge；不进入签署恢复、不触发重签 |
| Java completed + result `retired` | 仅允许发生在 operation completed 且正式 revision 已验证、保留期届满后；status 返回稳定 retired，不能用于响应丢失恢复，也不触发重签 |
| Java `failed_after_private_key_known` | operation irreversible_failed，request/workflow failed；不自动重签 |
| Java `outcome_unknown` | operation/request/workflow manual_review，保留 appearance/evidence hold |
| complete staging | 接管、重新验证、继续晋升 |
| promoted + final exists | 只重放事务 B |
| promoted + final missing | manual review + 告警 |
| ready file missing/hash mismatch | 禁止下载，revision unavailable，告警 |
| orphan file | 先排除 execution/appearance 当前 exact retirement epoch 已登记的 canonical/staged path；真正 orphan 才移入普通 quarantine，禁止自动登记 |

reconciler 所有状态更新使用 operation fence 和 Java execution CAS；不得把仍在 deadline 内的正常 `executing` 当作异常，也不得把已知 post-key failure 降格成可重试失败。

## 八、Java PDF 核心设计

V1 从现有大服务拆出最少职责：

- `PdfSignatureInspector`；
- `PdfUnsignedFinalizationService`；
- `PdfWorkflowPreparationService`；
- `IncrementalPdfSignatureService`；
- `PlacementCoordinateMapper`；
- `SignatureAppearanceFactory`；
- `PadesBtSignatureFactory`；
- `PdfSignatureVerifier`；
- `PdfAllowedChangeValidator`。

### 8.1 inspection

返回：

- 文件是否已签、加密或损坏；
- 页面数、MediaBox、CropBox、rotation、UserUnit；
- AcroForm 和签名字段；
- DocMDP、FieldMDP、`/Lock`；
- 结构风险与稳定错误码；
- versioned inspection manifest/hash。

V1 的新流程发现任何现有数字签名即拒绝。

### 8.2 prepare

1. 复核 planning revision SHA 和 finalization/inspection/placement hash；
2. 一次性创建三个人的字段和一个 deferred 首页章字段；
3. 创建 widget、空 AP、include-self-only `/Lock`；锁字典引用当前 fully-qualified field name，禁止 All/Exclude 或其他字段；
4. 不改变页面内容、二维码或元数据；
5. 记录 field/widget/object refs；
6. 增量或全量保存 prepared revision均可，因为此时尚无数字签名，但输出必须作为新的不可变 revision；
7. 重新 inspection 并验证 field plan。

### 8.3 sign existing field

1. 复核 operation、request、source revision、field、appearance 和 policy；
2. 验证所有历史签名和修改权限；
3. 确认目标字段预存在、未填、未锁定；
4. `pdf_signature_role=certification_p2` 时要求这是输入中的第一个签名，创建唯一 DocMDP transform `P=2` 并写 `/Perms/DocMDP`；approval 时要求该 certification signature 已存在且有效，禁止新增或改写 `/Perms/DocMDP`；
5. 只生成目标字段的可视 AP；
6. 创建一个 signature dictionary，并由当前字段 include-self-only `/Lock` 产生对应 FieldMDP约束；
7. 使用 `saveIncrementalForExternalSigning(...)`；
8. 生成 PAdES-B-T CMS 和 RFC 3161 signature timestamp；
9. 写入 `/Contents`；
10. 执行分层验证；
11. 返回二进制 PDF 和结构化验证摘要。

必要不变量：

```text
output_bytes[0 : input_bytes.length] == input_bytes
```

但仍需验证对象变化和修改权限。

---

## 九、V1 对象变化与签名验证

V1 不在每个请求中实现完整 PDF forensic 平台，但保留足以保护本系统生成 PDF 的硬门禁。

### 9.1 必须验证

- 输入前缀完全不变；
- 新签名 `/ByteRange` 恰好四个非负整数；
- 唯一 gap 精确对应同一签名字典的 `/Contents`；
- `/Contents` 只有一个 DER CMS，剩余 padding 全零；
- 目标签名 field `/V`、目标 widget/AP 和必要 AcroForm key 是共同允许变化；
- 仅 `certification_p2` 首签额外允许 Catalog 新增唯一 `/Perms/DocMDP` 指向本次签名字典，以及本次签名字典新增 DocMDP transform params `P=2`；Catalog 其他 key 不变；
- approval 签名要求 Catalog 与既有 `/Perms/DocMDP` object ref/transform params 完全不变；页面内容、Pages tree、元数据和非目标字段始终不变；
- 旧签名的 `/V`、`/ByteRange`、`/Contents` 不变；
- xref/trailer 构成预期增量修订；
- 所有历史签名重新验证；
- DocMDP、FieldMDP 和字段 `/Lock` 允许本次修改。

### 9.2 Phase 0/安全工具保留

以下完整 forensic 能力先作为测试工具和后续 hardening，不阻塞 V1 生产链路：

- xref table/stream/hybrid 全量 raw manifest；
- 每个 indirect object 和 raw stream hash；
- semantic object canonicalization；
- repaired xref、object stream 和 shadow-update 深度分析；
- 任意第三方 PDF 的兼容承诺。

若 V1 输入不再限定为本系统生成 PDF，必须先升级这些能力。

---

## 十、PAdES-B-T 精确合同

生产基线：**ETSI EN 319 142-1 V1.2.1**。

V1 固定：

- PDF `/SubFilter /ETSI.CAdES.detached`；
- digest：`id-sha256`；
- signature：`sha256WithRSAEncryption`，RSA modulus ≥ 2048；
- CMS `encapContentInfo.eContentType=id-data`，`eContent` absent；
- signed attributes 含 content-type、message-digest、SigningCertificateV2/ESSCertIDv2；
- CMS `signing-time` absent；
- PDF `/M` exactly one，仅作 claimed time/显示信息；
- RFC 3161 `id-aa-signatureTimeStampToken` exactly one；
- timestamp imprint 绑定 `SignerInfo.signatureValue`；
- TSA URL、policy、trust bundle 和 timeout 来自 immutable policy；
- TSA 失败不产出 ready revision，不降级到 B-B；
- `/Contents` reserved size 由真实证书链和 TSA 样本测量并留余量；
- 是否写入 ESIC Extension Dictionary 由 Gate 0A 样本固定，选定后在首签前冻结，不能每次动态决定。

组织证书信任政策：

- trust anchors 使用版本化 allowlist，不直接依赖主机任意系统根；
- leaf 必须满足 CA=false 与 document-signing 所需 KeyUsage/EKU；
- 签署时证书链、有效期和当前撤销状态必须为 good，否则 fail closed；
- OCSP/CRL 网络失败或证据不足返回 `indeterminate`，公开页面不得显示“可信”；
- 有可信 signature timestamp 时，以时间戳时间评估签名存在时刻；PDF `/M` 不提升信任。

每个签名验证结果分层返回：

1. `cms_integrity`；
2. `certificate_trust`；
3. `signed_revision_integrity`；
4. `later_revision_permission`；
5. `timestamp_trust`；
6. `document_current_state`。

状态使用 `valid / invalid / indeterminate`，不能折叠成单一“签名有效”。

V1 不实现 B-LT、B-LTA、DSS/VRI 或 DocTimeStamp。

---

## 十一、Java 服务安全与 Phase S

### 11.1 网络

- Java 只监听 `127.0.0.1:8080`；
- Docker 映射 `127.0.0.1:8080:8081`；
- 非本机网络不可达；
- 未来全容器化后改专用 internal network，是否启用 mTLS另行决定。

### 11.2 HMAC 合同

使用 HMAC-SHA-256 + canonical part manifest，不签 Guzzle 动态 multipart boundary。

固定 headers：

```text
X-Pdf-Auth-Version
X-Pdf-Key-Id
X-Pdf-Timestamp
X-Pdf-Nonce
X-Pdf-Correlation-Id
X-Pdf-Operation-Id
X-Pdf-Metadata-Sha256
X-Pdf-Part-Manifest-Sha256
X-Pdf-Signature
```

无 DB operation 请求使用：

```text
X-Pdf-Operation-Id: -
```

签名串固定为 UTF-8、十行、字段间单个 LF、末尾无 LF：

```text
version
key_id
method
normalized_path_and_query
metadata_sha256
part_manifest_sha256
timestamp
nonce
correlation_uuid
operation_uuid_or_dash
```

要求：

- key 至少 32 bytes CSPRNG；
- MAC 使用 64-char lowercase hex、constant-time compare；
- metadata 和 part manifest 使用 RFC 8785 JCS；不可逆签名 POST 的 metadata 还必须包含 operation UUID、current lease epoch、operation input manifest hash、input fingerprint、signing policy version ID/UUID、policy hash 和 config bundle hash；
- part manifest 按 ASCII part name 排序；每个 part 记录 name、规范化 content type、长度和 SHA-256；重复、未知、遗漏或额外 part 一律拒绝；
- method 大写；path/query 使用 versioned RFC 3986 canonicalization，拒绝 dot segment、重复 slash 和重复 query key；空值与缺失不同；
- Java 收到 headers 时立即记录 receipt time，timestamp 与该时刻偏差最大 60 秒；body receive deadline 固定 120 秒；
- nonce 首发使用 Redis `SET pdf-hmac:{version}:{key_id}:{nonce} <correlation_uuid> NX EX 300`，Redis 不可用时 fail closed；先验证 header/MAC 并 claim nonce，再流式接收和核对 body/manifest，后续 body/hash 失败也不释放 nonce；
- `X-Pdf-Operation-Id`、metadata operation UUID 和数据库 operation UUID 必须完全一致；无 operation 请求三者都使用/表达固定 sentinel `-`；
- 校验通过前不得触发 PDF writer 或私钥；
- PHP/Java 共用完整正向和负向 request-to-MAC vectors；
- legacy 和 internal write/private-key endpoint 使用同一 filter；
- production 关闭 DEBUG 和敏感 multipart 日志。

### 11.3 策略收口

- 调用方不能传算法、TSA URL、证书路径、PFX 密码或证书别名；签名请求只引用 immutable policy version；
- Java 只读加载 `pdf_signing_policy_versions`，重算 policy/config hash 并与 operation direct snapshot 比对后才选择算法、组织证书和 TSA；请求中的任何同名自由参数均拒绝；
- PFX 密码无默认值，`changeit` fallback 删除；
- 缺失、不可读或过期签名材料时启动失败；
- Browser→Laravel source upload 和 Laravel→Java source part 上限统一为 20 MiB；Java 生成结果、内部 result GET 和 Laravel staging 上限统一读取 Gate 0A 冻结的 `generated_revision_max_bytes`，不能把输入上限误用于带 CMS/TSA 的输出；
- legacy 时间戳能力如实标记 `false`，不能把未实现 TSA 宣称为 B-T；
- `/api/pdf/process` 拒绝已签输入；
- 新工作流永不调用 legacy 签名接口。

### 11.4 Phase S 切换顺序

1. Java 端口改为 loopback；
2. 双方部署 HMAC secret，Java 暂不 enforce；
3. 删除默认密码和调用方策略参数；
4. PHP/Java vectors 通过；
5. 短时 audit-only/dual-accept；
6. Laravel 全部调用发送 HMAC；
7. 日志确认旧调用为零；
8. enforce smoke test；
9. Java 切换 enforce；
10. 删除 dual-accept；
11. 完成一次 key rotation；
12. 安全措施不随业务回滚撤销。

---

## 十二、API 合同

### 12.1 Browser → Laravel

| Endpoint | V1 contract |
|---|---|
| `POST /api/pdf/signing-sources/inspect` | 上传未签 PDF，返回 source UUID、结构与几何 inspection |
| `POST /api/pdf/signing-sources/{source}/confirm` | 确认报告编号并创建 document/source 绑定 |
| `POST /api/pdf/signing-sources/{source}/finalize` | 返回 202，生成 finalized planning revision |
| `POST /api/pdf/signing-workflows` | 创建三人任务、字段计划和一个 deferred 首页章 act |
| `POST /api/pdf/signing-workflows/{id}/prepare` | 只创建 fields/widgets/AP/locks，返回 202 |
| `POST /api/pdf/signing-workflows/{id}/activate-homepage-seal` | 基于 current published 创建 late-seal workflow，no-write bind 既有字段 |
| `POST /api/pdf/signing-workflows/{id}/cancel` | 只能调用 5.14 集中仲裁；私钥边界已开始返回 409，绝不直接清 active pointer |
| `POST /api/pdf/signing-requests/{id}/reject` | 与取消共用 5.14 仲裁；有 active operation 时不得绕过私钥边界直接拒绝 |
| `GET /api/pdf/signing-workflows/{id}` | 查询 workflow、requests 和 revision chain |
| `GET /api/pdf/signing-requests/{id}` | 返回当前用户获授权任务和精确 revision |
| `POST /api/pdf/signing-requests/{id}/appearances` | 规范化笔迹/首页章并返回 immutable artifact |
| `POST /api/pdf/signing-requests/{id}/challenge` | 当前密码再认证并绑定 revision/field/appearance/policy |
| `POST /api/pdf/signing-requests/{id}/sign` | claim operation，返回 202；不得重复发送 Java 签名 POST |
| `GET /api/pdf/signing-operations/{uuid}` | 返回 state、stage、Java execution state、attempt/next-retry/deadline/next-poll、error 和 result revision |
| `GET /api/pdf/revisions/{uuid}/download` | 下载 ready 且摘要正确的精确 revision |
| `GET /api/public/pdf/revisions/{uuid}` | 查询服务器登记状态 |
| `POST /api/public/pdf/revisions/{uuid}/verify` | 上传实际 PDF，由服务端计算摘要和验证签名 |
| `GET /api/public/pdf/documents/{publicId}` | 查询当前 published revision 和历史链 |

权限至少拆分：

- `pdf.workflow.create`；
- `pdf.workflow.cancel`；
- `pdf.request.reject`；
- `pdf.request.sign_assigned`；
- `pdf.organization_key.use`；
- `pdf.revision.download`；
- `pdf.verification.read`；
- `pdf.manual_review.resolve`。

### 12.2 Laravel → Java

| Endpoint | V1 contract |
|---|---|
| `POST /internal/pdf/signatures/inspect` | 结构、签名、权限和几何检查 |
| `POST /internal/pdf/signatures/finalize-unsigned` | 只处理未签 source，生成 planning revision |
| `POST /internal/pdf/signatures/prepare` | 一次性创建全部字段/widget/AP/锁 |
| `POST /internal/pdf/signatures/sign-existing-field` | 对 operation UUID at-most-once 填一个既有 field |
| `GET /internal/pdf/signatures/executions/{operationUuid}` | 查询 Java execution 与 result integrity 状态；查询不读取 workflow/request |
| `GET /internal/pdf/signatures/executions/{operationUuid}/result` | 仅 completed + result available；Java 在 operation→execution 锁内打开同一只读 descriptor、完整验证并 rewind，提交后保持该 descriptor 流式返回，绝不按路径重开；响应受固定总 deadline/最低吞吐约束，Laravel 完整落 staging 后再次验 SHA/size。retiring 返回 `409 EXECUTION_RESULT_RETIRING`，missing/breached 返回 `503 EXECUTION_RESULT_INTEGRITY_ERROR`，retired 返回 `410 EXECUTION_RESULT_RETIRED` |
| `POST /internal/pdf/signatures/verify` | 分层验证全部签名和修改权限 |

---

## 十三、公开验证与旧系统兼容

### 13.1 新 V1 文档

公开页面区分：

- **document 页面：** 当前 published revision 和历史 revision；
- **revision GET：** 服务器登记状态；
- **revision POST verify：** 用户上传文件的实际字节、CMS、证书、时间戳和权限验证。

`overall_valid` 只保留旧兼容语义，不能覆盖分层结论。

已发布 revision 处于 integrity hold 时，不返回内部 revision 常用的 404；公开页面保留其历史身份并显示 `integrity_review_pending`，禁止下载，POST verify 返回 `indeterminate`。从未发布的 planning/prepared/abandoned revision 仍统一 404。

### 13.2 旧 `pdf_files`

V1 采用最小双读：

- 原 `file_id`、下载地址和旧报告编号 resolver 保持；
- schema 新列允许 legacy row 先为 null；
- backfill 只在旧 SHA、MD5、大小与磁盘字节全部一致时生成 document/revision identity；
- 不覆盖旧摘要；
- 缺失或不一致的旧文件进入不可用/隔离清单；
- 不按相同报告编号自动合并历史文档；
- legacy document 首发只读，不允许进入新签署 workflow；
- 旧入口消费者迁移完毕后再删除旧字段和逻辑。

这避免首发同时建设历史身份治理系统。

---

## 十四、页面交互

### 14.1 规划页

```text
┌──────────────────────────────── PDF 签名位置规划 ────────────────────────────────┐
│ 报告编号 XDP...   finalized SHA-256 abcd...                 [保存草稿] [冻结计划] │
├──────────────┬──────────────────────────────────────┬───────────────────────────┤
│ 页面          │ PDF 实时预览                          │ 签署行为                   │
│ [01]          │                                      │ ① 主检  [蓝色]             │
│ [02]          │       ┌──────── 主检签名 ────────┐   │ ② 审核  [绿色]             │
│ [03]          │       │ 可拖动 / 等比缩放 / 微调  │   │ ③ 签发  [紫色]             │
│ ...           │       └──────────────────────────┘   │ ○ 首页章 [deferred]        │
│               │                    100%  第 3/13 页   │ x/y/w/h + 页面 + 误差提示  │
├──────────────┴──────────────────────────────────────┴───────────────────────────┤
│ 冻结后生成全部 signature fields/widgets；后续签署只填写既有字段                 │
└─────────────────────────────────────────────────────────────────────────────────┘
```

- 使用 finalized planning revision；
- 左侧页面缩略图，中间 PDF，右侧三人任务和 deferred 首页章；
- 拖动、等比缩放、方向键微调；
- 冻结前 overlay 只存在前端；
- 冻结后人员、顺序、字段、几何不可修改；
- 修改必须取消 workflow 并从 planning revision 新建 generation。

### 14.2 签署页

```text
┌──────────────────────────────── 当前签署任务 ────────────────────────────────────┐
│ 操作人：张三   证书主体：中山市…公司   Revision SHA-256: abcd...                 │
├──────────────────────────────────────────┬──────────────────────────────────────┤
│ 只读 PDF / 当前字段高亮                   │ 手写签名板                           │
│                                          │ ┌──────────────────────────────────┐ │
│              [目标签名区域]               │ │                                  │ │
│                                          │ └──────────────────────────────────┘ │
│                                          │ [清除] [确认笔迹]                    │
├──────────────────────────────────────────┴──────────────────────────────────────┤
│ 确认笔迹 → 当前密码再认证 → [确认使用组织证书签署] → operation 实时状态          │
└─────────────────────────────────────────────────────────────────────────────────┘
```

- 当前 PDF 只读；
- 只高亮当前用户字段；
- 显示当前 revision SHA-256；
- 显示“操作人”和“组织证书主体”；
- 手写完成后先上传 appearance，再验证当前密码；
- challenge 后禁止修改 appearance；
- 点击签署后轮询 operation；
- manual review 时明确显示“签署结果待管理员核对”，不能引导用户再次点击。

### 14.3 首发设备

- 必须支持桌面 Chrome；
- 触控笔/移动端可以完成基础验证，但不作为 V1 上线阻塞项；
- 移动端完整交互优化进入后续迭代。

---

## 十五、实施顺序

### Phase S：立即安全封口

- loopback；
- HMAC filter 和 vectors；
- Redis nonce；
- 删除默认密码；
- 固定算法/TSA/key policy；
- 关闭 legacy 虚假 TSA 能力；
- 上传上限与日志收敛；
- Gate S smoke 和 key rotation。

**Gate S：** 未认证、篡改、重放、过期、未知 key 和 Redis 故障均不能触发私钥或 PDF writer。

### Phase 0A：核心 PDF/PAdES 门禁

- 单字段、单 widget、单签名 PAdES-B-T；
- 第一个且唯一 certification signature、DocMDP P=2、include-self-only field lock；
- ByteRange/Contents/padding；
- RFC 3161；
- 组织证书 trust policy；
- 增量前缀和对象 allowlist；
- Acrobat、Foxit、Java 和独立验证器结论矩阵；
- finalized geometry/coordinate round trip。

**Gate 0A：** 通过后即可进入 Phase 1，不等待完整平台能力。

### Phase 1：单人最小纵向切片

```text
new unsigned PDF
  → inspect
  → confirm report number
  → finalize
  → plan one field
  → prepare
  → canonical appearance
  → password challenge
  → organization PAdES-B-T
  → immutable revision
  → exact download/verify
```

同时实现最小 documents/revisions/workflow/request/field/slot/appearance/challenge/operation/outbox 和 Java execution ledger，并完成 pre-key 重试、post-key known failure、registration/execution deadline polling、outcome-unknown/manual-review、取消-vs-private-key 竞争、completed-result missing/breached、同一 descriptor 读取及 GET-vs-retirement/restore 并发故障测试。operation 直接冻结 policy/config/certificate/appearance snapshots。Java 使用最小权限数据库账号：只授予 `pdf_signing_operations` 白名单列 SELECT 和专用 `java_gate_version` 列级 UPDATE、immutable policy 表 SELECT、execution 表 SELECT/INSERT/UPDATE、execution-event 表 INSERT；Java 通过 gate CAS 获取 operation 行锁，不读取或修改 workflow/request/document/revision/publication。workflow 取消与 pointer 清理由 Laravel 的 5.14 集中仲裁服务负责。

### Phase 0B：顺序签署和 deferred seal 门禁

可与 Phase 1 后半并行取证：

- 三个顺序 CMS；
- 三个后继 approval signatures；每次验证 Gate 0A 建立的 `/Perms/DocMDP` object ref 不变、P=2 不变且当前 `/Lock` 只锁自身；
- deferred 首页章字段；
- later-seal workflow；
- 一个 field 多 widget；
- 历史签名在后续 seal revision 中仍满足权限。

multi-widget 失败时降级为单 widget，不阻塞三人流程。

### Phase 2：三人流程 + deferred 首页章

- 主检、审核、签发；
- 后继 request 激活；
- publication pointer；
- 首页章 later-seal workflow；
- public document/revision verification；
- manual-review 运维流程与统一 evidence retirement arbiter；result/appearance 都通过 staged→grace→purge，覆盖单对象及 document-wide hold-vs-stage/purge、排序锁多个历史 operation、旧 epoch、rename/unlink/DB commit 故障；
- 真实 Chrome 完整流程。

完成后即构成 Lean V1 可上线版本。

### Phase 3：legacy 迁移和旧入口退役

- 新旧 `file_id/revision_uuid` 双读；
- 旧下载/二维码回归；
- legacy 安全入口在途批次完成；
- 新 UI 切流；
- 删除 legacy signature branch；
- 历史异常文件清单和人工处置。

### Phase 4：外部已签 PDF 与恢复能力

仅有明确业务需求后实施：

- import existing signed；
- bind existing fields；
- recovery upload；
- exact SHA 候选消歧；
- duplicate document policy；
- identity registry/alias/adjudication；
- 完整 raw/semantic forensic manifest。

### Phase 5：高可用和自动恢复

触发条件：Java 需要多实例、HSM/远程签名或自动灾难恢复。

- S3-compatible immutable result store；
- operation-level irreversible guard；
- cross-epoch attempts；
- completion-vs-uncertainty winner protocol；
- 自动 historical-result adoption；
- 产品化 quarantine 双人裁决。

### Phase 6：长期验证与个人签名

- PAdES-B-LT/B-LTA；
- OCSP/CRL evidence bundle；
- DocTimeStamp；
- 个人 CA、UKey、CSC 或远程签名；
- 新的身份和供应商门禁。

---

## 十六、验收门禁

### 16.1 身份与业务

- 三个 request 只能由各自用户完成；
- 后继任务只在前驱 ready revision 完成后激活；
- challenge 绑定同一 token、revision、field、appearance、policy；
- 三个 CMS 都显示组织证书主体；
- 首页章 deferred act 不参与首次 workflow 完成条件；
- later-seal 只填首签前已存在字段。

### 16.2 修订与恢复

- revision UUID 永远映射同一字节和 SHA；
- ready 与 published 明确分离；
- 相同幂等输入返回同一 operation/result；
- stale worker 不能晋升或提交；延迟到达的旧 lease 签名 POST 也不能创建 execution 或跨越私钥边界；
- rename 前后和事务 B 前后故障均可恢复到唯一合法状态；
- promoted 不会重新调用私钥；
- Java response loss 只查询 execution；
- workflow/request 取消必须与 Java 私钥边界争用同一 operation→execution 锁；取消先赢时 Java 被挡在私钥前，Java 先赢时取消返回 409 且不清 active pointer；
- completed execution 的 result path/SHA/size 每次取回前重验；验证和返回必须使用同一 descriptor：锁内打开/验证，提交后仍保持该 descriptor 到响应结束，Laravel 对完整 staging 再验 SHA/size；missing/breached 不回退 execution、不触发重签；存在 verified staging/promoted 副本时继续使用该副本，否则按正式 revision 是否已物化进入 manual review 或 append-only 完整性事件；
- result 退休必须由 Laravel 先冻结绑定 document `integrity_version` 的短时有效 authorization snapshot，Java 不读取 revision/document 推导删除资格；result/appearance 删除都只能经过可撤销的同盘暂存、至少 24 小时宽限和第二次持锁 purge。hold 与 purge 只有一个赢家，document-wide hold 按 ID 锁全相关 operation后原子生效；旧 epoch、并发 GET/preview、每个 rename/unlink/DB commit 故障点均有确定恢复结果；
- `failed_before_private_key` 只对 policy allowlist 中的瞬时错误重试，使用单一 CAS、冻结次数/退避且只有一个并发赢家；确定性错误稳定失败；
- `executing` 在 deadline 前保持轮询状态，不提前转人工复核；execution 尚不可见时先受 registration deadline 约束，只有可证明 body 未发送才允许首次请求重发；
- `failed_after_private_key_known` 与 `outcome_unknown` 是两个不同终态，前者为明确不可发布失败，后者才进入人工结果归属复核；
- outcome unknown 不自动重签；manual-review 采用 completed result 时只恢复同一 operation、重新领取 lease 并继续 staging；
- manual-review/irreversible-failure appearance 在结案前受 hold 保护，sweeper 不得删除；
- hold 在 retirement stage 前或宽限期内赢得仲裁时必须保住/恢复 exact bytes；最终 purge 已先赢时，迟到 hold 返回稳定冲突并写审计，绝不伪装保全成功；
- orphan/quarantine 文件永不公开。

### 16.3 PDF/PAdES

- 输入前缀不变；
- 本次 ByteRange、Contents、DER 和 padding 合法；
- 对象变化只在 allowlist；
- 所有历史签名重新验证；
- DocMDP、FieldMDP、`/Lock` 允许本次操作；
- 首签恰好一个 certification DocMDP P=2；后续签名均为 approval，不能新增/替换 DocMDP；每个字段锁只包含自身，后继字段在轮到前保持可签；
- `/ETSI.CAdES.detached`、ESSCertIDv2 和 RFC 3161 真实存在；
- TSA 失败不产出 ready revision；
- Acrobat、Foxit、Java 和独立验证器结论一致。

### 16.4 安全

- Java 非本机不可达；
- HMAC 失败、nonce 重放、Redis 故障不能触发私钥；
- 签名 POST 不自动重试；
- 缺失或默认 PFX 密码时启动失败；
- 调用方不能控制算法、TSA 或 key；
- 日志不包含密码、token、完整 PDF、笔迹或私钥材料。

### 16.5 性能与工程

- 13 页、20 MiB 边界 source、真实 finalization/field plan、三次签署和一次首页章在生产资源门槛内完成，最终 revision 不超过 Gate 0A 冻结且含至少 20% headroom 的 generated revision/逐次 signature increment budgets；
- PDF、CMS 和响应使用流式处理，不返回 Base64 PDF；
- Java/Laravel/frontend focused tests 和 production builds 通过；
- `git diff --check` 通过；
- 最终样本绑定 exact commit 和文件 SHA-256。

---

## 十七、非完成条件

以下均不算交付完成：

- 只把 PNG 画到 PDF；
- 只调用 `saveIncremental`，不验证对象变化和权限；
- 只验证最终 SHA 或单一 `overall_valid`；
- 在已签 PDF 上动态建字段；
- 把单位证书描述为三个人的个人数字签名；
- 签名 POST 响应丢失后自动再发一次；
- 把私钥后的明确失败混入 `failed_before_private_key` 或 `outcome_unknown`；
- `failed_before_private_key` 没有 retryable-error allowlist、CAS、lock version、次数、backoff 和并发约束就直接重试；
- Java 仍在 deadline 内正常 executing，却立即转 manual review；
- attempt deadline 只在私钥边界后才开始，导致 preflight 中的 executing 可无限挂起；
- Java execution 尚不可见且 delivery unknown 时，不经过 registration deadline 观察就猜测请求未到达并再次 POST；
- Java 不校验 Laravel operation 的当前 state/stage/lease，允许延迟旧 POST 在取消或人工复核后才跨越私钥边界；
- workflow/request 取消直接清 active pointer，而不先与 Java 在 operation→execution 锁上仲裁；
- 把 Java execution=`completed` 等同于 result bytes 一定可用，result missing/breached 后重新调用私钥或静默失败；
- completed result 校验后关闭 descriptor、再按路径重新打开并流式返回，或允许任何代码 in-place 修改 completed result；
- evidence sweeper 资格检查后直接 unlink，未经过 staged/grace/purge、retirement epoch及与 hold 的同锁仲裁；
- 已经 unlink 后仍把迟到 legal/manual-review hold 写成 active success；
- 为满足 Java 校验给其 workflow/request 表权限，而不是让 operation 成为唯一执行授权快照并由 Laravel 统一仲裁取消；
- manual review/irreversible failure 尚未结案就删除关联 canonical appearance；
- 文件 rename 后没有事务 B/reconciler；
- 新接口安全但 legacy 私钥入口仍未鉴权；
- 测试全绿但没有真实阅读器/PAdES 证据；
- 为首发提前实现本方案明确延后的平台能力。

---

## 十八、最终静态复审记录

本版在 v14 Final Lean 基础上执行了“必要性、可达性、恢复性、首发价值、不可逆边界、取消竞态、结果完整性、最小权限、证据保全、读取身份与签名权限”十一类反向审查。

### 18.1 已删除或延后的过度设计

| 原目标能力 | Final Lean 处理 |
|---|---|
| 先建完整 Phase 2 平台底座 | 改为 Phase 1 单人纵向切片先行 |
| source intake/candidate/duplicate 平台 | V1 只支持新未签 PDF 和稳定冲突 |
| identity registry/alias/adjudication | legacy 首发只读，Phase 4 再做 |
| 外部已签 PDF import/recovery | Phase 4 |
| B-LT/B-LTA/evidence bundle | Phase 6 |
| Java 多实例 + S3 result ledger | V1 单实例持久化 volume，Phase 5 升级 |
| completion/uncertainty 分布式 winner | V1 使用单行 CAS + immutable deadline；deadline 前轮询，只有 outcome unknown 才人工复核 |
| 产品化双人 quarantine | V1 受控脚本 + 审计，Phase 5 产品化 |
| 全量 raw/semantic forensic runtime | V1 核心 allowlist；完整能力留测试/Phase 4 |
| 功能章和骑缝章完整迁移 | V1 只做一个 deferred 首页章 |
| 移动端完整产品体验 | V1 以桌面 Chrome 为发布门槛 |

### 18.2 保留理由审查

保留的每个核心实体均对应首发不可替代的事实：

- document：逻辑文档与发布指针；
- revision：不可变字节和修订链；
- workflow/request/act：三人顺序和 deferred 首页章；
- field/slot：首签前字段和坐标；
- appearance/challenge：实际笔迹与再认证绑定；
- policy：算法、证书和 TSA 不可变；
- operation/outbox：幂等、异步和 commit 后不丢单；
- Java execution/event：单实例下的私钥 at-most-once、响应丢失查询和 append-only attempt 证据。

### 18.3 一致性复审结论

- 首发范围与 Phase 1/2 验收一一对应；
- 延后能力没有残留为 V1 必填表、API 或发布门禁；
- deferred 首页章存在可达的数据状态和 API 路径；
- 一个 active workflow + 一个 active operation 足以阻止 V1 sibling branch；
- Java `failed_before_private_key` 先区分瞬时/确定性错误，只有 allowlisted 瞬时错误具备单赢家、次数受限和退避明确的安全重试 CAS；初始/重试 POST 与私钥边界都绑定 Laravel operation 的 current state/stage/lease 和 immutable input/policy snapshot；
- Java 请求发送后先受 immutable registration deadline 约束，execution 可见后从 attempt 开始受 immutable execution deadline 约束；两个 deadline 前均保持正常轮询，超时后才按 delivery/private-key evidence 分类；
- Java pre-key retry、post-key known failure、deadline 内 executing 和 outcome unknown 四类状态互斥且可达；只有 outcome unknown 进入结果归属人工复核；
- 取消与 Java 私钥边界使用同一 operation→execution 锁序：取消先赢会终态化 operation 并使迟到 Java 拒绝，Java 先赢则取消返回 409 且不清 pointer；Java 无需读取 workflow/request；
- completed execution 与 result bytes integrity 分层；available 才可采用，missing/breached 不回退 completed、不重签；retiring 只来自可恢复的授权保留期清理，retired 是宽限与最终持锁 purge 后的不可逆终态；
- manual-review/irreversible-failure appearance 具备统一 evidence hold、release 和 sweeper fail-closed 条件；未越过私钥边界的人工结案只能恢复同一 workflow，新 appearance/challenge/operation；无法归属但确认无可用结果的结案必须失败原 workflow并新建 generation；
- Java outcome unknown 不会自动重签；
- 文件系统与数据库恢复协议没有宣称跨资源原子；
- `ready`、`published`、公开验证和旧兼容语义互不混淆；
- 方案没有依赖尚未取得的 Gate S/Gate 0 结果预先宣称兼容性。

### 18.4 v12 外部复审 1 个 P0、3 个 P1 闭环

| Finding | v13 修复 | 反向验证 |
|---|---|---|
| P0 缺少私钥已调用但结果明确失败状态 | Java execution 增加 `failed_after_private_key_known`，Laravel 投影为 `irreversible_failed`；reserved-size、TSA 明确拒绝和结果验证失败不再混入 pre-key retry 或 unknown | 同 operation 禁止重试；只有显式新 generation 可恢复 |
| P1 pre-key retry 缺少 CAS、次数和并发约束 | 冻结 retryable-error allowlist、max attempts/backoff；CAS 同时校验 state、retryability、attempt count、lock version、private-key 未开始、next_retry_at 和 input/policy hash | 确定性错误不重试；瞬时错误并发测试只有一个赢家；上限耗尽稳定失败并要求新 challenge/appearance |
| P1 executing 被立即转人工复核 | POST 开始发送时冻结 registration deadline，attempt `claimed → executing` 时冻结 execution deadline，并增加 `java_polling/next_java_poll_at`；两个 deadline 前持续查询/heartbeat，超时后按 delivery/private-key evidence 分类 | ingress/ledger 延迟不会误报；preflight hang 进入 pre-key failure；越过私钥后仅真正 unknown 进入 manual review |
| P1 manual-review 笔迹缺少保留字段 | v13 首次增加 evidence hold reason/start/release、legal hold 与 deleted 字段；v15 已把单一 reason 升级为多原因 bit mask；终态事务设置 hold，结案后才 release + 24h；sweeper 以 hold/reference 条件 fail closed | 故障测试覆盖结案前 sweep、结案后删除和处置失败不释放；并发原因不能互相覆盖或误清 |

反向状态机复核还确认：transport retry 与显式 pre-key application retry 已分离；只有 allowlisted transient pre-key error 可重试；签名 body 可能已发送但 execution 暂不可见时先经过 registration deadline 观察，不会立即误判或重发；private-key boundary 之后的任何失败都不能回到 `claimed`；manual review 中“可证明未调用私钥”与“可能已调用但确认无可用结果”走不同恢复路径，前者可继续同一 workflow，后者必须新 generation；manual review CLI 不能发布 quarantine 文件或在 hold 未释放时清理证据；延迟到达的 stale-lease POST 会在 Java execution 创建前或私钥边界前被 authoritative operation fence 拒绝。

### 18.5 v13 外部复审 1 个 P0、2 个 P1 闭环

| Finding | v14 修复 | 反向验证 |
|---|---|---|
| P0 取消与 Java 私钥边界竞态 | 新增 5.14 仲裁：取消和 Java 均先锁 operation→execution；取消只有在 `private_key_started_at IS NULL` 时才能终态化 operation并清 pointer，Java 边界先赢时取消稳定返回 409 | 并发测试覆盖取消先赢、Java 先赢、execution 尚未创建、pre-key retry 与取消竞争；不存在“已取消后才启动私钥” |
| P1 completed result 丢失/损坏无恢复状态 | execution 增加 `available/retiring/missing/breached/retired`，completed result 读回校验、每次取回重验、崩溃可恢复退休及下游副本/人工处置 | missing/breached 永不触发重签；verified staging/promoted 可继续，正式 revision 已存在时只写 append-only 完整性事件，否则保持 manual review/evidence hold |
| P1 Java 最小权限与 workflow 指针校验矛盾 | operation 增加专用 `java_gate_version`；Java 只 SELECT 白名单 direct snapshots，并仅对 gate version 有列级 UPDATE 权限，以条件 UPDATE 获取 operation 行锁后读写 execution/event；不读取 workflow/request。Laravel 取消/拒绝锁同一 operation 行并由集中 service 清 pointer | 权限测试证明 Java 无 workflow/request 权限且无 operation 业务列 UPDATE 权限仍可完成签署；gate CAS 与取消并发只有一个赢家；直接清 pointer 的 controller/CLI 被拒绝 |

以下是 v14 当时的自检结论：所有 operation 创建后的状态推进都使用统一锁序；operation cancelled 是稳定幂等终态，取消事务同时关闭 outbox，且已处于 retryable `failed_before_private_key` 的 execution 也会关闭 retry；completed execution 的 result integrity 异常不会被误分类为 outcome unknown，也不会复活私钥调用；verified downstream 副本可继续完成提交；retention 先以 execution completion 计时、事务 B 再延长到 operation completion；Java 只额外读取 immutable policy 表，算法/TSA/key 不由请求自由传入；报告编号 normalizer、late-seal source-field identity、NULL-safe publication CAS、persistent result volume 与 HMAC nonce/body deadline 均已形成唯一合同。v15 复审证明 v14 的单一 `available→retiring→retired` 删除状态仍不足以裁决 hold 与最终 unlink 的并发，也不足以证明 GET 校验与流式读取的是同一文件身份；这两项旧结论由 18.6 的 v15 合同取代。

### 18.6 v14 外部复审与 v15 独立自审闭环

| Finding | v15 修复 | 反向验证 |
|---|---|---|
| P0 证据 hold 与异步删除可并发，最终 unlink 前没有数据库赢家 | appearance 与 result 统一采用 `stage_intent → staged → purge_intent → retired`、单调 `retirement_epoch` 和四个窄权限门；所有 hold/retirement 都遵守 5.15 的完整固定锁序，最终 unlink 只在 `purge_intent` 的同一 epoch 下执行 | hold 先赢会清退休授权并恢复 staged bytes；purge 先赢会稳定返回 `EVIDENCE_ALREADY_RETIRED`，不伪造 hold 成功；任一阶段崩溃均由 phase、epoch、canonical/staged path 与 hash 重放，旧 epoch worker 必败 |
| P1 result GET 在校验后按路径重开文件，存在 TOCTOU | `RESULT_READ_OR_INTEGRITY` 在锁内只打开 canonical result 一次，记录稳定文件身份并从同一只读 descriptor 完成 hash/size 校验；事务提交后继续从该 descriptor 流式响应，禁止按路径重开，result 禁止原地改写 | Linux 持久卷探针验证 rename/unlink 后已打开 descriptor 仍可读取相同 bytes；并发退休测试验证响应只会返回已校验 bytes 或失败，不会混入替换文件 |

v15 自审同时消除了由上述修复暴露的次生矛盾：

- appearance 与 result 都改为位掩码 evidence hold；manual review、irreversible failure、quarantine、retirement integrity 与 legal hold 只能增减各自拥有的 bit，任一原因不能覆盖或误清其他原因；
- result retirement 拆为 stage intent/apply 与 purge intent/apply 四个不同能力，短期授权只用于启动 stage，24 小时后 purge 不依赖已过期授权，也不存在宽泛 `RESULT_RETIRE` 权限；
- document-wide hold 先按 ID 顺序锁定全部相关 operation/execution，再锁 document/revision/artifact，并以 `integrity_version` 失效旧退休授权；锁不全则整笔失败，不产生部分 hold；
- document integrity hold 也采用多原因 bit mask；任一 integrity bit 或 document-wide evidence-hold set 增减都递增 `integrity_version`，单个结案不能覆盖其他 hold；evidence-only legal hold 不误下线有效 PDF，publication-integrity hold 也不能被误恢复；
- appearance challenge 创建与 operation 事务 A 都重新校验 artifact 可用、无 hold、无退休且 hash/size 一致；合法 challenge 引用会延长保留期并阻止 sweeper；
- 输入 20 MiB 与生成修订上限不再错误等同；Gate 0A 必须用边界源文件、最终字段和四次真实证书/TSA 增量测得上限并留至少 20% 余量；
- 主检首签被固定为唯一 `certification_p2`，必须写唯一 `/Perms/DocMDP` 且 P=2；审核、签发与 deferred 首页章只能是 approval signature，必须保持既有 Catalog/Perms/DocMDP 不变；所有字段只锁自身完全限定字段名；
- Java retire 只由计划中的定时 sweeper 触发，API 表不再虚构未设计的 retire HTTP 入口；Laravel 不挂载 Java result root；
- scheduled sweeper 的退休授权唯一来源是 Laravel-only-write/Java-read-only 的 operation manifest；删除了与不存在的 retirement HMAC 请求比对这一矛盾；
- 单机 V1 明确依赖 Linux POSIX 持久 bind volume、稳定 file identity 与 bounded streaming；无法满足探针的平台必须升级为 read lease/对象存储 ledger，不能降级为路径二次打开。

最终逐节复核了实体约束、状态可达性、锁序、能力边界、API 对应关系、字段角色、删除恢复矩阵、容量单位和验收门禁；全文机械检查没有发现失配、悬空状态、未定义权限或格式错误。

**最终 verdict：静态方案 Review = GO，No Findings。**

实施可以按 Phase S、Phase 0A、Phase 1、Phase 0B、Phase 2 顺序推进；后续 Phase 3-6 只有达到明确触发条件才立项，禁止重新把目标架构一次性塞回首发范围。

---

## 十九、规范基线

- [ETSI EN 319 142-1 V1.2.1：PAdES baseline signatures](https://www.etsi.org/deliver/etsi_EN/319100_319199/31914201/01.02.01_60/en_31914201v010201p.pdf)
- [ETSI TS 119 102-2 V1.4.1：Signature Validation Report](https://www.etsi.org/deliver/etsi_TS/119100_119199/11910202/01.04.01_60/ts_11910202v010401p.pdf)
- [RFC 8785：JSON Canonicalization Scheme](https://www.rfc-editor.org/rfc/rfc8785.html)
- [RFC 5754：SHA-2 Algorithms with CMS](https://www.rfc-editor.org/info/rfc5754/)
- [RFC 5652：Cryptographic Message Syntax](https://www.rfc-editor.org/rfc/rfc5652.html)
- [ISO 32000-2:2020 clause 12 errata：signature fields、DocMDP、FieldMDP、permissions](https://pdf-issues.pdfa.org/32000-2-2020/clause12.html)
- [Apache PDFBox 3.0.5 `PDDocument`：incremental/external signing API](https://javadoc.io/static/org.apache.pdfbox/pdfbox/3.0.5/org/apache/pdfbox/pdmodel/PDDocument.html)

规范结构、密码学、证书信任、PDF 修改权限和阅读器兼容性是不同门禁，任何一个通过都不能代替其他门禁。
