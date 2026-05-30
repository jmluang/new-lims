import { describe, expect, it } from 'vitest'
import { filterForbiddenStandardFields } from '../standardPermissions'
import { standardCatalogSchema, standardSchema } from '../standardSchema'

describe('standard form', () => {
  it('excludes fields without update permission from submit payloads', () => {
    const payload = filterForbiddenStandardFields(
      {
        std_no: 'GB/T 7000.1-2023',
        chinese_name: '灯具 第1部分：一般要求与试验',
        publish_date: '2023-01-01',
        implement_date: '2023-07-01',
        status: 'active',
        abolish_date: '',
        replaced_by: '',
        corresponding_std: '',
        category: 'lighting',
        language: 'zh',
      },
      {
        std_no: { update: false },
        chinese_name: { update: true },
      },
    )

    expect(payload.std_no).toBeUndefined()
    expect(payload.chinese_name).toBe('灯具 第1部分：一般要求与试验')
    expect(payload.status).toBe('active')
  })

  it('accepts disabled as the soft delete status', () => {
    expect(
      standardSchema.parse({
        std_no: 'GB/T 7000.1-2023',
        chinese_name: '灯具 第1部分：一般要求与试验',
        status: 'disabled',
      }).status,
    ).toBe('disabled')
  })

  it('requires explicit catalog code', () => {
    const parsed = standardCatalogSchema.safeParse({
      name: '试验要求',
      content: '接地电阻',
    })

    expect(parsed.success).toBe(false)
  })
})
