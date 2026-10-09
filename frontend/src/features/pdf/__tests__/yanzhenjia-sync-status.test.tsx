import { renderToStaticMarkup } from 'react-dom/server'
import { describe, expect, it } from 'vitest'
import { YanzhenjiaSyncStatus } from '../YanzhenjiaSyncStatus'

function renderStatus(status: string, deleted = true) {
  return renderToStaticMarkup(<YanzhenjiaSyncStatus row={{
    status,
    source_deleted_at: deleted ? '2026-10-09T09:00:00Z' : null,
    api_version: 'legacy',
  }} />)
}

describe('deleted PDF sync history status', () => {
  it.each(['pending', 'queued'])('shows %s as cancelled after deletion', (status) => {
    const markup = renderStatus(status)
    expect(markup).toContain('已取消')
    expect(markup).toContain('源文件已删除')
    expect(markup).not.toMatch(/待同步|排队中/)
  })

  it.each(['running', 'failed'])('stops retries without claiming the remote outcome for %s', (status) => {
    const markup = renderStatus(status)
    expect(markup).toContain('已停止重试')
    expect(markup).toContain('远端结果未确认')
    expect(markup).not.toMatch(/同步中|>失败</)
  })

  it('preserves confirmed success, including a response arriving after deletion', () => {
    const markup = renderStatus('succeeded')
    expect(markup).toContain('成功')
    expect(markup).toContain('源文件已删除')
    expect(markup).not.toMatch(/已取消|已停止重试|未确认/)
  })

  it.each([
    ['pending', '待同步'], ['queued', '排队中'], ['running', '同步中'],
    ['succeeded', '成功'], ['failed', '失败'],
  ])('keeps live %s status unchanged', (status, label) => {
    const markup = renderStatus(status, false)
    expect(markup).toContain(label)
    expect(markup).not.toContain('源文件已删除')
  })
})
