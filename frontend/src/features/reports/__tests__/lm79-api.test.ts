import { beforeEach, describe, expect, it, vi } from 'vitest'
import { generateReportNumber, loadReportNumber, reportFormData, saveReport } from '../lm79Api'

const postNumber = vi.hoisted(() => vi.fn())
vi.mock('../../../lib/api', () => ({ api: { post: postNumber } }))
beforeEach(() => { postNumber.mockReset() })
const navigation = (key: string) => ({ preload: false, location: { href: '/reports/lm79/new', state: { __TSR_key: key } } })

describe('LM-79 editor submission contract', () => {
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
})
