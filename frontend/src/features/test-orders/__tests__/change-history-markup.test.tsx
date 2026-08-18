import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { renderToStaticMarkup } from 'react-dom/server'
import { describe, expect, it, vi } from 'vitest'

const entries = [
  {
    id: 2,
    occurred_at: '2026-08-18T08:32:00.000000Z',
    actor_user_id: 1,
    actor_name: 'Super Admin',
    changes: [
      { field: '紧急程度', old_value: 'urgent', new_value: 'normal' },
      { field: '计划结束时间', old_value: null, new_value: '2026-09-10' },
    ],
  },
  {
    id: 1,
    occurred_at: '2026-08-18T08:30:00.000000Z',
    actor_user_id: 1,
    actor_name: 'Super Admin',
    changes: [
      { field: '特别说明', old_value: '', new_value: 'default\n第二行' },
      { field: '报告形式', old_value: [], new_value: ['electronic', 'paper'] },
    ],
  },
]

vi.mock('../../../lib/api', () => ({
  api: { get: vi.fn(async () => ({ data: { data: entries } })) },
}))

async function markup() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  await client.prefetchQuery({ queryKey: ['test-order-history', 7], queryFn: async () => entries })
  const { TestOrderChangeHistory } = await import('../TestOrderChangeHistory')

  return renderToStaticMarkup(
    <QueryClientProvider client={client}>
      <TestOrderChangeHistory orderId={7} />
    </QueryClientProvider>,
  )
}

describe('TestOrderChangeHistory markup', () => {
  it('shows who changed what, and how many fields at a glance', async () => {
    const html = await markup()

    expect(html).toContain('Super Admin')
    expect(html).toContain('2 项')
    expect(html).toContain('紧急程度')
    // Enum codes are what the database stores; nobody should have to read them.
    expect(html).toContain('加急')
    expect(html).toContain('普通')
    expect(html).not.toContain('urgent')
  })

  // shipping_notes and requirement carry newlines. Rendered without this the
  // whole field collapses into one run-on line and the change is unreadable.
  it('keeps the line breaks a multi-line field was saved with', async () => {
    expect(await markup()).toContain('whitespace-pre-wrap')
  })

  it('writes an absent value rather than leaving a blank gap', async () => {
    const html = await markup()

    expect(html).toContain('（空）')
    expect(html).toContain('电子版、纸质版')
  })

  // Only code-shaped values are translated. A remark that happens to match a
  // dictionary key must survive as the person typed it.
  it('leaves free text exactly as it was written', async () => {
    const html = await markup()

    expect(html).toContain('default')
    expect(html).toContain('第二行')
    expect(html).not.toContain('默认')
  })

  // The rest of the app outlines panels in emerald, not slate; this panel used
  // to be the one place that did not.
  it('uses the border the other panels use', async () => {
    const html = await markup()

    expect(html).toContain('border-emerald-900/10')
    expect(html).not.toContain('border-slate-200')
  })
})
