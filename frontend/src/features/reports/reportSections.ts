import { Building2, Files, Gauge, SlidersHorizontal, Thermometer } from 'lucide-react'

export const reportSections = [
  { id: 'basic', title: '基础资料', icon: Building2 },
  { id: 'conditions', title: '测试条件', icon: Thermometer },
  { id: 'measurements', title: '测量结果', icon: Gauge },
  { id: 'equipment', title: '设备与不确定度', icon: SlidersHorizontal },
  { id: 'files', title: '照片与附录', icon: Files },
] as const

export type ReportSectionId = typeof reportSections[number]['id']

export function sectionForGroup(firstField?: string): ReportSectionId {
  if (firstField === 'lab_name' || firstField === 'product_name') return 'basic'
  if (firstField === 'ambient_temp') return 'conditions'
  if (firstField === 'c_step' || firstField === 'u_std_lamp') return 'equipment'
  return 'measurements'
}
