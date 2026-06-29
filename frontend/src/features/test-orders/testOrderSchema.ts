import { z } from 'zod'

export const reportFormOptions = ['formal_report', 'simple_report', 'electronic_report', 'paper_report', 'english_report'] as const
export const reportSubmissionOptions = ['self_pick', 'mail'] as const
export const outsourcingOptions = ['allowed', 'not_allowed'] as const
export const sampleReturnOptions = ['return', 'destroy'] as const
export const sampleConditionOptions = ['good', 'abnormal'] as const

export const testOrderStandardSchema = z.object({
  id: z.number().optional(),
  standard_id: z.number().nullable().optional(),
  standard_code: z.string().min(1, '请填写标准编号'),
  standard_name: z.string().min(1, '请填写标准名称'),
  report_language: z.string().optional(),
  qualifications_text: z.string().optional(),
  requirement: z.string().optional(),
})

export const testOrderSampleSchema = z.object({
  id: z.number().optional(),
  sample_name: z.string().min(1, '请填写样品名称'),
  specification: z.string().optional(),
  model: z.string().optional(),
  input_voltage: z.string().optional(),
  rated_current: z.string().optional(),
  power: z.string().optional(),
  rated_frequency: z.string().optional(),
  quantity_unit: z.string().optional(),
  status: z.enum(['pending', 'partially_received', 'received', 'rejected', 'cancelled']),
  sample_condition: z.enum(sampleConditionOptions).optional().or(z.literal('')),
  sample_condition_note: z.string().optional(),
  quantity: z.number().int().min(1, '数量至少为 1'),
  detail_content: z.string().optional(),
  remark: z.string().optional(),
})

export const testOrderSchema = z.object({
  contract_no: z.string().optional(),
  order_date: z.string().min(1, '请选择委托日期'),
  planned_end_date: z.string().optional(),
  urgency: z.enum(['normal', 'urgent', 'critical']),
  client_customer_id: z.number().nullable().optional(),
  client_company: z.string().min(1, '请填写委托单位'),
  client_address: z.string().optional(),
  client_contact: z.string().optional(),
  client_phone: z.string().optional(),
  client_email: z.string().email('请输入有效邮箱').or(z.literal('')).optional(),
  manufacturer_customer_id: z.number().nullable().optional(),
  manufacturer_company: z.string().optional(),
  manufacturer_address: z.string().optional(),
  manufacturer_contact: z.string().optional(),
  manufacturer_phone: z.string().optional(),
  manufacturer_email: z.string().email('请输入有效邮箱').or(z.literal('')).optional(),
  maker_customer_id: z.number().nullable().optional(),
  maker_company: z.string().optional(),
  maker_address: z.string().optional(),
  maker_contact: z.string().optional(),
  maker_phone: z.string().optional(),
  maker_email: z.string().email('请输入有效邮箱').or(z.literal('')).optional(),
  report_forms: z.array(z.string()).optional(),
  delivery_method: z.string().optional(),
  outsourcing_option: z.string().optional(),
  sample_return: z.enum(sampleReturnOptions).optional().or(z.literal('')),
  remark: z.string().optional(),
  sample_status: z.enum(['not_received', 'partially_received', 'received', 'testing', 'completed']),
  address_lab_name: z.string().optional(),
  address_contact: z.string().optional(),
  address_detail: z.string().optional(),
  address_phone: z.string().optional(),
  shipping_notes: z.string().optional(),
  client_signature: z.string().optional(),
  client_sign_date: z.string().optional(),
  dept_confirm: z.string().optional(),
  dept_confirm_date: z.string().optional(),
  lab_confirm: z.string().optional(),
  lab_confirm_date: z.string().optional(),
  standards: z.array(testOrderStandardSchema).min(1, '至少需要一条执行标准'),
  samples: z.array(testOrderSampleSchema).min(1, '至少需要一条样品信息'),
})

export type TestOrderFormValues = z.infer<typeof testOrderSchema>

type ApiPayload = Record<string, unknown>
type PartyPrefix = 'client' | 'manufacturer' | 'maker'

type PartyCustomer = {
  id: number
  name: string
  credit_code?: string | null
  phone?: string | null
  address?: string | null
  status?: 'active' | 'disabled'
  default_contact?: PartyContact | null
}

type PartyContact = {
  id: number
  name: string
  customer_id?: number
  phone?: string | null
  email?: string | null
  is_default?: boolean
  status: 'active' | 'disabled'
}

export function normalizeTestOrderPayload(values: TestOrderFormValues): ApiPayload {
  return cleanEmptyValues({
    ...values,
    client_customer_id: numericId(values.client_customer_id),
    manufacturer_customer_id: numericId(values.manufacturer_customer_id),
    maker_customer_id: numericId(values.maker_customer_id),
    standards: values.standards
      .filter((row) => row.standard_code.trim() !== '' || row.standard_name.trim() !== '' || row.id !== undefined)
      .map((row, index) =>
        cleanEmptyValues({
          id: row.id,
          standard_id: numericId(row.standard_id),
          standard_code: row.standard_code,
          standard_name: row.standard_name,
          report_language: row.report_language,
          qualifications: splitCsv(row.qualifications_text),
          requirement: row.requirement,
          sort_order: index,
        }),
      ),
    samples: values.samples
      .filter((row) => row.sample_name.trim() !== '' || row.id !== undefined)
      .map((row, index) =>
        cleanEmptyValues({
          id: row.id,
          sample_name: row.sample_name,
          specification: row.specification,
          model: row.model,
          input_voltage: row.input_voltage,
          rated_current: row.rated_current,
          power: row.power,
          rated_frequency: row.rated_frequency,
          quantity_unit: row.quantity_unit,
          status: row.status,
          sample_condition: row.sample_condition,
          sample_condition_note: row.sample_condition_note,
          quantity: Number(row.quantity),
          detail_content: row.detail_content,
          remark: row.remark,
          sort_order: index,
        }),
      ),
  })
}

export function customerSearchValue(customer: PartyCustomer) {
  return [customer.name, customer.credit_code, customer.phone].filter(Boolean).join(' ')
}

export function contactOptionsForCustomer(customer?: PartyCustomer | null, contacts?: PartyContact[]) {
  const activeContacts = (contacts ?? [])
    .filter((contact) => contact.status === 'active')
    .map((contact) => ({ id: contact.id, name: contact.name, phone: contact.phone ?? null, email: contact.email ?? null }))

  if (activeContacts.length > 0) {
    return activeContacts
  }

  if (!customer?.default_contact || customer.default_contact.status !== 'active') {
    return []
  }

  return [{ id: customer.default_contact.id, name: customer.default_contact.name, phone: customer.default_contact.phone ?? null, email: customer.default_contact.email ?? null }]
}

export function copyClientPartyValues(values: TestOrderFormValues, target: Exclude<PartyPrefix, 'client'>): Partial<TestOrderFormValues> {
  return {
    [`${target}_customer_id`]: values.client_customer_id ?? null,
    [`${target}_company`]: values.client_company,
    [`${target}_address`]: values.client_address,
    [`${target}_contact`]: values.client_contact,
    [`${target}_phone`]: values.client_phone,
    [`${target}_email`]: values.client_email,
  }
}

function numericId(value?: number | null) {
  if (value === null || value === undefined || Number.isNaN(value) || value === 0) {
    return null
  }

  return value
}

function splitCsv(value?: string) {
  return (value ?? '')
    .split(',')
    .map((item) => item.trim())
    .filter(Boolean)
}

function cleanEmptyValues<T extends ApiPayload>(payload: T): T {
  return Object.fromEntries(
    Object.entries(payload).map(([key, value]) => {
      if (value === '') {
        return [key, null]
      }

      return [key, value]
    }),
  ) as T
}
