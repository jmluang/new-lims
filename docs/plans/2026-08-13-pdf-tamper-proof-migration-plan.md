# PDF 防篡改系统迁移（zs-lims → new-lims）

**目标：** 把 `zs-lims/` 的「PDF 防篡改系统」整体迁移到 new-lims 的 React + Laravel 13 架构，保留原有全部功能；「处理光度数据后签名」代码完整移植但默认不开启。

**状态：** 已完成。

---

## 迁移范围

| zs-lims | new-lims | 说明 |
|---|---|---|
| Filament 页面「PDF防篡改系统」 | `/pdf/signing` | 签名台，React 重写 |
| PdfVerifyTab | `/pdf/verify` | 后台文件验证 |
| PdfFileResource | `/pdf/files` | 签章台账 |
| PdfVerificationLogResource | `/pdf/verification-logs` | 验证日志 |
| DigitalSignatureResource | `/pdf/digital-signatures` | 首页盖章 |
| PerforationStampResource | `/pdf/perforation-stamps` | 骑缝章 |
| HomepageFunctionStampResource | `/pdf/function-stamps` | 首页功能章 |
| CertificateTemplateResource | `/pdf/certificate-templates` | 声明页模板 |
| `/verify`（Blade + Vue） | `/verify` | 免登录报告核验页 |
| `services/pdf-signer-java` | `services/pdf-renderer-java` | 迁移前已在仓库中，未改动 |

**未迁移：** zs-lims 的 `RsaKeyResource`（RSA 密钥管理）。Java 签章服务的 `SignerService` 全程只用 `DEFAULT_PFX_PATH` 加载 PKCS#12 材料，传入的 `signing_key_id` 在 21 处出现但没有任何一处读取，这张表对签名结果零影响，因此整套删除。

**未迁移（经确认）：** Filament 页面「PDF防篡改系统(非数字签名)」（legacy 浏览器端 pdf-lib 盖章 + `/api/pdf/sign-digest` 摘要签名 + `/api/pdf-password` 加密）。该页面依赖的 `signature` / `public_key` 字段在 `pdf_files` 表中已保留，后续如需补齐 legacy 流程无需再改表结构。

## 数据表

`digital_signatures`、`perforation_stamps`、`homepage_function_stamps`、`certificate_templates`、`pdf_files`、`pdf_verification_logs`。

与 zs-lims 的差异：

- 去掉了 `visible_in` 字段。该字段原本用于区分数字签名版与 legacy 版两个后台页面，只迁移前者后不再有意义。
- `pdf_files` 增加 `created_by_id` 外键，保留原有的 `created_by` 姓名快照，便于人员改名后仍能追溯。
- 签章配置的删除是真删除：连同已上传的图片一起移除，审计日志保留被删配置的字段值以便解释历史签章；只想临时停用请用编辑里的「启用」开关。
- 印章图片、声明页模板、签章成品统一放在私有 `pdf` 磁盘（`storage/app/private/pdf`），通过带鉴权的接口读取，不再依赖 `storage:link` 公开目录。

## 防篡改原理

签章时记录 SHA-256 + MD5 + 字节数三项指纹入库；验证时重新计算并回查台账。任何一次编辑都会改变 SHA-256，即使刻意保持字节数不变也无法通过。MD5 单独匹配而 SHA-256 不匹配会被判为疑似碰撞攻击并高亮告警。

- 后台验证：摘要在浏览器内计算，文件本身不上传；支持批量队列、失败重试、结果逐条展开。
- 公开核验：文件上传由服务端计算摘要，不信任外部调用方提交的摘要；一次最多 10 个文件；返回结果会隐去签发人、内部编号等信息。
- 签章台账在写入时快照印章/声明页的**名称**，配置被删除后历史签章仍可解释。

## 权限

新增 8 个资源：`pdf_signing`、`pdf_verification`、`pdf_files`、`pdf_verification_logs`、`pdf_digital_signatures`、`pdf_perforation_stamps`、`pdf_function_stamps`、`pdf_certificate_templates`。迁移脚本 `2026_08_13_000300_add_pdf_tamper_proof_permissions` 会把它们授予 `super_admin`。

## 处理光度数据后签名（默认关闭）

代码位于 `backend/app/Services/Pdf/PhotometricContentRemover.php`，逐页重建 PDF 并在「Photometric & Radiometric Parameters」区域覆盖白色矩形。

关闭方式是三重的：

1. `config/pdf_service.php` 的 `signing.photometric_removal_enabled` 默认 `false`；
2. 签名台接口收到 `remove_photometric_content=true` 时直接返回 422 `photometric_removal_disabled`；
3. 前端根据 `meta.photometric_removal_enabled` 隐藏该上传入口。

**开启步骤：**

```bash
cd backend
composer require setasign/fpdi-tcpdf tecnickcom/tcpdf smalot/pdfparser
```

然后在 `.env` 设置 `PDF_SIGNING_PHOTOMETRIC_REMOVAL_ENABLED=true`。

注意该功能的遮盖坐标是按英文报告模板估算的固定值，并非由文字外框推导；报告排版一旦变化就可能盖错位置，这也是它默认关闭的原因。

## 验证

- 后端：`php artisan test`（184 项，其中 `tests/Feature/Pdf/` 24 项）
- 前端：`npm run lint`、`npx tsc -b`、`npm run test`（149 项）
