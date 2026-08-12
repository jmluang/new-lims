import { localDateInputValue } from '../system/utils'
import type { TestOrder } from './TestOrderListPage'
import type { TestOrderFormValues } from './testOrderSchema'

export function testOrderDefaultValues(order?: TestOrder | null): TestOrderFormValues {
  return {
    contract_no: order?.contract_no ?? '',
    order_date: order?.order_date ?? localDateInputValue(),
    planned_end_date: order?.planned_end_date ?? '',
    urgency: order?.urgency ?? 'normal',
    client_customer_id: order?.client_customer_id ?? null,
    client_company: order?.client_company ?? '',
    client_address: order?.client_address ?? '',
    client_contact: order?.client_contact ?? '',
    client_phone: order?.client_phone ?? '',
    client_email: order?.client_email ?? '',
    manufacturer_customer_id: order?.manufacturer_customer_id ?? null,
    manufacturer_company: order?.manufacturer_company ?? '',
    manufacturer_address: order?.manufacturer_address ?? '',
    manufacturer_contact: order?.manufacturer_contact ?? '',
    manufacturer_phone: order?.manufacturer_phone ?? '',
    manufacturer_email: order?.manufacturer_email ?? '',
    maker_customer_id: order?.maker_customer_id ?? null,
    maker_company: order?.maker_company ?? '',
    maker_address: order?.maker_address ?? '',
    maker_contact: order?.maker_contact ?? '',
    maker_phone: order?.maker_phone ?? '',
    maker_email: order?.maker_email ?? '',
    report_forms: order?.report_forms ?? ['formal_report', 'electronic_report'],
    delivery_method: order?.delivery_method ?? 'self_pick',
    outsourcing_option: order?.outsourcing_option ?? 'allowed',
    sample_return: order?.sample_return ?? 'return',
    remark: order?.remark ?? '',
    sample_status: order?.sample_status ?? 'not_received',
    address_lab_name: order?.address_lab_name ?? '中山市鑫普达检测有限公司',
    address_contact: order?.address_contact ?? '鑫普达检测',
    address_detail: order?.address_detail ?? '广东省中山市古镇镇东兴东路33号7栋1层之一',
    address_phone: order?.address_phone ?? '',
    shipping_notes: order?.shipping_notes ?? '',
    client_signature: order?.client_signature ?? '',
    client_sign_date: order?.client_sign_date ?? '',
    dept_confirm: order?.dept_confirm ?? '',
    dept_confirm_date: order?.dept_confirm_date ?? '',
    lab_confirm: order?.lab_confirm ?? '',
    lab_confirm_date: order?.lab_confirm_date ?? '',
    standards: order?.standards.length
      ? order.standards.map((row) => ({
          id: row.id,
          standard_id: row.standard_id ?? null,
          standard_code: row.standard_code,
          standard_name: row.standard_name,
          report_language: row.report_language ?? '',
          qualifications_text: row.qualifications?.join(', ') ?? '',
          requirement: row.requirement ?? '',
        }))
      : [emptyStandardRow()],
    samples: order?.samples.length
      ? order.samples.map((row) => ({
          id: row.id,
          sample_name: row.sample_name,
          specification: row.specification ?? '',
          model: row.model ?? '',
          input_voltage: row.input_voltage ?? '',
          rated_current: row.rated_current ?? '',
          power: row.power ?? '',
          rated_frequency: row.rated_frequency ?? '',
          status: row.status,
          quantity: row.quantity,
          quantity_unit: row.quantity_unit ?? '',
          sample_condition: row.sample_condition ?? '',
          sample_condition_note: row.sample_condition_note ?? '',
          detail_content: row.detail_content ?? '',
          remark: row.remark ?? '',
        }))
      : [emptySampleRow()],
  }
}

function emptyStandardRow() {
  return {
    standard_id: null,
    standard_code: '',
    standard_name: '',
    report_language: 'zh',
    qualifications_text: 'CMA',
    requirement: '',
  }
}

function emptySampleRow() {
  return {
    sample_name: '',
    specification: '',
    model: '',
    input_voltage: '',
    rated_current: '',
    power: '',
    rated_frequency: '',
    status: 'pending' as const,
    quantity: 1,
    quantity_unit: '个',
    sample_condition: 'good' as const,
    sample_condition_note: '',
    detail_content: '',
    remark: '',
  }
}
