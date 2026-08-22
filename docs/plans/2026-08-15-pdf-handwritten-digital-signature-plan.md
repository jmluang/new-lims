# PDF 手写可视签名与增量数字签名实施方案

**版本：** v10（已吸收 GPT-5.6 Sol Ultra、ChatGPT Pro 九轮审核意见，并完成 v10 独立自审）

**状态：** Phase S-A GO；Phase S-B 合同可实施，须按 Gate S 取得真实 vectors/Redis/负向请求/生产 smoke 证据；Phase 0 GO；Phase 2+ NO-GO，须通过 Gate 0 并取得本方案要求的真实运行证据

**仓库基线：** `main @ 786e3f83940ed695ee83c25914d1d7b6f36aed4e`

**目标页面：** `/pdf/handwritten-signing`

**首发范围：** 组织证书签名；个人 CA/UKey/远程签名延后至外部供应商门禁通过

---

## 一、执行结论

采用“React 规划与手写交互 + Laravel 持久化签署工作流 + Java PAdES 服务”的三层架构。

```text
Browser (React + pdf.js)
  └─ placement planning / handwriting / read-only preview
       ↓ authenticated business API
Laravel
  └─ immutable source / workflow / request / challenge / operation / revision
       ↓ authenticated internal API
Java PDF service
  └─ inspect / prepare fields / incremental sign / layered verify
```

以下原则不可降级：

1. **签名按签署行为建模，不按坐标建模。** 一个签署行为对应一个 PDF 签名字段、一个签名字典、一个 CMS/PAdES 签名，可关联一个或多个可视 widget。
2. **首签前冻结流程。** 所有签署字段、widget、锁定策略和未来首页章/功能章/骑缝章槽位必须在首个数字签名前创建。
3. **后续签署只填已有字段。** 已签 PDF 没有预留字段时，受控流程默认拒绝动态新增字段。
4. **每次成功签署形成一个不可变修订。** `revision_uuid` 永远只对应一组固定字节，下载只暴露 `ready` 修订。
5. **文件系统与数据库不宣称原子。** 使用可恢复提交协议、状态机和 reconciliation job 保证最终一致与可审计恢复。
6. **首发只做组织证书。** 主检、审核、签发分别通过登录会话和当前密码再认证生成三个顺序 CMS，但三个 CMS 的密码学签署者都是组织证书；用户身份来自 Laravel 授权和审计，不能描述成三份个人证书签名或 MFA。
7. **个人数字签名必须另过供应商门禁。** 未选定 CA/远程签名/UKey 方案前，不接受 `signing_key_id` 作为“已支持个人私钥”的替代品。
8. **旧 `/api/pdf/process` 立即纳入同级安全边界。** 新工作流永不调用旧接口；回滚只能回滚 UI 流量，不能重新开放无鉴权私钥入口。
9. **签名位置只能基于 finalized unsigned planning revision。** 声明页、二维码、元数据和光度处理完成并重新 inspection 后，用户才可规划槽位。
10. **认证挑战必须绑定实际笔迹。** 浏览器不能在 challenge 创建后替换 PNG；sign 只接受已固化的 `appearance_uuid`。
11. **安全封口不依赖 PDF Gate。** 当前 legacy 私钥接口的 loopback、HMAC、策略收口和默认密码清理属于独立 Phase S，立即实施。
12. **公开版本由发布指针决定。** `ready` 只表示字节完整，不表示正式签发；公众只能看到 `pdf_documents.published_revision_id`。

---

## 二、首发业务语义

### 2.1 一个签署行为与多个外观

```text
SigningWorkflow
  └─ SigningRequest / SigningAct
       ├─ assigned actor
       ├─ exactly one PDF signature field
       ├─ one or more visual slots/widgets
       ├─ exactly one signature dictionary
       └─ exactly one CMS/PAdES signature revision
```

同一人一次确认多个页面位置时，只产生一个 CMS。多个位置通过同一字段的多个 widget 表达。跨页 multi-widget 是否被 Acrobat、Foxit 和独立验证器一致接受，是 Phase 0 的强门禁；未通过时不得使用普通 annotation `/AP` 冒充 P=2 下的正式兼容方案。

普通 annotation 外观只能作为独立的实验 profile，必须明确标为“非受控兼容实验”，没有独立阅读器证据时不能宣称后续修改符合 DocMDP `P=2`。

### 2.2 首发的三人流程

附件里的主检、审核、签发对应三个独立 `SigningRequest`：

1. 主检登录、查看精确修订摘要、手写并二次确认，生成组织证书 CMS #1；
2. 审核只能在 CMS #1 验证通过后操作，生成组织证书 CMS #2；
3. 签发只能在 CMS #2 验证通过后操作，生成组织证书 CMS #3；
4. 后续首页章/功能章/骑缝章使用预留字段，再生成组织证书 CMS #4。

每次审计记录真实操作用户、认证方法、源修订摘要、字段清单、证书 fingerprint 和结果修订。UI 必须显示“数字证书主体：中山市鑫达普检测服务有限公司（示例）”，不能把手写姓名展示成证书 subject。

若业务或法务要求“张某、李某、吴某分别以本人私钥签署”，该能力属于 Phase 6，必须接入真实个人签名提供方。

---

## 三、当前基线与必须迁移的旧契约

当前仓库已有 PDF 上传、Java PDFBox/Bouncy Castle 签名、私有文件存储、摘要台账、公开验证和审计能力，但存在以下结构性约束：

- `pdf_files.file_id` 当前唯一，并由 `PdfSigningService` 写入业务 `file_number`；
- 下载响应使用 `X-Final-File-Id`；
- PDF 台账搜索、下载和审计仍以 `file_id` 为主键语义；
- 公开验证按上传文件精确 SHA 查找，并折叠成单一 `overall_valid`；
- Java `/api/pdf/process` 可触发私钥操作，调用方还能传签名策略参数；
- Java 上传上限为 200 MB，Laravel 当前上限为 20 MB，限制不一致；
- 当前只配置默认组织 PFX，`signing_key_id` 尚未形成真实个人密钥选择与认证闭环。

因此 v10 不在现有 `file_id` 上叠加多修订语义，也不把旧公开验证结果直接包装成 PAdES 验证。

---

## 四、不可变来源、最终成文与防 TOCTOU

### 4.1 `pdf_source_intakes`

上传字节先形成可恢复、一次性消费的 intake，不允许只靠进程内临时文件或浏览器状态完成消歧：

- `id`, `intake_uuid UNIQUE`, `actor_user_id`, `idempotency_key`；
- `upload_intent`: `create_new_document` / `recover_existing_document` / `import_existing_signed`；
- nullable `requested_document_id`, `requested_base_revision_id`, `recovery_of_workflow_id`, `recovery_authorization_hash`；
- nullable `business_identity_type`, `business_identity_normalized`, `business_identity_value_hash`, `business_identity_normalization_version`；
- nullable `duplicate_of_document_id`, `duplicate_reason_code`；
- `ingress_object_key`, `sha256`, `file_size`, `inspection_manifest`, `inspection_manifest_hash`；
- `candidate_set_manifest`, `candidate_set_hash`：每项含 `candidate_kind`、document/revision/source identity、exact SHA 与授权快照摘要；按 `(document_public_id, candidate_kind, revision_uuid_or_dash, source_uuid_or_dash)` 的 ASCII 字节序全排序后做 JCS/SHA-256；
- `status`: `pending_resolution` / `resolved` / `expired` / `cancelled`；
- nullable `resolved_document_id`, `resolved_source_upload_id`, `resolved_candidate_revision_id`, `resolved_at`, `cancelled_at`；
- `expires_at`, timestamps。

inspect 在同一事务把 actor、intent、ingress object 的 SHA/size/inspection hash、当时有权限看到的完整候选集合及其 hash 固化。ingress object 使用不可变 key，resolve 时必须重新流式核对 SHA/size/inspection hash；任何一项变化都进入安全隔离，不能替换原 intake 字节。`(actor_user_id, idempotency_key)` 唯一；同 key/同 fingerprint 返回原 intake，同 key/不同 fingerprint 返回 409。

candidate manifest 在服务端审计中保留完整 FK identity；浏览器响应只投影当前 actor 可见的 public-safe identifiers。recover 内部/abandoned source 不得因进入 candidate snapshot 而向客户端泄露 object key、内部路径、其他 workflow 用户或异常详情。

业务身份不能仅由字节摘要决定：

1. `recover_existing_document` 必须在 inspect 时指定 `requested_document_id`；技术恢复另须指定同 document 的 `requested_base_revision_id/recovery_of_workflow_id`。候选只在目标 document 内查询，包括授权且可信的 source，以及 `ready + internal active/abandoned` 的 finalized/prepared/partially-signed revision；quarantined/unavailable/incomplete-evidence 不可自动成为 base。0 match 固定返回 `RECOVERY_SOURCE_NOT_FOUND`，永远不能创建新 document；
2. `import_existing_signed` 只查询曾 published、ready 且未 integrity-withdrawn 的 exact-SHA revision；命中时必须显式选择复用既有 document，0 match 才允许创建 imported document；
3. `create_new_document` 可创建新 document。若 exact unsigned/generic source bytes 已归属其他 document，只有调用者具备 `document.create_duplicate`、提交独立业务身份和强制原因码、且业务身份唯一时才允许，并持久化 `duplicate_of_document_id/reason_code`；否则必须选择既有 document 或取消。若 inspection 已发现数字签名或冻结的报告身份，则同字节不得改挂另一业务身份，create-new 必须拒绝并要求 import/reuse 既有 document；不能用 duplicate policy 洗掉已签文件身份。

`recovery_authorization_hash` 固化 inspect 时的 actor、目标 document/revision/workflow、权限集合版本和候选集合，但不能替代 resolve 时的实时授权：resolve 事务再次锁定目标 document/workflow、重验当前权限和 recovery terminal 状态，snapshot 或实时权限任一不满足都拒绝。

首发业务身份固定为 `business_identity_type=report_number`：`authoritative_report_number` 经 versioned normalizer 做 Unicode NFKC、去除首尾 Unicode whitespace、ASCII 字母大写，拒绝 control/空值/超过 128 code points；内部字符不折叠。`business_identity_value_hash=SHA256(UTF-8(normalized))`，document 以 `(business_identity_scope_key, business_identity_type, business_identity_value_hash)` 唯一；`authoritative_report_number` 是该 normalized identity 的展示投影。`business_identity_scope_key` 只能由服务端从当前组织/租户上下文派生，首发单组织固定为 `org:default`，调用方不得提交或覆盖。未来其他身份类型必须新增 normalizer version，不能复用自由文本规则。

`POST /api/pdf/signing-source-intakes/{intake_uuid}/resolve` 必须带原 `candidate_set_hash`、目标 candidate（或获准的 `create_new_document` 决策）与新的 idempotency key。事务锁定 intake，重新校验 actor/权限/expiry/status/candidate-set 未漂移，再一次性创建或绑定 document/source 并置 `resolved`；重放同一决定返回原结果，不同决定返回 409。`DELETE` 只把仍 pending 的 intake 置 `cancelled`。进程重启后由数据库和不可变 ingress 恢复；过期 job 先 CAS 到 `expired` 再删除 bytes，保留安全审计 hash。

`create_new_document` 的 resolve 决策必须同时提交用户确认的 report number；服务端规范化后仅允许把 intake 的 business-identity 列从全 null 一次性写为全 non-null，并将其纳入 resolution fingerprint，再执行 document unique/duplicate policy。`recover_existing_document/import_existing_signed` 不接受调用方修改业务身份。document 创建后 finalization 只读取该冻结 identity，不能再次选择另一报告编号；若确认值需变更，必须取消 pending intake 并重新 inspect/resolve，不能改写既有 document identity。

### 4.2 `pdf_source_uploads`

首次安全 inspection 后必须把原始上传固化为服务端不可变来源，而不是让浏览器在后续阶段重新上传：

- `id`, `source_uuid UNIQUE`, `document_id NOT NULL`；
- `source_role`: `initial_unsigned` / `imported_signed` / `duplicate_probe` / `recovery_upload`；
- `source_generation`：document 内单调分配；
- `stored_path`, `sha256`, `file_size`, `page_count`；
- `inspection_manifest`, `inspection_manifest_hash`；
- `created_by_id`, `expires_at`, `retention_policy_version`, `retention_until`, nullable `legal_hold_until`, `consumed_at`, `deleted_at`；
- `status`: `inspected` / `consumed` / `expired` / `quarantined`。

首发固定 **source:document = N:1**：一个 source 永远只属于一个 document；一个 document 可拥有多次受控上传/导入/恢复 session，unique `(document_id, source_generation)`。上传先创建 4.1 intake，完成 SHA-256 与 security inspection 后、创建正式 source/document 之前按 intent 生成候选：recover-existing-document 只查目标 document 的可信 internal/source bytes，import-existing-signed/create-new 才查 `ready + 曾 published + 未 integrity-withdrawn` exact-SHA revision：

create-new 还必须查询 actor 有权处理的其他 document 的 active/retained source exact-SHA identity，写入同一 candidate snapshot；无权匹配只产生统一冲突/不可处理结果，不泄露 document。即使 published revision 为 0，只要存在 source duplicate，也必须执行 4.1 的 duplicate permission/business identity/reason policy。

1. 0 个 published 匹配：`import_existing_signed/create_new_document` 可按各自 intent 在 resolve 事务创建新 document、分配 public ID、创建 generation=1 source 并绑定，但 create-new 仍须先处置 source duplicate candidate；`recover_existing_document` 不走本分支，按目标 document 的 recovery candidate 规则返回 match 或 `RECOVERY_SOURCE_NOT_FOUND`；
2. 1 个匹配且当前用户有该 document 的导入/恢复权限：先返回 pending intake；resolve 时锁定既有 document，分配新 source generation，以 `duplicate_probe` 或 `recovery_upload` 绑定；不得复制 publication event；
3. 1 个匹配但无权限：返回统一无权/不可处理响应，不确认 document 身份，审计后清理 ingress；
4. 多个匹配：返回 `ambiguous_registration` 与 `intake_uuid/candidate_set_hash`；只提供当前用户有权处理的 public-safe revision/document identifiers，要求显式选择；禁止按最早/最新/数据库自然顺序选择；选择前不创建正式 source/document；
5. 未在 15 分钟内 resolve：按 intake expiry 合同清理 ingress bytes，仅保留安全审计 hash，不建立 source 行。

inspection 响应在完成归属后返回 `source_uuid`、`document_uuid/document_public_id` 和 nullable `matched_published_revision_uuid`。相同已发布字节的 `import_existing_signed` 进入 `reuse_existing_revision` DB-only result mode：source 绑定既有 document、operation 指向既有 revision，不创建 root claim/revision，也不新造 publication history。未匹配的 unsigned/signed source 才分别创建 `initial_unsigned/imported_signed` 根修订。

`unsigned_finalize` 和 materializing `import_existing_signed` 必须从 source 绑定锁定 document，并在 operation 保存 `document_id`。input fingerprint 同时覆盖 source UUID/SHA-256、document UUID/public ID、source generation 和 action；并发消费由 source/document 行锁、root claim 和 idempotency 共同阻断。

#### 4.2.1 Source pin、消费与清理

source pin 是数据库引用推导结果，不是 worker 内存标记：

| Source/root state | Lifecycle contract |
|---|---|
| `inspected` 且无 root claim/operation | 到 `expires_at` 后可由 sweeper 清理 |
| root claim=`reserved/retryable` | pinned，禁止过期删除；合法新 attempt 继续使用同一 source |
| operation=`claimed/processing/recovery_pending` | pinned 至 operation 进入可证明终态；recovery pending 期间保持原 active claim |
| claim/execution=`uncertain` 或 quarantine held | pinned 至双人 adjudication/人工终结和 legal hold 结束 |
| root revision committed | 同一事务把 source 置 `consumed`、写 `consumed_at` 和版本化 `retention_until` |
| exact published reuse DB-only completed | 不创建 root claim；同一事务把 duplicate/recovery source 置 `consumed` |
| `consumed` | 原字节保留至 `retention_until/legal_hold_until`；到期后可删 bytes，但永久保留 SHA、inspection manifest、document binding 和审计 |

sweeper 必须锁定 source，并查询 root claims、operations、Java execution、quarantine artifact、active workflow/recovery 引用和 legal hold；任一 pin 存在即跳过，不能只比较 `expires_at`。删除成功后才写 `deleted_at/status=expired`；删除失败保留状态并告警。planning revision 已生成但仍处审计/恢复保留期时，即使 source 已 consumed 也不得提前删除。

### 4.3 Finalized unsigned planning revision

最终成文是独立、可恢复的 `unsigned_finalize` operation，顺序固定为：

```text
raw source upload
  → security inspection
  → unsigned finalization
  → finalized_unsigned planning revision
  → inspect finalized geometry
  → user placement planning
  → freeze workflow
  → prepare fields/widgets only
```

`finalize` 返回 `planning_revision_uuid`、精确 SHA-256、最终页数/几何和 `finalization_manifest_hash`。manifest 至少冻结：

- 声明页模板版本、模板文件摘要和合并位置；
- 操作者确认的权威报告编号；
- 二维码目标 URL/参数和生成策略；
- 元数据策略；
- 光度处理参数；
- 所有图像、字体和章面资产摘要；
- raw source UUID/SHA、产出 revision UUID/SHA 和执行策略版本。

报告编号只有一个权威来源：create-new intake resolve 时操作者确认的 document business identity；UI 可用 inspection 的封面抽取值辅助确认，但不能让 Java 自动覆盖。ledger 的 `cover_report_number`、finalization manifest、二维码查询值和公开 resolver 必须使用同一 normalized document identity，不允许 finalization 再选择另一编号。

规则：

1. `POST .../inspect` 上传一次，Laravel 流式落盘、计算 SHA-256，再调用 Java security inspection；
2. `finalize` 只读 raw source，输出不可变 planning revision；
3. 对 planning revision 重新执行页面结构、签名和几何 inspection；
4. 创建 workflow 只提交 `planning_revision_uuid`，不能只绑定 raw `source_uuid`，也不能重传 PDF；
5. workflow 冻结 planning revision SHA、inspection manifest hash、finalization manifest hash 和 placement plan hash；
6. `prepare` 只能创建 fields/widgets/AP/locks，严禁再增加页面、二维码、元数据或改变页面几何；
7. challenge 绑定当前 expected revision SHA、field manifest、placement plan 和 appearance manifest。

`geometry_hash` 只证明 planning revision 的页面几何合同未变化，不能替代整个修订或计划清单的绑定。

---

## 五、持久化领域模型

Cache 只允许保存尚未创建正式流程的 UI 草稿。正式流程全部持久化。

### 5.1 `pdf_documents`

- `id`, `document_uuid`, `document_public_id`；
- `authoritative_report_number`, `business_identity_scope_key`, `business_identity_type`, `business_identity_normalized`, `business_identity_value_hash`, `business_identity_normalization_version`；
- nullable `duplicate_of_document_id`, `duplicate_reason_code`；
- `active_workflow_id`, `published_revision_id`；
- `integrity_hold_state`: `none` / `active` / `resolved`；nullable `integrity_hold_reason`, `integrity_hold_started_at`, `integrity_hold_resolved_at`, `integrity_hold_incident_uuid`；
- `next_revision_number`：document 级单调序号分配器；
- `next_source_generation`：document 级 source session 单调序号分配器；
- `status`: `draft` / `signing` / `issued` / `superseded` / `revoked`；
- `created_by_id`, timestamps。

`pdf_documents` 在 raw source inspection/dedupe 完成后、首次 unsigned finalization/import materialization 之前创建并分配高熵 public ID；若 exact bytes 已归属既有 published revision，则复用既有 document 而不是创建空壳 document。该聚合是公开发布的唯一根。公众“当前版本”只读取 `published_revision_id`，绝不按最大 revision number、最后写入时间或 `ready` 状态推导。只有 workflow 达到配置的签发终态且全部 required request 完成时，事务 B 才可原子推进发布指针。

同字节创建独立 document 时，`duplicate_of_document_id` 必须指向已存在的 exact-byte document，reason code 来自 allowlist，且新 document 的业务身份唯一；duplicate relation 不复制签名、revision、publication event 或法律状态。

#### 5.1.1 `pdf_document_publication_events`

- `event_uuid`, `document_id`, `revision_id`；
- `event_type`: `published` / `superseded` / `revoked` / `integrity_withdrawn` / `integrity_restored`；
- nullable `integrity_incident_uuid`：withdrawn/restored 事件以同一 UUID 关联一次完整性事故；
- `reason_code`, `reason`, `actor_user_id`, `occurred_at`；
- `previous_published_revision_id`, nullable `replacement_document_id`, nullable `related_publication_event_id`, `audit_context_hash`。

revision 增加 `first_published_at` 和 `last_publication_event_id`。从未有 `published` event 的 revision 永不进入公开 exact-revision API；曾发布后被 superseded/revoked 的 revision 可保留公开历史状态。

`replacement_document_id` 使用正式 FK 指向替代逻辑文档；关联事件通过 `related_publication_event_id` 形成可审计双向关系。两者进入公开 DTO allowlist 时只暴露 public ID 和公开状态，不暴露内部主键。

完整性 hold 与业务/法律 revoke 分离：发现正式 revision 缺失、摘要变化或存储不可判定时，锁定 document 置 `integrity_hold_state=active`、写 `integrity_withdrawn`，立即禁止下载、新签署和 published pointer 推进，但 `document.status` 不自动变 `revoked`。只允许从可信备份恢复同一 revision UUID、同一 immutable path contract 的 exact bytes，并重验 SHA、revision manifest 和全部签名；双人审批后置 resolved、写同 `integrity_incident_uuid` 的关联 `integrity_restored` 才恢复服务。revision UUID/path/SHA/manifests 永不改变，只有 `pdf_files.integrity_state` 可在事故事务中从 unavailable/quarantined 恢复 ready。确认篡改或超过处置期限无法恢复时，另走显式 revoke 事务。

### 5.2 `pdf_signing_workflows`

- `id`, `workflow_uuid`, `document_id`, `workflow_generation`, `signing_plan_generation`, `lineage_uuid`；
- nullable `source_upload_id`, `origin_type`: `source_upload` / `existing_revision`, `base_revision_id`, nullable `planning_revision_id`, nullable `prepared_revision_id`, `current_revision_id`；
- `base_revision_sha256`, nullable `finalization_manifest_hash`, `inspection_manifest_hash`, `placement_plan_hash`；
- `binding_strategy`: `create_fields` / `bind_existing`；
- nullable `bind_existing_source_type`: `imported_signed` / `internal_prepared` / `internal_partially_signed` / `late_seal_current_published`；
- nullable `recovery_of_workflow_id`, `recovery_reason_code`, `inherited_signed_act_manifest_hash`；
- `mode`: 首发仅 `organization`；预留 `personal_remote`；
- `status`: `draft` / `preparing` / `ready` / `signing` / `completed` / `rejected` / `cancelled` / `failed`；
- `created_by_id`, timestamps。

未签新计划的 base revision 是 finalized unsigned planning revision；外部已签流程的 base 是 imported signed revision；技术恢复可从 internal prepared/partially-signed revision 建立新 generation。`current_revision_id` 通过数据库锁和 CAS 推进。“每个父修订最多一个成功子修订”只约束同一活动 lineage。

`origin_type=source_upload` 必须有 source 且 source/document 一致；`existing_revision` 必须 `source_upload_id IS NULL`，provenance 来自 base revision manifest、同 document FK 及 recovery/late-seal link。internal recovery、已发布报告激活预留章和 legacy backfill 后续流程不得为满足列约束伪造 source。

取消/拒绝后，业务计划、人员、顺序、slot、field、lock 或页面任一发生变化时，旧 prepared revision 不得复用，必须从可信 planning/base revision 重新规划并 prepare。只有签署技术失败且冻结的 placement/field/lock/act manifest 完全不变时，才允许新 generation/new lineage 以可信 internal prepared/partially-signed revision 为 base，通过 no-write bind 复用 PDF 对象；旧 workflow/claim 永久保留 failed/irreversible_failed/uncertain 审计。

#### 5.2.1 `pdf_signing_acts`

签署行为是 document 级稳定语义，不随技术恢复 workflow/attempt 重建：

- `id`, `logical_signing_act_uuid UNIQUE`, `document_id`, `signing_plan_generation`；
- `field_name`, `semantic_role`, `sequence`, `frozen_plan_hash`；
- `status`: `planned` / `deferred` / `completed` / `permanently_skipped` / `cancelled`；
- nullable `completed_revision_id`, timestamps。

同一 document/plan generation 的 `(field_name)` 与 `(semantic_role, sequence)` 均唯一。首个 workflow 冻结计划时创建 act；internal recovery 和 later-seal activation 保持 `signing_plan_generation` 并复用同一 act。`deferred` 表示 PDF field/widget 已预建、当前 workflow 不执行，未来仍可激活；`permanently_skipped` 才表示永远放弃。completed/permanently-skipped act 保持原终态与完成 revision/skip audit；未完成/deferred act 可在合法后续 workflow 建新 binding。业务计划变化必须递增 plan generation、创建新 acts/new prepared revision，不能把 deferred activation 冒充重规划。

状态迁移固定为：prepare 事务把未来 act `planned→deferred`；实际签署事务把 `planned|deferred→completed`；冻结 activation policy 的不可逆放弃事务把 `planned|deferred→permanently_skipped`；只有字段尚未 prepare 的整份草案废弃才可 `planned→cancelled`。late-seal workflow 在完成前失败/拒绝/取消时原 act 保持 deferred；uncertain 时也保持 deferred 但被 claim/adjudication gate 阻止再次激活。completed/permanently-skipped/cancelled 均不可回退。

#### 5.2.2 `pdf_workflow_signing_act_bindings`

- `id`, `act_binding_uuid UNIQUE`, `workflow_id`, `signing_act_id`；
- `binding_kind`: `actionable` / `prepared_deferred` / `inherited_completed` / `inherited_skipped`；
- nullable `source_workflow_id`, `source_request_id`, `source_completed_revision_id`, `inherited_outcome_hash`；
- `bound_field_name`, nullable `bound_object_ref`, `binding_manifest_hash`, timestamps；
- unique `(workflow_id, signing_act_id)`。

首个 workflow 为本次执行 act 建 actionable binding，为未来首页章/功能章/骑缝章建 `prepared_deferred` binding。prepared-deferred 必须创建 field/slot 并在首签前进入 prepared，但不得创建 request/challenge；当前 workflow 完成条件忽略该 binding，act 在 prepare 后 CAS 为 deferred。后续 `origin_type=existing_revision` 的 late-seal workflow 对同一 logical act 创建 actionable binding/request/field mapping，以 no-write bind 映射原 PDF object，签署成功时 `deferred → completed`。

技术 recovery 对已完成 act 只建 `inherited_completed` binding；对永久 skipped act 建 `inherited_skipped` binding并绑定原 activation/skip decision hash；二者不创建 request/field/challenge。对 planned/deferred 未完成 act 根据本 workflow 目的创建 actionable 或 prepared-deferred binding。所有引用必须同 document/plan generation；completed revision 必须位于 recovery base 的受验证历史链，permanent skip 必须仍满足冻结 policy。业务重规划不得把 permanently-skipped act 重新激活；需要恢复该行为时必须新 plan generation/new field plan。

### 5.3 `pdf_signing_requests`

- `id`, `request_uuid`, `attempt_uuid UNIQUE`, `workflow_id`, `document_id`, `signing_act_id`, `signing_act_binding_id UNIQUE`, `sequence`, `predecessor_request_id`；
- `request_type`: `handwritten` / `homepage_seal` / `function_stamp` / `perforation_stamp`；
- `requirement`: `required` / `optional` / `conditional`；
- `activation_policy`, `skip_reason`；
- `assigned_user_id`, `signing_policy_version_id`；
- `status`: `pending` / `available` / `signing` / `signed` / `permanently_skipped` / `rejected` / `failed` / `cancelled`；
- `expected_source_revision_id`, `expected_source_sha256`：pending 后继任务初始为 null；
- `completed_revision_id`, timestamps。

`challenged` 不是 request 的稳定状态。prepare/bind 完成时首个 required request 绑定当前 revision 并进入 `available`；后继 request 只在前驱事务 B 中原子绑定新 ready revision后激活。进入 `available` 后 expected source 与 signing policy version 不可修改。prepared-deferred act 在本 workflow 根本没有 request，不参与完成条件；已经创建的 optional/conditional request 若按冻结 policy 放弃，只能记录 `permanently_skipped` decision。workflow 完成条件是本 workflow 全部 required request signed，其他实际 request signed 或合法 permanently skipped。

### 5.4 `pdf_signing_fields`

- `id`, `field_uuid`, `workflow_id`, nullable `request_id`, `signing_act_id`, `signing_act_binding_id UNIQUE`；
- `field_name`, `field_type`；
- `binding_mode`: `created_before_first_signature` / `imported_existing` / `internal_rebound`；
- `lock_policy`；
- nullable `prepared_revision_id`, nullable `prepared_object_ref`；
- `status`: `planned` / `prepared` / `signed` / `locked` / `cancelled`。

受控约束：actionable binding 恰好对应一个 request 和一个 signature field；prepared-deferred binding 有 field/slot 但 `request_id IS NULL`；inherited binding 不创建 field。该 field 恰好在 act 成功修订中获得 `/V`。不同 workflow 的 field row 都通过 binding 指向相同 logical act；`act_binding_uuid` 不能替代稳定逻辑身份。

### 5.5 `pdf_signing_slots`

- `id`, `slot_uuid`, `field_id`；
- `placement_type`, `page_index`, `widget_index`；
- `normalized_rect`, `geometry_hash`；
- nullable `prepared_widget_object_ref`, nullable `prepared_appearance_object_refs`；
- `status`: `planned` / `prepared` / `rendered` / `cancelled`。

一个 field 可拥有多个 slot/widget。`widget_index` 在 field 内唯一，页面、矩形和 object ref 在 prepare/bind 后冻结。

### 5.6 `pdf_signature_appearance_artifacts`

- `id`, `appearance_uuid`, `request_id`, `created_by_id`；
- `artifact_type`: `handwriting` / `homepage_seal` / `function_stamp` / `perforation_source`；
- `source_asset_version`, `source_asset_sha256`；
- `slot_manifest`, `canonical_image_sha256`, `appearance_manifest_hash`；
- `width`, `height`, `crop_box`, `render_parameters`, `renderer_version`；
- `state`: `available` / `claimed` / `consumed` / `quarantined` / `expired`；
- `claimed_by_operation_id`, `claimed_at`, `retention_until`, nullable `legal_hold_until`, nullable `adjudication_released_at`, `deleted_at`, `file_path`。

手写 artifact 由浏览器原图经服务端解码、颜色空间/透明背景/方向/裁边/padding 规范化后生成 canonical PNG。印章 artifact 额外快照资产版本与摘要、透明化/缩放/切片算法、每个 widget 最终渲染摘要、骑缝章每页 slice hash、资产选择人和授权依据。

事务 A 原子 claim artifact，一个 artifact 只能被一个非终态 operation claim。私钥调用前的 transient infrastructure failure 只允许同一 operation/lease 重试，绝不释放给新 operation；不可逆 POST 开始后改由 execution ledger 恢复，不重发。普通 completed 流程在完成后 24 小时删除 canonical 图像；可证明的 pre-sign terminal failure 从终态起 24 小时删除。

`recovery_pending/uncertain/quarantined/irreversible_failed` 一律设置 `legal_hold_until` 或无限 hold，禁止 24 小时 sweeper。只有双人 adjudication 记录笔迹与 execution/result 的归属、写 `adjudication_released_at` 并确认无其他 legal hold 后，才从 release 时刻起计算 24 小时删除期。长期审计保留 appearance hash、尺寸、算法、decision hash 和成品/forensic PDF；不能以隐私最小化为由提前破坏人工裁决证据。

### 5.7 `pdf_signing_challenges`

- `challenge_uuid`, `request_id`, `user_id`；
- `source_revision_id`, `source_sha256`；
- `plan_hash`, `field_manifest_hash`, `appearance_artifact_id`, `appearance_manifest_hash`, `intent`；
- `signing_policy_version_id`, `policy_hash`, `expected_certificate_fingerprint`；
- `nonce_hash`, `auth_method`, `auth_policy_version`, `provider_transaction_id`；
- `auth_context_type= sanctum_token`, `auth_context_id`, `auth_context_hash`；
- `password_changed_at_snapshot`, `reauthenticated_at`；
- `expires_at`, `consumed_at`, `cancelled_at`。

挑战通过行锁或条件更新原子消费：`WHERE consumed_at IS NULL AND cancelled_at IS NULL AND expires_at > now()`。成功 operation 的 `challenge_id` 唯一。sign 必须使用创建 challenge 的同一 Sanctum token，验证 token 仍存在、用户 active/未 locked/无 `must_change_password`、当前 `password_changed_at` 等于快照、assignment/权限仍一致。过期、取消、用户/会话/修订/appearance/policy 不匹配均拒绝。

所有密码修改、管理员重置、账户禁用/锁定、token revoke/logout 都调用统一 `SigningChallengeCancellationService`，按 user/auth context 取消未消费 challenge；不能依赖各 controller 自行拼接取消逻辑。`auth_context_hash` 使用独立服务密钥对 token id、tokenable id、token created_at 做 HMAC，不存储 bearer token。

### 5.8 `pdf_signing_policy_versions`

- `id`, `version_uuid`, `policy_hash`, `immutable_at`；
- `pades_profile`；
- `digest_algorithm_oid`, `signature_algorithm_oid`, `signature_algorithm_parameters`；
- `organization_certificate_fingerprint`, `certificate_chain_fingerprints`；
- `signing_material_version`, `key_locator`；
- `tsa_policy_oid`, `tsa_endpoint_set`, `tsa_failover_order`；
- `tsa_timeout_ms`, `tsa_retry_count`, `tsa_retry_backoff_policy`；
- `tsa_trust_bundle_version`, `tsa_trust_bundle_hash`；
- `signing_trust_policy_version`, `signing_trust_bundle_hash`；
- `revocation_policy_version`, `ocsp_crl_endpoint_policy`；
- `reserved_size_bytes`, `reserved_size_policy_version`；
- `config_bundle_hash`：以上所有行为配置的 JCS/SHA-256 总摘要。

workflow 冻结时，每个 request 绑定 exact immutable policy version；challenge 与 operation fingerprint 同时绑定 policy/config bundle hash 和证书 fingerprint。Java 必须回传实际 policy/config hash、算法、证书链 fingerprints、signing material version、TSA endpoint ID/policy、trust bundle hash 和 reserved size，Laravel 完全比对后才能晋升。证书/密钥定位/TSA endpoint/retry/trust/revocation/reserved-size 轮换只能创建新 version，活动版本禁止就地修改。

首发认证方法固定为 `password_reauthentication`，不宣称 MFA：

- 需要有效 Laravel/Sanctum 登录会话、任务归属权限和当前账户密码重新验证；
- `POST .../challenge` 接收 `appearance_uuid`、intent 和 current password，经 TLS 传输；密码只用于即时 `Hash::check`，不落库、不进日志；
- 按 user + IP 对失败再认证限流：15 分钟最多 5 次，超限返回统一错误并写安全审计；成功后清除该再认证失败计数；
- challenge 有效期 5 分钟且一次性；密码修改、账户禁用、会话撤销或任务归属变化立即取消未消费 challenge；
- 管理员不能代签或绕过再认证；没有本地可验证密码的账户禁止首发签署，未来 SSO 必须提供等价 step-up contract；
- 后续若引入 TOTP/WebAuthn，必须作为新的 auth policy version，另行覆盖注册、恢复、丢失设备、限流和管理员重置。

### 5.9 `pdf_signing_operations`

- `operation_uuid`, `idempotency_key`, `idempotency_scope_key`, `actor_user_id`；
- `scope_type`: `source` / `workflow` / `request` / `document` / `legacy` / `quarantine`；
- `document_id`, nullable `source_upload_id`, nullable `workflow_id`, nullable `request_id`, nullable `legacy_context_id`, nullable `challenge_id`；
- `action`: `unsigned_finalize` / `prepare_create_fields` / `import_existing_signed` / `bind_existing_fields` / `fill_signature_field` / `legacy_finalize` / `lt_validation_data` / `document_timestamp` / `register_quarantine_artifact` / `destroy_quarantine_artifact`；
- `result_mode`: `materialize_revision` / `reuse_existing_revision` / `no_write_bind` / `quarantine_disposition`；
- immutable `operation_input_manifest`, `operation_input_manifest_hash`, `input_fingerprint`；
- `expected_source_revision_id`, `expected_source_sha256`, `signing_policy_version_id`, `policy_hash`, `config_bundle_hash`, `expected_certificate_fingerprint`, `appearance_manifest_hash`；
- 预留结果：`result_revision_uuid`, `target_file_id`, `target_file_path_prefix`, `expected_parent_revision_id`, `expected_revision_role`, `reserved_revision_number`；晋升后记录 exact `promoted_file_path`；
- `state`: `claimed` / `processing` / `recovery_pending` / `promoted` / `completed` / `failed` / `irreversible_failed` / `quarantined`；
- `stage`: `awaiting_dispatch` / `db_only` / `java_call` / `recovery_query` / `staging` / `verifying` / `promoting` / `committing` / `done`；
- `lease_owner`, `lease_epoch BIGINT UNSIGNED NOT NULL DEFAULT 0`, `lease_expires_at`, `heartbeat_at`, `attempt_count`, `next_retry_at`；
- `started_at`, `completed_at`；
- nullable `parent_claim_id`, nullable `root_claim_id`；二者对产出 revision 的 operation 必须恰好一个非空；
- nullable `java_execution_epoch`, `adopted_java_execution_attempt_id`, `source_execution_epoch`, `java_result_sha256`；
- nullable `recovery_pending_since`, `recovery_deadline_at`, `outcome_decided_at`；
- `result_revision_id`, `response_fingerprint`, `error_code`, `error_retryability`；
- immutable `audit_context`, `audit_context_hash`。

`audit_context` 在事务 A 快照原始 IP、User-Agent（截断到固定上限）、transport correlation UUID、Sanctum auth context、actor id/name snapshot、reauthenticated_at、workflow/request/policy/appearance hashes。队列中的 AuditLogger 必须显式使用该快照，禁止从 worker 的 `request()` 推导。

`operation_input_manifest` 是事务 A 直接固化并由 schema version 标识的 Java 执行输入；其 JCS hash 等于 `operation_input_manifest_hash`，`input_fingerprint` 绑定 action/scope/source/field/plan/manifest/policy。Java 只读取这些 operation 快照，不追读可变 request/challenge/policy 关系，也不从 `audit_context` 解析安全决策。签名 POST 发送前，Laravel 在同一 fenced 更新中写 `java_execution_epoch=current lease_epoch`；采用旧 execution 结果时另外写 adopted attempt、source epoch 和结果 SHA，不能覆盖原执行身份。

scope FK 在物理列上允许 null，但由 action-specific CHECK + 单一 domain service 收紧：`fill_signature_field` 必须同时具备同 document 的 workflow/request/challenge、parent claim、expected source 和全部 policy/config/certificate/appearance 快照；`prepare_create_fields/bind_existing_fields` 必须有 workflow；source action 必须有 source；`lt_validation_data/document_timestamp` 使用 document scope + current published parent claim，不伪造 workflow/request；legacy/quarantine action 只能带其对应 context。任何跨 scope 多余 FK、缺失 required FK 或 document 不一致都在事务 A 拒绝。

相同 idempotency key + fingerprint 返回原结果/状态；不同 fingerprint 返回 409。事务 A 同时写 outbox，dispatcher 异步投递 job 并返回 202。worker 原子领取有期限租约，长步骤 heartbeat；job retry 复用原 operation。reconciler 只接管 lease 已过期且达到 `next_retry_at` 的 operation，活跃租约不得被读取 staging、晋升或清理。`promoted` 后只重放事务 B，禁止再次调用私钥。

错误码区分 `CLIENT_DISCONNECTED`、`PHP_WORKER_EXITED`、`JAVA_TIMEOUT`、`TSA_TIMEOUT`、`LEASE_EXPIRED` 和 terminal validation errors。所有会产出 revision 的 action 都经过同一 staging/promotion/reconciler；`bind_existing_fields`、exact-published `reuse_existing_revision` 和 quarantine disposition 是 no-write/no-new-edge operation，不创建新 parent/root claim。

#### 5.9.1 `pdf_operation_outbox`

- `operation_id UNIQUE`, `job_type`, `payload_hash`；
- `state`: `pending` / `dispatched`；
- `available_at`, `dispatched_at`, `attempt_count`, `last_error`。

operation 与 outbox 必须在同一事务 A 创建。outbox dispatcher 和 reconciler 都能重投 `claimed + no lease + pending/超时 dispatched`，queue job 仍以 operation lease 保证唯一执行。

#### 5.9.2 `pdf_java_irreversible_execution_guards`

任何跨组织私钥/HSM/TSA 不可逆边界的 Laravel operation 恰好一个 operation-level guard，跨全部 Java 实例与全部 lease epoch 争用同一行：

- `id`, `operation_id UNIQUE`, `operation_uuid UNIQUE`；
- `execution_kind`: `organization_signature` / `legacy_signature` / `document_timestamp`；
- `state`: `available` / `executing` / `completed` / `failed_post_sign_known` / `uncertain`；
- nullable `active_attempt_id`, `active_epoch`, `execution_key_hash`, `private_key_started_at`, `completed_attempt_id`；
- nullable `terminal_decision_source`, `terminalized_at`；`lock_version`, timestamps。

Guard 是“该 operation 是否已经越过私钥边界”的唯一权威事实；attempt 记录不能单独授权私钥。`completed/failed_post_sign_known/uncertain` 为永久终态，不能回到 available。只有 guard 仍为 available 且所有旧 attempt 都是可证明的 `failed_pre_sign`，新 epoch 才可创建并赢得 attempt。

#### 5.9.3 `pdf_java_irreversible_execution_attempts`

- `id`, `guard_id`, `operation_id`, `operation_uuid`, `execution_kind`, `lease_epoch`；
- `operation_input_manifest_hash`, `input_fingerprint`, `policy_hash`, `config_bundle_hash`, `expected_certificate_fingerprint`, `appearance_manifest_hash`, `execution_key_hash`；
- `state`: `claimed` / `executing` / `completed` / `failed_pre_sign` / `failed_post_sign_known` / `uncertain`；
- `private_key_started_at`, `completed_at`；
- nullable immutable `result_object_key`, `result_sha256`, `result_size`, `cms_sha256`, `validation_report`, `validation_report_hash`；
- `result_integrity_state`: `not_applicable` / `available` / `retired` / `breached`；
- `error_code`, `retention_until`, nullable `legal_hold_until`, nullable `bytes_deleted_at`, timestamps；
- unique `(operation_uuid, lease_epoch)`；`execution_key_hash=SHA256(operation_uuid|lease_epoch|operation_input_manifest_hash|policy_hash|config_bundle_hash)`。

进入私钥的唯一事务（MySQL `READ COMMITTED`）固定锁序：

1. `SELECT pdf_signing_operations ... FOR UPDATE`，确认 operation 仍为 processing/java_call、当前 `lease_epoch` 与请求一致、`java_execution_epoch` 一致，并逐字段比较 input/policy/config/certificate/appearance 快照；
2. 锁定 operation 的 exact parent/root logical claim，确认 `state=reserved` 且 `active_operation_id=当前 operation`；`fill_signature_field/document_timestamp` 使用 parent claim，legacy 初始输出可使用 root claim；
3. 按 `operation_id` `SELECT ... FOR UPDATE` guard；不存在时插入，duplicate-key 后重新读取并加锁；
4. 锁定/创建 `(operation_uuid, lease_epoch)` attempt；
5. guard 只有 `available`、attempt 只有 `claimed` 且没有任何 private-key boundary 证据时，才在同一事务把 guard 与 attempt 置 `executing`、写相同 active attempt/epoch/execution key 与 `private_key_started_at`；
6. 提交事务后才调用组织私钥/TSA。旧 epoch 即使先创建 attempt，只要未在 Laravel fence 有效时赢得 guard，就不能进入私钥；被新 retry operation 替换的旧 operation 即使迟到，也会因 claim active-operation 校验失败而被挡在边界外。

状态语义不可混用：

- `failed_pre_sign`：输入、source/field/policy 快照不匹配，或确定发生在 guard/private-key boundary 前的基础设施失败；attempt 终止，guard 保持 available；
- `failed_post_sign_known`：CMS/DocTimeStamp token 已生成但 `/Contents` 预留不足、私钥/TSA 明确失败、产物验证明确失败，或已确定没有可发布结果但不可逆 provider 已被调用；guard 与 attempt 同时永久终止，Laravel operation/claim 进入 `irreversible_failed`，原 lineage 不可重试；
- `uncertain`：guard 仍 executing 时进程在私钥/HSM/TSA 调用中崩溃、provider 结果归属未知，且 winner protocol 无法证明 completed/known-failed；guard/attempt 永久隔离，Laravel 才进入 `OUTCOME_UNKNOWN`。已 completed ledger 的 object 缺失/不一致是 result integrity breach，不得把 guard 重分类为 uncertain；
- `completed`：唯一不可变结果已经按 5.9.4 完成 durable commit。

技术上需要重做时，`failed_post_sign_known` 和 `uncertain` 都不得复活原 claim/operation；只有按第八章创建新 workflow generation/new lineage，复用相同 logical signing acts 并对未完成 act 建立新 attempt。前者是已知不可逆失败，后者还必须完成人工结果归属裁决。

#### 5.9.4 不可逆 execution ledger 与 result 的生产持久化 profile

- Schema 只由 Laravel migration 管理；Java 禁用 Hibernate/JPA auto-DDL，使用 Spring JDBC + MySQL Connector/J；Phase 2 明确新增依赖、datasource/health 配置和 migration contract；
- Java 使用独立最小权限数据库账号：只读并加锁 operation 执行快照和 logical claim，读写 guard/attempt；不得写 workflow/request/revision/publication 表；
- 事务隔离固定 `READ COMMITTED`；所有 Java 签名边界事务使用 operation → logical claim → guard → attempt 的相同锁序，deadlock 只允许在私钥边界前按同 execution key 有界重试；
- 结果固定写入私有 S3-compatible object store/bucket `pdf-java-executions`，开启 TLS、server-side encryption、versioning、访问审计和 deny-public；Java 是唯一写者，Laravel 只能经 Java 内部 API 读取；
- immutable key：`java-executions/{operation_uuid}/{lease_epoch}/{result_sha256}.pdf`，数据库不向调用方暴露 bucket/path；同 key 使用 conditional PUT (`If-None-Match: *`)，已存在时必须流式复核 SHA/size 才能采用；
- 完成顺序：本地 execution-scoped temp 写完并 `fsync` → 计算 SHA/size/validation report → conditional PUT → HEAD 后再流式读回并核对 SHA/size → 在同一 DB 事务按 operation → logical claim → guard → attempt 锁序确认 immutable execution snapshots 与 guard ownership 未变后写 result metadata并同时置 attempt/guard completed → 删除 temp。私钥边界已经赢得后，Laravel 可因 worker 恢复递增 lease epoch；完成事务不得要求 operation 当前 epoch 仍等于 source execution epoch，也不得因此丢弃已完成结果；
- `GET /internal/pdf/irreversible-executions/{operation_uuid}/{lease_epoch}` 只返回 JSON metadata/state；`GET .../{lease_epoch}/result` 仅对 completed、`result_integrity_state=available` 且 `bytes_deleted_at IS NULL` 的 attempt 流式返回 PDF，并带 `X-Result-Sha256`、`Content-Length`、`X-Validation-Report-Sha256`，不返回预签名 URL或 object key；合法退役后 status 仍返回 hashes/`result_bytes=retired`，result 返回 410 `EXECUTION_RESULT_RETIRED`；breached 返回 503 `EXECUTION_RESULT_INTEGRITY_BREACH` 并保持隔离；
- ledger completed、`bytes_deleted_at IS NULL` 但 object 缺失/读回不匹配是 integrity breach：guard/attempt 仍保持 completed（避免任何重复私钥调用），另将 `result_integrity_state=breached` 并告警。若 Laravel operation 尚未 promoted/completed 且仍依赖该 result，则把 operation/claim 隔离；若业务 operation 已 completed，则其终态不可回退，创建独立 integrity incident、重验正式 revision bytes/hash/signatures，仅在正式 revision 自身失败时才按 publication integrity-withdrawn 合同处置。合法 sweeper 必须在删除全部版本成功后才原子写 `bytes_deleted_at` 和 `result_integrity_state=retired`，失败保持 available/null 并重试。object 存在但 ledger 未 completed 时，reconciler 只有在 object metadata、operation 快照、guard ownership、attempt execution key 全匹配且能重新完成全量结果验证时，才可在锁序事务中原子补成 completed，否则登记 orphan object 并隔离；
- bucket 容量按“20 MB × 峰值签署次数 × 180 天 × 1.5 安全系数”监控，70/85/95% 告警；数据库每日备份、bucket versioning/跨故障域备份按生产 RPO/RTO 执行；Laravel retention projector 在 operation/claim 人工终结事务后设置 `retention_until >= terminal_at + 180 days` 并同步 legal hold，未终结或同步失败一律视为 hold；bucket 对该 prefix 禁用独立按龄删除，只允许 Java result sweeper 锁定 attempt、确认 completed/终态、`now > max(retention_until, legal_hold_until)` 后删除所有版本并写 `bytes_deleted_at`。execution/guard/hash/CMS hash/validation report 按主审计保留期保存。

Laravel 对 `organization_signature/legacy_signature/document_timestamp` 的不可逆 POST 从 request body 开始发送后都禁止 transport retry。响应丢失只查询原 `java_execution_epoch` 的 status/result；guard 暂不可判定时进入 `recovery_pending`，不得直接重发或永久终态化。故障注入覆盖跨 epoch 并发、私钥/TSA 前中后崩溃、object PUT 前后、DB completed 前后、completed 响应丢失、object missing/orphan、stale epoch 和 completion-vs-uncertainty 竞争。

#### 5.9.5 Java completion 与 uncertainty terminalization 的原子 winner protocol

`recovery_pending` 是持久化非终态：保留原 operation/claim active ownership、source、appearance、execution result 和 legal hold；status 返回 202 + `RECOVERY_PENDING`，禁止创建 retry/new generation。Java status API/network 暂不可用但共享 ledger DB 可用时，Laravel 只锁 operation 置 recovery_pending/recovery_query 并持续恢复；共享 DB 本身不可用时无法安全写任何终态，只告警并等待 DB 恢复，不能用本地推断替代 ledger。

两个终态竞争者必须使用完全相同的 `READ COMMITTED` 事务和锁序：operation → exact parent/root claim → guard → active attempt。

**Java completion 赢：**

1. 要求 operation=`processing|recovery_pending`、claim=`reserved` 且 active operation 匹配、guard/attempt=`executing`；
2. durable object 已完成 conditional PUT/read-back；
3. CAS guard/attempt `executing → completed`，写 result metadata、`terminal_decision_source=java_completion`；
4. 之后任何 uncertainty terminalizer 读到 completed 都必须退出并转入 historical-result adoption；不得再写 OUTCOME_UNKNOWN。

**Uncertainty terminalization 赢：**

1. 只有超过 immutable recovery deadline，并有“provider outcome 无法确定”的审计证据时才能启动；临时 HTTP 失败本身不够；
2. 同一事务要求 guard/attempt 仍为 executing，CAS 二者 `executing → uncertain`，同时 operation=`quarantined`、claim=`uncertain`；organization workflow action 还把对应 request/workflow=`failed` 并清 document active workflow，legacy/DocTimeStamp 等无 request/workflow 的 action 只更新其实际 scope 与旧 published pointer 保持不变；写 `terminal_decision_source=recovery_timeout` 与 outcome timestamp；
3. Java completion 事务的 executing→completed CAS 随后必须失败。已经生成/上传的 late object 不得改变 guard，按原 operation/attempt/epoch 登记 `pdf_quarantine_artifacts`，保留为 forensic-only、永不发布；
4. 人工 adjudication 完成前禁止 new generation。裁决必须同时检查 guard、HSM/TSA provider log、late object prefix、正式 revision 链和 publication events；裁决完成后才能按技术恢复合同创建新 lineage。

若竞争事务读取 guard 已为 `failed_post_sign_known/uncertain/completed`，只能执行该终态对应的 Laravel 投影，不得重新分类。故障注入必须覆盖 Laravel↔Java 网络分区、双方同时竞争、completion 先赢、terminalizer 先赢、late object 已写而 guard uncertain、人工 adjudication 前后迟到进程恢复。

### 5.10 扩展 `pdf_files` 为不可变修订

- 保留既有 `file_id`；新修订生成 `REV-<revision_uuid>`；
- 新增 `document_id`, `revision_uuid`, nullable `lineage_uuid`, nullable `workflow_generation`；finalized planning revision 在 workflow 创建前二者为 null；
- `parent_pdf_file_id`, `revision_number`, `operation_profile`；
- `revision_role`: `finalized_unsigned` / `prepared` / `imported_signed_base` / `approval_signature` / `organization_seal` / `lt_validation_data` / `document_timestamp` / `legacy_signed_output`；
- `revision_created_at NOT NULL`, `signed_at NULLABLE`；
- 继续使用现有 `file_path`；保留 `sha256_hash`, `md5_hash`, `file_size`, `source_sha256_hash`；
- 通用 `revision_manifest`, `revision_manifest_hash`, `parent_manifest_hash`；prepare 特有基线另存 `prepared_baseline_manifest`；imported base 保存 `embedded_signatures_manifest`；
- `integrity_state`: `ready` / `quarantined` / `unavailable` / `incomplete_evidence`；
- `disposition`: `active` / `abandoned` / `superseded` / `published`。

选择 **materialized-only revision**：事务 A 不创建 `pdf_files`，只在逻辑 claim/operation 预留 revision UUID/编号/路径/角色；staging、验证和 rename 完成后，事务 B 才插入正式 revision 行。失败尝试只存在于 claim/operation/outbox/audit，不写零摘要占位、不留下 processing revision。已晋升但事务 B 冲突或 orphan final **统一进入 `pdf_quarantine_artifacts`**，不得插入正常 `pdf_files`。

legacy 行 `revision_created_at=signed_at`。finalized/prepared/imported_signed_base 的 `signed_at=null`；imported 的每个历史签名时间和信任结论只放 `embedded_signatures_manifest`。业务签名 revision 的 `signed_at` 是本次 CMS 生成时间；LT/DocTimeStamp 不伪装成业务签署时间。台账默认按 `revision_created_at` 排序，签名筛选单独使用 nullable `signed_at`。`signedDownloadName()` 只用于 `published` 签名成品；planning/prepared/imported base 仅限内部授权下载并使用明确草稿命名。

`ready` 只表示文件完整；公开必须同时满足 `disposition=published` 且被 document 发布指针引用。`revision_uuid → file_path + sha256_hash` 永久不可变。业务报告编号只存 document/`cover_report_number`，旧 `X-Final-File-Id` 对新修订返回 `REV-<revision_uuid>`，新客户端读取 `X-Pdf-Revision-Id`。

### 5.11 MySQL 约束矩阵

- entity UUID（document/intake/workflow/act/binding/request/field/slot/appearance/challenge/operation/policy/publication event/revision）使用 `CHAR(36) CHARACTER SET ascii COLLATE ascii_bin` 并在各自 entity 上 unique；`integrity_incident_uuid` 是 withdrawn/restored event group identity，不另建空壳 entity；workflow 的 `lineage_uuid` unique，但 revisions 上重复的 `lineage_uuid` 只建普通 index；
- `document_public_id` 使用 CSPRNG 生成至少 128 bit 的 base64url 值，`CHAR(22) ascii_bin UNIQUE NOT NULL`，不得使用报告编号；
- documents 的 `(business_identity_scope_key, business_identity_type, business_identity_value_hash)` UNIQUE；normalization version/normalized value/hash 必须同时非空；`duplicate_of_document_id` 是同表 FK 且不得自指，duplicate reason 必填；integrity hold active 时禁止 workflow activation、download 与 pointer CAS；
- `pdf_files` 增加 unique `(id, document_id)` 作为所有跨 document revision 复合外键的被引用候选键；`document_id` 对新 revision 必须非空，legacy backfill 在启用 FK 前完成 document 归属；
- source intakes：`intake_uuid UNIQUE`、unique `(actor_user_id, idempotency_key)`；requested/resolved document/revision/workflow 与 duplicate relation 使用正式 FK；pending ingress key + SHA + size + inspection/candidate-set/authorization hash 不可修改；create-new resolve 可且仅可把 business-identity tuple 从全 null 一次写为全 non-null并纳入 resolution fingerprint，之后不可改；recover 必须目标 document、0 match 不得 resolve/create，import/create-new 按各自候选策略；只有 `pending_resolution → resolved/expired/cancelled`；
- source uploads：`source_uuid UNIQUE`、`document_id NOT NULL` FK、unique `(document_id, source_generation)`；每个 source 只绑定一个 document，但 document 可有多个 generation；intake resolve、授权/消歧和 source 归属在同一事务完成；operations 的 `document_id` 必须与 source/workflow/request scope 一致；
- publication events 的 `replacement_document_id` FK 到 documents，`related_publication_event_id` FK 到同表；integrity withdrawn/restored 必须带同一 incident UUID，restored 必须关联同 document/revision 的 withdrawn；domain transaction 阻止自替代和跨越未发布 document 的公开关系；
- workflows：unique `(document_id, workflow_generation)`、unique `(id, document_id)`、`lineage_uuid UNIQUE`；`origin_type=source_upload` iff source nonnull，`existing_revision` iff source null；技术 recovery 继承 signing plan generation，业务重规划递增它；base/planning/prepared/current 使用复合 FK `(revision_id, document_id) → pdf_files(id, document_id)`；`recovery_of_workflow_id` 必须同 document 且指向 terminal failed/cancelled workflow；
- signing acts：`logical_signing_act_uuid UNIQUE`、unique `(document_id, signing_plan_generation, field_name)`、unique `(document_id, signing_plan_generation, semantic_role, sequence)`、unique `(id, document_id)`；completed revision 与 act document 使用复合 FK；domain transition 只允许 `planned→deferred/completed/permanently_skipped/cancelled`、`deferred→completed/permanently_skipped`，终态不可回退；
- workflow-act bindings：`act_binding_uuid UNIQUE`、unique `(workflow_id, signing_act_id)`；actionable 恰好对应 request+field，prepared_deferred 对应 field 且无 request，inherited completed/skipped 二者均不存在；source workflow/request/revision 与当前 act 必须同 document/plan generation；
- requests：`attempt_uuid UNIQUE`、`signing_act_binding_id UNIQUE`、unique `(workflow_id, sequence)`、unique `(workflow_id, signing_act_id)`、unique `(id, workflow_id)`；act/binding/request 必须同 workflow document/plan generation；复合 FK `(predecessor_request_id, workflow_id) → (id, workflow_id)` 保证前驱同 workflow；冻结事务验证前驱 sequence 更小并将计划置为不可变，从而阻断环；
- requests 的 expected/completed revision 使用 `(revision_id, document_id) → pdf_files(id, document_id)`，并校验 `document_id` 与 workflow 一致；
- fields：`request_id UNIQUE NULLABLE`、`signing_act_binding_id UNIQUE`、unique `(workflow_id, field_name)`、unique `(workflow_id, signing_act_id)`；nullable request 只允许 prepared_deferred binding；field/request/actionable binding 引用同一 act；slots：unique `(field_id, widget_index)`；
- challenges：`challenge_uuid UNIQUE`；operations：`operation_uuid UNIQUE`、`challenge_id UNIQUE NULLABLE`；
- operation 写入非空 `idempotency_scope_key=SHA256(scope_type|scope_id|actor_or_system)`，unique `(idempotency_scope_key, idempotency_key)`，避免 nullable actor/system operation 绕过；
- operations 的 `lease_epoch` 只允许领取/接管/quarantine CAS 单调递增；recovery_pending 保持原 claim ownership 且禁止新 operation；`result_mode=materialize_revision` 时 CHECK/domain invariant 要求 `parent_claim_id` 与 `root_claim_id` 恰好一个非空；reuse/bind/quarantine disposition 二者都为空；
- Java irreversible guard：`operation_id UNIQUE`/`operation_uuid UNIQUE`；attempt unique `(operation_uuid, lease_epoch)`，attempt execution_kind 必须等于 guard；operation、claim、guard、attempt 使用正式 FK；completed/failed_post_sign_known/uncertain guard 不可回退；completion/uncertainty 终态都固定 operation → logical claim → guard → attempt 锁序，并验证 claim active operation；
- MySQL 8 CHECK：`finalized_unsigned/prepared/imported_signed_base/lt_validation_data/document_timestamp` 的 `signed_at IS NULL`，`approval_signature/organization_seal/legacy_signed_output` 的 `signed_at IS NOT NULL`；迁移前验证生产 MySQL 确实 enforce CHECK；
- 新 materialized row 若 integrity=`ready/quarantined`，CHECK 要求 `file_path`、`sha256_hash`、`file_size`、`revision_manifest_hash` 全部非空；`unavailable/incomplete_evidence` 仅用于 legacy/已物化后异常，不允许作为事务 A 占位；
- `revision_number` 是 document 全局单调序号，由锁定 `pdf_documents.next_revision_number` 分配；unique `(document_id, revision_number)`；
- documents 的 `published_revision_id` 使用复合 FK `(revision_id, document_id) → pdf_files(id, document_id)`；ready/published 状态仍由发布事务锁定 document 并校验；
- documents 的 nullable `(active_workflow_id, id)` 使用复合 FK 指向 workflows `(id, document_id)`；workflow 创建事务先锁定 document，要求 integrity hold 非 active，并只允许 `active_workflow_id IS NULL` 或旧 workflow 已在 completed/rejected/cancelled/failed 终态，再以同一事务写新 pointer；所有终态转换原子清除 pointer；
- MySQL 无法用 declarative CHECK 表达跨行无环和状态 FK，相关不变量集中在单一 domain service/事务中，并以并发数据库集成测试证明，不散落在 controller。

#### 5.11.1 `pdf_revision_parent_claims`

- `id`, `lineage_uuid`, `parent_revision_id NOT NULL`, `child_revision_uuid`, `reserved_revision_number`, `target_file_id`, `target_file_path_prefix`；
- nullable `claim_owner_request_id`, `claim_owner_action`, `active_operation_id`；
- `state`: `reserved` / `retryable` / `irreversible_failed` / `uncertain` / `committed`；
- unique `(lineage_uuid, parent_revision_id)`，`child_revision_uuid UNIQUE`。

parent claim 表示一条**逻辑修订边**，不是一次 operation attempt；所有历史 attempt 由各自 operation 永久保留并指向同一 claim。事务 A 首次创建 `reserved` claim；同 operation 的队列重投复用它。签名 POST 一旦开始发送不做网络重发，响应恢复只查询 Java execution ledger。

- 尚未调用私钥/不可逆 writer、没有 verified staging 且可证明没有产物时，失败把 claim 置 `retryable`；新 idempotency key 可创建新 operation，原子替换 `active_operation_id`，但继续使用同一 `child_revision_uuid`、revision number 和 target path prefix；
- 私钥已调用且结果明确失败时置 `irreversible_failed`；outcome unknown 时置 `uncertain`；已有 verified staging 时也不得创建新 operation，只允许原 operation/reconciler 在 fencing 合同下继续；
- promoted 或 materialized ready 后置 `committed`；事务 B 后续冲突不回退该状态，物理产物转 quarantine artifact；
- `irreversible_failed/uncertain/committed` 永不退回 retryable，即使 child 后续 superseded/abandoned 也继续占用父节点；
- `recovery_pending` 保持 claim reserved/active operation 不变；只有 5.9.5 uncertainty terminalization 事务赢得 guard 后，才使 request/workflow failed、operation quarantined、claim uncertain。人工 adjudication 前禁止 new lineage；
- claim 行和唯一键永不删除。只有 `retryable` 可由新 operation 复用，因此既保留永久防分叉，也允许可证明处于 pre-irreversible 状态的合法业务重试。

#### 5.11.2 `pdf_document_root_claims`

- `id`, `document_id`, nullable `source_upload_id`, `root_action`, `root_generation`；
- `active_operation_id`, `reserved_revision_uuid`, `reserved_revision_number`, `target_file_id`, `target_file_path_prefix`；
- `state`: `reserved` / `retryable` / `irreversible_failed` / `uncertain` / `committed`；
- unique `(document_id, root_generation)`；有 source 时再加 unique `(source_upload_id, root_generation)`。

document 尚无 revision 时的 `unsigned_finalize`、未匹配 `import_existing_signed` 和 legacy 初始登记不伪造 nullable parent claim，而是占用 root claim。首发 `root_generation=1`，同一 document 只能选择一个首根动作；exact published reuse 不创建 root claim。重试、uncertain 和 fencing 规则与 parent claim 相同。root revision 的 `parent_pdf_file_id` 必须为 null；所有真正 child revision 的 parent claim `parent_revision_id` 必须非空，禁止依赖 MySQL unique index 的 NULL 行为。

#### 5.11.3 `pdf_quarantine_artifacts`

- `id`, `operation_id`, `lease_epoch`, `reserved_revision_uuid`, `document_id`, nullable `parent_revision_id`；
- `file_path`, `sha256`, `file_size`, `revision_manifest`, `revision_manifest_hash`；
- `reason_code`, `disposition`: `held` / `adjudicated_registered` / `adjudicated_destroyed`；
- `evidence_manifest`, `evidence_manifest_hash`, `adjudication_operation_id`；
- `created_at`, `initiated_by_id`, `approved_by_id`, `approved_at`, `destroy_after`, `adjudicated_at`。

unique `(operation_id, lease_epoch, file_path)`；同一 operation 的多个迟到 epoch candidate 必须分别登记，不能相互覆盖。

事务 B 冲突、orphan final、fence 丢失后的迟到产物统一登记到此表和隔离目录，不进入普通台账/下载/发布指针。自动流程永不把它插入 `pdf_files`。

人工处置唯一采用“**登记原预留身份，仅供取证**”语义：

- 发起人需 `quarantine.adjudicate`，独立复核人需 `quarantine.approve`，二者不得为同一用户；复核冻结 reason、源/父摘要、claim/epoch、对象 diff、签名验证和证据 manifest；
- `register_quarantine_artifact` 使用原 `reserved_revision_uuid/number` 和原 logical claim，不创建新 parent/root claim；选择同一 reservation 的一个 epoch candidate，验证后把原 claim 置/保持 `committed`，其他 candidate 继续 held 待销毁；
- 登记时插入 `pdf_files(integrity_state=quarantined, disposition=abandoned)` 并迁入只读 forensic path；`first_published_at=null`，永不进入 current/published pointer、普通下载、普通 workflow base 或 legacy verifier；
- 若业务决定重新使用内容，必须创建新 generation/new lineage 和新 revision UUID/number，原预留身份仍只作取证；
- `destroy_quarantine_artifact` 需要同样双人审批、至少 7 天延迟、无 legal hold 且不再被 execution/operation 取证引用；到期任务验证 SHA/path 后删除并写不可变 destruction audit；
- 所有处置 operation `scope_type=quarantine/result_mode=quarantine_disposition`，`parent_claim_id/root_claim_id=null`，禁止直接推进发布指针。预留序号在销毁时保持审计空洞，在登记时只对应 quarantined/abandoned forensic revision。

### 5.12 业务 manifest canonical hash

`finalization_manifest_hash`、`inspection_manifest_hash`、`placement_plan_hash`、`field_manifest_hash`、`appearance_manifest_hash`、`policy_hash`、`revision_manifest_hash` 和 `parent_manifest_hash` 统一使用 versioned canonical schema：

- payload 必含 `schema_id` 和 `schema_version`；按 RFC 8785 JCS 编码后计算 SHA-256 lowercase hex（固定 64 chars）；
- UUID、数据库 ID、页码、长度、时间和 PDF object ref 均为字符串；object ref 格式固定为 `"<object-number> <generation> R"`；
- 坐标/尺寸不使用 JSON floating number，统一为无指数、固定 6 位小数的十进制字符串；`0.1` 必须编码为 `"0.100000"`；
- null、字段缺失和空数组具有不同语义；每个 schema 固定 required/optional 字段，禁止解析端自行填默认值；
- certificate fingerprints 规范为 lowercase hex 并按字节序排序；slots 按 `slot_uuid`、object entries 按 object number/generation、TSA endpoints 按冻结 failover order；其他数组顺序由 schema 明确；
- 拒绝重复 key、未知字段（除 schema 明确 extension map）、非法 Unicode、负零、非有限数值和超大 JSON number；
- PHP/Java 为每类 manifest 共用 golden vectors 与负向 vectors；schema/version/hash 任一不一致时 challenge 创建、Java 调用或 revision 晋升均 fail closed。

---

## 六、旧数据与公开验证迁移合同

### 6.1 回填

回填只比较当前磁盘字节与旧信任合同，绝不重定义历史摘要：

```text
read legacy sha256_hash + md5_hash + file_size
  → calculate current file hashes and size
  → compare without overwriting legacy values
```

处理规则：

- SHA-256、MD5、大小全部一致：创建 document 聚合，生成 revision 标识，`revision_number=1`、parent=null、role=`legacy_signed_output`、`revision_created_at=signed_at`、integrity=`ready`、disposition=`published`，并设置 published pointer；
- 文件不存在：integrity=`unavailable`，不得 published；
- SHA-256 或大小不一致：integrity=`quarantined`，阻断切换；
- 旧 MD5 有合法值但与当前文件不一致：integrity=`quarantined`，即使 SHA-256 一致也不得 published；
- 旧 MD5 缺失但 SHA-256 和大小一致：允许补充 MD5，但记录 `migration-derived` 来源和审计；
- 旧 `file_size` 为 null：integrity=`incomplete_evidence`，不得 published，进入人工证据处置；不能直接把当前大小写回后放行；
- SHA-256/MD5 含非 hex、长度错误、空白或其他异常格式：保留 raw 值，记录规范化候选和计算值，置 `incomplete_evidence` 或 `quarantined`，不得静默修正；大小写差异只可用于 compare normalization，不能覆盖 raw 值；
- 禁止用新计算 SHA 覆盖旧 SHA 后继续通过；
- migration report 逐行记录 raw old value、normalized comparison value、recalculated value、是否 migration-derived、文件状态、人工处置人和处理结果。

“全部历史文件零摘要差异”是切换硬门禁；异常必须人工取证和处置，不能由迁移脚本自动洗白。

历史回填默认“一条 legacy `pdf_files` = 一个 document + 一个 published revision”；不得仅按相同报告编号自动合并。只有存在可审计的旧父子关系证据时，才由单独迁移映射合并逻辑文档。

新建业务 document 必须使用 `report_number` identity。legacy 回填若报告编号在组织范围内唯一且有可靠来源，可规范化为 `report_number`；重复、缺失或证据不足时使用独立 `business_identity_type=legacy_file_id`、`business_identity_normalization_version=legacy_file_id_v1`，以 immutable 原 `file_id` 形成唯一 hash，`authoritative_report_number` 仅保留为展示/查询值。不得为通过 unique 而合并同号历史行、加随机后缀或伪造 duplicate relation；此类 document 若要进入新 workflow，须先经过单独身份归并/更正审批和可审计迁移。

dual-read 清单必须覆盖 PdfFile serializer、台账默认排序、`signed_at` 筛选、`signedDownloadName()`、下载响应头、公开验证 DTO 和审计日志，确保 unsigned/internal revision 不被旧代码当成签名正本。

既有 `file_id` 和下载地址继续有效，但只指向这一个历史修订，绝不改成“逻辑文档当前版本”的游标。

### 6.2 双标识公开验证

区分服务器登记查询与持有文件验证：

- **登记状态查询：** QR/URL 携带 `revision_uuid`，只展示服务器保存的修订状态和公开修订链；
- **持有文件验证：** 用户上传 PDF，服务端计算实际字节摘要、解析嵌入签名并与指定 revision 比较；
- **逻辑文档查询：** URL 携带 `document_public_id`，展示当前最新合法修订及修订链。

公开验证结果至少展示：

1. 是否精确命中登记修订；
2. 每个嵌入签名的密码学、证书、时间戳和权限结论；
3. 该修订是否为当前最新；
4. 是否存在更新的合法修订；
5. 后续修订是否被旧签名权限允许。

保留 `overall_valid` 作为旧客户端兼容字段，并固定其唯一含义为：**上传字节以 exact SHA-256 命中一个曾正式发布、当前未被 integrity-withdrawn 的 revision**。它不代表证书、时间戳、DocMDP/FieldMDP 或后续修订权限全部有效；任一分层结论异常必须由新字段和新 UI 明确展示，旧 UI 不得仅凭 `overall_valid=true` 显示“数字签名完全可信”。

GET 页面只能写“服务器登记的修订状态”，不能写“您持有的文件验证通过”。公开 DTO 使用显式 allowlist，只包含 revision/document public id、公开报告编号、摘要匹配结论、分层签名结论、修订时间和链路状态；禁止序列化 actor、内部 operation、文件路径、nonce、challenge 或完整审计数据。

document integrity hold active 时，曾发布页面以公开安全文案显示“文件完整性复核中，暂不可下载/继续签署”，并令 `overall_valid=false`；不得显示成“已撤销”。只有显式 revoked event 才显示法律/业务撤销。restored 后显示同一 revision 已恢复可验证服务及关联公开时间，不暴露事故内部原因或存储拓扑。

public exact revision 必须是当前 `published_revision_id`，或拥有 `first_published_at`/历史 `published` event 的 superseded/revoked revision。finalized unsigned、prepared、active intermediate、abandoned、quarantined、unavailable、incomplete evidence 和从未发布的 imported base 一律返回同样的 404，UUID 不是授权边界。

`/api/public/pdf/documents/{document_public_id}` 在 document 第一次 `published` event 之前同样统一返回 404，不确认 draft/signing document、报告编号或处理进度存在。revoked/superseded document 只有在曾发布后才能按公开政策展示历史状态；planning PDF 中二维码可提前存在，但 resolver 在首次发布前只返回统一“未登记”。

新 PDF 在 unsigned finalization 时只嵌入 `document_public_id` 的 logical-document URL；此时最终 revision UUID 尚不存在，严禁写 planning revision UUID 再解释为成品。exact revision URL 只在成品发布后通过下载页、响应头和验证结果提供。

#### 6.2.1 Legacy verifier 兼容适配器

- 查询候选只允许 `integrity_state=ready` 且存在历史 `published` event 的 revision；finalized/prepared/active/abandoned/imported base 均不得参与；
- exact revision verify 只比较 URL 已指定 revision 的保存摘要，不进行全库选择；
- generic legacy upload verify 以 exact SHA-256 查询**全部** published-ready 候选，禁止 `first()`、自然顺序、最早或最新隐式选一：0 条返回 `not_registered`，1 条正常返回，多条返回 `ambiguous_registration`；
- 多匹配只返回公开安全的候选 document/revision public IDs、报告编号消歧片段和公开状态；用户必须提供 revision/document 标识再执行精确验证，无权候选不得泄露；
- 记录身份只由 exact SHA-256 + 显式消歧决定，禁止使用 MD5 fallback 找记录；
- MD5 仅在 exact SHA-256 已命中后作为兼容附加比较，不得改变记录身份；
- 旧 verifier 退役前，每个新 published revision 在事务 B 流式生成并保存 MD5；若未来停止生成，必须先发布并迁移到明确的新兼容规则，不能让 MD5 null 的合法成品被旧客户端判失败；
- revoked/integrity-withdrawn revision 的 `overall_valid=false`；superseded 但字节完整的历史 revision 可 exact match，同时通过独立字段显示“非当前版本”；
- 证书、时间戳或权限无效时分层结果必须为 invalid/indeterminate；兼容字段不得覆盖这些结论。

### 6.3 双读与退役

- 旧 ledger/search/download/audit 继续按 `file_id` 读取；
- 新接口优先使用 `revision_uuid` 或 `document_public_id`；
- 新结果同时回填旧响应字段与新修订字段；
- 通过访问日志确认旧消费者迁移完毕，再设 removal version/date；
- removal gate：连续一个发布周期无旧客户端、旧链接回归通过、数据回填审计为零差异。

旧 PDF 中已经固化的报告编号二维码不可修改，必须长期保留 resolver：

```text
legacy /certificate-query?query={report_number}
  → find historical revisions by authoritative cover_report_number
  → one match: redirect to registered revision page
  → multiple matches: require PDF upload or show public-safe disambiguation
  → no match: show not registered
```

---

## 七、可恢复提交协议

文件 rename 与数据库 commit 跨资源，不能称为原子提交。`unsigned_finalize`、`prepare_create_fields`、签署、legacy finalization、LT validation data 和 DocTimeStamp 等所有产出 revision 的 action 都执行同一状态机。

### 7.1 正常路径

**事务入口：幂等优先**

1. 按 scope + actor + idempotency key 查询并锁定 operation；
2. operation=`completed`：同 fingerprint 返回已保存响应，不同 fingerprint 返回 409；不再检查已消费 challenge；
3. operation=`claimed/processing/recovery_pending/promoted`：同 fingerprint 返回 202 和同一 status URL（recovery_pending 返回固定 recovery 状态），不同 fingerprint 返回 409；
4. operation=`failed`：同 fingerprint 稳定返回原终态错误。产出 revision 的 action 只有在 `error_retryability=retryable_with_new_operation` 且 logical claim 已在可证明的 pre-irreversible 阶段置为 `retryable` 时，才允许新 idempotency key；签署动作还必须创建新 artifact/challenge。no-write bind 只有在原数据库事务完整回滚、未留下任何 field/slot binding 且错误显式可重试时才允许新 operation；
5. operation=`irreversible_failed`：同 fingerprint 稳定返回原已知不可逆错误，不同 fingerprint 返回 409；永不重新进入事务 A，原 claim/lineage 不可复用；
6. operation=`quarantined`：返回不可自动重试的人工处置状态；
7. 仅 operation 不存在时进入事务 A。

**数据库事务 A**

1. 锁定 action 对应的 source/workflow/request 和父修订；
2. 对签署 action，确认 request=`available`，且 request expected source、workflow current revision、父修订三者一致；
3. 对签署 action，锁定 challenge，核对用户、source、plan、field、appearance、policy hash、证书 fingerprint、认证方法、intent 和有效期；
4. 以条件更新原子消费 challenge、claim appearance artifact，并创建唯一绑定两者的 operation；非签署 action 不需要 challenge/artifact；
5. `result_mode=materialize_revision` 时获取 claim：child action 创建/复用 logical parent claim；未匹配的 `unsigned_finalize/import_existing_signed/legacy` 根动作获取 root claim。若这是 pre-irreversible failure 后的新 operation，必须先锁 claim，要求 `state=retryable`，再在同一事务 CAS `retryable → reserved`、替换 `active_operation_id`、创建 operation/outbox；root claim 同规则。影响行数不是 1 即冲突，旧 operation 不得恢复。exact-published reuse、bind 和 quarantine disposition 不创建 claim；**事务 A 不创建正常 `pdf_files` 行**；
6. 创建同事务 outbox，operation=`claimed`、stage=`awaiting_dispatch`；签署 request 进入 `signing`；
7. 提交事务 A，HTTP 返回 `202 + operation_uuid + status_url`。

只有 outbox dispatcher 投递 `RunPdfOperationJob`。worker 必须以 CAS 成功领取租约并原子递增 `lease_epoch` 后才进入处理；客户端断开不取消 operation。事务 commit 后进程退出也不会丢单，因为 outbox/reconciler 会重投 `claimed + no lease`。

**DB-only `bind_existing_fields`**

1. worker 领取 lease/epoch，state=`processing`、stage=`db_only`；
2. 锁定 workflow/imported revision，重新验证 manifest 与权限；
3. 使用 `lease_owner + lease_epoch + state + stage` CAS 原子写 binding/request/field/slot 与审计；late-seal 只能把目标 deferred act 映射为 actionable，不得新建 PDF 对象或更改其他 deferred/permanent acts；
4. 使用同一 fence 将 state=`completed`、stage=`done`，不创建 revision、不写 staging、不进入 promoted；
5. 失败稳定记录 error；retryable failure 仍由同 operation job retry，terminal failure 按状态表处理。

**DB-only exact published reuse**

1. 锁定 source、目标 document 和 exact published revision，重算并确认 source SHA/size；
2. 再次验证 actor 对该 document 的导入/恢复权限，匹配多条时必须已经显式消歧；
3. source 置 consumed，operation=`completed/result_mode=reuse_existing_revision` 并返回既有 revision；
4. 不创建 root claim/revision/publication event，不改变 published pointer。

**Quarantine disposition**

`register/destroy_quarantine_artifact` 只按 5.11.3 的双人审批执行。register 可用原 reservation 插入 quarantined/abandoned forensic row；destroy 只删除隔离 bytes。二者均不创建新 claim、不进入普通发布事务。

**Fencing 合同**

1. 每次首次领取或过期接管 lease 都在同一 CAS 中令 `lease_epoch=lease_epoch+1`；Java metadata、响应、job context 和 staging manifest 必须携带 exact epoch；
2. staging 路径固定为 `staging/{operation_uuid}/{lease_epoch}/candidate.pdf`；晋升目标固定为不可变 epoch path `revisions/{result_revision_uuid}/{lease_epoch}/document.pdf`。不同 epoch 绝不共享 staging 或晋升路径，事务 B 只把当前 fence 的 exact epoch path 写入 `pdf_files.file_path`；
3. 进入 Java/私钥调用前、收到 Java 结果后、验证前、进入 promoting 前、rename 前、fsync 后和写 promoted 前，都必须重新读取或 CAS 校验 `operation_id + lease_owner + lease_epoch + state + stage`；
4. 所有状态更新使用等价谓词：`WHERE id=? AND lease_owner=? AND lease_epoch=? AND state=? AND stage=?`；影响行数不是 1 即视为 fence 丢失；
5. fence 丢失的 worker 只能清理自己 epoch 的 staging，禁止读取其他 epoch、把其 epoch candidate 登记为 revision、写 quarantine 或推进数据库；其迟到 Java 响应只能记录为 fenced-out telemetry。即使旧 worker 在极端暂停后写出自己的 epoch path，也只能成为 orphan candidate，由独立 scanner 隔离，不能阻塞/覆盖新 epoch；
6. 私钥/TSA 边界后的取消、人工 quarantine 或 OUTCOME_UNKNOWN 不允许只更新 Laravel fence；必须先按 5.9.5 赢得 guard uncertainty terminalization，再递增 epoch/清空 lease owner。guard completed 时取消 CAS 失败并转 adoption；guard 暂不可判定时只进入 recovery_pending；
7. 阻塞 Java hard timeout 由 immutable policy 固定，必须满足 `java_hard_timeout + 60s safety <= 当前 lease 剩余时间`；调用前 CAS 延长 lease。若供应商/TSA 无法满足有界 timeout，则改为 Java 异步 operation + polling，Laravel 在轮询期间持续 heartbeat；
8. rename 临界区开始前，worker 先以 fence CAS 进入 `stage=promoting` 并把 lease 延长至足以覆盖 rename + file fsync + directory fsync 的有界窗口；未取得该 CAS 禁止碰 epoch 晋升路径。

**文件生成与晋升**

对 `fill_signature_field/legacy_finalize/document_timestamp`，worker 对当前 operation/epoch 只允许发送一次对应不可逆 POST；HTTP 响应丢失时转为轮询 execution status/取回同一 completed result。ledger 暂不可判定时进入 `recovery_pending`；只有 winner protocol 把 guard 终态化为 uncertain 后才投影 `OUTCOME_UNKNOWN`，任何情况下都不得重发不可逆请求。

1. Java 输出流只写入当前 `operation_uuid/lease_epoch` staging；正常响应 epoch 必须与当前 fence 相同。若 reconciler 采用已完成的历史 attempt，则必须先按 7.2 记录 adopted attempt/source epoch/result SHA，再把该 result 流入**当前** fence staging；此时 result header 保留 source execution epoch，不能冒充当前 Java 执行；
2. `fsync(staging_file)` 并记录大小、SHA-256；旧 verifier 兼容期在同一流中同时计算 MD5，确保任何可能 published 的新 revision 均有兼容摘要；必要时同步 staging parent；
3. Java 与 Laravel 双重验证修订、签名、实际 policy/证书/算法/TSA、对象 diff 和摘要；
4. 取得 `stage=promoting` fence CAS 后，在同一文件系统内 rename 到当前 epoch 的不可变晋升路径；路径已存在即 quarantine，不覆盖；
5. `fsync(final_parent_directory)`，确认目录项崩溃后可恢复；
6. 只有目录同步成功后，operation 才标记 `promoted`、stage=`committing`，记录实际摘要、大小和 revision manifest；此时仍没有正式 `pdf_files` 行。

若部署文件系统不能保证上述语义，禁止使用 rename profile，改为对象存储“写入新 immutable key → 服务端完整性校验 → DB 指针切换”协议。

**数据库事务 B**

1. 以当前 lease epoch fence 再次锁定 action scope、logical claim 和父修订/root slot；
2. 对 workflow action，再次确认父修订仍是 `current_revision_id`；
3. 核对当前 fence 的 `promoted_file_path` 存在且摘要、大小、manifest 与 operation 记录一致；其他 epoch candidate 不参与；
4. 使用 claim 预留 UUID/number 和当前 epoch promoted path 插入 materialized `pdf_files` 行，integrity=`ready`，并把 parent/root claim 置 committed；
5. 签署 request 置 `signed`，对应 signing act 以 `planned|deferred → completed` CAS 绑定本次 revision，appearance 置 `consumed`；workflow CAS 推进 current revision；若存在下一 request，原子绑定新 revision id/SHA 后再置 `available`；
6. 按领域终态矩阵决定是否发布；未到终态 revision 保持 `active`；
7. AuditLogger 显式使用 operation.audit_context 写关键审计；operation 置 `completed`、stage=`done` 并保存 result revision/response fingerprint；
8. 提交事务 B。

若事务 B 发现父修订/root slot 已变化，使用 fence 将 operation 置 `quarantined`，logical claim 保持不可回退的 `committed`；已晋升字节只登记到 `pdf_quarantine_artifacts` 并移动至隔离目录，不插入 `pdf_files`，不能形成新分支或下载。

### 7.2 Reconciler

定时任务和启动恢复只扫描无租约或租约过期的 operation；活跃 lease 必须跳过：

| State/stage | Contract |
|---|---|
| `claimed + no lease` | 根据 transactional outbox 投递/重投 job，返回 202 |
| `claimed/processing + valid lease` | 不接管；status API 返回 202 与 heartbeat |
| `recovery_pending` | 保持 claim/source/appearance/result hold；读取共享 guard 并按 5.9.5 决定 adoption、known failure 或 uncertainty；不创建新 operation |
| expired `processing` before `java_call` | 原子接管 lease，从同 operation 安全重试 |
| expired `processing/java_call` + no verified staging | 按下方 Java-first 恢复协议处理；禁止仅因本地无 staging 就判 `OUTCOME_UNKNOWN` |
| expired `processing` + complete staging | 原子接管，校验 manifest 后继续 verifying/promoting |
| `promoted` + final exists | 原子接管，只重放事务 B，禁止重新调用 Java/私钥 |
| `promoted` + final missing | 递增 fence，`quarantined` + 告警，人工核对目录持久化，不自动重签 |
| `completed` | 同 key 返回保存结果 |
| `failed` | 同 key 返回保存错误；revision action 仅 `claim=retryable` 可用新 key，no-write 仅完整回滚且显式可重试时可新建 operation |
| `irreversible_failed` | 同 key 返回已知不可逆错误；claim 永久 `irreversible_failed`，原 operation/lineage 不重试 |
| `quarantined` | 禁止自动重试，等待人工 disposition |
| materialized ready 后文件缺失/哈希不符 | revision 置 unavailable/quarantined，按发布状态矩阵撤销公开可用性 |
| orphan final | 只登记 `pdf_quarantine_artifacts`、移入隔离目录并告警，不插入 `pdf_files` |

`java_call + no verified staging` 必须先读取 operation 中持久化的 `java_execution_epoch`，查询原 Java attempt，随后在锁定 operation/claim 的恢复事务中递增 Laravel fence并记录处理决定：

| Java status | Java-first recovery |
|---|---|
| `completed` | 流式取得原 result；逐项核对 operation UUID、source execution epoch、operation input manifest/policy/config/certificate/appearance hash、result SHA/size/validation-report hash。匹配后记录 `adopted_java_execution_attempt_id/source_execution_epoch/java_result_sha256`，把同一 bytes 写入当前 epoch staging 并继续双重验证；绝不重签 |
| `executing` | 新 Laravel epoch 只拥有观察/晋升权，不拥有私钥权；operation 进入/保持 recovery_pending，按 immutable policy 有界轮询。超过 recovery deadline 且仍无法确定时，只能通过 5.9.5 原子 uncertainty terminalization；不能单表写 OUTCOME_UNKNOWN |
| `failed_pre_sign` | guard 必须仍为 available，且审计能证明请求没有越过 private-key boundary；claim 才置 `retryable`。新 operation/epoch 可按 logical claim 规则安全执行，旧 POST 不重放 |
| `failed_post_sign_known` | operation/claim=`irreversible_failed`；organization workflow action 另置 request/workflow failed，其他 action 保持旧 published pointer；不重发 POST。技术恢复只能 new generation/new lineage |
| `uncertain` | 仅在 guard/attempt 已由 winner transaction 原子 uncertain 后，投影 operation=`quarantined/OUTCOME_UNKNOWN`、claim=`uncertain`；organization workflow action 另置 request/workflow failed，其他 action 按实际 scope 投影且保持旧 published pointer；等待人工裁决 |
| attempt 不存在 | 只有共享 DB 可证明从未 claim 且 transport 在 body 前失败，才按 pre-sign retry；request 可能送达时进入 recovery_pending |
| Java API 不可用、共享 ledger 可用 | 直接读取/锁 guard；保持 recovery_pending，按 winner protocol 决定 |
| 共享 ledger DB 不可用或 delivery unknown | 不能提交任何终态；保持既有 reserved claim 的排他性并告警，DB 恢复后先写 recovery_pending，再执行 winner protocol |

历史 completed attempt 可以被当前 Laravel epoch 采用；采用的是其不可变结果，不是转移 guard ownership。下载 result 前后各查询一次 metadata，要求 completed attempt id/hash/size 未变化。采用事务与当前 staging manifest 同时保存 source/current epoch，随后每个 verify/promote CAS 仍只使用当前 Laravel fence。

任何 epoch 晋升路径都不覆盖、不复用、不就地修复。reconciler 的每次动作均使用 operation audit context 写审计并保持幂等。故障注入必须覆盖 commit→outbox dispatch、completed 响应丢失与跨 epoch adoption、Java outcome unknown、rename 成功但 final directory 尚未持久化和 promoted→事务 B。

### 7.3 领域终态与发布矩阵

所有推进都锁定 document/workflow/request/current revision；不得由定时器按“最新 ready”推导。workflow activation 还必须在 document 锁内验证/设置唯一 `active_workflow_id`，不能先插 workflow 后异步修 pointer。

| Event | Request | Workflow | Document | Revision/publication |
|---|---|---|---|---|
| workflow activated | 只为 actionable bindings 建 request；first=`available`, others=`pending` | `ready/signing` | 要求 integrity hold 非 active；`active_workflow_id=W`; 无旧发布则 `signing`，有旧发布仍 `issued` | prepared_deferred field 已存在但无 request；base 不自动 published |
| non-final request signed | current=`signed`, next=`available` | `signing` | 保持；有旧发布继续指向旧 revision | 新 revision `active`，不公开 |
| optional/conditional permanently skipped | current=`permanently_skipped`，act CAS=`permanently_skipped`并固化 activation/decision hash，next 按规则激活 | `signing` 或进入 final check | 保持 | 不创建 revision；后续只建 inherited_skipped binding，禁止再激活 |
| final completion condition met | 本 workflow required 均 signed，其他实际 request signed/permanently_skipped；prepared_deferred 不参与 | `completed` | 清 `active_workflow_id`，status=`issued` | 新 revision `published` + publication event；deferred act/field 保留；旧 published→`superseded` |
| late-seal workflow activated | 为目标 deferred act 建 actionable request/field mapping | `ready/signing` | base=current published，active workflow 指向新流程，document 保持 issued | no-write bind 同一预建 field，不创建/修改字段 |
| deferred act signed | request=`signed`，act `deferred→completed` | workflow 按本次 request 完成 | 清 active，保持 issued | 新 organization_seal revision published，旧 published→superseded，历史签名重验通过 |
| request rejected | request=`rejected`，后继不激活 | workflow=`rejected` | 清 active；无旧发布回 `draft`，有旧发布保持 `issued` | 本 generation 未发布 revisions→`abandoned` |
| retryable operation exhausted before irreversible boundary | claim=`retryable` 时 request 恢复 `available`，签署需新 artifact/challenge | workflow 保持 `signing` | 保持 | 不创建 revision；新 operation 复用 logical claim |
| known failure after private-key boundary | request=`failed`，禁止原 lineage 重试 | workflow=`failed` | 清 active；旧 published 保持有效 | operation/claim=`irreversible_failed`；技术重做须 new generation/lineage |
| winner protocol 判定 outcome unknown | request=`failed`，禁止原 lineage 重试 | workflow=`failed` | 清 active；旧 published 保持有效 | guard/attempt=`uncertain`、operation=`quarantined`、claim=`uncertain`；人工终结后才可 new generation/lineage |
| non-retryable validation failure | request=`failed` | workflow=`failed` | 清 active；无旧发布 `draft`，有旧发布保持 `issued` | 未发布 lineage→`abandoned/quarantined` |
| workflow cancelled | pending/available→`cancelled` | `cancelled` | 清 active；旧 published 不变 | 未发布 lineage→`abandoned` |
| unsigned_finalize completed | 不适用 | 尚未创建/保持 draft | `draft` | finalized revision=`active/internal`，不发布 |
| unmatched import_existing_signed completed | 不适用 | 尚未创建/保持 draft | `draft` | imported revision=`active/internal`，不自动发布 |
| exact published source reuse completed | 不适用 | 不适用 | 既有 document/status/pointer 不变 | source consumed；返回既有 revision；不创建 claim/revision/event |
| legacy_finalize completed（Phase 2+） | 不适用 | 不适用 | `issued` | 新 revision=`published`，写 publication event；旧 published→`superseded` |
| lt_validation_data completed on current published | 不适用 | 不适用 | 保持 `issued` | 新 B-LT revision=`published`，旧 revision→`superseded`，原子切 pointer |
| document_timestamp completed on current published | 不适用 | 不适用 | 保持 `issued` | 新 B-LTA revision=`published`，旧 revision→`superseded`，原子切 pointer |
| legacy/LT/DTS failed or cancelled | 不适用 | 不适用 | 保持原状态 | 旧 published pointer 不变；失败产物按 quarantine 合同处理 |
| quarantine artifact registered/destroyed | 不适用 | 不适用 | status/pointer 不变 | registered=quarantined/abandoned forensic；destroyed=审计空洞；均不发布 |
| explicit document revoke | 不适用 | active workflow 先取消 | `revoked`，保留 pointer 供历史说明 | 写 revoked event；下载禁用，公开显示撤销原因 |
| new logical document supersedes old | 不适用 | 不适用 | old=`superseded` | 写 superseded event并指向替代 document |
| published file missing/hash mismatch | 不适用 | 状态不回退，但所有推进被 document hold gate 阻断 | status 不自动 revoked；`integrity_hold_state=active` | revision→`unavailable/quarantined`，写 integrity_withdrawn，pointer 保留作历史说明，下载禁用并告警 |
| exact bytes restored and reverified | 不适用 | 原 workflow 可继续 | integrity hold=`resolved`，业务 status 不变 | 同 revision UUID/path contract 恢复 available，写关联 integrity_restored；不创建新 revision/publication |
| confirmed tamper or unrecoverable | 不适用 | active workflow 取消 | 显式事务置 `revoked` | 写 revoked event；不得把 hold 自动升级冒充法律撤销 |

`rejected` 加入 workflow status。revoke/supersede/integrity withdrawal 使用专用事务、原因码和权限，不混入普通签署事务。任何 publication event、document pointer、revision disposition 和关键审计必须在同一事务提交。

---

## 八、受控 PDF 生命周期

### 8.1 未签 PDF

```text
inspect immutable raw source
  → unsigned finalization (statement / QR / metadata)
  → inspect finalized unsigned planning revision
  → freeze workflow + placement plan against planning revision
  → prepare all signature fields/widgets/AP/locks once
  → verify prepared revision + manifest
  → sequentially fill existing fields with incremental PAdES-B-T
  → append reserved seals through existing fields
  → layered verification and delivery
```

所有会改变页内容、二维码、声明页、光度或元数据的操作必须在用户规划前完成。prepare 之后只能创建预定表单结构，不能再改变页面几何或页面内容。

`finalize-unsigned` 必须先验证输入不存在任何数字签名字典/签名 revision；发现已签输入立即拒绝，不能借该接口清元数据、补二维码或加声明页。

### 8.2 既有字段的 no-write bind

`bind_existing_fields` 统一支持四种只读来源：

```text
imported_signed
  → 外部已签 revision，验证历史签名与修改权限

internal_prepared
  → 本系统旧失败 workflow 的 prepared revision，尚无业务签名

internal_partially_signed
  → 本系统旧失败 workflow 的中间签名 revision，保留已完成签名

late_seal_current_published
  → 当前正式 published revision，仅激活首签前已预建的 deferred seal field

任一来源
  → bind_existing_fields(no-write)
  → 新 workflow 创建全新的 act bindings
  → actionable binding 创建全新的 request/field mapping/slot rows
  → prepared_deferred binding 只建立 field mapping，不创建 request/challenge
  → inherited binding 不创建 field/request，只引用原 act/result manifest
  → 全部 binding 只映射既有 field/widget/object refs
  → prepared_revision_id/current_revision_id = exact source revision
  → no PDF byte change
```

`bind_existing_fields` 只读并建立数据库映射，不调用 `prepare_create_fields`，不转移旧 workflow 行，也不产出新 revision。仅当以下条件全部满足才允许后续签署：

1. 已有未填 signature field，且 DocMDP/FieldMDP/`/Lock` 明确允许；
2. 所需 widget 已存在且属于目标 field；
3. 当前修订所有历史签名验证结论满足政策；
4. field/widget/AP/object refs、prepared baseline manifest、placement/field/lock/act manifests 与新 workflow 冻结值完全一致；
5. `internal_prepared` 的目标字段全部为空；`internal_partially_signed` 已完成/permanently-skipped act 通过 inherited bindings + manifest 固化，deferred act 保持 prepared-deferred；new workflow 只为剩余 planned act 创建 request，禁止再次签或重新决策同一 signing act；
6. `late_seal_current_published` 只允许把用户本次明确选择的 deferred act 映射成 actionable；其他 deferred act 继续保持 prepared-deferred，completed/permanently-skipped act 只能 inherited，禁止创建新 PDF field/object；
7. recovery/late-seal source、旧 workflow、旧 act/binding、失败 operation/claim（如有）、new generation/lineage 和新 immutable policy version 形成可审计链。

缺少字段、权限不明、旧签名已失效或需要新增页面内容时，受控流程拒绝。不能用“旧 ByteRange 数学上仍可验证”替代“最终文档修改被允许”。

未匹配的普通外部导入创建 role=`imported_signed_base`、`revision_created_at=import time`、`signed_at=null`、disposition=`active`，不得自动 published；每个嵌入签名的 claimed time/timestamp/trust 放结构化 manifest。上传字节精确匹配本系统既有 published revision 且完成授权/消歧时，只新增指向原 document 的 consumed source 和 DB-only operation，直接返回原 revision/publication history，不创建 imported revision 或“已发布”事件。

### 8.3 Deferred seal 的后续激活

首页章、功能章、骑缝章等“首签时不要求、后续可能追加”的行为必须在第一个 workflow 冻结前建模为 `SigningAct(status=deferred)`，并在第一次 `prepare` 时创建字段/widget/AP/锁；它们不是永久跳过项，也不创建当前签署 request。

```text
first workflow
  → approval acts = actionable
  → future seal acts = prepared_deferred
  → prepare all PDF fields exactly once
  → complete current approval requests
  → publish revision while deferred fields remain empty

later seal decision
  → create workflow(origin_type=existing_revision, base=current published)
  → bind_existing_fields(source=late_seal_current_published, no-write)
  → selected deferred act becomes actionable in this workflow
  → create request/challenge/appearance only now
  → fill the same pre-created field incrementally
  → verify all historical signatures and publish a new revision
```

激活前必须重新验证当前 published revision 的摘要、所有历史签名、DocMDP/FieldMDP/`/Lock` 权限、目标字段为空以及 act/field/lock manifest。`permanently_skipped` 或 `cancelled` act 不得通过 late-seal workflow 复活；需要不同字段、不同锁或不同几何时只能创建新的逻辑文档版本，不能修改既有已签 PDF 的结构。later workflow 不创建 `pdf_source_uploads`，其 `origin_type=existing_revision`、`source_upload_id=null`，来源完全由 `base_revision_id` 和 inherited manifests 固定。

### 8.4 取消与重新规划

取消 workflow 时，在 document 锁内将该 generation 的未发布 revisions 统一标记 `disposition=abandoned`，清除 `active_workflow_id`，但不删除字节或审计。新 workflow 必须增加 generation 并从 document 明确指定的可信 base revision 创建；新 lineage 的线性约束独立计算。任何 abandoned/intermediate revision 都不能推进 `published_revision_id`。

业务计划变化必须回到 planning/base revision 重新规划与 prepare，不能复用旧 prepared。只有 `/Contents` 不足、TSA/私钥 outcome 已确定失败等纯技术故障，且字段计划完全未变时，才允许以 `internal_prepared/internal_partially_signed` 建 recovery workflow。旧 workflow/claim 保持 failed/irreversible_failed/uncertain；新 workflow 重新创建 request/field/slot/challenge/appearance，使用新 policy version，不改写旧 operation 为成功。

已签发报告后追加预留首页章/功能章时，以当前 `published_revision_id` 创建新的 workflow generation，走 `bind_existing_fields` 映射首签前已经存在的空字段。旧 published revision 在新 generation 完成前继续对外有效；新章签名和验证全部通过后，发布事务才把旧修订置 `superseded` 并切换指针。失败或取消不影响旧正式版本。

---

## 九、页面与坐标合同

### 9.1 规划页

```text
┌──────────────────────────────────────────────────────────────────────────────┐
│ PDF 签署流程规划        XDP2025120133.pdf     状态：未冻结    [冻结并准备] │
├────────────┬───────────────────────────────────────────┬─────────────────────┤
│ 页面       │ 第 3 / 13 页                     100%     │ 签署任务             │
│ [1]        │ ┌─────────────────────────────────────┐   │ 1 主检  张丁浪       │
│ [2]        │ │ 主检：[槽位 1]                     │   │   组织证书签名       │
│ [3] ●      │ │ 审核：[槽位 2]                     │   │ 2 审核  李雪         │
│ [4]        │ │ 签发：[槽位 3]                     │   │   组织证书签名       │
│ ...        │ │ 首页章：[预留槽位 4]               │   │ 3 签发  吴卫强       │
│ [13]       │ └─────────────────────────────────────┘   │ 4 首页章  单位       │
│            │ 拖动 · 等比缩放 · 方向键微调              │                     │
└────────────┴───────────────────────────────────────────┴─────────────────────┘
```

冻结后几何、顺序、字段和人员不允许修改。变更需取消未完成工作流，从可信源重新规划。

### 9.2 签署页

```text
┌────────────────────────────────────────────────────────────┐
│ 待签任务：审核确认                当前修订 SHA-256: 9d…   │
├────────────────────────────────────────────────────────────┤
│                    当前 PDF 只读预览                        │
│                  [我的 widget 高亮]                         │
├────────────────────────────────────────────────────────────┤
│ [手写] [清空]  操作人：李雪  证书主体：单位  [验证当前密码并签署] │
└────────────────────────────────────────────────────────────┘
```

未确认前 overlay 不写入 PDF。点击认证前先上传笔迹并得到不可变 `appearance_uuid`，随后 challenge 绑定该 artifact；认证后不能再修改笔迹。签署开始后页面轮询 operation；重复点击携带同一 idempotency key、challenge 和 appearance UUID。

### 9.3 坐标合同

前端提交相对于 pdf.js 旋转后可视 `CropBox` 的归一化矩形：

```json
{
  "page_index": "2",
  "x": "0.327100",
  "y": "0.734200",
  "width": "0.148000",
  "height": "0.042000",
  "geometry_hash": "0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef"
}
```

坐标 API 直接要求字符串，Laravel 不接收 number、不替调用方舍入：

- `page_index` 为无前导零的十进制字符串，lexical form 固定 `^(0|[1-9][0-9]*)$`，范围由 inspection page count 决定；页码、byte length、数据库 ID 等 canonical 整数一律使用相同字符串策略；
- `geometry_hash` 固定为 64-char lowercase hex，不带 `sha256:` 前缀；
- lexical form 固定 `^(0|1)\.[0-9]{6}$`，禁止正负号、科学计数法、`-0`、NaN、Infinity、空白和超过/少于六位小数；
- `x/y >= 0 && < 1`，`width/height > 0 && <= 1`，使用十进制定点运算验证 `x + width <= 1.000000`、`y + height <= 1.000000`；
- 浏览器由整数 pointer pixel 与冻结 viewport 尺寸计算比例，使用同一 decimal library 按 **ROUND_HALF_UP** 量化到六位后再序列化；raw JavaScript number/原始浏览器 JSON 永不参与任何 manifest hash；
- Laravel 以严格 decimal parser 验证并直接写 canonical placement manifest；Java 只消费同一字符串并用定点/BigDecimal 转换，禁止 binary float 重新格式化；
- 前端、Laravel、Java 共享边界/半值/溢出/科学计数/负零 golden vectors；任何一端 canonical bytes 不一致即拒绝冻结 workflow。

inspection 返回 `MediaBox`、有效 `CropBox`、rotation、`UserUnit`、pdf.js `view` 与 viewport。唯一 `PlacementCoordinateMapper` 负责从旋转后左上坐标转换到默认用户空间。

测试覆盖 A4、Letter、横向、非零 CropBox 原点、90/180/270 度、非默认 UserUnit、不同 DPR/缩放；最终位置误差不超过 1 mm。

---

## 十、API 合同

### 10.1 Browser → Laravel

| Endpoint | Contract |
|---|---|
| `POST /api/pdf/signing-sources/inspect` | 接受 upload intent；`recover_existing_document` 必须带 requested document/base revision/recovery workflow 与授权 hash；创建持久 intake 并冻结 inspection + intent-specific exact-SHA candidate snapshot |
| `POST /api/pdf/signing-source-intakes/{intake_uuid}/resolve` | 绑定 candidate-set hash，按 intake 冻结 intent 单次幂等解析；recover 零匹配返回 `RECOVERY_SOURCE_NOT_FOUND` 且不创建 document；create-new 必须提交确认的 report number，由服务端 canonicalize 后执行 business identity unique/duplicate policy |
| `DELETE /api/pdf/signing-source-intakes/{intake_uuid}` | 取消仍 pending 的 intake；不得删除已 resolved source |
| `POST /api/pdf/signing-sources/{source_uuid}/finalize` | claim operation，返回 202、operation UUID 和 status URL |
| `POST /api/pdf/signing-sources/{source_uuid}/import-signed` | 已签输入验证并登记原字节 revision；返回 202 operation |
| `POST /api/pdf/signing-workflows` | 接受 `origin_type`、`base_revision_uuid`、nullable `source_upload_uuid`/recovery workflow、binding strategy、任务和 placement plan；existing-revision/late-seal 来源禁止伪造 source upload |
| `POST /api/pdf/signing-workflows/{id}/prepare` | 未签 planning revision 创建字段；返回 202 operation |
| `POST /api/pdf/signing-workflows/{id}/bind-existing` | imported/internal prepared/internal partially-signed 只读绑定已有字段；返回 202 no-write operation |
| `GET /api/pdf/signing-workflows/{id}` | 查询工作流、请求和修订链 |
| `GET /api/pdf/signing-requests/{id}` | 返回授权任务和精确 ready revision |
| `POST /api/pdf/signing-requests/{id}/appearances` | 规范化手写或快照印章/骑缝切片，返回不可变 artifact/manifest hash |
| `POST /api/pdf/signing-requests/{id}/challenge` | 验证当前密码，绑定当前 Sanctum token、密码版本、revision/appearance/policy |
| `POST /api/pdf/signing-requests/{id}/sign` | claim operation，返回 202；Laravel→Java 签名 POST 发送后禁止 transport retry，只查 execution status |
| `GET /api/pdf/signing-operations/{operation_uuid}` | 返回授权后的 state、stage、error code、retry time 和 result revision |
| `GET /api/pdf/revisions/{revision_uuid}/download` | 只下载 ready 且摘要正确的精确修订 |
| `POST /api/pdf/documents/{document_uuid}/integrity-incidents/{incident_uuid}/restore` | 双人审批、exact bytes/SHA/manifests/signatures 全量重验后解除 hold，写关联 `integrity_restored`；不创建新 revision |
| `POST /api/pdf/documents/{document_uuid}/integrity-incidents/{incident_uuid}/revoke` | 确认篡改/不可恢复后显式撤销；与自动 integrity hold 分离 |
| `POST /api/pdf/quarantine-artifacts/{id}/register` | 双人审批后以原预留身份登记 quarantined/abandoned forensic revision；不发布 |
| `POST /api/pdf/quarantine-artifacts/{id}/destroy` | 双人审批、7 天延迟且无 legal hold 后执行 destruction operation |
| `GET /api/public/pdf/revisions/{revision_uuid}` | 只查询服务器登记状态和公开修订链 |
| `POST /api/public/pdf/revisions/{revision_uuid}/verify` | 上传 PDF，由服务端计算摘要并验证持有字节 |
| `GET /api/public/pdf/documents/{document_public_id}` | 逻辑文档当前修订与链路 |

权限至少拆成 `workflow.create`、`request.sign_assigned`、`organization_key.use`、`revision.download`、`verification.read`。能进入页面不等于能调用组织私钥。

operation status 仅向 operation actor、workflow 管理员或具备审计权限的用户开放；公开响应不返回内部 lease owner、路径、nonce 或 exception，只返回协议化 stage/error/result。

### 10.2 Laravel → Java

| Endpoint | Contract |
|---|---|
| `POST /internal/pdf/signatures/inspect` | 签名、权限、结构和几何检查 |
| `POST /internal/pdf/signatures/finalize-unsigned` | 声明页/二维码/元数据/光度成文，只用于 planning revision |
| `POST /internal/pdf/signatures/prepare` | 一次性创建全部字段/widget/空 AP/锁 |
| `POST /internal/pdf/signatures/sign-existing-field` | 只填一个既有 signature field 并增量执行 organization signature |
| `POST /internal/pdf/signatures/legacy-sign` | Phase 2 兼容期 legacy signature；必须 claim 同一通用 irreversible guard，禁止绕过 ledger |
| `POST /internal/pdf/signatures/document-timestamp` | 为 B-LTA 增量追加 DocTimeStamp；必须 claim 同一通用 irreversible guard |
| `GET /internal/pdf/irreversible-executions/{operation_uuid}/{lease_epoch}` | 对 organization/legacy/DocTimeStamp 通用，只读返回 execution JSON metadata/status；绝不包含 PDF 或触发私钥/TSA |
| `GET /internal/pdf/irreversible-executions/{operation_uuid}/{lease_epoch}/result` | 仅 completed 时流式返回同一不可变 PDF及 SHA/size/validation-report hash；不暴露 object key |
| `POST /internal/pdf/signatures/verify` | 分层验证全部签名、修订和权限 |

新工作流永不调用 `/api/pdf/process`。

---

## 十一、Java 服务安全与部署

### 11.1 当前部署形态

首发保持 Laravel 运行于宿主机、Java 运行于容器/独立服务：

- Java 仅映射 `127.0.0.1:8080:8081`；
- Laravel 通过 `PDF_SERVICE_BASE_URL=http://127.0.0.1:8080` 调用；
- 立即预部署 HMAC 能力；按 11.2 完整线协议 vectors 通过后再切 enforce，不等待容器化改造；
- 未来 Laravel 与 Java 都容器化时，取消 host port，改用专用 internal network + mTLS。

### 11.2 HMAC 合同

选择 **canonical part manifest**，不签由 Guzzle 动态 boundary 决定的原始 multipart body。Laravel 在发送前计算每个 part，Java 将 multipart 严格落临时文件并重新计算；校验完成前不得调用业务服务、私钥或 PDF writer。

线协议固定为：

- algorithm：HMAC-SHA-256；
- key：CSPRNG 生成的随机 binary secret，至少 32 bytes；secret manager 中以 unpadded Base64URL 存储，启动时严格解码为原始 bytes，长度不足即启动失败；
- signature：完整 32-byte MAC 编码为 64-char lowercase hex，不允许截断；接收端先验证长度/hex，再使用 constant-time comparison；
- 所有 SHA-256 header 均为 64-char lowercase hex；timestamp 为 Unix seconds 十进制字符串；nonce 为 16-byte CSPRNG 的 unpadded Base64URL；UUID 使用 canonical lowercase hyphenated form。

Headers 固定为：

```text
X-Pdf-Auth-Version: 1
X-Pdf-Key-Id
X-Pdf-Timestamp
X-Pdf-Nonce
X-Pdf-Correlation-Id
X-Pdf-Operation-Id
X-Pdf-Metadata-Sha256
X-Pdf-Part-Manifest-Sha256
X-Pdf-Signature
```

除独立放行的 health 外，全部 header 必须存在；无 DB operation 的 inspect/render/extract/Phase-S legacy 请求固定使用非空 sentinel `X-Pdf-Operation-Id: -`。signing string 使用 UTF-8、无 BOM、固定十行、字段间单个 LF、末尾无 LF；header 值禁止前后空白和折行。PHP 与 Java 都不得省略 sentinel、把空串补成 sentinel 或接受其他别名。无文件 part 时 part manifest 是 JCS `[]` 的 SHA-256。

签名串固定为：

```text
version\nkey_id\nmethod\nnormalized_path_and_query\nmetadata_sha256\npart_manifest_sha256\ntimestamp\nnonce\ncorrelation_uuid\noperation_uuid_or_dash
```

- JSON metadata 和完整 part manifest 都使用 RFC 8785 JCS 后计算 SHA-256；输入必须满足 I-JSON；
- UUID、数据库 ID、byte length 和 timestamp 全部编码为十进制/UUID字符串，禁止依赖 PHP/Java 的 64 位数值表示；
- part name 限制为协议定义的 ASCII allowlist，按 ASCII 字节序排序；每项固定包含 `name`、规范化 content type、audit filename、byte length 字符串、SHA-256；
- content type 解析后 type/subtype 小写；PDF/PNG/octet-stream 禁止参数；JSON 仅允许 `charset=utf-8`，规范输出固定为 `application/json;charset=utf-8`；拒绝重复参数、未知参数、非法 token 和 quoted 等价变体；
- audit filename 不参与业务定位：要求合法 UTF-8，拒绝 NUL/control/path separator，最长 255 code points，保留原 Unicode code points且不执行 NFC/NFD normalization；
- metadata/manifest parser 拒绝重复 JSON key、非法 surrogate、NaN、Infinity、负零和未建模的超大数值；数组顺序保持，object properties 按 JCS 递归排序；
- canonical request target：method 大写；path 必须以 `/` 开头、拒绝 dot segment/重复斜杠，unreserved 字符解码、百分号使用大写十六进制，尾斜杠有意义；query 参数 UTF-8 解码后按 RFC 3986 重编码并按 key/value 排序，拒绝重复 key，空值 `k=` 与缺失不同；
- 禁止重复 part name、未知 part、遗漏 required part、额外 part、重复 metadata 和顺序歧义；
- Java 对每个 part 设置独立大小上限，流式落 operation-scoped 临时文件并重新计算 manifest；业务层只接收 gate 产生的 verified temp-file handles，禁止再次读取原始 request stream；
- `correlation_uuid` 是每次 transport request 的追踪 ID，inspection 等无 DB operation 的请求也必须有；
- `operation_uuid_or_dash` 对已经创建 `pdf_signing_operations` 的 mutating request 使用数据库中的 canonical UUID；其他请求精确使用单字节 ASCII `-`；两者不是同一概念；
- `X-Pdf-Key-Id` 必须命中 active/previous allowlist，且 key ID 已进入 MAC input，禁止 header substitution；
- Java filter 在收到 headers 时立即捕获不可变 `request_received_at`；timestamp 与该时刻最大偏差 60 秒，不能在 20 MB body 完全落盘后再取当前时间判断；body receive deadline 固定 120 秒；
- nonce store 首发固定为所有 Java 实例共享的 Redis，key 精确为 `pdf-hmac:{auth_version}:{key_id}:{nonce}`，value 为 correlation UUID；使用原子 `SET key value NX EX 300`，失败即统一 replay 401；Redis 不可用时 fail closed；
- 300 秒 TTL 覆盖 60 秒时钟偏差、120 秒最大 body 接收时间和 120 秒安全余量；key rotation 因 key ID 位于 Redis key 与 MAC input 中，不混淆 active/previous nonce 空间；
- 顺序固定为：捕获 receipt time → 校验 header/时间/request target/MAC → Redis 原子 claim nonce → 流式接收并重算 metadata/part manifest → 才把 verified handles 交给业务层；claim 后的 body/hash/auth 失败仍保留 nonce 到 TTL，禁止释放后重放；可安全重发的只读/非不可逆 transport request 使用新 nonce；organization signature、legacy signature、DocTimeStamp 任一不可逆 POST 开始发送后禁止自动重发，只查询 execution ledger；
- 未验证临时文件位于独立 ingress 目录，verified staging 位于 operation/epoch 目录；认证失败、oversize、连接中断立即 best-effort 删除，后台 sweep 删除超过 15 分钟的 ingress orphan，并按 20 MB 单请求、2 GB 全局临时目录和部署容量计算出的并发配额 fail closed；进程崩溃恢复不得把 ingress 文件当 verified handle；
- 支持 `active_key_id` + `previous_key_id` 的短期轮换重叠，重叠结束立即撤销旧 key；
- Spring authentication/filter + multipart manifest gate 覆盖所有 private-key、stamp 和 PDF-write endpoint，包括 legacy `/api/pdf/process`；
- PHP/Java 维护完整 request-to-MAC vectors（headers、canonical request target、metadata、part manifest、signing string、expected MAC），至少包含“有 operation UUID”和“无 operation 使用 `-`”两组正向 vector，并覆盖未知 key、空/缺失/sentinel 错误 header、大小写差异、截断 MAC、Unicode、重复 key、`-0`、超大整数、filename/query/content-type 差异和篡改 manifest；任一实现结果不同即阻断 Gate S；
- 缺失/未知/格式错误/过期/重放/MAC 不匹配统一返回 HTTP 401 + `PDF_AUTH_FAILED`，不暴露具体认证失败原因；oversize 单独返回 413，且任何失败都不得触发业务服务/私钥；
- health 只能返回存活状态，不能泄露证书和策略；
- 生产关闭 DEBUG 与敏感 multipart 日志。

### 11.3 策略收口

- 调用方不能传摘要算法、TSA URL、证书路径、PFX 密码或证书别名；
- 算法、TSA、证书和 profile 由 immutable `signing_policy_version_id` 映射；
- PFX/HSM 密钥为空、默认值、不可读或证书过期时启动失败；
- 首发只加载组织证书；`signing_key_id` 不接受任意调用方值；
- Java 与 Laravel 上传上限统一为 20 MB；同时设置页数、图片解码像素、字段数与并发限制；
- 日志不记录完整笔迹、PDF、token、密码或私钥材料。

#### 11.3.1 Legacy 时间戳能力收口

Phase S-A 不实现 PAdES-B-T，必须把现状如实固定为：

```text
legacy_timestamp_mode=none
timestamp_capability=false
timestamp_present=false
```

- 禁止把当前 `PDF_SIGNING_TSA_ENABLED=true` 或 TSA URL 配置解释为时间戳已生效；Phase S-A 将该开关关闭并从 legacy request options 移除；
- authenticated legacy capabilities/options、部署 health assertion、`pdf_files` metadata、审计和 UI 都明确记录/显示 `timestamp_capability=false`、`timestamp_present=false`；公开 liveness health 仍不暴露策略；legacy 输出不得标记 PAdES-B-T；
- 若生产政策要求所有签名必须有可信时间戳，legacy 签名入口 fail closed，只保留不触发私钥的 inspect/render/extract；不得继续生成无 TSA 文件；
- 真正 B-T capability 只有在 Phase 0 ASN.1/阅读器样本通过且 Phase 3 的 RFC 3161 实现验收后，才能通过新的 immutable policy version 开启。

### 11.4 Phase S 无中断安全切换

Phase S 不等待 Gate 0，按以下顺序执行：

1. 先将 Docker 端口从 `8080:8081` 改为 `127.0.0.1:8080:8081` 并验证非本机不可达；
2. 生成 HMAC key，以只读 secret 部署到双方，但 Java 先不 enforce；
3. 删除 `changeit` fallback、收敛日志、拒绝已签 legacy 输入、固定服务端算法/key，并按 11.3.1 关闭 legacy 虚假 TSA capability；
4. PHP/Java 完整 request-to-MAC vectors 全部通过；
5. Java 在 loopback 上进入最长一个维护窗口的 audit-only/dual-accept，记录但不接受非 loopback 来源；
6. Laravel `PdfRendererClient` 所有 legacy/render/extract/internal 调用统一发送 HMAC canonical manifest；
7. 通过访问日志确认 100% Java 写入/私钥调用命中有效认证，旧调用为零；
8. 生产 smoke test 在 enforce 候选模式验证成功/负向请求；
9. Java 切换 enforce，未认证请求 fail closed；
10. 删除 dual-accept 代码与配置，不保留长期兼容开关；
11. 轮换一次 key，验证 active/previous 短窗口，再撤销旧 key；
12. 回滚只允许回滚业务流量，loopback、enforce、无默认密码和策略收口永不回滚。

audit-only 窗口必须有开始/结束时间、责任人和告警；若不能接受 dual-accept，则在维护窗口内先停写流量、部署双方、执行健康/签章 smoke test，再原子恢复流量。

### 11.5 Legacy 退役

- legacy 只在 `legacy_pdf_process_enabled` 下开放；
- Phase S 尚无 revision schema，不承诺 legacy revision 登记；Phase 2 schema 上线后所有 legacy 输出强制登记；
- 新工作流永不调用 legacy API；新旧 UI 用 feature flag 分流并处理在途批次；
- Phase 5 删除 legacy signature branch，仅保留 unsigned finalization 或彻底删除接口。

### 11.6 Graceful drain 与发布门禁

Java 生产配置固定 `server.shutdown=graceful`，`spring.lifecycle.timeout-per-shutdown-phase=300s`；容器 `stop_grace_period=330s`。签名 policy 的 Java hard timeout 必须小于 240 秒；若未来 provider 需要更长时间，必须同步提高三者并重新做故障测试，不能让容器先杀死 JVM。

部署流程固定为：

1. 实例进入 drain，readiness 立即置 false；liveness 仍 true；
2. HMAC filter 继续允许 execution status/result 和 health，只对新的 PDF write/private-key/TSA 请求返回 503 `PDF_SIGNING_DRAINING`；已通过 guard 的 executing attempt 继续完成；
3. 发布脚本查询 Java guard/attempt 的 executing count，并交叉查询 Laravel `processing/java_call` operation 的 lease/heartbeat；二者均为零且持续两个轮询周期后才停止实例；
4. 在 300 秒内等待现有请求和私钥调用完成，再由容器给予额外 30 秒退出；普通滚动发布未排空则阻断，不自动 `kill -9`；
5. 强制终止是事故路径：所有当时 executing guard 对应 operation 先进入/保持 `recovery_pending` 并触发 P0 告警；启动恢复查询 shared ledger/result/provider evidence，只能按 5.9.5 winner protocol 采用 completed、投影 known failure，或在 deadline/evidence 满足后竞争 uncertain。禁止因进程被杀就直接写 uncertain，更禁止自动重签。

单实例首发在 readiness false 后还必须先停止 Laravel 向该实例派发；多实例由负载均衡摘除目标实例。部署 smoke 同时验证 drain 期间新签名被拒、status/result 可读、在途签名可完成、未排空发布被阻断。

---

## 十二、Java PDF 核心设计

从当前大服务拆出：

- `PdfSignatureInspector`；
- `PdfWorkflowPreparationService`；
- `IncrementalPdfSignatureService`；
- `PlacementCoordinateMapper`；
- `SignatureAppearanceFactory`；
- `PadesSignatureFactory`；
- `OrganizationSigningMaterialProvider`；
- `SigningExecutionLedgerService`；
- `SigningOperationGuardService`；
- `ImmutableExecutionResultStore`；
- `PdfRevisionDiffValidator`；
- `PdfSignatureVerifier`。

### 12.1 Prepare

1. 复核 planning revision SHA、finalization manifest、inspection manifest 和 placement plan；
2. 严禁执行任何 unsigned finalization 或页面/元数据修改；
3. 按冻结计划一次性创建 actionable 与 prepared-deferred 的全部 fields/widgets/AP/`/Lock`；prepared-deferred 只建 binding/field/slot，不建 request；
4. 记录每个 prepared object ref；
5. 生成通用 revision manifest，并保存 prepare 特有 `prepared_baseline_manifest` 与 hash；
6. 通过通用 operation/reconciler 保存、验证并晋升 prepared revision；事务 B 将 prepared-deferred acts 从 planned CAS 为 deferred；
7. prepared revision 之后禁止页面内容、二维码和元数据变化。

### 12.2 Bind existing fields（no-write）

1. 仅接受已登记并验证通过的 `imported_signed/internal_prepared/internal_partially_signed/late_seal_current_published` revision；
2. internal recovery 复核旧 workflow terminal 状态、recovery link 和 placement/field/lock/act manifest 完全相同；业务计划变化直接拒绝；
3. 枚举已签/未填 signature fields、widgets、object refs、DocMDP/FieldMDP 和 `/Lock`；重验全部已有签名；
4. 为外部来源待签 act、内部恢复未完成 act 或 late-seal 目标 deferred act 创建对应 binding 并映射既有对象；只有 actionable 创建 request，继续延后的 act 使用 prepared_deferred 且无 request。外部来源使用 `binding_mode=imported_existing`，内部/late-seal 使用 `internal_rebound`；
5. partially-signed source 对已完成/permanently-skipped act 只创建 `inherited_completed/inherited_skipped` binding 并指回原结果，只为剩余空字段创建 actionable request/field，禁止重复 signing act；
6. 保存数据库 manifest，`prepared_revision_id/current_revision_id=exact source revision`；
7. 输出字节数必须为零，不调用 PDF save，不创建 revision；任何缺字段/缺 widget/需页面修改的输入直接拒绝。

### 12.3 Sign existing field

1. 完成 HMAC/body/manifest 验证后，以 operation+epoch+input/policy/config/certificate/appearance snapshots claim attempt；completed 返回原结果，executing 不重复执行，failed_post_sign_known/uncertain fail closed；
2. 只从 operation immutable snapshots 复核父修订 SHA、field/appearance/policy manifest，不追读 mutable request/challenge/policy relation；
3. 验证所有历史签名与当前权限；
4. 定位属于 request 的 prepared field/widget；
5. 从已绑定的 canonical appearance artifact 生成透明 AP，只更新允许对象；
6. 创建一个 signature dictionary；
7. 严格按 operation → logical claim → guard → attempt 锁序赢得 operation-level private-key boundary 后，使用 `saveIncrementalForExternalSigning(...)`；
8. 恰好一次调用组织私钥/TSA，生成 PAdES-B-T CMS 并写入 `/Contents`；
9. 返回实际 policy hash、算法 OID/参数、证书链 fingerprints 和 TSA policy；
10. 验证 prefix、对象 diff、全部历史签名和新签名，将结果按 conditional PUT/读回核验协议持久化；
11. guard + attempt 原子 completed 后，通过分离的 status/result API 返回同一结果与结构化验证摘要。

必要但不充分的不变量：

```text
output_bytes[0 : input_bytes.length] == input_bytes
```

### 12.4 通用不可逆执行适配器

`organization_signature`、`legacy_signature`、`document_timestamp` 共用同一套 HMAC gate、immutable operation snapshot、operation → claim → guard → attempt 锁序、`recovery_pending` winner protocol、immutable result store 和 status/result API。action adapter 只允许差异化 PDF profile 与 provider payload，不能自行调用私钥/TSA、建立第二套 ledger 或在 transport error 后重试。

- `organization_signature`：使用既有 field、组织私钥和 RFC 3161 signature timestamp；
- `legacy_signature`：兼容期仍使用组织私钥，但必须登记 root/parent claim 与 revision；Phase S-A 的 timestamp capability 继续明确为 false，不能伪装为 B-T；
- `document_timestamp`：使用 TSA 生成 DocTimeStamp signature；token/CMS result 同样受 guard at-most-once 和 object-store durability 约束；
- `lt_validation_data`：只追加已验证的 DSS/VRI，不调用私钥/TSA，不建立 irreversible guard；其 materialization 仍走通用 operation、fencing、revision diff 和 publication pointer 事务。

每种 action 都要有同一组 completion-wins、uncertainty-wins、late object、DB/API partition 和 response-loss fixtures。已终态 guard 只能被投影/采用，不能被 action adapter 覆盖。

---

## 十三、可执行对象差异清单

每个 revision 生成两层不可变 `revision_manifest`；prepare 另外冻结 `prepared_baseline_manifest`，供后续 field signing 的 allowlist 比对。

**Raw revision manifest**

- 父文件长度与完整 SHA-256；
- 当前 revision 新增字节区间与摘要；
- startxref、xref table/xref stream/hybrid chain 和 trailer `/Prev` 链；
- object number + generation + offset/对象流位置；
- 每个 raw indirect object bytes SHA；
- stream 原始压缩字节 SHA；
- 每个签名字典 object ref、其 direct `/ByteRange` token span、direct `/Contents` token span、四个整数值和该签名所属 revision end offset；
- trailing bytes 状态、linearization dictionary 和修复标记。

**Semantic object manifest**

- dictionary key 按 PDF name 的编码字节排序；
- scalar 类型与规范值分开编码，禁止字符串化后混淆类型；
- indirect reference 记录 object number/generation，不默认递归展开；
- stream dictionary 排除仅由序列化器重算的 `/Length` 后做 key-level canonical form，同时单独记录原 `/Length`、`/Filter`、`/DecodeParms`；
- raw stream hash 永远计算；解码后内容 hash 只在 filter allowlist、解压大小/比例上限内安全计算；
- Catalog、Pages tree、每页 dictionary/contents/resources、AcroForm、field/widget/AP 分别记录 ref 与 semantic hash；
- 同一语义的不同编码仍在 raw 层显示变化，semantic 层用于判断允许的序列化差异，不能用 semantic 相等掩盖未授权 raw 重定义。

解析器遇到重复 object number/generation、同修订多次定义、异常 `/Prev`、重叠 offset、尾随垃圾、无法安全解码或 repaired xref 时标记 shadow-update risk，并按策略拒绝，不能只采用“最后一个对象获胜”。

签署后验证顺序：

1. 输入字节前缀完全不变；
2. 解析新增和重定义 object set；
3. 对重定义对象做 key-level diff；
4. 确认 page content/tree/catalog/metadata/非目标字段未变；
5. 明确禁止旧签名 `/V`、`/ByteRange`、`/Contents` 变化；
6. 确认 xref/trailer 只形成预期增量关系；
7. 重新验证全部旧签名；
8. 再验证 DocMDP/FieldMDP/`/Lock` 权限。

### 13.1 新签名 ByteRange/Contents 绑定

`PdfSignatureInspector`、`PdfSignatureVerifier` 和 raw revision manifest 对本系统生成的新签名强制同一合同：

1. `/ByteRange` 必须是签名字典内的 direct array，exactly 4 个 PDF integer；拒绝 real、负数、间接数组、溢出和额外元素；
2. 值必须为 `[0, first_length, second_offset, second_length]`，满足 `0 <= first_length < second_offset`、各段有序无重叠且不越界；
3. `second_offset + second_length` 必须恰好等于该签名所属增量 revision 的 end offset；存在后续 revision 时不能误用当前整个文件长度；
4. 唯一未签 gap `[first_length, second_offset)` 必须逐字节恰好等于**同一签名字典** direct `/Contents` hex-string token 的完整序列化 span（含 `<`/`>`）；field `/V` 必须指向该签名字典；禁止指向其他对象的 Contents、额外 gap 或未签尾部；
5. 新生成 `/Contents` 固定为无内部 whitespace、偶数 hex digits 的 direct hex string。按 hex 解码后，DER length 必须界定 exactly one CMS `ContentInfo/SignedData`；
6. DER CMS 结束至 reserved buffer 末尾的 padding 必须全部为 `0x00`；拒绝第二个 ASN.1 对象、非零尾随字节、截断 DER、伪造长长度或 gap 外附加 CMS；
7. 对签署输出先验证该合同，再验证 CMS message-digest/签名/TSA；后续每次增量修订仍重新验证所有旧签名的原 ByteRange/Contents span 未变；
8. imported signature 不符合本生成 profile 时不得伪装成合规新签名；inspector 返回明确 invalid/indeterminate code，由 import policy 决定拒绝。

Gate 0 负向 fixtures 覆盖 3/5 元素 ByteRange、real/negative/overflow、乱序/重叠/越界、额外 gap、错误 revision end、指向另一 Contents、indirect Contents、非零 padding、双 DER 对象和截断/超长 DER。

Operation profiles：

- `unsigned_finalize`：声明页/二维码/元数据/光度处理及预定 Catalog `/Extensions`，全部发生在首签和规划前；
- `fill_signature_field`：目标签名字典、目标 field `/V`、目标 widget/AP 和必要 AcroForm key；
- `document_timestamp`：仅允许 DocTimeStamp signature revision 所需对象；
- `lt_validation_data`：仅在 B-LT/LTA 阶段允许 DSS/VRI；
- B-T 普通签署 profile 不泛化允许 DSS/VRI。

Phase 0 adversarial fixtures 必须覆盖 xref table、xref stream、hybrid xref、object stream、linearized PDF、重复 object number、尾随垃圾、被修复的损坏 xref 和增量 shadow update。

---

## 十四、PAdES-B-T 精确合同

生产规范基线固定为 **ETSI EN 319 142-1 V1.2.1 (2024-01)**。V1.2.0 草案和未来 draft 不作为本期验收基线。

### 14.1 PDF/CMS

- PDF `/SubFilter /ETSI.CAdES.detached`；
- CMS `SignedData` digest 与 signature algorithm 必须来自 immutable policy version；
- `SignedData.encapContentInfo.eContentType` exactly `id-data` OID `1.2.840.113549.1.7.1`，`eContent` 必须 absent，以 RFC 5652 detached/external content 形式签署 PDF ByteRange；禁止嵌入 PDF 内容；
- 首发唯一算法：digest `id-sha256` OID `2.16.840.1.101.3.4.2.1`，AlgorithmIdentifier parameters absent；signature `sha256WithRSAEncryption` OID `1.2.840.113549.1.1.11`，RSASSA-PKCS1-v1_5，parameters DER NULL；RSA modulus 最少 2048 bit；
- RSASSA-PSS、ECDSA 或其他算法只能创建新的 immutable policy version，并重新通过 Acrobat、Foxit、Java 和独立验证器样本门禁，不能复用当前 policy hash；
- PDF Signature Dictionary `/M` exactly one 且必须存在，表达 claimed signing time，并与审计时钟一致；它本身不是可信时间证据；
- `SignerInfo.signedAttrs` 必须使用 DER；CMS signed attribute `content-type` exactly one，值固定为 `id-data` OID `1.2.840.113549.1.7.1`；`message-digest` exactly one；`SigningCertificateV2` attribute exactly one，其 SET OF values exactly one，该 value 的 `certs` 首发 exactly one ESSCertIDv2 并匹配 signer certificate；
- CMS `signing-time` attribute **必须 absent**；禁止 Bouncy Castle 默认属性生成器把它自动加入；
- `SignedData.certificates` 必须存在，至少包含 signer certificate，并按 policy 提供构链所需中间证书；
- B-T 的可信时间只来自验证通过的 RFC 3161 signature-time-stamp unsigned attribute；首发 `id-aa-signatureTimeStampToken` OID 固定为 `1.2.840.113549.1.9.16.2.14`，exactly one attribute，attribute SET OF values exactly one DER-encoded `TimeStampToken`；虽标准允许多个实例，本项目首发策略拒绝零个或多个；
- 新 unsigned 流程在 `unsigned_finalize` 阶段加入并冻结 PDF Catalog Extensions Dictionary：`/ESIC << /BaseVersion /1.7 /ExtensionLevel 2 >>`（或 Gate 0 证明等价 Adobe extension）；签署 operation 不再修改 Catalog；
- imported signed base 绝不为了补 Extensions Dictionary 改写 PDF，只报告其现状并由 Gate 0/validation policy 决定能否继续；
- `/Contents` 预留空间通过真实证书链和 TSA token 样本测量，取高分位 + 安全余量；不足发生在私钥调用后时，claim 不得回到 retryable，当前 workflow 失败并保留证据。字段计划未变的重做须创建 new generation/new lineage，明确使用 `bind_existing_source_type=internal_prepared/internal_partially_signed` no-write 映射原空字段，使用新 policy/artifact/challenge 并扩大 placeholder；严禁重写 prepared PDF、转移旧 workflow 行、重建字段或把旧 attempt 冒充未发生。

### 14.2 RFC 3161 TSA

- TSA URL、policy OID、trust anchors、超时和重试来自服务端策略；
- request 的 SHA-256 message imprint 精确计算 `SignerInfo.signatureValue` OCTET STRING 的值字节，并使用随机 nonce；不能对 PDF、整个 CMS 或 signed attributes 再做错误摘要；
- response 校验 status、nonce、message imprint、policy OID、TSA signer cert chain、EKU 和 token signature；
- unsigned attribute 和其中的 `TimeStampToken` 必须通过 DER round-trip/cardinality 校验；BER、不规范 SET 排序、重复 timestamp attribute 或单 attribute 多 value 均按首发 policy 拒绝；
- TSA 失败分类：`TSA_TIMEOUT`、`TSA_REJECTED`、`TSA_IMPRINT_MISMATCH`、`TSA_NONCE_MISMATCH`、`TSA_UNTRUSTED`；
- B-T 策略下 TSA 失败不产出 ready 修订，不静默降级到 B-B。

### 14.3 组织签名证书信任政策

- trust anchors 来自版本化、只读、fingerprint allowlisted 的组织签名信任包，不使用运行主机任意系统根；
- 自签证书只有在其 exact root fingerprint 经组织审批并进入该信任包时才为 trusted；
- leaf 必须 `basicConstraints CA=false`，KeyUsage 至少允许 `digitalSignature` 或 `contentCommitment`；存在 EKU 时必须命中策略配置的 document-signing OID 或 `anyExtendedKeyUsage`；
- 有可信 RFC 3161 signature-time-stamp 时，以可信时间戳时间作为签名存在时间并验证证书在该时刻的有效期/撤销证据；没有可信时间戳时，以当前验证时间判断证书状态，PDF `/M` 仅显示 claimed time，不提升信任；CMS `signing-time` 按本 profile 不存在；
- OCSP 优先、CRL fallback；响应按 issuer+serial+thisUpdate/nextUpdate 缓存，校验 responder 授权、签名和时效；
- 明确 revoked 或链签名错误为 `invalid`；网络失败、撤销信息缺失/过期、无法形成链为 `indeterminate`，公开页面不得显示“可信”；
- 在线签署时组织证书链、有效期、用途和当前撤销状态必须为 good，否则 fail closed，不产生 ready revision；
- verifier 响应记录 policy version、trust-anchor fingerprint、validation time、revocation source 和 evidence freshness，确保不同部署使用同一政策得出可解释结论。

### 14.4 验证结果

每个签名返回：

1. `cms_integrity`；
2. `certificate_trust`；
3. `signed_revision_integrity`；
4. `later_revision_permission`；
5. `timestamp_trust`；
6. `document_current_state`。

状态使用 `valid` / `invalid` / `indeterminate`，并带错误码。ETSI validation report/schema checker 只证明报告结构或指定规则符合，不能代替真实信任链、撤销信息和阅读器兼容验收。

### 14.5 后续 LT/LTA

- B-LT：通过独立 operation profile 增量加入证书链、OCSP/CRL 和 DSS/VRI；
- B-LTA：在 B-LT 后追加 `/ETSI.RFC3161` DocTimeStamp signature revision；
- 每次 validation data 或 DocTimeStamp 都形成独立不可变修订。

---

## 十五、DocMDP、FieldMDP 与字段锁

inspection 合并解析 Catalog `/Perms/DocMDP`、签名 `/Reference` transforms、字段 `/Lock` 和 FieldMDP `All/Include/Exclude`，多个限制取最严格结论。

- `P=1`：拒绝业务内容、字段和 annotation 变化；仅规范允许且 profile 明确的 validation data/DocTimeStamp 路径另行判断；
- `P=2`：只填充/签署预存在、未锁定且被允许的字段；
- `P=3`：不据此默认允许新建表单字段；
- 字段锁比流程计划严格时，以锁为准；
- 无法确定时拒绝。

是否使用 certification signature、哪个签署行为设置 DocMDP、后续首页章需要的 permission 必须在 Phase 0 样本矩阵中定稿。不能先编码再猜 Acrobat 行为。

---

## 十六、个人签名供应商门禁

首发明确不交付个人 CMS。Phase 6 立项前必须形成 PoC 与合同决策，至少回答：

- CA/远程签名厂商或 UKey 型号与合规范围；
- 上传原文、上传摘要，还是 CSC API；
- 用户账号与证书 subject/SAN 的绑定；
- PIN/OTP/MFA 的认证强度和明确签署意愿证据；
- pending/timeout/cancel/retry 状态与 provider transaction 映射；
- 证书链、撤销、TSA、证据和日志保留期；
- UKey 受控桌面客户端及浏览器通信安全；
- 数据驻留、跨境与供应商可用性；
- 不可否认性和法律措辞经法务确认。

门禁失败时，产品继续使用组织证书模式，不以共享 PFX 模拟个人签名。

---

## 十七、实施顺序

### Phase S-A：立即前置安全加固（GO，不受 Gate 0 阻塞）

- 完成 loopback、HMAC key 预部署、删除 `changeit` fallback、固定 legacy 算法/key、拒绝已签输入和日志收敛；同步设 `legacy_timestamp_mode=none`、关闭 `PDF_SIGNING_TSA_ENABLED` 的能力表达并禁止 legacy 标记 B-T；
- 本阶段不实现新 PAdES 语义、不迁移 revision 数据；
- 验证非本机无法连接，现有 `/pdf/signing`、render、extract 和符合“无可信 TSA”政策的 legacy 调用仍可用；若生产政策强制 TSA，则 legacy 私钥入口按合同 fail closed。

### Phase S-B：HMAC enforce（CONDITIONAL GO）

- 严格按 11.2/11.4 完成 `-` sentinel、有/无 operation 完整 request-to-MAC vectors、Redis replay store/receipt-time/temp quota、短时 dual-accept、Laravel 全调用认证、enforce smoke test、删除 dual-accept 和 key rotation；
- vectors 未证明 PHP/Java 对 headers、content type、request target、JCS 和 MAC 完全一致前，不得切 enforce；
- 未认证请求必须在业务服务/私钥前统一失败。

**Gate S：** Java enforce 已开启、完整 vectors/Redis replay 与 ingress 故障测试/生产 smoke test 通过、dual-accept 已删除、密钥轮换通过、未认证调用为零。Gate S 失败立即回滚业务流量，但不得回滚 loopback、无默认密码和已完成的前置加固。

### Phase 0：冻结合同与阅读器/供应商门禁

- 建立未签、单签、多签、跨页 multi-widget、rotation/CropBox/UserUnit、DocMDP/FieldMDP、损坏签名样本；
- 用 Acrobat、Foxit、Java verifier 和另一独立 PAdES validator 建立结论矩阵；
- 固化 PAdES-B-T CMS/TSA 样本：CMS signing-time absent、PDF `/M`、detached eContent absent、id-data、certificates、ESSCertIDv2、RFC 3161 exact OID/DER/cardinality、ESIC Extension、reserved size 与错误码；保存 ASN.1 dump，并加入 embedded eContent、重复 attribute、多 timestamp value、BER/错误 SET 排序负向样本；
- 固化 canonical multipart manifest 和 raw/semantic object manifest 测试向量；
- 加入 xref table/stream/hybrid、object stream、linearized、重复对象、尾随垃圾、repaired xref、shadow update 及 ByteRange/Contents/padding 构造攻击样本；
- 验证 finalized planning revision 前后页码、几何与权威报告编号合同；
- 固定组织模式 `password_reauthentication`、immutable policy version 和精确 RSA/CMS OID；
- 固化 async operation lease/heartbeat/lease-epoch fencing、logical claim attempt/retry、root claim、quarantine adjudication、source pin 与 reconciler 故障矩阵；
- 固化 Java 通用 irreversible guard 与 attempt ledger：organization/legacy/DocTimeStamp 三种 action 的跨 epoch/多实例 claim、私钥/TSA 已完成但响应丢失、executing crash/stale epoch、historical completed adoption 和 status/result recovery；
- 固化 completion-vs-uncertainty winner protocol，覆盖双方抢锁、ledger/API/对象存储分区、deadline 前后和 uncertainty 后 late object；
- 固化 `recover_existing_document` 的目标 document/base/workflow 身份、授权快照与 intent-specific candidate snapshot；零匹配必须稳定失败且不得创建 document；
- 固化首次审批流程预建 deferred seal、当前完成条件忽略 deferred、后续 no-write 激活同一 field 的完整阅读器样本；
- 决定 certification signature 和 lock policy；
- 个人 provider 只做 go/no-go，不阻塞组织模式首发。

**Gate 0：** multi-widget、P=2、B-T、policy binding、对象 manifest 和后续预留章任一关键样本没有一致证据，阻塞 Phase 2 及之后的数据迁移和正式业务功能；不阻塞 Phase S。

### Phase 2：来源、修订和工作流底座

- migrations/backfill：documents/publication events、document business identity/duplicate relation/integrity hold、persistent source intake + recover target/auth/business identity + N:1 source role/generation/pin、planning revision、workflow origin/recovery/source type、stable signing acts + deferred/permanently-skipped workflow-act bindings + request attempts、request、field、slot、通用 appearance/legal hold、policy version、session-bound challenge、带 immutable execution snapshots/lease/stage/epoch/audit context/recovery-pending 的 operation、outbox、通用 Java irreversible guard/execution attempts、logical parent/root claims、quarantine artifacts/adjudication、materialized-only revision；
- 将 `signed_at` 改 nullable，新增 revision role/created time/integrity/disposition、published pointer 和 integrity withdrawn/restored event contract；
- 完成 `file_id` 双读迁移；
- 实现不可变存储、CAS 线性链、可恢复提交和 reconciler；
- Java 新增 Spring JDBC、MySQL Connector/J、最小权限 datasource 与 S3-compatible private result store；Laravel migration 单独拥有 schema，Java auto-DDL 禁用；完成 object/ledger 崩溃一致性、180 天 retention/legal hold/backup/capacity 配置；
- 实现 source intake resolve/cancel、recover target-document scope/零匹配失败、upload-intent 权限和同字节新 document 的 canonical business identity/unique/duplicate relation/原因码政策；
- 将 finalize/prepare/import/bind/legacy/LT/DocTimeStamp 全部接入通用 operation；从本阶段起 legacy 输出强制登记 revision；
- 公开验证拆成登记 GET、持有文件 POST 和逻辑文档查询；generic SHA 多匹配返回 ambiguous，不允许 `first()`；保留报告编号 resolver。

### Phase 3：组织模式垂直切片

```text
unsigned upload
 → inspect immutable raw source
 → finalize unsigned planning revision
 → inspect finalized geometry and plan placements
 → prepare one field with multiple widgets
 → freeze canonical appearance artifact and challenge
 → organization PAdES-B-T
 → promote ready revision
 → layered verification
 → public exact-revision verification
```

再增加四条垂直切片：外部 `imported_signed → bind_existing_fields → sign`、技术失败 `internal_prepared/partially_signed → new generation → no-write bind → sign`、`current published + deferred act → late-seal no-write bind → sign`、exact published duplicate → DB-only reuse。同时完成 Java cross-epoch guard、completion-vs-uncertainty winner、execution response-loss/historical-result adoption、object diff manifest、TSA、历史签名重验、graceful drain 和真实阅读器证据。

### Phase 4：React 手写规划与三任务流程

- 规划页、只读签署页、Pointer Events 画板、overlay 实时预览；规划只使用 finalized planning revision；
- 笔迹先规范化固化，再创建 challenge；认证后不允许替换 artifact；
- 主检/审核/签发三个顺序任务，各自执行当前密码再认证并生成组织证书 CMS；UI 不宣称 MFA；
- 桌面 Chrome、移动触控和坐标 golden tests；
- UI 明确区分操作人、手写外观和组织证书主体。

### Phase 5：现有盖章迁移与 legacy 退役

- 首页章、功能章、骑缝章全部迁入预建 field/slot；
- 首次 workflow 把未来可能追加的章建为 prepared-deferred，不创建 request；后续 late-seal workflow 只激活本次选择项并 no-write 映射同一字段；
- 统一通过 signature appearance artifact 快照资产/切片；当前可选任务只有不可逆业务决定后才为 `permanently_skipped`，不得把未来章永久跳过；
- 移除已签 PDF 上全量 save、清元数据和动态二维码路径；
- 完成在途批次、旧链接和旧 ledger 兼容验收；
- 删除 legacy signature branch，决定是否仅保留 unsigned finalization。

### Phase 6：个人远程签名

- 仅在供应商 Gate 通过后实现 `RemotePersonalSigningProvider`/CSC/UKey adapter；
- 一个用户一次独立 challenge 和 provider transaction；
- 不把浏览器或 Laravel 变成私钥托管端；
- 重新执行身份、撤销、信任、超时和阅读器全套验收。

---

## 十八、验收门禁

### 身份与业务

- 三个任务只能由各自被分配用户完成；
- 每次 challenge 精确绑定 expected revision、plan、field manifest、appearance manifest 和 intent；
- challenge 和 operation 同时绑定 immutable policy hash、组织证书 fingerprint 和 `password_reauthentication`；Java 实际返回值不一致时不得晋升；
- token A 创建的 challenge 不能由 token B 使用；token revoke、密码版本变化、账户锁定/禁用、must-change-password 均立即使 challenge 无效；
- 第二、第三个 request 只在前驱完成时原子绑定新 ready revision；进入 available 后不可改；
- stale 页面/challenge 在 workflow current revision 改变后必须冲突失败；
- 三次组织模式 CMS 均明确显示组织证书主体，不冒充个人证书；
- 一个 request 多个 widget 只生成一个 CMS。
- optional/conditional 当前任务只有满足冻结 activation policy 才能 permanently skipped，required 任务不得跳过；未来章使用 deferred，不得以 skip 代替延期；
- 第一个 workflow 为全部未来 seal acts 预建 field/widget/AP/lock，但 deferred act 不创建 request/challenge 且不阻塞本次完成；后续 workflow 只把明确选择的 deferred act 激活为 actionable；
- workflow `origin_type=source_upload` 时必须绑定 source；`origin_type=existing_revision` 时 source 必须为 null，并由 base revision/recovery/late-seal provenance 完整证明来源；
- document 行锁与复合 FK 保证同一 document 最多一个非终态 workflow；两个并发创建请求只能一个成功；terminal transition 原子清除 active pointer；

### 修订与恢复

- `revision_uuid` 永远映射同一字节和 SHA-256；
- 事务 A 不创建 pdf_files；只有 materialized bytes 完成验证和 promotion 后事务 B 才插入 revision，失败 operation 不产生零摘要/processing row；
- 同一活动 lineage 的父修订最多一个 active/published 成功子修订；业务计划变化必须从可信 planning/base 重新 prepare；纯技术失败且 manifests 完全相同时可由新 generation no-write rebind internal prepared/partially-signed objects；
- `ready` 中间 revision 不会自动公开，只有 document published pointer 决定当前正式版本；
- finalized/prepared 的 `signed_at` 必须为 null，LT/DocTimeStamp 不计作业务签署时间；
- 相同幂等输入返回同一结果，不同输入冲突；
- 原 operation=`irreversible_failed` 时同 fingerprint 稳定返回原终态错误，不进入事务 A；pre-irreversible retry 只有在同一事务 CAS claim `retryable→reserved` 并替换 `active_operation_id` 后才能创建新 operation，root/parent identity 不变；
- 已完成 Laravel operation 的客户端幂等重试必须在检查已消费 challenge 前返回原响应；Java 签名 POST 不自动重发；
- 在事务 A 后、rename 前、rename 后、事务 B 前后注入故障，reconciler 均能恢复到唯一合法状态；
- 故障注入覆盖 rename 成功但目录项未 fsync；
- `ready`/published 文件缺失或哈希变化立即置 document integrity hold、禁止下载/签署/pointer 推进并写 withdrawn event，但不自动标记法律 revoked；exact bytes 恢复到同 revision UUID、全量重验和双人审批后写 linked restored event；确认篡改/不可恢复才显式 revoke；
- orphan final 不会自动公开。
- worker 活跃 lease 期间 reconciler 不接管；lease 过期可原子接管；promoted 恢复不会重复调用私钥；
- stale worker 在 lease epoch 被接管后，即使收到迟到 Java/TSA 响应，也不能验证、rename、晋升或写事务 B；每个 epoch staging 相互隔离；
- pre-irreversible failure 可由新 operation 复用同一 logical claim；OUTCOME_UNKNOWN/verified staging/promoted 后原 lineage 不得恢复 available；
- 初始 unsigned/import/legacy 根修订由唯一 root claim 占位，不依赖 nullable parent unique；同一 source 并发 materialize 只能成功一次，既有 published exact match 走无 root/revision 的 DB-only reuse；
- source root reserved/retryable/processing/uncertain/quarantine 状态均阻止 sweeper；root commit 原子 consumed，保留期/legal hold 到期后才删 bytes，SHA/manifest/audit 永久保留；
- source intake 在重启后仍能按原 intent/candidate-set hash resolve；替换 ingress bytes、重复/冲突 resolution、越权 candidate、过期 intake 均不能创建 source；recover 只扫描 requested document 的可信内部/正式来源，必须校验 requested base/workflow 和授权快照，零匹配稳定返回 `RECOVERY_SOURCE_NOT_FOUND` 且 document 数不变；
- create-new 使用服务端 canonical business identity 和组织范围唯一约束；重复业务身份默认 409，只有授权 duplicate + 选择 canonical existing document + reason code 才可新建并写 `duplicate_of_document_id`；
- Java operation guard 在跨 epoch、多实例、响应丢失和 worker retry 下最多一次进入 private key；completed 取回同一 CMS/result，executing/failed_post_sign_known/uncertain 不得重复调用；
- `recovery_pending` 保持 operation/claim 排他 ownership、source/appearance/result legal hold，不允许 retry/new generation；completion 与 uncertainty terminalizer 以相同 operation→claim→guard→attempt 锁序竞争，completed winner 必须被 Laravel adoption，uncertain winner 后的 Java late completion CAS 必须失败且 late object 进入 forensic quarantine；
- fault injection 分别证明 API 不可用但 ledger 可读、ledger 不可用、object PUT/guard commit 前后、deadline 前后、双方同时抢锁、uncertainty 后 late object 与人工 adjudication 的唯一合法结果；
- completed old-epoch attempt 可由 current epoch 验证后采用并写入 current fenced staging；任何 input/policy/config/certificate/appearance/result hash 不一致都隔离；
- `failed_pre_sign`、`failed_post_sign_known`、`uncertain` 三类故障 fixture 均进入不同终态，只有第一类允许 logical claim retry；
- quarantine register/destroy 均需不同用户双人审批；register 只生成 quarantined/abandoned forensic revision，destroy 满足 7 天延迟与无 legal hold；二者均不发布；
- status API 权限隔离正确，202、heartbeat、retry time、错误码和 result revision 可观测；
- `claimed/no lease` 通过 outbox 重投；DB-only bind 不进 staging；failed/quarantined 幂等响应和 retryability 符合状态表；

### PDF 与 PAdES

- 输入字节前缀不变；
- object/key diff 只在 operation allowlist；
- 旧签名 `/V`、`/ByteRange`、`/Contents` 不变；
- 本次新签名 `/ByteRange` exactly 4 个 direct non-negative integers，唯一 gap 精确绑定同一签名字典 direct `/Contents`，第二段结束等于该签名 revision end；
- `/Contents` 只有一个 DER CMS，剩余 reserved padding 全为零；额外 gap、错误对象、双 ASN.1、非零尾随和越界 fixture 全部拒绝；
- `/ETSI.CAdES.detached`、ESSCertIDv2 和 RFC 3161 token 真实存在且可验证；
- CMS signing-time absent；PDF `/M` exactly one；encapContentInfo eContentType=id-data 且 eContent absent；content-type exactly one且为 id-data；SignedData.certificates 存在；SigningCertificateV2 与 signatureTimeStampToken 的 OID/DER/cardinality 符合首发 policy；新 unsigned PDF 的 ESIC Extension 在首签前冻结；
- TSA 失败不产出 ready 修订；
- Acrobat、Foxit、Java 和独立 validator 结论矩阵符合预期；
- 后续首页章填预留字段后，历史签名的权限结论仍符合设计。
- imported/internal prepared/internal partially-signed/late-seal-current-published bind-existing 全程零输出字节并创建新 workflow bindings；只有 actionable 生成 request，deferred/inherited 不生成；缺 field/widget、manifest 改变或需改页面时拒绝；`finalize-unsigned` 拒绝任何已签输入；
- 首次完成三人审批后 PDF 保留空 deferred seal field；后续基于 current published 激活同一 act/field、增量盖章后，Acrobat/Foxit/Java/独立 validator 对三条历史签名及新签名结论仍符合 policy；permanently-skipped act 无法复活；
- CMS 使用 id-sha256 `2.16.840.1.101.3.4.2.1` 与 sha256WithRSAEncryption `1.2.840.113549.1.1.11`/NULL，RSA modulus ≥2048；

### 公开验证与迁移

- 旧 `file_id` 链接仍精确指向原修订；
- PDF 内嵌二维码只使用 logical `document_public_id`；exact revision 链接只在成品发布后由下载页、响应头或验证结果提供，不得混用 planning revision UUID；
- 页面明确显示 exact match、latest、newer legal revision 和 later permission；
- backfill 数量、摘要和文件存在性全量对账；
- migration 不覆盖旧摘要；缺失文件 unavailable，摘要/大小不一致 quarantined，切换前必须零差异；
- MD5 present mismatch 必须 quarantined；nullable size/非法摘要进入 incomplete evidence，raw/normalized/calculated 三值均入报告；
- GET 只声明服务器登记状态；只有 POST 上传并由服务端计算摘要后才声明持有文件 exact match；
- 旧报告编号 QR resolver 的单命中、多命中和无命中均有回归；
- published pointer、abandoned lineage 和 unsigned revision 不会泄露到公开“当前版本”；
- 从未发布的 exact revision 统一 404；内嵌新二维码只使用 document public ID，不使用 planning revision UUID；
- logical document 在首次 publication event 前也统一 404；replacement document/event 只通过 public ID 安全关联；
- legacy verifier 只以曾发布 ready revision 的 exact SHA-256 确定身份，MD5 不作 fallback；兼容期内新 published revision 均有 MD5；
- exact revision verify 不做全库选择；generic legacy upload 的 0/1/多 SHA 匹配分别返回 not-registered/success/ambiguous-registration，禁止自然顺序选第一条；
- 双读 removal gate 有访问日志证据。

### 安全与部署

- 未认证、篡改 metadata/part manifest、重复/遗漏/额外 part、重放 nonce、过期 challenge 均不能触发私钥；
- PHP/Java JCS vectors 对重复 key、Unicode、非法 surrogate、负零、超大整数、query/filename 差异输出一致；业务层只消费 verified temp handles；
- HMAC-SHA-256 headers/signing string/lowercase hex/constant-time comparison/request-to-MAC vectors 完全一致，未知 key、截断 MAC 和大小写差异统一 401 `PDF_AUTH_FAILED`；
- challenge 后替换 PNG 或 appearance UUID 必须被拒绝；
- HMAC nonce store 故障时 fail closed；
- organization signature、legacy signature、DocTimeStamp 任一不可逆 POST 开始发送后即使使用新 nonce也不得自动重发；响应丢失只查询通用 execution status；ledger 暂不可判定时进入 `recovery_pending`，只有 winner protocol 原子赢得 uncertain 后才暴露 `OUTCOME_UNKNOWN`；
- 无 operation 请求使用 `X-Pdf-Operation-Id: -`，有/无 operation 两组 MAC vectors 一致；Redis `SET NX EX 300`、receipt-time 判断、nonce 保留和 ingress 临时文件 sweep/配额通过故障测试；
- legacy endpoint 与新 endpoint 使用同一 filter；
- Java 只监听 loopback，生产无 DEBUG；
- 缺失/默认/不可读 PFX 时启动失败；
- trust anchor、验证时刻、KeyUsage/EKU、OCSP/CRL 和网络失败状态符合固定 policy version；
- policy hash 覆盖 TSA endpoint/failover/timeout/retry、trust bundle hash、key locator/material version、revocation endpoints 和 reserved size；
- Java 只按 operation direct immutable snapshots 执行；修改 request/challenge/policy 关系或 audit JSON 不会改变已 claim operation 的执行输入；
- Phase S-A 的 authenticated legacy capabilities/options、部署 health assertion、UI/audit 均明确 `timestamp_capability=false`、`timestamp_present=false`，无 TSA 文件不得标记 B-T；
- Laravel 与 Java 均拒绝超过 20 MB 的输入。
- Phase S 验证 loopback → dual-accept → 全调用 HMAC → enforce → 删除 dual-accept → key rotation 的完整顺序；
- Java drain 时 readiness 先失败、新 private-key/TSA/write 请求返回 503、status/result 仍可读、in-flight 可完成；executing 未清零时部署脚本阻断普通停止，强杀后进入 recovery_pending 并由 winner protocol裁决；

### 性能与工程

- PDF、CMS 和响应使用流式处理，不使用 Base64 JSON 传整份 PDF；
- 13 页、20 MB、三次组织签名加首页章在生产资源门槛内完成；
- MySQL unique/FK/generated guard 在并发测试中阻止重复 UUID、重复 sequence/field/widget/challenge、幂等 NULL 绕过和同 lineage 分叉；
- action-specific CHECK/domain tests 拒绝 operation 缺 required scope FK、携带 forbidden scope FK 或跨 document 绑定；prepare 前 nullable object refs 与 prepare 后必填状态转换一致；
- documents.active_workflow 使用同 document 复合 FK；并发创建 workflow、错误跨 document pointer 均被数据库/集中事务拒绝；
- placement API 仅接受六位 decimal strings；范围、ROUND_HALF_UP、负零/科学计数拒绝和 PHP/Java/frontend golden vectors 完全一致；
- placement `page_index` 与所有 canonical 整数使用无前导零字符串，全部 hash 为 64-char lowercase hex 且无算法前缀；
- signing act 在 internal recovery 保持 logical UUID/plan generation；已完成 act 只建 inherited binding，未完成 act 的 actionable binding/request attempt 获得新 UUID；业务重规划使用新 plan generation，唯一约束不冲突；
- deferred act 从首次 prepare 到 later-seal completion 保持 logical UUID；多个后续 workflow 不能同时激活同一 act，失败/cancelled 后仍回到 deferred，只有成功才 CAS completed；permanently-skipped 永不回退；
- appearance 对 recovery_pending/uncertain/quarantined/irreversible_failed 持 legal hold，人工双人 adjudication release 后才开始 24 小时删除倒计时；普通完成/已证实 pre-sign failure 才使用普通 24 小时策略；
- Java ledger 由 Laravel migration 管理；Spring JDBC 使用固定锁序和最小权限账号；result object conditional PUT、read-back hash、orphan/missing-object recovery、retention/legal hold/capacity/backup 测试通过；
- organization/legacy/DocTimeStamp 三种不可逆 action 都通过同一 guard/attempt/status/result/winner 测试；LT validation-data 不调用私钥/TSA但仍走 fenced materialization/publication；
- Java result `available/retired/breached` 三态故障测试通过；合法退役不告警为丢失，恢复副本 breach 不回退已完成 Laravel operation，正式 revision 只有自身重验失败才 integrity-withdrawn；
- parent/root claim 在 child superseded/abandoned、uncertain 或 committed 后仍永久阻止旧 lineage/root slot 分叉；只有 pre-irreversible `retryable` claim 可替换 active operation；
- quarantine artifact 永不进入普通 `pdf_files`、current/published pointer 或普通下载；跨 document revision pointer 全部被复合 FK/domain transaction 拒绝；
- 全部业务 manifests 使用 versioned JCS schema/SHA-256 lowercase hex，PHP/Java golden vectors 一致；
- document/workflow/request/revision/publication 的完成、拒绝、失败、取消、撤销、替代、完整性撤回与 exact restore 符合状态矩阵；
- Java/Laravel/frontend focused tests、full tests、production builds、`git diff --check` 通过；
- 真实 Chrome 完成规划、三人顺序签署、首页章、下载和重新验证；
- 最终 Acrobat/独立验证样本绑定 exact commit 与文件 SHA-256。

---

## 十九、非完成条件

以下均不算交付完成：

- 只把 PNG 画到 PDF；
- 只调用 `saveIncremental` 而不验证对象 diff 和修改权限；
- 只验证最终文件 SHA 或单一 `overall_valid`；
- 动态新增字段后仅证明旧 CMS 数学有效；
- 用共享单位 PFX 宣称三个人各自完成个人数字签名；
- 文件 rename 成功却没有 operation/reconciler；
- 在 finalization 前按 raw source 页码规划槽位；
- challenge 只绑定用户和 PDF，却没有绑定 canonical appearance；
- GET 登记页面声称验证了用户手里的 PDF；
- 迁移通过覆盖旧 SHA 把异常历史文件重新登记为 ready；
- 把 unsigned/prepared revision 填上伪造的 `signed_at` 或当成“正本”；
- 用最后一个 ready revision 推导公开当前版本，而不是 document published pointer；
- 对已签 PDF 再运行 create-fields prepare；
- challenge 未绑定 immutable signing policy，或把密码再认证宣传成 MFA；
- reconciler 在 worker 活跃 lease 内读取/晋升 staging；
- 新接口安全但 legacy 私钥接口仍可被未授权调用；
- 事务 A 为 `pdf_files` 写零摘要/临时 processing 占位，或失败后删除修订冒充未发生；
- operation commit 后直接投队列而没有 transactional outbox/claimed 重投；
- token A 再认证生成的 challenge 被 token B 消费；
- HMAC 未固定完整 headers、canonical request target、key ID、编码与 request-to-MAC vectors；
- CMS 自动带入 `signing-time`，或缺少 `/M`、`id-data`、certificates、ESSCertIDv2、RFC 3161/ESIC profile 证据；
- 从未发布的 intermediate/imported revision 可被公开 exact API 探测；
- permanent parent claim 与“原 workflow 新 operation 重试”并存，却没有 logical claim attempt 状态；
- lease 只有过期时间而没有 epoch fence，允许迟到 worker 触碰 final path；
- 根修订把 `parent_revision_id=NULL` 塞进 unique parent claim；
- 无 operation 请求用空终端 HMAC 字段，依赖框架保留空 header；
- promoted 冲突产物由实现临场选择写 `pdf_files` 或丢进未知隔离目录；
- legacy verifier 用 MD5 fallback 选择记录，或把内部/未发布修订当公开正本；
- 配置写 TSA enabled、实际 CMS 无时间戳，却仍显示 B-T/可信时间能力；
- public logical document 在首次发布前泄露 draft/signing 状态；
- LT/DocTimeStamp 生成成功却不原子推进 published pointer，或失败时破坏旧发布版本；
- detached CMS 嵌入 eContent，或 timestamp/SigningCertificateV2 未校验 exact OID、DER 与 cardinality；
- 技术失败后口头要求复用 prepared fields，却没有 internal no-write bind source type 和新 workflow rows；
- 强制 source:document 1:1，导致重复上传既有 published bytes 时创建无法归属的空壳 document；
- 只用 Laravel lease/HMAC 防重复，却允许响应丢失后再次 POST 触发组织私钥/TSA；
- Java 只有 `(operation_uuid, lease_epoch)` 唯一键，却没有跨 epoch 的 operation-level private-key guard；
- reconciler 在 `java_call + no staging` 时不查原 Java attempt，误把已 completed 结果判成 unknown；
- 把私钥后的明确失败与结果未知混成同一状态，或允许 failed-post-sign 的原 claim 重试；
- Java completed 结果只在容器临时盘，或 object 与 ledger 间没有 conditional write/read-back/recovery/retention 合同；
- source ambiguous registration 只留临时文件，没有持久 intake、candidate-set hash 和一次性 resolve；
- recovery 为同一 logical act 重新生成身份，或因全局 act UUID unique 无法建立新 binding；
- routine deployment 直接 immediate shutdown 正在 executing 的 Java 私钥调用；
- quarantine artifact 声称可人工登记/销毁，却没有 action、双人审批、claim 和发布禁令；
- generic SHA verifier 对多条合法匹配直接 `first()`；
- 只证明 CMS 可解析，却不验证新签名 ByteRange 唯一 gap 与同一 Contents/padding 的字节绑定；
- active_workflow_id 可跨 document 指向 workflow，或并发创建两个非终态流程；
- placement API 发送 binary float 并直接参与 canonical hash；
- source sweeper 只看 expires_at，在 reserved/processing/uncertain/取证期间删除原字节；
- 把未来首页章/功能章在首个 workflow 中永久 skipped，或后续动态新建 PDF field；
- `recover_existing_document` 零匹配时创建新 document，或跨目标 document 搜索/绑定内部 revision；
- create-new duplicate 只靠 PDF SHA/自由文本判断，没有 canonical business identity unique 与 duplicate-of 审计；
- workflow 为 revision-origin recovery/late-seal 伪造 source upload；
- Java guard 仍为 executing 时仅凭 API/网络超时把 Laravel claim 写 uncertain，未通过同锁序 winner transaction；
- operation=`irreversible_failed` 仍进入事务 A，或 pre-sign retry 未原子执行 claim `retryable→reserved` 与 active operation 替换；
- 把临时存储/哈希异常自动冒充法律 revoke，或 exact restore 不关联原 integrity incident；
- uncertain/quarantine 尚未 adjudication 就按普通 24 小时规则删除 canonical appearance；
- legacy/DocTimeStamp 越过私钥/TSA 边界却绕过通用 irreversible guard/status/result/winner protocol；
- 测试全绿但没有真实 Acrobat/Chrome/独立验证器证据。

---

## 二十、九轮外部审核与 v10 独立自审闭环

### 20.1 第二轮 7 个 P0 + 3 个 P1

| Finding | 处理 | 章节 |
|---|---|---|
| P0-1 跨资源“原子提交”不成立 | 改为事务 A、staging/fsync、同盘晋升、事务 B、reconciler 和不可变 final path | 七 |
| P0-2 缺少 field/slot/widget 一等模型 | 新增 fields、slots；固定 request→field→CMS；multi-widget 设 Phase 0 门禁，移除普通 annotation 正式 fallback | 二、五 |
| P0-3 challenge/operation 错挂 request | challenge 与 operation 独立持久化；request 移除 `challenged`；明确幂等冲突语义 | 五 |
| P0-4 旧 `pdf_files`/公开验证迁移缺失 | 明确全量回填、不可变 `file_id`、双标识、线性链、双读和 removal gate | 三、六 |
| P0-5 inspect/prepare TOCTOU | 新增 immutable raw source 与 finalized planning revision；后续只传 UUID；冻结四类 hash | 四 |
| P0-6 legacy `/api/pdf/process` 未切断 | 新流程禁用旧 API；旧 API 立即同级认证、拒绝已签输入、feature flag 和明确退役 | 十、十一 |
| P0-7 个人 provider 未落地 | 首发降为组织证书；个人 CMS 移至供应商 PoC/合同门禁后的 Phase 6 | 二、十六、十七 |
| P1-1 PAdES 规范和属性不精确 | 固定 EN 319 142-1 V1.2.1，明确 SubFilter、ESSCertIDv2、算法、TSA、时间语义、错误码与 reserved size | 十四 |
| P1-2 部署拓扑不具体 | 固定 host Laravel + loopback Java + HMAC；未来容器内网+mTLS；补轮换、nonce fail-closed、canonical part manifest 和 20 MB 对齐 | 十一 |
| P1-3 对象 diff 不可执行 | 准备不可变 object manifest、key-level diff、operation profiles；B-T 不泛化 DSS/VRI | 十三 |

### 20.2 第三轮新增 6 个 P0 + 6 个 P1

| Finding | v4 处理 | 章节 |
|---|---|---|
| P0-1 槽位可能基于最终成文前几何 | 增加 finalized unsigned planning revision；finalize 后重新 inspection，prepare 禁止再改页面/元数据；报告编号单一权威 | 四、八、十、十二 |
| P0-2 后继 request 无法预知 source | 增加 predecessor；后继初始 null，前驱事务 B 激活时原子绑定 ready revision，available 后不可变 | 五、七、十八 |
| P0-3 challenge 未绑定实际笔迹 | 增加 immutable appearance artifact；challenge 和 operation fingerprint 同时绑定 manifest hash | 五、九、十 |
| P0-4 幂等晚于 challenge 消费 | 事务入口先查询/锁 operation；仅新 operation 才消费 challenge；challenge_id 唯一绑定 | 五、七 |
| P0-5 GET 无法证明持有文件字节 | GET 只查登记状态；POST 上传 PDF 后由服务端计算摘要与解析签名；公开 DTO allowlist | 六、十 |
| P0-6 回填会洗白被替换文件 | 旧摘要只读比较；缺失 unavailable、差异 quarantined；零差异为切换硬门禁 | 六、十八 |
| P1-1 multipart HMAC 不可实现 | 明确选择 canonical part manifest + RFC 8785 metadata；Java 严格重算并拒绝重复/额外 part | 十一 |
| P1-2 非签署 revision 未进入 operation | operation 泛化 scope/action；所有产出 revision 的动作共用提交和 reconciler；Phase 1/2 顺序消歧 | 五、七、十七 |
| P1-3 新 revision 旧列/QR 不明确 | 复用 `file_path`；新 `file_id=REV-<UUID>`；保留 `X-Final-File-Id` 与报告编号 resolver | 五、六 |
| P1-4 rename 缺目录 fsync | staging file fsync、rename、final parent directory fsync 后才能 promoted；提供对象存储替代协议 | 七 |
| P1-5 组织证书信任政策缺失 | 固定 trust bundle、验证时刻、KU/EKU、OCSP/CRL、fail-closed 与 indeterminate 语义；精确 TSA imprint | 十四 |
| P1-6 object canonical hash 未定义 | 拆 raw revision 与 semantic object manifest，定义 stream/dictionary/ref 规则和对抗样本 | 十三 |

### 20.3 第四轮新增 6 个 P0 + 6 个 P1

| Finding | v5 处理 | 章节 |
|---|---|---|
| P0-1 Gate 0 错误阻塞安全封口 | 独立 Phase S 立即执行，并给出 Gate S 与无中断切换；Gate 0 只阻塞 Phase 2+ | 十一、十七 |
| P0-2 unsigned revision 与 `signed_at NOT NULL` 冲突 | `signed_at` nullable；新增 created time、revision role；台账/下载/serializer 双读 | 五、六 |
| P0-3 缺 logical document/publication/replan | 新增 documents 聚合、published pointer、integrity/disposition、generation/lineage 与 abandoned replan | 五、八 |
| P0-4 已签 PDF 会误入 create-fields prepare | 新增 import signed + bind existing no-write 路径；finalize-unsigned 拒绝已签输入 | 五、八、十、十二 |
| P0-5 challenge 未绑定 policy 且认证模糊 | 新增 immutable policy version；绑定 policy/cert/TSA/trust；首发明确为 current-password reauthentication，不宣称 MFA | 五、十七 |
| P0-6 operation 无 lease/status API | 统一 202 async job、status URL、lease/heartbeat/retry；reconciler 只接管 expired lease | 五、七、十 |
| P1-1 artifact 只覆盖手写 | 泛化 signature appearance artifact 到首页/功能/骑缝章；增加 requirement/activation/skipped | 五、十七 |
| P1-2 artifact claim/retention 不明 | 事务 A claim；同 operation transient retry；terminal quarantine；完成后 consumed，24h 删除图像 | 五、七 |
| P1-3 JCS 跨语言仍有歧义 | metadata/manifest 都用 JCS；固定 path/query/filename/ID 规则、verified handles 和共享 vectors | 十一 |
| P1-4 MySQL 约束不可执行 | 给出 UUID/sequence/field/widget/challenge/idempotency/lineage 的实际 unique/FK/generated guard | 五 |
| P1-5 HMAC 上线会中断旧签章 | 固定 loopback→短时 dual-accept→全调用 HMAC→enforce→删除兼容→轮换的上线编排 | 十一 |
| P1-6 RSA 算法不精确 | 固定 id-sha256 与 sha256WithRSAEncryption OID、PKCS#1 v1.5/NULL、RSA≥2048；其他算法需新 policy | 十四 |

### 20.4 第五轮新增 6 个 P0 + 7 个 P1

| Finding | v6 处理 | 章节 |
|---|---|---|
| P0-1 processing/失败 revision 无法落非空摘要 | 选择 materialized-only revision；事务 A 只预留，事务 B 才插入 pdf_files；失败留 operation/audit | 五、七 |
| P0-2 imported signed 缺 role/时间语义 | 增加 imported_signed_base，顶层 signed_at=null，历史签名进入 embedded manifest，默认不发布 | 五、八 |
| P0-3 operation 状态分支不闭合 | 增加 stage、transactional outbox、全状态表、DB-only bind、claimed 重投和 stable failed/quarantine 语义 | 五、七 |
| P0-4 再认证未绑定 Sanctum 会话 | challenge 绑定 token/auth-context/password snapshot/reauth time；统一 cancellation service | 五、十 |
| P0-5 HMAC 缺精确线协议 | 固定 HMAC-SHA-256、32-byte key、headers、lowercase hex、key_id input、constant-time 和统一 401 | 十一 |
| P0-6 PAdES 属性与 ETSI 冲突 | CMS signing-time absent；固定 `/M`、id-data、certificates、ESSCertIDv2、RFC3161 和 ESIC Extension | 十三、十四 |
| P1-1 lineage/revision pointer 约束不完整 | workflow lineage unique、revision lineage index、document-global numbering、composite FK、permanent parent claims | 五 |
| P1-2 业务 manifest hash 无 canonical encoding | 全部业务 hash 使用 versioned JCS schema、固定 decimal/object-ref/array/hash 规则与 golden vectors | 五 |
| P1-3 async job 丢审计上下文 | operation 在事务 A 固化 audit context/hash，队列 AuditLogger 显式使用，不读 worker request | 五、七 |
| P1-4 policy 未覆盖 TSA/key 实际行为 | policy/config bundle 纳入 endpoint/failover/timeout/retry/trust hash/key locator/revocation/reserved size | 五 |
| P1-5 public exact visibility/QR 不明确 | exact 仅曾发布 revision；未发布统一 404；内嵌 QR 固定 document public ID | 五、六 |
| P1-6 migration 分支不完整 | MD5 mismatch quarantine；nullable size/非法摘要 incomplete evidence；报告保留 raw/normalized/calculated | 六 |
| P1-7 workflow/document 终态不闭合 | 增加完成、拒绝、失败、取消、撤销、替代、完整性撤回的同事务状态矩阵 | 七 |

### 20.5 第六轮新增 4 个 P0 + 7 个 P1

| Finding | v7 处理 | 章节 |
|---|---|---|
| P0-1 permanent claim 与合法重试冲突 | parent claim 改为逻辑边，operation 作为 attempt；仅 pre-irreversible retryable 可替换 active operation，uncertain 必须新 lineage | 五、七 |
| P0-2 lease 无 fencing token | 增加单调 lease_epoch，Java/staging/所有 CAS/rename 都绑定 fence，迟到 worker 只能清自身 staging | 五、七 |
| P0-3 source/document/root revision 未绑定 | v7 首次绑定 source/document 并增加 root claim；1:1 基数在第七轮发现重复导入冲突后由 v8 的 N:1 合同替代 | 四、五、七、十 |
| P0-4 空 operation ID 与无尾 LF 冲突 | 无 operation 固定 `X-Pdf-Operation-Id: -`，签名串固定十行，补有/无 operation vectors | 十一 |
| P1-1 promoted conflict 归宿二选一 | 唯一采用 pdf_quarantine_artifacts；不插正常 revisions，保留 revision number 审计空洞 | 五、七 |
| P1-2 legacy verifier 查询/MD5 不精确 | published-ready + exact SHA identity；MD5 仅附加；固定 overall_valid，兼容期新发布生成 MD5 | 六 |
| P1-3 legacy 虚假 TSA 能力 | Phase S-A 固定 timestamp mode none/capability false；政策要求 TSA 时 legacy 私钥入口 fail closed | 十一、十七 |
| P1-4 logical document 预发布泄露/替代字段缺失 | 首次发布前 logical API 统一 404；publication event 增加 replacement/related event FK | 五、六 |
| P1-5 非 workflow 修订无发布规则 | 为 unsigned/import/legacy/LT/DTS 增加 action-specific publication matrix，失败保持旧 pointer | 七 |
| P1-6 replay gate 存储/时间/临时文件不精确 | 固定共享 Redis SET NX EX 300、receipt time、TTL、ingress sweep 和磁盘/并发配额 | 十一 |
| P1-7 detached CMS/时间戳属性不完整 | 固定 eContent absent、timestamp OID、exactly-one DER/cardinality、signedAttrs DER 与 ASN.1 负向样本 | 十四、十七 |

### 20.6 第七轮新增 3 个 P0 + 6 个 P1

| Finding | v8 处理 | 章节 |
|---|---|---|
| P0-1 prepared 技术恢复与 bind 合同冲突 | bind_existing 泛化 imported/internal prepared/internal partially-signed；计划不变才允许新 generation no-write 映射新 rows | 五、八、十、十二、十四 |
| P0-2 source:document 1:1 与 published reuse 冲突 | 改为 source→document N:1；正式建 source 前 exact-SHA 授权消歧，命中既有 published 走 DB-only reuse | 四、五、七、十 |
| P0-3 Java 私钥调用缺逻辑 at-most-once | 增加持久化 execution ledger；operation+epoch key、历史 epoch guard、POST 不重发、响应丢失只查同一结果 | 五、七、十、十二 |
| P1-1 quarantine 人工登记无 action/claim | 增加 register/destroy actions；原预留身份、无新 claim、双人审批、forensic-only、7 天延迟且永不发布 | 五、七、十 |
| P1-2 legacy SHA 多匹配未处理 | exact URL 直接比较指定 revision；generic 查询全部并按 0/1/many 返回，many 显式 ambiguous | 六 |
| P1-3 新签名 ByteRange/Contents 不精确 | 固定四整数、唯一 gap、同字典 direct Contents、revision end、单 DER 与全零 padding，并补负向 fixtures | 十三、十八 |
| P1-4 active workflow 跨 document/并发 | workflows 增加复合候选键，document active pointer 使用复合 FK；创建锁 document，终态原子清除 | 五、七、十八 |
| P1-5 placement float 与 canonical decimal 冲突 | API 只收六位字符串；前端 HALF_UP，Laravel strict decimal，Java 定点解析，共享 golden vectors | 九、十八 |
| P1-6 source expiry 与异步 operation 脱节 | source 状态机按 root/operation/execution/quarantine/legal hold 推导 pin，commit consumed，sweeper 不只看 expires_at | 四、十八 |

### 20.7 第八轮新增 4 个 P0 + 5 个 P1

| Finding | v9 处理 | 章节 |
|---|---|---|
| P0-1 不同 lease epoch 可同时 claim Java execution | 拆 operation-level guard 与 per-epoch attempt；私钥前同事务锁 operation/logical claim/guard/attempt，再提交后调用私钥 | 五、七、十二 |
| P0-2 reconciler 会误判已完成 Java 结果 | `java_call + no staging` 先查原 execution；completed 受控采用旧 epoch 结果并写 current fenced staging，executing 有界轮询，未知才 quarantine | 五、七、十 |
| P0-3 缺少私钥后已知失败状态 | 增加 `failed_post_sign_known` 与 Laravel/claim `irreversible_failed`；和 pre-sign/uncertain 做互斥错误映射 | 五、七、十八 |
| P0-4 result ledger/store 未形成生产协议 | 固定 Laravel migrations + Spring JDBC/MySQL、S3-compatible immutable store、conditional PUT/read-back、status/result 分离、orphan/breach/retention/backup | 五、十、十七、十八 |
| P1-1 ambiguous registration 不可恢复 | 增加 persistent source intake、candidate-set hash、upload intent、resolve/cancel、同字节新 document 权限和业务键政策 | 四、十、十八 |
| P1-2 operation 缺直接安全快照 | operation 直接固化 input/policy/config/certificate/appearance snapshots，Java 禁止追读可变关系或解析 audit JSON | 五、十二、十八 |
| P1-3 canonical page/hash 类型冲突 | page index/ID/length 固定无前导零字符串，hash 固定 64-char lowercase hex 且无前缀 | 五、九、十八 |
| P1-4 recovery act identity 与 unique 冲突 | 增加 document-level signing acts、workflow-act bindings、request attempt；recovery 保持 logical act，重建 binding/attempt | 五、八、十二、十八 |
| P1-5 Java immediate shutdown | 固定 graceful shutdown、readiness drain、stop grace period、executing/lease 双检查与强杀 uncertain 合同 | 十一、十七、十八 |

### 20.8 v9 独立自审新增并关闭的 6 项

本轮在吸收第八轮清单后，重新按数据约束、状态可达性、跨服务竞态、对象保留和恢复行为逐段审查，没有把“外部 finding 已改”直接视为完成。自审新增并已回写：

| Self-review finding | v9 关闭方式 | 章节 |
|---|---|---|
| operation guard 仍不足以阻断旧 retry operation | 私钥边界事务增加 logical claim 锁与 `active_operation_id` 校验；固定 operation → claim → guard → attempt 锁序 | 五、十二、十八 |
| partially-signed recovery 缺少 completed/skipped act 的规范化引用 | 增加 workflow-act binding；completed/skipped 只建 inherited binding，未完成才创建 actionable binding/request/field | 五、七、八、十二 |
| completed object 意外丢失与合法 180 天退役会混淆 | 增加 result integrity state/`bytes_deleted_at`；available 丢失是 breach，授权 sweeper 退役返回 410且 guard 仍 completed | 五、十、十八 |
| signing act 没有随事务 B/skip 决策进入稳定终态 | 签署事务 CAS act→completed 并绑定 revision；skip 事务 CAS act→skipped 并固化 decision hash，recovery 继承终态 | 五、七、十八 |
| Java recovery copy breach 会污染已完成业务 operation | 未晋升 operation 才隔离；已完成 operation 保持终态并创建独立 incident，只有正式 revision 自身失败才撤回发布 | 五、七、十八 |
| nullable workflow/field/operation 列缺少阶段与 action 约束 | 补 prepare 前 nullable 语义，并以 action-specific CHECK + 单一 domain service 固定 required/forbidden scope FK | 五、十八 |

自审完成后的剩余 NO-GO 均是本方案明确列出的真实证据门禁（Gate S/Gate 0、数据库并发/故障注入、真实 Chrome、Acrobat/Foxit/独立 PAdES validator），不是尚未给出合同的已知架构缺口。

### 20.9 第九轮新增 3 个 P0 + 5 个 P1

| Finding | v10 处理 | 章节 |
|---|---|---|
| P0-1 future seal 与永久 skipped 不可同时成立 | act 增加 deferred/permanently-skipped，binding 增加 prepared-deferred；首签前预建字段但不建 request，later-seal no-write 激活同一 act/field | 五、七、八、十二、十七、十八 |
| P0-2 recover 可能丢失 document 身份 | intake 固化 target document/base/workflow/authorization，按 intent 查可信 internal source/revision；recover 零匹配稳定失败且绝不建 document；同时落地 canonical business identity unique 与 duplicate-of/reason | 四、五、十、十七、十八 |
| P0-3 Java completed 与 Laravel unknown 可形成冲突终态 | 增加 recovery-pending；completion/uncertainty 以同一 operation→claim→guard→attempt 锁序竞争，winner 永久确定结果，late object 只进 forensic quarantine | 五、七、十、十二、十七、十八 |
| P1-1 irreversible-failed 与 retry 入口不闭合 | 幂等入口稳定返回 irreversible-failed；新 retry 同事务 CAS claim retryable→reserved、替换 active operation 并创建 operation/outbox | 五、七、十八 |
| P1-2 workflow 强制 source 与 revision-origin 冲突 | source nullable，增加 source-upload/existing-revision origin 互斥约束，recovery/late-seal 不伪造 source | 五、十、十七、十八 |
| P1-3 safety freeze 无持久恢复语义 | document 增加 integrity hold none/active/resolved，withdrawn/restored 共享 incident UUID；exact restore 与显式 revoke 分离 | 五、七、十、十七、十八 |
| P1-4 uncertain 时 appearance 可能先被删 | appearance 增加 legal hold/adjudication release；人工终结后才开始 24 小时删除倒计时 | 五、十八 |
| P1-5 guard 未覆盖 legacy/DocTimeStamp | guard/attempt/status/result/winner 泛化到 organization/legacy/DocTimeStamp；LT data 明确不跨私钥/TSA边界 | 五、七、十、十一、十二、十七、十八 |

### 20.10 v10 独立自审新增并关闭的 12 项

在吸收第九轮 finding 后，重新从名称一致性、状态可达性、来源泄露、事件可追溯和 action scope 做反向审查，新增关闭如下：

| Self-review finding | v10 关闭方式 | 章节 |
|---|---|---|
| intake intent 在模型与 API 使用不同名字 | 全文统一 `create_new_document/recover_existing_document/import_existing_signed`，避免实现出现两套分支 | 四、十 |
| candidate 只按 document ID 排序，单 document 多候选无确定顺序 | manifest 增加 candidate kind/revision/source identity，并以四元组全排序；客户端仅看 public-safe projection | 四 |
| business identity 在 document 创建时必需，但旧 finalization 合同才选择报告编号 | 改为 create-new resolve 一次性冻结 canonical identity；finalization 只读同一 document identity，禁止二次选择 | 四、十 |
| duplicate policy 只查 published bytes，会漏掉其他 document 的相同 raw source；已签 bytes 又不应改挂身份 | create-new candidate 同查受权 source identity；unsigned generic source 可走受控 duplicate，已签/冻结身份 bytes 只允许 import/reuse | 四 |
| 新 business identity unique 与“legacy 同报告号不自动合并”冲突 | 新文档使用 report-number identity；重复/缺证据 legacy 使用 immutable legacy-file-id identity，保留报告号展示但不伪造合并 | 六 |
| integrity incident 被列成 entity 但没有数据模型 | 不新增空壳表；以 publication event 上的 incident UUID 对 withdrawn/restored 分组并要求同 document/revision 关联 | 五 |
| late-seal 失败/取消后 act 状态不明确 | 明确 act 只在成功时 deferred→completed；失败/拒绝/取消保持 deferred，uncertain 由 adjudication gate 阻止重激活 | 五、十八 |
| bind source enum 和 Java status path 仍残留旧合同 | 增加 late-seal source type，status/result 统一为 irreversible-executions 通用路径 | 五、十、十二 |
| LT/DocTimeStamp 已列为 operation action，却没有合法的非 workflow scope | operation 增加 document scope；LT/DocTimeStamp 绑定 current published parent claim，不伪造 workflow/request | 五、七、十二 |
| uncertainty 事务假定所有 action 都有 request/workflow | 终态投影按 action scope 处理；legacy/DocTimeStamp 无业务 request 时保持旧 published pointer | 五、七 |
| graceful-drain 仍写“强杀直接 uncertain”，绕过新 winner protocol | 强杀只进入 recovery-pending；恢复后通过同一 guard 事务采用 completed/known-failed/uncertain | 十一 |
| integrity restore 可能被理解为替换 revision identity | 固定 same UUID/path/SHA/manifests exact restore，只有 integrity-state 可经同 incident 事务恢复 ready | 五、七、十、十八 |

v10 自审后未发现仍缺合同的 P0/P1。Phase 2+ 继续 NO-GO 的原因只剩 Gate S/Gate 0、MySQL/对象存储/多实例竞态故障注入和真实 Chrome/Acrobat/Foxit/独立 PAdES 验证证据；文档修订本身不能替代这些运行时门禁。

---

## 二十一、规范基线

- [ETSI EN 319 142-1 V1.2.1 (2024-01): PAdES building blocks and baseline signatures](https://www.etsi.org/deliver/etsi_EN/319100_319199/31914201/01.02.01_60/en_31914201v010201p.pdf)
- [ETSI TS 119 102-2 V1.4.1 (2023-06): Signature Validation Report](https://www.etsi.org/deliver/etsi_TS/119100_119199/11910202/01.04.01_60/ts_11910202v010401p.pdf)
- [RFC 8785: JSON Canonicalization Scheme (JCS)](https://www.rfc-editor.org/rfc/rfc8785.html)
- [RFC 8785 verified errata: negative zero handling](https://www.rfc-editor.org/errata/rfc8785)
- [RFC 5754: SHA-2 algorithms with CMS](https://www.rfc-editor.org/info/rfc5754/)
- [RFC 5652: Cryptographic Message Syntax](https://www.rfc-editor.org/rfc/rfc5652.html)

规范结构检查、密码学验证、信任链验证、PDF 修改权限和阅读器兼容性是五个不同门禁，任何一个通过都不能替代另外四个。
