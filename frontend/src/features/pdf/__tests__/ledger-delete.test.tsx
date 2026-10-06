import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { renderToStaticMarkup } from 'react-dom/server'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { PdfFileListPage } from '../PdfFileListPage'

afterEach(() => vi.unstubAllGlobals())

function markup(allowed: boolean, canDelete = true) {
  vi.stubGlobal('localStorage', { getItem: () => null })
  const client = new QueryClient({ defaultOptions: { queries: { staleTime: Infinity, retry: false } } })
  client.setQueryData(['effective-permissions'], {
    resources: { pdf_files: { actions: { delete: allowed }, fields: {} } },
  })
  client.setQueryData(['pdf', 'files', { search: '', created_by: '', signed_from: '', signed_to: '' }, 1, 15], {
    data: [{ id: 1, file_name: 'report.pdf', sha256_hash: 'a'.repeat(64), can_delete: canDelete }],
    meta: { total: 1, current_page: 1, per_page: 15 },
  })

  return renderToStaticMarkup(<QueryClientProvider client={client}><PdfFileListPage /></QueryClientProvider>)
}

function deleteButtons(html: string) {
  return (html.match(/<button\b[^>]*>[\s\S]*?<\/button>/g) ?? []).filter((button) => button.endsWith('删除</button>'))
}

describe('PDF ledger delete controls', () => {
  it('shows delete beside detail in desktop and mobile layouts for an authorized user', () => {
    const html = markup(true)
    expect(html.match(/详情/g)).toHaveLength(2)
    expect(html.match(/删除<\/button>/g)).toHaveLength(2)
    expect(deleteButtons(html).every((button) => !button.includes('disabled=""'))).toBe(true)
  })

  it('hides delete from readers without delete permission', () => {
    expect(markup(false)).not.toContain('删除</button>')
  })

  it('disables deletion for workflow revisions and explains why', () => {
    const html = markup(true, false)
    expect(html.match(/title="关联签署流程的版本不可单独删除"/g)).toHaveLength(2)
    expect(deleteButtons(html).every((button) => button.includes('disabled=""'))).toBe(true)
  })
})
