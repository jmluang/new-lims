# 二维码配置参数说明

## 概述

PDF证书查询二维码功能已完全参数化，所有配置项都可以通过环境变量进行自定义。这些参数遵循与现有签章配置（如`FRONT_SEAL_OFFSET_LEFT_MM`）相同的命名约定。

## 配置参数列表

### 1. 二维码像素尺寸（生成时）

#### `QR_CODE_WIDTH_PX`
- **描述**: 生成二维码图片的宽度（像素）
- **默认值**: 200
- **示例**:
  ```bash
  export QR_CODE_WIDTH_PX=300
  ```

#### `QR_CODE_HEIGHT_PX`
- **描述**: 生成二维码图片的高度（像素）
- **默认值**: 200
- **示例**:
  ```bash
  export QR_CODE_HEIGHT_PX=300
  ```

### 2. 二维码显示尺寸（PDF中）

#### `QR_CODE_SIZE_MM`
- **描述**: 二维码在PDF中的显示尺寸（毫米）
- **默认值**: 15.6（约56点）
- **计算**: 1mm = 2.83465点
- **示例**:
  ```bash
  export QR_CODE_SIZE_MM=20.0  # 显示为20mm大小
  ```

### 3. 边距配置

#### `QR_CODE_MARGIN_MM`
- **描述**: 二维码距离页面边缘的边距（毫米）
- **默认值**: 10.0
- **说明**: 控制二维码与页面右上角的距离
- **示例**:
  ```bash
  export QR_CODE_MARGIN_MM=15.0  # 增加边距到15mm
  ```

### 4. 位置偏移配置

#### `QR_CODE_OFFSET_LEFT_MM`
- **描述**: 二维码额外的左偏移量（毫米）
- **默认值**: 0.0
- **说明**: 在默认右上角位置基础上的额外偏移，用于精确调整位置
- **示例**:
  ```bash
  export QR_CODE_OFFSET_LEFT_MM=5.0  # 向左偏移5mm
  ```

#### `QR_CODE_OFFSET_TOP_MM`
- **描述**: 二维码自页面底部起算的额外偏移量（毫米）
- **默认值**: 0.0
- **说明**: 与 `QR_CODE_MARGIN_MM` 相加得到距离底部的总间距；若值过大将自动钳制到顶部边界
- **示例**:
  ```bash
  export QR_CODE_OFFSET_TOP_MM=120.0  # 自底部向上偏移120mm
  ```

## 使用场景

### 场景1: 调整二维码大小
如果二维码太小或太大，可以调整显示尺寸：
```bash
export QR_CODE_SIZE_MM=25.0
```

### 场景2: 调整边距
如果二维码距离边缘太近或太远，可以调整边距：
```bash
export QR_CODE_MARGIN_MM=15.0
```

### 场景3: 精确定位
如果需要将二维码移动到特定位置，可以使用偏移参数：
```bash
export QR_CODE_OFFSET_LEFT_MM=10.0
export QR_CODE_OFFSET_TOP_MM=5.0
```

### 场景4: 高分辨率二维码
如果需要更清晰的二维码，可以增加像素尺寸：
```bash
export QR_CODE_WIDTH_PX=400
export QR_CODE_HEIGHT_PX=400
```

## 位置计算公式

二维码在PDF第一页的位置计算如下：

```
x = 页面宽度 - QR_CODE_SIZE_MM点 - QR_CODE_MARGIN_MM点 - QR_CODE_OFFSET_LEFT_MM点
y = clamp(QR_CODE_MARGIN_MM点 + QR_CODE_OFFSET_TOP_MM点, 0, 页面高度 - QR_CODE_SIZE_MM点 - QR_CODE_MARGIN_MM点)
```

其中：
- 1mm = 2.83465点（PDF点单位）

## Docker部署示例

```bash
docker run --rm -p 127.0.0.1:8080:8081 \
  -e DEFAULT_PFX_PATH=/keys/signer.pfx \
  -e DEFAULT_PFX_PASS="$DEFAULT_PFX_PASS" \
  -e PDF_SERVICE_HMAC_KEYS="$PDF_SERVICE_HMAC_KEYS" \
  -e QR_CODE_SIZE_MM=20.0 \
  -e QR_CODE_MARGIN_MM=12.0 \
  -e QR_CODE_OFFSET_LEFT_MM=5.0 \
  -e QR_CODE_OFFSET_TOP_MM=3.0 \
  -e QR_CODE_WIDTH_PX=300 \
  -e QR_CODE_HEIGHT_PX=300 \
  pdf-signer:0.1.0
```

## 配置文件示例

创建 `.env` 文件：
```bash
# 二维码配置
QR_CODE_SIZE_MM=18.0
QR_CODE_MARGIN_MM=10.0
QR_CODE_OFFSET_LEFT_MM=0.0
QR_CODE_OFFSET_TOP_MM=0.0
QR_CODE_WIDTH_PX=250
QR_CODE_HEIGHT_PX=250

# 签章配置
DEFAULT_PFX_PATH=/keys/signer.pfx
DEFAULT_PFX_PASS=<required-secret>
PDF_SERVICE_HMAC_KEYS=<key-id:base64-secret>
```

## 验证配置

可以通过查看Java应用日志来验证配置是否生效：

```
INFO c.e.pdfsigner.service.SignerService - Adding QR code to first page at position: x=450.2, y=720.5, size=51.0pt (18.0mm)
INFO c.e.pdfsigner.service.SignerService - Generating QR code for data: https://example.com/certificate-query?query=XDP2025100073 (size: 250x250)
```

## 注意事项

1. **像素尺寸 vs 显示尺寸**:
   - `QR_CODE_WIDTH_PX`/`QR_CODE_HEIGHT_PX` 控制生成二维码的像素密度
   - `QR_CODE_SIZE_MM` 控制二维码在PDF中的实际显示大小

2. **偏移量**:
   - 正值：`LEFT`向右偏移，`TOP`向上偏移
   - 负值：`LEFT`向左偏移，`TOP`向下偏移

3. **边界检查**:
   - 系统会自动确保二维码不会超出页面边界
   - 如果配置导致二维码超出边界，系统会记录警告日志

4. **性能考虑**:
   - 更大的像素尺寸会增加二维码生成时间
   - 建议在200-400像素范围内

## 相关代码

- **配置读取**: `SignerService.getConfiguredFloat()`
- **位置计算**: `SignerService.addQrCodeToFirstPage()`
- **像素尺寸**: `SignerService.getQrCodePixelSize()`
- **二维码生成**: `SignerService.generateQrCode()`
