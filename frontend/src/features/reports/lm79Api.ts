import { api } from '../../lib/api'
import type { InspectionMedia } from '../equipment/inspectionShared'
import { blobErrorMessage } from '../system/utils'

export type EquipmentRow = { snapshot_id?: string; equipment_id?: number | null; equipment_no?: string; manufacturer?: string; next_calibration_date?: string; name: string; model: string; serial: string; cal_cert: string; cal_org: string; cal_due: string }
export type ReportData = { values: Record<string, string>; standards: string[]; equipment: EquipmentRow[]; measurement_records?: Partial<Record<MeasurementKind, number>> }
export type MeasurementKind = 'gos' | 'haas'
export type MeasurementImportResult = { kind: MeasurementKind; records: { index: number; model: string; sample: string; date: string }[]; selected_record: number | null; values: Record<string, string>; spectrum_point_count: number; notice: string }
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
  { name: 'ies', label: 'IES 文件', accept: '.ies,.txt', group: 'total_flux', description: '可选 · 上传后点击“计算配光与光效”' },
  { name: 'gos', label: 'GOS 文件', accept: '.gos', group: 'voltage', description: '选择后自动回填' },
  { name: 'haas', label: 'HAAS 文件', accept: '.haas', group: 'cct', description: '选择后自动回填色度与光谱' },
  { name: 'photos', label: '样品照片', accept: '.jpg,.jpeg,.png', group: null, description: 'JPG / PNG · 最多 10 张 · 每张 5 MB' },
  { name: 'pdf_gonio', label: '附录 A：配光测试 PDF', accept: '.pdf', group: null, description: '一份未加密、未签名的 PDF' },
  { name: 'pdf_sphere', label: '附录 B：积分球测试 PDF', accept: '.pdf', group: null, description: '一份未加密、未签名的 PDF' },
] as const

export function reportFormData(sampleId: number, reportNumber: string, data: ReportData, retained: number[], files: Record<string, File[]>) {
  const body = new FormData()
  body.append('sample_id', sampleId ? String(sampleId) : '')
  body.append('report_number', reportNumber)
  body.append('values', JSON.stringify(data.values))
  body.append('standards', JSON.stringify(data.standards))
  body.append('equipment', JSON.stringify(data.equipment))
  body.append('measurement_records', JSON.stringify(data.measurement_records ?? {}))
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

export async function parseMeasurementFile(kind: MeasurementKind, file?: File, reportId?: number, record?: number): Promise<MeasurementImportResult> {
  const body = new FormData()
  body.append('kind', kind)
  if (file) body.append('file', file)
  if (reportId) body.append('report_id', String(reportId))
  if (record) body.append('record', String(record))
  return (await api.post<{ data: MeasurementImportResult }>('/api/lm79-reports/parse-measurement', body)).data.data
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
