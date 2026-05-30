import { describe, expect, it } from 'vitest'
import { normalizeTestOrderPayload, testOrderSchema, type TestOrderFormValues } from '../testOrderSchema'

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
          status: 'pending',
          quantity: 1,
          detail_content: '功能检查（更新）',
          remark: '',
        },
        {
          sample_name: '电源',
          specification: 'PSU',
          model: 'P-1',
          status: 'pending',
          quantity: 2,
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
      { id: 22, sample_name: '控制器', sort_order: 0 },
      { sample_name: '电源', quantity: 2, sort_order: 1 },
    ])
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
    manufacturer_customer_id: null,
    manufacturer_company: '',
    manufacturer_address: '',
    manufacturer_contact: '',
    manufacturer_phone: '',
    maker_customer_id: null,
    maker_company: '',
    maker_address: '',
    maker_contact: '',
    maker_phone: '',
    report_forms: ['electronic'],
    delivery_method: '',
    outsourcing_option: 'allowed',
    remark: '',
    sample_status: 'not_received',
    address_lab_name: '',
    address_contact: '',
    address_detail: '',
    address_phone: '',
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
