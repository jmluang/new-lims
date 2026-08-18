import { renderToStaticMarkup } from 'react-dom/server'
import { describe, expect, it } from 'vitest'
import { PageShell } from '../shared'

describe('PageShell header placement', () => {
  it('keeps page actions and content in the page without duplicating title copy', () => {
    const markup = renderToStaticMarkup(
      <PageShell
        title="签章台账"
        description="所有经本系统签章的 PDF 记录。"
        actions={<button type="button">导出</button>}
      >
        <section>台账内容</section>
      </PageShell>,
    )

    expect(markup).toContain('导出')
    expect(markup).toContain('台账内容')
    expect(markup).not.toContain('签章台账')
    expect(markup).not.toContain('所有经本系统签章的 PDF 记录。')
  })
})
