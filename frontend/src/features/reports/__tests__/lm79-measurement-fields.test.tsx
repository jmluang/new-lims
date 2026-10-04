import { renderToStaticMarkup } from 'react-dom/server'
import { describe, expect, it } from 'vitest'
import { ReportFileField } from '../ReportFileField'
import { attachmentTypes, type MeasurementImportResult } from '../lm79Api'

const imported: MeasurementImportResult = {
  kind: 'haas', records: [{ index: 1, model: 'HAAS', sample: 'S1', date: '2026-10-04' }, { index: 2, model: 'HAAS', sample: 'S2', date: '2026-10-04' }],
  selected_record: 2, values: { cct: '6000' }, spectrum_point_count: 4001, notice: '已读取色度参数和光谱。',
}
const props = { type: attachmentTypes[2], fileKey: 0, editable: true, media: [], selected: [new File(['bytes'], 'reading.haas')], imported, record: 2,
  onSelect: () => {}, onRemove: () => {}, onParse: () => {} }

describe('measurement file selection', () => {
  it('offers an explicit record choice and shows the imported spectrum count', () => {
    const html = renderToStaticMarkup(<ReportFileField {...props} />)
    expect(html).toContain('检测记录')
    expect(html).toContain('第 2 条')
    expect(html).toContain('光谱 4,001 点')
    expect(html).not.toContain('重新解析')
  })

  it('does not offer parsing or removal for frozen reports', () => {
    const html = renderToStaticMarkup(<ReportFileField {...props} editable={false} />)
    expect(html).not.toContain('重新解析')
    expect(html).not.toContain('移除待上传文件')
    expect(html).toContain('disabled')
  })
})
