import { PdfAssetSettingsPage } from './PdfAssetSettingsPage'

export function DigitalSignatureSettingsPage() {
  return (
    <PdfAssetSettingsPage
      title="首页盖章"
      description="报告首页加盖的印章图样，同时作为 PDF 数字签名域的可视化外观。"
      path="digital-signatures"
      resource="pdf_digital_signatures"
      kind="seal"
    />
  )
}

export function PerforationStampSettingsPage() {
  return (
    <PdfAssetSettingsPage
      title="骑缝章"
      description="沿页边逐页切片盖印的骑缝章，缺页或换页会导致印章无法对齐。"
      path="perforation-stamps"
      resource="pdf_perforation_stamps"
      kind="seal"
    />
  )
}

export function FunctionStampSettingsPage() {
  return (
    <PdfAssetSettingsPage
      title="首页功能章"
      description="CMA、CNAS 等资质标识，可在签章时多选，并按顺序排列在首页顶部。"
      path="function-stamps"
      resource="pdf_function_stamps"
      kind="function_stamp"
    />
  )
}

export function CertificateTemplateSettingsPage() {
  return (
    <PdfAssetSettingsPage
      title="声明页模板"
      description="签章时可选附加到报告末尾的声明页 PDF，合并在浏览器内完成，随报告一同签名。"
      path="certificate-templates"
      resource="pdf_certificate_templates"
      kind="certificate_template"
    />
  )
}
