# Docker Compose 配置说明

## 概述

本文档说明如何在 Docker Compose 中配置 PDF 签章服务的二维码功能。

## 二维码配置参数

### 1. 环境变量列表

在 `docker-compose.yml` 中已添加以下二维码配置参数：

| 参数名 | 默认值 | 说明 |
|--------|--------|------|
| `QR_CODE_SIZE_MM` | 15.6 | 二维码显示尺寸（毫米） |
| `QR_CODE_MARGIN_MM` | 10 | 二维码边距（毫米） |
| `QR_CODE_OFFSET_LEFT_MM` | 0 | 二维码左偏移（毫米） |
| `QR_CODE_OFFSET_TOP_MM` | 0 | 二维码自底部起的额外偏移（毫米） |
| `QR_CODE_WIDTH_PX` | 200 | 二维码像素宽度 |
| `QR_CODE_HEIGHT_PX` | 200 | 二维码像素高度 |

### 2. docker-compose.yml 中的配置

```yaml
services:
  pdf-signer:
    build:
      context: .
      dockerfile: Dockerfile
    image: pdf-signer:local
    ports:
      - "127.0.0.1:8080:8081"
    environment:
      # ... 其他配置 ...

      # 二维码配置
      QR_CODE_SIZE_MM: ${QR_CODE_SIZE_MM:-15.6}
      QR_CODE_MARGIN_MM: ${QR_CODE_MARGIN_MM:-10}
      QR_CODE_OFFSET_LEFT_MM: ${QR_CODE_OFFSET_LEFT_MM:-0}
      QR_CODE_OFFSET_TOP_MM: ${QR_CODE_OFFSET_TOP_MM:-0}
      QR_CODE_WIDTH_PX: ${QR_CODE_WIDTH_PX:-200}
      QR_CODE_HEIGHT_PX: ${QR_CODE_HEIGHT_PX:-200}

    volumes:
      - ./keys:/keys:ro
    restart: unless-stopped
```

## 使用方式

### 方式1：使用 .env 文件

1. 复制示例文件：
```bash
cp .env.example .env
```

2. 编辑 `.env` 文件，根据需要修改二维码配置：
```bash
# 自定义二维码配置
QR_CODE_SIZE_MM=20.0
QR_CODE_MARGIN_MM=15.0
QR_CODE_OFFSET_LEFT_MM=5.0
QR_CODE_OFFSET_TOP_MM=120.0
QR_CODE_WIDTH_PX=300
QR_CODE_HEIGHT_PX=300
```

3. 启动服务：
```bash
docker-compose up -d
```

### 方式2：命令行直接指定

启动时直接通过 `-e` 参数指定环境变量：

```bash
docker-compose up -d \
  -e QR_CODE_SIZE_MM=20.0 \
  -e QR_CODE_MARGIN_MM=15.0 \
  -e QR_CODE_OFFSET_LEFT_MM=5.0 \
  -e QR_CODE_OFFSET_TOP_MM=3.0 \
  -e QR_CODE_WIDTH_PX=300 \
  -e QR_CODE_HEIGHT_PX=300
```

### 方式3：使用环境文件

创建自定义环境文件（如 `qr-config.env`）：

```bash
# qr-config.env
QR_CODE_SIZE_MM=18.0
QR_CODE_MARGIN_MM=12.0
QR_CODE_OFFSET_LEFT_MM=0.0
QR_CODE_OFFSET_TOP_MM=0.0
QR_CODE_WIDTH_PX=250
QR_CODE_HEIGHT_PX=250
```

使用该文件：

```bash
docker-compose --env-file qr-config.env up -d
```

## 配置示例

### 示例1：标准配置
```yaml
environment:
  QR_CODE_SIZE_MM: 15.6    # 标准尺寸
  QR_CODE_MARGIN_MM: 10    # 标准边距
  QR_CODE_OFFSET_LEFT_MM: 0
  QR_CODE_OFFSET_TOP_MM: 0
  QR_CODE_WIDTH_PX: 200
  QR_CODE_HEIGHT_PX: 200
```

### 示例2：大尺寸配置
```yaml
environment:
  QR_CODE_SIZE_MM: 25.0    # 更大尺寸
  QR_CODE_MARGIN_MM: 15    # 更大边距
  QR_CODE_OFFSET_LEFT_MM: 0
  QR_CODE_OFFSET_TOP_MM: 0
  QR_CODE_WIDTH_PX: 400    # 高分辨率
  QR_CODE_HEIGHT_PX: 400
```

### 示例3：自定义位置配置
```yaml
environment:
  QR_CODE_SIZE_MM: 20.0
  QR_CODE_MARGIN_MM: 10
  QR_CODE_OFFSET_LEFT_MM: 10.0   # 向右偏移
  QR_CODE_OFFSET_TOP_MM: 80.0    # 自底部向上偏移
  QR_CODE_WIDTH_PX: 300
  QR_CODE_HEIGHT_PX: 300
```

### 示例4：紧凑布局配置
```yaml
environment:
  QR_CODE_SIZE_MM: 12.0    # 较小尺寸
  QR_CODE_MARGIN_MM: 5     # 较小边距
  QR_CODE_OFFSET_LEFT_MM: 0
  QR_CODE_OFFSET_TOP_MM: 0
  QR_CODE_WIDTH_PX: 200
  QR_CODE_HEIGHT_PX: 200
```

## 验证配置

### 1. 查看服务日志

启动后查看二维码配置是否生效：

```bash
docker-compose logs pdf-signer | grep "Adding QR code"
```

期望看到类似输出：
```
INFO  c.e.pdfsigner.service.SignerService - Adding QR code to first page at position: x=450.2, y=720.5, size=51.0pt (18.0mm)
INFO  c.e.pdfsigner.service.SignerService - Generating QR code for data: https://example.com/certificate-query?query=XDP2025100073 (size: 250x250)
```

### 2. 测试 API

接口启用固定十行 `PDF-HMAC-V1` 协议，metadata 与 part manifest 使用受限 RFC 8785 JCS，nonce 以 Redis AOF 持久化并固定保留 300 秒，body 接收门限为 120 秒。调用方必须使用与 Laravel `PdfRendererClient` 相同的签名协议；禁止使用未认证的裸 `curl` 触发 PDF 写入或私钥调用。

检查返回的 JSON 响应中的 `cover_fields` 字段。

## 完整配置示例

### docker-compose.yml 完整示例

```yaml
version: '3.8'

services:
  pdf-signer:
    build:
      context: .
      dockerfile: Dockerfile
    image: pdf-signer:local
    ports:
      - "127.0.0.1:8080:8081"
    environment:
      # 证书配置
      DEFAULT_PFX_PATH: ${DEFAULT_PFX_PATH:-/keys/signer.pfx}
      DEFAULT_PFX_PASS: ${DEFAULT_PFX_PASS:?DEFAULT_PFX_PASS is required}
      PDF_SERVICE_HMAC_KEYS: ${PDF_SERVICE_HMAC_KEYS:?PDF_SERVICE_HMAC_KEYS is required}

      # 功能章配置
      FUNCTION_STAMP_TOP_MARGIN_MM: ${FUNCTION_STAMP_TOP_MARGIN_MM:-32}
      FUNCTION_STAMP_LEFT_MARGIN_MM: ${FUNCTION_STAMP_LEFT_MARGIN_MM:-25}

      # 首页盖章配置
      FRONT_SEAL_OFFSET_LEFT_MM: ${FRONT_SEAL_OFFSET_LEFT_MM:-45}
      FRONT_SEAL_OFFSET_UP_MM: ${FRONT_SEAL_OFFSET_UP_MM:-8}
      FRONT_SEAL_PAGE3_OFFSET_LEFT_MM: ${FRONT_SEAL_PAGE3_OFFSET_LEFT_MM:-15}
      FRONT_SEAL_PAGE3_OFFSET_UP_MM: ${FRONT_SEAL_PAGE3_OFFSET_UP_MM:-5}
      FRONT_SEAL_SIZE_MM: ${FRONT_SEAL_SIZE_MM:-35}

      # 骑缝章配置
      PERFORATION_STAMP_HEIGHT_MM: ${PERFORATION_STAMP_HEIGHT_MM:-47}
      PERFORATION_MULTI_STATE_APPEARANCE: false

      # 二维码配置
      QR_CODE_SIZE_MM: ${QR_CODE_SIZE_MM:-15.6}
      QR_CODE_MARGIN_MM: ${QR_CODE_MARGIN_MM:-10}
      QR_CODE_OFFSET_LEFT_MM: ${QR_CODE_OFFSET_LEFT_MM:-0}
      QR_CODE_OFFSET_TOP_MM: ${QR_CODE_OFFSET_TOP_MM:-0}
      QR_CODE_WIDTH_PX: ${QR_CODE_WIDTH_PX:-200}
      QR_CODE_HEIGHT_PX: ${QR_CODE_HEIGHT_PX:-200}

    volumes:
      - ./keys:/keys:ro
    restart: unless-stopped
```

### .env 完整示例

参考 `.env.example` 文件，其中包含了所有配置参数和详细说明。

## 故障排除

### 1. 配置不生效

**症状**：二维码配置修改后，生成的二维码位置或大小没有变化

**解决方案**：
- 确保使用 `docker-compose down` 停止服务
- 重新启动：`docker-compose up -d`
- 检查日志确认配置读取：`docker-compose logs pdf-signer | grep "QR code"`

### 2. 偏移量效果相反

**症状**：设置左偏移后，二维码向左移动而不是向右

**说明**：
- `QR_CODE_OFFSET_LEFT_MM` 的正值会使二维码向右偏移
- `QR_CODE_OFFSET_TOP_MM` 允许设置负值，表示向下偏移（接近底部时需注意尺寸）

### 3. 二维码超出页面边界

**症状**：生成的二维码位置不正确或被裁剪

**解决方案**：
- 减小 `QR_CODE_SIZE_MM` 值
- 增加 `QR_CODE_MARGIN_MM` 值
- 调整偏移量：`QR_CODE_OFFSET_LEFT_MM` 和 `QR_CODE_OFFSET_TOP_MM`

## 最佳实践

1. **使用 .env 文件**：便于管理和版本控制
2. **记录配置变更**：在团队中共享配置变更
3. **测试配置**：在生产环境部署前测试所有配置
4. **文档化**：为自定义配置添加注释
5. **监控日志**：定期检查日志以验证配置生效

## 相关文档

- [二维码配置参数说明](./QR_CODE_CONFIG.md)
- [实现总结](./IMPLEMENTATION_SUMMARY.md)
- [Docker 官方文档](https://docs.docker.com/compose/)
