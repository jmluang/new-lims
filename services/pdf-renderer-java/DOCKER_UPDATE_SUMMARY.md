# Docker Compose 配置更新总结

## ✅ 已完成的更改

### 1. 更新 docker-compose.yml

在 `docker-compose.yml` 中添加了6个二维码配置参数：

```yaml
# 二维码配置
QR_CODE_SIZE_MM: ${QR_CODE_SIZE_MM:-15.6}
QR_CODE_MARGIN_MM: ${QR_CODE_MARGIN_MM:-10}
QR_CODE_OFFSET_LEFT_MM: ${QR_CODE_OFFSET_LEFT_MM:-0}
QR_CODE_OFFSET_TOP_MM: ${QR_CODE_OFFSET_TOP_MM:-0}
QR_CODE_WIDTH_PX: ${QR_CODE_WIDTH_PX:-200}
QR_CODE_HEIGHT_PX: ${QR_CODE_HEIGHT_PX:-200}
```

### 2. 创建 .env.example

新增 `.env.example` 文件，包含：
- 所有环境变量的完整列表
- 详细的参数说明
- 使用说明和示例

### 3. 创建 Docker 配置文档

新增 `DOCKER_CONFIG.md`，提供：
- 完整的使用指南
- 多种配置方式示例
- 配置验证方法
- 故障排除指南
- 最佳实践建议

## 📋 配置参数列表

| 参数 | 默认值 | 说明 |
|------|--------|------|
| QR_CODE_SIZE_MM | 15.6 | 二维码显示尺寸（毫米） |
| QR_CODE_MARGIN_MM | 10 | 二维码边距（毫米） |
| QR_CODE_OFFSET_LEFT_MM | 0 | 二维码左偏移（毫米） |
| QR_CODE_OFFSET_TOP_MM | 0 | 二维码自底部起的额外偏移（毫米） |
| QR_CODE_WIDTH_PX | 200 | 二维码像素宽度 |
| QR_CODE_HEIGHT_PX | 200 | 二维码像素高度 |

## 🚀 快速开始

### 方法1：使用 .env 文件
```bash
cp .env.example .env
# 编辑 .env 文件自定义配置
docker-compose up -d
```

### 方法2：命令行指定
```bash
docker-compose up -d \
  -e QR_CODE_SIZE_MM=20.0 \
  -e QR_CODE_MARGIN_MM=15.0
```

### 方法3：使用环境文件
```bash
# 创建自定义环境文件
cat > qr-config.env << EOF
QR_CODE_SIZE_MM=18.0
QR_CODE_MARGIN_MM=12.0
QR_CODE_OFFSET_LEFT_MM=5.0
QR_CODE_OFFSET_TOP_MM=120.0
QR_CODE_WIDTH_PX=250
QR_CODE_HEIGHT_PX=250
EOF

# 启动服务
docker-compose --env-file qr-config.env up -d
```

## 🔍 验证配置

查看日志确认配置生效：
```bash
docker-compose logs pdf-signer | grep "Adding QR code"
```

期望输出：
```
INFO c.e.pdfsigner.service.SignerService - Adding QR code to first page at position: x=450.2, y=720.5, size=51.0pt (18.0mm)
INFO c.e.pdfsigner.service.SignerService - Generating QR code for data: https://example.com/certificate-query?query=XDP2025100073 (size: 250x250)
```

## 📁 创建的文件

1. **docker-compose.yml** - 更新现有文件，添加二维码配置
2. **.env.example** - 新建示例环境变量文件
3. **DOCKER_CONFIG.md** - 新建 Docker 配置详细说明文档

## 💡 使用建议

1. 生产环境建议使用 `.env` 文件管理配置
2. 测试环境可以使用命令行参数快速调整
3. 团队协作时，将 `.env.example` 纳入版本控制
4. 定期查看日志确认配置生效
5. 参考 `DOCKER_CONFIG.md` 获取详细使用指南

## 🔗 相关文档

- [二维码配置参数详解](./QR_CODE_CONFIG.md)
- [Docker 配置说明](./DOCKER_CONFIG.md)
- [实现总结](./IMPLEMENTATION_SUMMARY.md)
