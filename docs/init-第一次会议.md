本次会议主要讨论了新系统的功能规划，重点明确了用户权限、日志、设备管理及标签打印等功能的具体需求。
小结
​​1. 系统整体规划​​

新系统将采用新旧并行的方式上线，先完成新系统的开发，待全部功能完成后，再逐步将旧系统的数据和功能迁移至新平台。
初期版本将专注于网页后台，暂不考虑小程序。

​​2. 用户权限与系统设置​​

用户权限将采用分组管理，权限将细化到具体字段，如允许不同用户组查看或隐藏报价、电话号码等敏感信息。
系统将包含用户管理、数据库备份和日志记录等功能。

​​3. 客户与设备管理​​

客户管理将分离客户基本信息和联系人信息。
设备管理模块将增加“放置场所”功能，支持多级目录的分类管理。
设备管理需增加标签打印功能，支持通过条码打印机打印包含设备编号、名称和二维码的标签。

待办
​​1. 新系统功能开发​​

开发用户权限管理功能，需支持按字段进行精细化控制。
开发数据库备份功能。先按每天备份，通过脚本的方式，所以需要写一个脚本
开发详细的日志记录功能，需完整记录所有用户操作。
开发设备管理中的“放置场所”功能，是一个类似分类的功能，只是描述不同，需要加相应的菜单，和单独的页面设置。
开发设备标签打印功能，支持打印4cm×6cm的标签。参考这段内容：
```
设备标签打印功能在 [equipment_label.php](example/equipment_label.php)。具体实现细节如下：

## 入参与数据查询
- 接收 GET 参数 `ids`（逗号分隔多个设备 ID）或 `id`（单个设备），通过 `intval` 强制转整数防注入 ([equipment_label.php:5-10](example/equipment_label.php:5))
- 用 `IN (?,?,...)` 预编译语句批量查询 `equipment` 表的 `equip_no`（设备编号）和 `equip_name`（设备名称）([equipment_label.php:16-21](example/equipment_label.php:16))
- 权限校验 `check_permission('viewer')` ([equipment_label.php:3](example/equipment_label.php:3))

## 标签布局（CSS）
- 单张标签尺寸固定 **4cm × 6cm**（`@page size: 4cm 6cm; margin:0`）([equipment_label.php:33-36](example/equipment_label.php:33))
- flex 居中排版，多张标签用 `page-break-after: always` 分页，最后一张用 `page-break-after: avoid` 避免空白页 ([equipment_label.php:52-57](example/equipment_label.php:52))
- 内容自上而下：设备编号（14px 粗体）→ 设备名称（12px）→ 二维码 → 页脚 "XPD_LIMS"（7px 灰色）

## 二维码生成
- 引入 CDN 库 `qrcodejs@1.0.0` ([equipment_label.php:80](example/equipment_label.php:80))
- 每个 `.qrcode` 容器把 `equip_no` 放在 `data-text` 属性里（用 `addslashes` 转义）([equipment_label.php:88](example/equipment_label.php:88))
- 页面 `onload` 时 `generateQRs()` 遍历生成 80×80px 的二维码，纠错级别为 M ([equipment_label.php:94-105](example/equipment_label.php:94))

## 自动打印
- `body onload="generateQRs(); setTimeout(window.print, 500);"` —— 先生成二维码，延迟 500ms 后自动唤起浏览器打印对话框 ([equipment_label.php:82](example/equipment_label.php:82))
- `@media print` 强制保留颜色（`-webkit-print-color-adjust: exact`）([equipment_label.php:75-78](example/equipment_label.php:75))

## 典型调用方式
从设备列表页跳转，例如 `equipment_label.php?ids=1,2,3` 或 `equipment_label.php?id=5`，打开即自动打印，无需额外操作。
```
