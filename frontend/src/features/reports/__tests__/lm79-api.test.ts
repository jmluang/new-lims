import { beforeEach, describe, expect, it, vi } from 'vitest'
import { applyMeasurementImport, generateReportNumber, loadReportNumber, parseMeasurementFile, reportFormData, saveReport, type MeasurementImportResult } from '../lm79Api'

const postNumber = vi.hoisted(() => vi.fn())
vi.mock('../../../lib/api', () => ({ api: { post: postNumber } }))
beforeEach(() => { postNumber.mockReset() })
const navigation = (key: string) => ({ preload: false, location: { href: '/reports/lm79/new', state: { __TSR_key: key } } })

describe('LM-79 editor submission contract', () => {
  it('uploads IES for immediate parsing using current input power without creating a binary record choice', async () => {
    const result: MeasurementImportResult = { kind: 'ies', records: [], selected_record: 1, values: { total_flux: '628.3185', efficacy: '31.4159' }, spectrum_point_count: 0, notice: '已解析并回填配光参数。' }
    postNumber.mockResolvedValue({ data: { data: result } })
    const file = new File(['TILT=NONE'], 'reading.IES')
    expect(await parseMeasurementFile('ies', file, undefined, undefined, '20')).toEqual(result)
    const body = postNumber.mock.calls[0][1] as FormData
    expect(body.get('kind')).toBe('ies')
    expect(body.get('file')).toBe(file)
    expect(body.get('power')).toBe('20')
    const current = { values: { power: '20' }, standards: [], equipment: [], measurement_records: { haas: 2 } }
    const applied = applyMeasurementImport(current, result, false)
    expect(applied.values).toEqual({ power: '20', ...result.values })
    expect(applied.measurement_records).toEqual({ haas: 2 })
  })

  it('preserves native GOS values for either upload order', () => {
    const current = { values: { beam_angle: '42', total_flux: '600' }, standards: [], equipment: [] }
    const ies: MeasurementImportResult = { kind: 'ies', records: [], selected_record: 1, values: { beam_angle: '20', total_flux: '620' }, spectrum_point_count: 0, notice: '' }
    const gos: MeasurementImportResult = { ...ies, kind: 'gos', values: { beam_angle: '60', total_flux: '600' } }
    expect(applyMeasurementImport(current, ies, true)).toBe(current)
    expect(applyMeasurementImport(applyMeasurementImport(current, ies, false), gos, true).values).toEqual(gos.values)
  })

  it('does not allocate numbers during route preloading', async () => {
    expect(await loadReportNumber({ preload: true })).toEqual({})
    expect(postNumber).not.toHaveBeenCalled()
  })

  it('allocates once when entering the new report route', async () => {
    postNumber.mockResolvedValue({ data: { data: { report_number: 'XPD20261004-001' } } })
    expect(await loadReportNumber(navigation('first-entry'))).toEqual({ report_number: 'XPD20261004-001' })
    expect(postNumber).toHaveBeenCalledOnce()
    expect(postNumber).toHaveBeenCalledWith('/api/lm79-reports/report-number')
  })

  it('makes a new allocation when regeneration is requested', async () => {
    postNumber.mockResolvedValueOnce({ data: { data: { report_number: 'XPD20261004-001' } } })
      .mockResolvedValueOnce({ data: { data: { report_number: 'XPD20261004-002' } } })
    await loadReportNumber(navigation('regeneration-entry'))
    expect(await generateReportNumber()).toBe('XPD20261004-002')
    expect(postNumber).toHaveBeenCalledTimes(2)
  })

  it('preserves allocation failure for manual retry without automatically requesting another number', async () => {
    const error = new Error('Network Error')
    postNumber.mockRejectedValue(error)
    expect(await loadReportNumber(navigation('failed-entry'))).toEqual({ error })
    expect(postNumber).toHaveBeenCalledOnce()
  })

  it('shares the initial allocation when route entry is replayed for the same navigation', async () => {
    postNumber.mockResolvedValue({ data: { data: { report_number: 'XPD20261004-010' } } })
    const context = navigation('replayed-entry')
    const allocations = await Promise.all([loadReportNumber(context), loadReportNumber(context)])
    expect(allocations).toEqual([{ report_number: 'XPD20261004-010' }, { report_number: 'XPD20261004-010' }])
    expect(postNumber).toHaveBeenCalledOnce()
  })

  it('does not reuse a saved report number when returning to the creation page', async () => {
    const context = navigation('saved-entry')
    postNumber.mockResolvedValueOnce({ data: { data: { report_number: 'XPD20261004-011' } } })
      .mockResolvedValueOnce({ data: { data: { id: 7, report_number: 'XPD20261004-011' } } })
      .mockResolvedValueOnce({ data: { data: { report_number: 'XPD20261004-012' } } })
    await loadReportNumber(context)
    await saveReport(undefined, new FormData())
    expect(await loadReportNumber(context)).toEqual({ report_number: 'XPD20261004-012' })
    expect(postNumber).toHaveBeenCalledTimes(3)
  })

  it('keeps manual measurement corrections and unescaped company text in multipart JSON', () => {
    const data = { values: { lab_name: 'A&B Laboratory', total_flux: '1234.5678', beam_angle: '36.5' }, standards: ['ANSI/IES LM-79-19'], equipment: [] }
    const body = reportFormData(5, 'REPORT-001', data, [8], { gos: [new File(['instrument bytes'], 'result.GOS')] })
    expect(JSON.parse(String(body.get('values')))).toEqual(data.values)
    expect(JSON.parse(String(body.get('retained_media_ids')))).toEqual([8])
    expect((body.get('gos') as File).name).toBe('result.GOS')
    expect(body.get('sample_id')).toBe('5')
  })

  it('explicitly submits empty retention and keeps a deleted sample link empty', () => {
    const body = reportFormData(0, 'REPORT-002', { values: {}, standards: [], equipment: [] }, [], {})
    expect(body.get('retained_media_ids')).toBe('[]')
    expect(body.get('sample_id')).toBe('')
    expect(body.get('standards')).toBe('[]')
  })

  it('retains the selected measurement record in multipart submissions', () => {
    const body = reportFormData(5, 'REPORT-001', { values: {}, standards: [], equipment: [], measurement_records: { haas: 2 } }, [], {})
    expect(JSON.parse(String(body.get('measurement_records')))).toEqual({ haas: 2 })
  })
})
