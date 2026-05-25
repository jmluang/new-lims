# PDF签名验证状态显示说明

## 签名验证状态的显示机制

### 1. 显示控制
签名验证状态（绿勾/红叉/黄色警告）的显示主要由 **Adobe Acrobat 自动控制**，而不是在PDF生成时预设的。

### 2. 状态图标含义

| 图标 | 含义 | 触发条件 |
|------|------|----------|
| ✅ 绿色勾 | 签名有效 | - 签名验证通过<br>- 文档未被修改<br>- 证书链完整且受信任 |
| ❌ 红色叉 | 签名无效 | - 文档被修改<br>- 签名损坏<br>- 证书已过期或被撤销 |
| ⚠️ 黄色警告 | 签名有效但有警告 | - 签名有效但证书不受信任<br>- 证书链不完整<br>- 时间戳有问题 |
| ❓ 问号 | 未验证 | - 签名尚未验证<br>- Acrobat无法验证 |

### 3. 代码实现要点

#### 3.1 签名外观设置
```java
// 创建外观字典
PDAppearanceDictionary appearance = new PDAppearanceDictionary();
appearance.setNormalAppearance(appearanceStream);
// 重要：不要设置n0/n1/n2等验证状态层，让Acrobat自动管理
widget.setAppearance(appearance);
```

#### 3.2 确保签名可验证
```java
// 设置正确的签名标志
acroForm.setSignaturesExist(true);
acroForm.setAppendOnly(true);  // 防止表单被修改
acroForm.setNeedAppearances(true);
```

### 4. 测试验证状态

#### 4.1 测试红色叉（签名失效）
1. 生成带签名的PDF
2. 用文本编辑器修改PDF中的任意内容
3. 在Acrobat中打开，签名应显示红色叉

#### 4.2 测试绿色勾（签名有效）
1. 生成带签名的PDF
2. 直接在Acrobat中打开
3. 如果证书受信任，应显示绿色勾

#### 4.3 测试黄色警告
1. 使用自签名证书签名
2. 在Acrobat中打开
3. 由于证书不受信任，显示黄色警告

### 5. 增量签名的重要性

当PDF被修改后再次签名时，使用**增量更新**（incremental update）可以：
- 保留原始签名的有效性
- 允许多个签名共存
- 让Acrobat能够显示文档的修改历史

```java
// 使用增量更新保存
currentDoc.saveIncremental(outputStream);
```

### 6. 常见问题

#### Q: 为什么签名总是显示黄色警告？
A: 通常是因为使用了自签名证书。解决方案：
- 使用受信任的CA颁发的证书
- 将自签名证书添加到Acrobat的信任列表

#### Q: 如何确保文档修改后签名显示红叉？
A: 确保：
1. 签名时设置 `setAppendOnly(true)`
2. 使用正确的签名子过滤器（如 `SUBFILTER_ADBE_PKCS7_DETACHED`）
3. 不要在签名后修改签名字典

#### Q: 能否自定义验证失败时的图标？
A: 不能。验证状态图标是Acrobat内置的，无法自定义。但可以：
- 在签名外观中包含自定义图片（始终显示）
- 使用文字说明签名信息

### 7. 最佳实践

1. **使用标准签名格式**：使用 `FILTER_ADOBE_PPKLITE` 和 `SUBFILTER_ADBE_PKCS7_DETACHED`
2. **保持签名字段简单**：让Acrobat管理验证状态显示
3. **使用增量更新**：保证签名链的完整性
4. **包含时间戳**：提供签名时间的可信证明
5. **测试不同场景**：确保各种验证状态都能正确显示