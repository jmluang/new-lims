import { renderToStaticMarkup } from 'react-dom/server'
import { describe, expect, it } from 'vitest'
import type { Customer } from '../../customers/CustomerListPage'
import { CustomerCompanySearchInput } from '../CustomerCompanySearchInput'
import { filterCustomerOptions } from '../customerCompanySearch'

const customers: Customer[] = [
  {
    id: 1,
    name: '中山市明辉照明有限公司',
    credit_code: '91442000MINGHUI',
    phone: '0760-88886666',
    status: 'active',
  },
  {
    id: 2,
    name: '中山市星河电器有限公司',
    credit_code: '91442000XINGHE',
    phone: '0760-99998888',
    status: 'active',
  },
]

describe('customer company search', () => {
  it('filters by company name, credit code and phone', () => {
    expect(filterCustomerOptions(customers, '明辉')).toEqual([customers[0]])
    expect(filterCustomerOptions(customers, 'XINGHE')).toEqual([customers[1]])
    expect(filterCustomerOptions(customers, '88886666')).toEqual([customers[0]])
  })

  it('renders an accessible combobox without a native datalist', () => {
    const markup = renderToStaticMarkup(
      <CustomerCompanySearchInput
        prefix="client"
        value=""
        customers={customers}
        className="input"
        onChange={() => undefined}
      />,
    )

    expect(markup).toContain('role="combobox"')
    expect(markup).toContain('aria-label="搜索选择委托单位"')
    expect(markup).toContain('aria-expanded="false"')
    expect(markup).not.toContain('<datalist')
    expect(markup).not.toContain('list=')
  })

  it('renders an explicit clear action for a selected company', () => {
    const markup = renderToStaticMarkup(
      <CustomerCompanySearchInput
        prefix="client"
        value="中山市明辉照明有限公司"
        customers={customers}
        className="input"
        onChange={() => undefined}
      />,
    )

    expect(markup).toContain('aria-label="清除委托单位"')
  })
})
