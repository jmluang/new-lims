import { Building2, Files, Gauge, SlidersHorizontal, Thermometer } from 'lucide-react'

export const reportSections = [
  { id: 'basic', title: '基础资料', description: '核对实验室、样品资料与引用标准。', icon: Building2 },
  { id: 'conditions', title: '测试条件', description: '记录本次测试的环境、方向、稳定时间及测试周期。', icon: Thermometer },
  { id: 'measurements', title: '测量结果', description: '核对电气、色度和光度结果，计算值也可人工修正。', icon: Gauge },
  { id: 'equipment', title: '设备与不确定度', description: '选择实际使用设备，补充校准溯源与不确定度分量。', icon: SlidersHorizontal },
  { id: 'files', title: '照片与附录', description: '保存样品照片及报告 PDF 附录，原始测量文件在对应参数区上传。', icon: Files },
] as const

export type ReportSectionId = typeof reportSections[number]['id']

export function sectionForGroup(firstField?: string): ReportSectionId {
  if (firstField === 'lab_name' || firstField === 'product_name') return 'basic'
  if (firstField === 'ambient_temp') return 'conditions'
  if (firstField === 'c_step' || firstField === 'u_std_lamp') return 'equipment'
  return 'measurements'
}
