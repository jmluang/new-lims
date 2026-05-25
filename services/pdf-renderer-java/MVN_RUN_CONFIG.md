# Maven Spring Boot 运行配置指南

## 概述

当使用 `mvn spring-boot:run` 命令直接运行 PDF 签章服务时，可以通过以下几种方式配置二维码参数。

## 配置方式

### 方式1：命令行参数（推荐 - 临时测试）

使用 `-D` 参数直接在命令中设置：

```bash
mvn spring-boot:run \
  -DQR_CODE_SIZE_MM=20.0 \
  -DQR_CODE_MARGIN_MM=15.0 \
  -DQR_CODE_OFFSET_LEFT_MM=5.0 \
  -DQR_CODE_OFFSET_TOP_MM=3.0 \
  -DQR_CODE_WIDTH_PX=300 \
  -DQR_CODE_HEIGHT_PX=300
```

**优点**：
- 快速测试不同配置
- 不需要修改配置文件
- 适合临时调试

**缺点**：
- 命令行较长
- 配置不会持久化
- 每次运行都需要重新输入

### 方式2：环境变量（推荐 - 生产环境）

#### Linux/macOS：
```bash
export QR_CODE_SIZE_MM=20.0
export QR_CODE_MARGIN_MM=15.0
export QR_CODE_OFFSET_LEFT_MM=5.0
export QR_CODE_OFFSET_TOP_MM=0.0
export QR_CODE_WIDTH_PX=300
export QR_CODE_HEIGHT_PX=300

mvn spring-boot:run
```

#### Windows (CMD)：
```cmd
set QR_CODE_SIZE_MM=20.0
set QR_CODE_MARGIN_MM=15.0
set QR_CODE_OFFSET_LEFT_MM=5.0
set QR_CODE_OFFSET_TOP_MM=0.0
set QR_CODE_WIDTH_PX=300
set QR_CODE_WIDTH_PX=300

mvn spring-boot:run
```

#### Windows (PowerShell)：
```powershell
$env:QR_CODE_SIZE_MM="20.0"
$env:QR_CODE_MARGIN_MM="15.0"
$env:QR_CODE_OFFSET_LEFT_MM="5.0"
$env:QR_CODE_OFFSET_TOP_MM="0.0"
$env:QR_CODE_WIDTH_PX="300"
$env:QR_CODE_HEIGHT_PX="300"

mvn spring-boot:run
```

**优点**：
- 配置持久化（当前会话）
- 适合生产环境
- 符合 12-factor app 原则

**缺点**：
- 需要设置环境变量
- 切换配置需要重新设置

### 方式3：Maven settings.xml（团队共享）

在 `~/.m2/settings.xml` 中添加 profile：

```xml
<settings>
  <profiles>
    <profile>
      <id>pdf-signer-qr-config</id>
      <properties>
        <QR_CODE_SIZE_MM>20.0</QR_CODE_SIZE_MM>
        <QR_CODE_MARGIN_MM>15.0</QR_CODE_MARGIN_MM>
        <QR_CODE_OFFSET_LEFT_MM>5.0</QR_CODE_OFFSET_LEFT_MM>
        <QR_CODE_OFFSET_TOP_MM>0.0</QR_CODE_OFFSET_TOP_MM>
        <QR_CODE_WIDTH_PX>300</QR_CODE_WIDTH_PX>
        <QR_CODE_HEIGHT_PX>300</QR_CODE_HEIGHT_PX>
      </properties>
    </profile>
  </profiles>

  <activeProfiles>
    <activeProfile>pdf-signer-qr-config</activeProfile>
  </activeProfiles>
</settings>
```

然后直接运行：
```bash
mvn spring-boot:run
```

**优点**：
- 团队共享配置
- 一次配置多次使用
- 适合团队开发

**缺点**：
- 需要修改 Maven 配置
- 可能影响其他 Maven 项目

### 方式4：创建运行脚本（便捷）

创建 `run.sh` (Linux/macOS)：
```bash
#!/bin/bash

# 二维码配置
export QR_CODE_SIZE_MM=20.0
export QR_CODE_MARGIN_MM=15.0
export QR_CODE_OFFSET_LEFT_MM=5.0
export QR_CODE_OFFSET_TOP_MM=0.0
export QR_CODE_WIDTH_PX=300
export QR_CODE_HEIGHT_PX=300

# 其他配置
export DEFAULT_PFX_PATH=/keys/signer.pfx
export DEFAULT_PFX_PASS=changeit

# 启动应用
mvn spring-boot:run
```

创建 `run.bat` (Windows)：
```cmd
@echo off

REM 二维码配置
set QR_CODE_SIZE_MM=20.0
set QR_CODE_MARGIN_MM=15.0
set QR_CODE_OFFSET_LEFT_MM=5.0
set QR_CODE_OFFSET_TOP_MM=0.0
set QR_CODE_WIDTH_PX=300
set QR_CODE_WIDTH_PX=300

REM 其他配置
set DEFAULT_PFX_PATH=/keys/signer.pfx
set DEFAULT_PFX_PASS=changeit

REM 启动应用
mvn spring-boot:run
```

使用方法：
```bash
# Linux/macOS
chmod +x run.sh
./run.sh

# Windows
run.bat
```

**优点**：
- 一键启动
- 配置清晰可见
- 可以有多个不同配置的脚本

**缺点**：
- 需要维护多个脚本文件
- 脚本需要纳入版本控制管理

### 方式5：开发环境配置（最灵活）

修改 `src/main/resources/application.yml`：

```yaml
server:
  port: 8080

# 添加二维码配置
QR_CODE_SIZE_MM: 20.0
QR_CODE_MARGIN_MM: 15.0
QR_CODE_OFFSET_LEFT_MM: 5.0
QR_CODE_OFFSET_TOP_MM: 0.0
QR_CODE_WIDTH_PX: 300
QR_CODE_HEIGHT_PX: 300

# 其他配置...
```

**注意**：这种方式的变量名需要匹配 Spring Boot 的配置属性格式。

**优点**：
- 配置与代码在一起
- IntelliJ IDEA 等 IDE 可以识别
- 适合开发环境

**缺点**：
- 需要修改代码仓库
- 不同环境需要不同配置
- 不推荐用于生产环境

## 完整示例

### 开发环境快速启动脚本

创建 `dev-run.sh`：
```bash
#!/bin/bash

echo "==================================="
echo "启动 PDF 签章服务 (开发配置)"
echo "==================================="

# 设置二维码配置
export QR_CODE_SIZE_MM=18.0      # 适中尺寸
export QR_CODE_MARGIN_MM=10.0    # 标准边距
export QR_CODE_OFFSET_LEFT_MM=0  # 无偏移
export QR_CODE_OFFSET_TOP_MM=0   # 无偏移
export QR_CODE_WIDTH_PX=250      # 中等分辨率
export QR_CODE_HEIGHT_PX=250

# 显示配置
echo "二维码配置："
echo "  尺寸: ${QR_CODE_SIZE_MM}mm"
echo "  边距: ${QR_CODE_MARGIN_MM}mm"
echo "  偏移: L=${QR_CODE_OFFSET_LEFT_MM}mm, T=${QR_CODE_OFFSET_TOP_MM}mm"
echo "  像素: ${QR_CODE_WIDTH_PX}x${QR_CODE_HEIGHT_PX}"
echo ""

# 启动服务
mvn spring-boot:run
```

### 生产环境配置

创建 `prod-run.sh`：
```bash
#!/bin/bash

echo "==================================="
echo "启动 PDF 签章服务 (生产配置)"
echo "==================================="

# 从环境变量读取配置（生产环境应该使用外部配置管理）
export QR_CODE_SIZE_MM=${QR_CODE_SIZE_MM:-15.6}
export QR_CODE_MARGIN_MM=${QR_CODE_MARGIN_MM:-10}
export QR_CODE_OFFSET_LEFT_MM=${QR_CODE_OFFSET_LEFT_MM:-0}
export QR_CODE_OFFSET_TOP_MM=${QR_CODE_OFFSET_TOP_MM:-0}
export QR_CODE_WIDTH_PX=${QR_CODE_WIDTH_PX:-200}
export QR_CODE_HEIGHT_PX=${QR_CODE_HEIGHT_PX:-200}

# 显示配置
echo "二维码配置："
echo "  尺寸: ${QR_CODE_SIZE_MM}mm"
echo "  边距: ${QR_CODE_MARGIN_MM}mm"
echo "  偏移: L=${QR_CODE_OFFSET_LEFT_MM}mm, T=${QR_CODE_OFFSET_TOP_MM}mm"
echo "  像素: ${QR_CODE_WIDTH_PX}x${QR_CODE_HEIGHT_PX}"
echo ""

# 启动服务
mvn spring-boot:run
```

## 不同场景推荐配置

### 场景1：本地开发测试
```bash
mvn spring-boot:run \
  -DQR_CODE_SIZE_MM=20.0 \
  -DQR_CODE_MARGIN_MM=15.0 \
  -DQR_CODE_WIDTH_PX=300 \
  -DQR_CODE_HEIGHT_PX=300
```

### 场景2：功能演示
```bash
export QR_CODE_SIZE_MM=25.0
export QR_CODE_MARGIN_MM=20.0
export QR_CODE_OFFSET_LEFT_MM=10.0
export QR_CODE_OFFSET_TOP_MM=5.0
export QR_CODE_WIDTH_PX=400
export QR_CODE_HEIGHT_PX=400
mvn spring-boot:run
```

### 场景3：性能测试
```bash
export QR_CODE_SIZE_MM=12.0
export QR_CODE_MARGIN_MM=8.0
export QR_CODE_WIDTH_PX=150
export QR_CODE_HEIGHT_PX=150
mvn spring-boot:run
```

### 场景4：打印优化
```bash
mvn spring-boot:run \
  -DQR_CODE_SIZE_MM=10.0 \
  -DQR_CODE_MARGIN_MM=5.0 \
  -DQR_CODE_OFFSET_LEFT_MM=0 \
  -DQR_CODE_OFFSET_TOP_MM=0
```

## 验证配置

启动后查看日志：
```bash
mvn spring-boot:run 2>&1 | grep "Adding QR code"
```

期望输出：
```
INFO  c.e.pdfsigner.service.SignerService - Adding QR code to first page at position: x=450.2, y=720.5, size=51.0pt (18.0mm)
```

## 常见问题

### Q1：命令行参数不生效？
A1：确保使用 `-D` 前缀，并且参数名完全匹配：
```bash
# 正确
mvn spring-boot:run -DQR_CODE_SIZE_MM=20.0

# 错误
mvn spring-boot:run -Dqr_code_size_mm=20.0
```

### Q2：如何设置多个配置？
A2：可以使用反斜杠换行：
```bash
mvn spring-boot:run \
  -DQR_CODE_SIZE_MM=20.0 \
  -DQR_CODE_MARGIN_MM=15.0 \
  -DQR_CODE_WIDTH_PX=300 \
  -DQR_CODE_HEIGHT_PX=300
```

### Q3：环境变量和命令行参数优先级？
A3：命令行参数 `-D` 会覆盖环境变量：
```bash
export QR_CODE_SIZE_MM=15.0
mvn spring-boot:run -DQR_CODE_SIZE_MM=20.0
# 最终使用的是 20.0
```

### Q4：如何在 IDE 中配置？
A4：在 IntelliJ IDEA 中：
1. Run → Edit Configurations
2. 选择 Spring Boot 配置
3. 在 "Program arguments" 中添加参数
4. 或在 "VM options" 中添加 `-DQR_CODE_SIZE_MM=20.0`

## 最佳实践

1. **开发环境**：使用命令行参数快速调整
2. **测试环境**：使用环境变量
3. **生产环境**：使用外部配置管理（如 Docker、环境变量等）
4. **团队协作**：使用 Maven settings.xml 或运行脚本
5. **文档化**：为每个环境创建对应的运行脚本

## 相关文档

- [二维码配置参数详解](./QR_CODE_CONFIG.md)
- [Docker 配置说明](./DOCKER_CONFIG.md)
- [Spring Boot 外部化配置](https://docs.spring.io/spring-boot/docs/current/reference/html/features.html#features.external-config)
