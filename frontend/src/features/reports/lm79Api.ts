import { api } from '../../lib/api'
import type { InspectionMedia } from '../equipment/inspectionShared'
import { blobErrorMessage } from '../system/utils'

export type EquipmentRow = { snapshot_id?: string; equipment_id?: number | null; equipment_no?: string; manufacturer?: string; next_calibration_date?: string; name: string; model: string; serial: string; cal_cert: string; cal_org: string; cal_due: string }
export type ReportData = { values: Record<string, string>; standards: string[]; equipment: EquipmentRow[] }
export type ReportMedia = Omit<InspectionMedia, 'collection'> & { collection: string }
export type Report = { id: number; sample_id: number | null; sample_snapshot: { sample_no: string; sample_name: string; model: string; order_no: string }; report_number: string; data: ReportData; locked: boolean; document_uuid: string | null; media: ReportMedia[]; updated_at: string }
export type ReportSummary = {
  id: number
  report_number: string
  sample_no: string
  order_no: string
  product_name: string
  model: string
  applicant: string
  created_by_name: string | null
  attachment_count: number
  spectrum_point_count: number
  locked: boolean
  document_uuid: string | null
  updated_at: string
}
export type ReportField = { name: string; label: string; default: string; numeric: boolean; type: string }
export type ReportOptions = { groups: { title: string; fields: ReportField[] }[]; defaults: Record<string, string> }
export type SampleOption = { id: number; snapshot: Report['sample_snapshot']; data: ReportData }
export const attachmentTypes = [
  { name: 'ies', label: 'IES 配光文件', accept: '.ies,.txt', group: 'total_flux', description: '上传 IES 后，点击“计算配光与光效”获取光度参数与配光明细。' },
  { name: 'gos', label: 'GOS 电气原始文件', accept: '.gos', group: 'voltage', description: '电气参数的数据来源。解析规则补充前，保存原始文件并手工录入下方结果。' },
  { name: 'haas', label: 'HAAS 色度与光谱原始文件', accept: '.haas', group: 'cct', description: '同一份 HAAS 用于色度参数及下方光谱数据，无需重复上传。解析规则补充前可手工录入。' },
  { name: 'photos', label: '样品照片（最多 10 张，每张 5 MB）', accept: '.jpg,.jpeg,.png', group: null, description: '' },
  { name: 'pdf_gonio', label: '附录 A：配光测试 PDF', accept: '.pdf', group: null, description: '' },
  { name: 'pdf_sphere', label: '附录 B：积分球测试 PDF', accept: '.pdf', group: null, description: '' },
] as const

export function reportFormData(sampleId: number, reportNumber: string, data: ReportData, retained: number[], files: Record<string, File[]>) {
  const body = new FormData()
  body.append('sample_id', sampleId ? String(sampleId) : '')
  body.append('report_number', reportNumber)
  body.append('values', JSON.stringify(data.values))
  body.append('standards', JSON.stringify(data.standards))
  body.append('equipment', JSON.stringify(data.equipment))
  body.append('retained_media_ids', JSON.stringify(retained))
  for (const [collection, uploads] of Object.entries(files)) {
    for (const file of uploads) body.append(collection === 'photos' ? 'photos[]' : collection, file)
  }
  return body
}

export async function saveReport(id: number | undefined, body: FormData): Promise<Report> {
  if (id) body.append('_method', 'PUT')
  const response = await api.post<{ data: Report }>(`/api/lm79-reports${id ? `/${id}` : ''}`, body)
  initialNumberAllocation = undefined
  return response.data.data
}

export async function generateReportNumber(): Promise<string> {
  return (await api.post<{ data: { report_number: string } }>('/api/lm79-reports/report-number')).data.data.report_number
}

export type ReportNumberAllocation = { report_number?: string; error?: unknown }
let initialNumberAllocation: { key: string; promise: Promise<ReportNumberAllocation> } | undefined

export function loadReportNumber({ preload, location }: {
  preload: boolean
  location?: { href: string; state: { __TSR_key?: string } }
}): Promise<ReportNumberAllocation> {
  // Route entry owns allocation; rendering or hovering a link must not consume another number.
  if (preload) return Promise.resolve({})
  const key = location?.state.__TSR_key ?? location?.href ?? 'new-report'
  if (initialNumberAllocation?.key === key) return initialNumberAllocation.promise
  const promise = generateReportNumber().then(report_number => ({ report_number })).catch(error => ({ error }))
  initialNumberAllocation = { key, promise }

  return promise
}

export async function previewReportPdf(id: number): Promise<Blob> {
  try {
    return (await api.get<Blob>(`/api/lm79-reports/${id}/pdf`, { responseType: 'blob' })).data
  } catch (error) {
    throw new Error(await blobErrorMessage(error, 'PDF 生成失败'), { cause: error })
  }
}
