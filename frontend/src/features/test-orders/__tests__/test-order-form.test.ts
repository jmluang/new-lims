import React from 'react'
import { renderToStaticMarkup } from 'react-dom/server'
import { describe, expect, it, vi } from 'vitest'
import {
  contactOptionsForCustomer,
  copyClientPartyValues,
  customerForSearchValue,
  customerSearchValue,
  customerSnapshotValues,
  normalizeTestOrderPayload,
  reportFormOptions,
  reportSubmissionOptions,
  testOrderSchema,
  type TestOrderFormValues,
} from '../testOrderSchema'
import { TestOrderForm } from '../TestOrderForm'
import { testOrderDefaultValues } from '../testOrderDefaults'
import type { TestOrder } from '../TestOrderListPage'

vi.mock('@tanstack/react-router', () => ({
  Link: ({ children, to }: { children: React.ReactNode; to: string }) => React.createElement('a', { href: to }, children),
}))

describe('test order form', () => {
  it('requires the order date, client company, standards and samples', () => {
    const parsed = testOrderSchema.safeParse({
      order_date: '',
      urgency: 'normal',
      client_company: '',
      sample_status: 'not_received',
      standards: [],
      samples: [],
    })

    expect(parsed.success).toBe(false)
  })

  it('normalizes dynamic child rows without losing stable ids', () => {
    const payload = normalizeTestOrderPayload({
      ...baseValues(),
      client_email: 'client@example.test',
      manufacturer_email: 'manufacturer@example.test',
      maker_email: 'maker@example.test',
      sample_return: 'return',
      shipping_notes: 'Please keep original packaging.',
      standards: [
        {
          id: 10,
          standard_id: 3,
          standard_code: 'GB/T 7000.1-2023',
          standard_name: '灯具 第1部分：一般要求与试验',
          report_language: 'zh',
          qualifications_text: 'CMA, CNAS',
          requirement: '接地电阻',
        },
        {
          standard_id: null,
          standard_code: 'IEC 60598-1',
          standard_name: 'Luminaires',
          report_language: 'en',
          qualifications_text: '',
          requirement: 'Photometric test',
        },
      ],
      samples: [
        {
          id: 22,
          sample_name: '控制器',
          specification: 'CTRL',
          model: 'C-1',
          input_voltage: '220V',
          rated_current: '1.3A',
          status: 'pending',
          power: '300W',
          rated_frequency: '50Hz',
          quantity: 1,
          quantity_unit: '个',
          sample_condition: 'good',
          sample_condition_note: '',
          detail_content: '功能检查（更新）',
          remark: '',
        },
        {
          sample_name: '电源',
          specification: 'PSU',
          model: 'P-1',
          input_voltage: '220V',
          rated_current: '0.8A',
          status: 'pending',
          power: '180W',
          rated_frequency: '50Hz',
          quantity: 2,
          quantity_unit: '台',
          sample_condition: 'abnormal',
          sample_condition_note: '外壳划痕',
          detail_content: '输入输出检查',
          remark: '',
        },
      ],
    })

    expect(payload.standards).toMatchObject([
      { id: 10, qualifications: ['CMA', 'CNAS'], sort_order: 0 },
      { standard_code: 'IEC 60598-1', qualifications: [], sort_order: 1 },
    ])
    expect(payload.samples).toMatchObject([
      {
        id: 22,
        sample_name: '控制器',
        rated_current: '1.3A',
        rated_frequency: '50Hz',
        quantity_unit: '个',
        sample_condition: 'good',
        sort_order: 0,
      },
      {
        sample_name: '电源',
        quantity: 2,
        quantity_unit: '台',
        sample_condition: 'abnormal',
        sample_condition_note: '外壳划痕',
        sort_order: 1,
      },
    ])
    expect(payload).toMatchObject({
      client_email: 'client@example.test',
      manufacturer_email: 'manufacturer@example.test',
      maker_email: 'maker@example.test',
      sample_return: 'return',
      shipping_notes: 'Please keep original packaging.',
    })
  })

  it('aligns report requirement defaults and options with the commission form', () => {
    expect(reportFormOptions).toEqual(['formal_report', 'simple_report', 'electronic_report', 'paper_report', 'english_report'])
    expect(reportSubmissionOptions).toEqual(['self_pick', 'mail'])

    const payload = normalizeTestOrderPayload({
      ...baseValues(),
      report_forms: ['formal_report', 'electronic_report', 'paper_report'],
      delivery_method: 'self_pick',
      outsourcing_option: 'allowed',
    })

    expect(payload).toMatchObject({
      report_forms: ['formal_report', 'electronic_report', 'paper_report'],
      delivery_method: 'self_pick',
      outsourcing_option: 'allowed',
    })
  })

  it('normalizes legacy report form values for current checkbox options', () => {
    const order = {
      id: 1,
      order_no: 'WT-1',
      order_date: '2026-08-18',
      client_company: '中山市测试公司',
      sample_status: 'not_received',
      report_forms: ['electronic', 'paper'],
      standards: [],
      samples: [],
    } as TestOrder

    expect(testOrderDefaultValues(order).report_forms).toEqual(['electronic_report', 'paper_report'])
  })

  it('renders entrust print fields in the existing form layout', () => {
    const html = renderToStaticMarkup(
      React.createElement(TestOrderForm, {
        customers: [],
        standards: [],
        submitting: false,
        error: null,
        onSubmit: async () => undefined,
        onCancel: () => undefined,
      }),
    )

    expect(html).toContain('委托方邮箱')
    expect(html).toContain('制造商邮箱')
    expect(html).toContain('生产商邮箱')
    expect(html).toContain('样品是否返还')
    expect(html).toContain('额定电流')
    expect(html).toContain('额定频率')
    expect(html).toContain('数量单位')
    expect(html).toContain('样品状态')
    expect(html).toContain('特别说明')
  })

  it('defaults sample delivery details to the laboratory address', () => {
    expect(testOrderDefaultValues()).toMatchObject({
      address_lab_name: '中山市鑫普达检测有限公司',
      address_contact: '鑫普达检测',
      address_detail: '广东省中山市古镇镇东兴东路33号7栋1层之一',
    })
  })

  it('leaves qualifications blank for new execution standards', () => {
    expect(testOrderDefaultValues().standards[0]?.qualifications_text).toBe('')
  })

  it('builds searchable company labels from customer registry records', () => {
    expect(
      customerSearchValue({
        id: 7,
        name: '中山市星河检测客户',
        credit_code: '91442000MA7TEST',
        phone: '0760-88886666',
        status: 'active',
      }),
    ).toBe('中山市星河检测客户 91442000MA7TEST 0760-88886666')
  })

  it('builds customer-linked snapshots and clears stale address data for unmatched searches', () => {
    const customer = {
      id: 7,
      name: '中山市星河检测客户',
      phone: '0760-88886666',
      email: 'company@example.test',
      address: '中山市东区一号',
      status: 'active' as const,
      default_contact: {
        id: 11,
        name: '默认联系人',
        phone: '13800000000',
        email: 'contact@example.test',
        status: 'active' as const,
      },
    }

    expect(customerForSearchValue([customer], '中山市星河检测客户')).toBe(customer)
    expect(customerSnapshotValues('client', customer)).toMatchObject({
      client_customer_id: 7,
      client_company: '中山市星河检测客户',
      client_address: '中山市东区一号',
      client_contact: '默认联系人',
      client_phone: '13800000000',
      client_email: 'contact@example.test',
    })
    expect(customerSnapshotValues('client', null, '未匹配公司')).toMatchObject({
      client_customer_id: null,
      client_company: '未匹配公司',
      client_address: '',
      client_contact: '',
      client_phone: '',
      client_email: '',
    })
  })

  it('uses the first active customer contact when no default contact is present', () => {
    expect(
      customerSnapshotValues('client', {
        id: 9,
        name: '联系人列表客户',
        address: '中山市联系人路9号',
        status: 'active',
        contacts: [
          { id: 31, name: '列表联系人', phone: '13900000031', email: 'list@example.test', status: 'active' },
        ],
      }),
    ).toMatchObject({
      client_address: '中山市联系人路9号',
      client_contact: '列表联系人',
      client_phone: '13900000031',
      client_email: 'list@example.test',
    })
  })

  it('falls back to the original order snapshot only when customer master contact data is missing', () => {
    expect(
      customerSnapshotValues(
        'client',
        { id: 1, name: '主档缺联系人公司', address: '主档地址', phone: '', email: '', status: 'active' },
        '',
        { contact: '快照联系人', phone: '13800000001', email: 'snapshot@example.test' },
      ),
    ).toMatchObject({
      client_address: '主档地址',
      client_contact: '快照联系人',
      client_phone: '13800000001',
      client_email: 'snapshot@example.test',
    })
  })

  it('uses customer contacts as selectable party contacts with default fallback', () => {
    expect(
      contactOptionsForCustomer(
        {
          id: 7,
          name: '中山市星河检测客户',
          status: 'active',
          default_contact: {
            id: 11,
            name: '默认联系人',
            phone: '13800000000',
            is_default: true,
            status: 'active',
          },
        },
        [
          { id: 12, customer_id: 7, name: '客户管理人A', phone: '13900000000', is_default: false, status: 'active' },
          { id: 13, customer_id: 7, name: '停用联系人', phone: '13700000000', is_default: false, status: 'disabled' },
        ],
      ),
    ).toEqual([{ id: 12, name: '客户管理人A', phone: '13900000000', email: null }])

    expect(
      contactOptionsForCustomer({
        id: 8,
        name: '无联系人客户',
        status: 'active',
        default_contact: {
          id: 21,
          name: '默认联系人',
          phone: '13800000000',
          is_default: true,
          status: 'active',
        },
      }),
    ).toEqual([{ id: 21, name: '默认联系人', phone: '13800000000', email: null }])
  })

  it('copies manufacturer and maker snapshots from the client party when same-as-client is enabled', () => {
    expect(
      copyClientPartyValues(baseValues(), 'manufacturer'),
    ).toMatchObject({
      manufacturer_customer_id: 1,
      manufacturer_company: '中山市XXX有限公司',
      manufacturer_address: '',
      manufacturer_contact: '',
      manufacturer_phone: '',
    })
  })
})

function baseValues(): TestOrderFormValues {
  return {
    contract_no: '',
    order_date: '2026-05-07',
    planned_end_date: '',
    urgency: 'normal',
    client_customer_id: 1,
    client_company: '中山市XXX有限公司',
    client_address: '',
    client_contact: '',
    client_phone: '',
    client_email: '',
    manufacturer_customer_id: null,
    manufacturer_company: '',
    manufacturer_address: '',
    manufacturer_contact: '',
    manufacturer_phone: '',
    manufacturer_email: '',
    maker_customer_id: null,
    maker_company: '',
    maker_address: '',
    maker_contact: '',
    maker_phone: '',
    maker_email: '',
    report_forms: ['formal_report', 'electronic_report'],
    delivery_method: 'self_pick',
    outsourcing_option: 'allowed',
    sample_return: 'return',
    remark: '',
    sample_status: 'not_received',
    address_lab_name: '',
    address_contact: '',
    address_detail: '',
    address_phone: '',
    shipping_notes: '',
    client_signature: '',
    client_sign_date: '',
    dept_confirm: '',
    dept_confirm_date: '',
    lab_confirm: '',
    lab_confirm_date: '',
    standards: [],
    samples: [],
  }
}
