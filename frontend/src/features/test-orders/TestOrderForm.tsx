import { zodResolver } from '@hookform/resolvers/zod'
import { Plus, Save, Trash2, X } from 'lucide-react'
import { useCallback, useEffect, useState } from 'react'
import { type UseFormReturn, useFieldArray, useForm, useWatch } from 'react-hook-form'
import { Button, ErrorNotice, Field, Panel } from '../system/shared'
import { inputClass, localDateInputValue, textareaClass } from '../system/utils'
import type { Customer } from '../customers/CustomerListPage'
import type { Standard } from '../standards/StandardListPage'
import { zhText } from '../../lib/zh'
import {
  contactOptionsForCustomer,
  copyClientPartyValues,
  customerSearchValue,
  normalizeTestOrderPayload,
  outsourcingOptions,
  reportFormOptions,
  reportSubmissionOptions,
  testOrderSchema,
  type TestOrderFormValues,
} from './testOrderSchema'
import type { TestOrder } from './TestOrderListPage'

export function TestOrderForm({
  order,
  customers,
  standards,
  submitting,
  error,
  onSubmit,
  onCancel,
}: {
  order?: TestOrder | null
  customers: Customer[]
  standards: Standard[]
  submitting: boolean
  error: unknown
  onSubmit: (values: Record<string, unknown>) => Promise<void>
  onCancel: () => void
}) {
  const form = useForm<TestOrderFormValues>({
    resolver: zodResolver(testOrderSchema),
    defaultValues: defaultValues(order),
  })
  const standardRows = useFieldArray({ control: form.control, name: 'standards' })
  const sampleRows = useFieldArray({ control: form.control, name: 'samples' })
  const watchedStandards = useWatch({ control: form.control, name: 'standards' })
  const [sameAsClient, setSameAsClient] = useState({ manufacturer: false, maker: false })
  const clientCustomerId = useWatch({ control: form.control, name: 'client_customer_id' })
  const clientCompany = useWatch({ control: form.control, name: 'client_company' })
  const clientAddress = useWatch({ control: form.control, name: 'client_address' })
  const clientContact = useWatch({ control: form.control, name: 'client_contact' })
  const clientPhone = useWatch({ control: form.control, name: 'client_phone' })
  const clientSyncKey = [clientCustomerId ?? '', clientCompany ?? '', clientAddress ?? '', clientContact ?? '', clientPhone ?? ''].join('\u001f')
  const copyClientToParty = useCallback(
    (prefix: 'manufacturer' | 'maker') => {
      const values = copyClientPartyValues(form.getValues(), prefix)

      Object.entries(values).forEach(([field, value]) => {
        form.setValue(field as keyof TestOrderFormValues, value as never, { shouldDirty: true, shouldValidate: false })
      })
    },
    [form],
  )

  useEffect(() => {
    form.reset(defaultValues(order))
  }, [order, form])

  useEffect(() => {
    if (sameAsClient.manufacturer) {
      copyClientToParty('manufacturer')
    }
    if (sameAsClient.maker) {
      copyClientToParty('maker')
    }
  }, [clientSyncKey, copyClientToParty, sameAsClient.manufacturer, sameAsClient.maker])

  async function submit(values: TestOrderFormValues) {
    await onSubmit(normalizeTestOrderPayload(values))
  }

  function applyCustomer(prefix: 'client' | 'manufacturer' | 'maker', customerId: string) {
    const customer = customers.find((item) => String(item.id) === customerId)
    const idField = `${prefix}_customer_id` as const

    form.setValue(idField, customer ? customer.id : null)

    if (!customer) {
      return
    }

    form.setValue(`${prefix}_company` as const, customer.name)
    form.setValue(`${prefix}_address` as const, customer.address ?? '')
    form.setValue(`${prefix}_phone` as const, customer.phone ?? '')

    const defaultContact = contactOptionsForCustomer(customer)[0]
    if (defaultContact) {
      applyContact(prefix, defaultContact)
    }
  }

  function applyCustomerSearch(prefix: 'client' | 'manufacturer' | 'maker', value: string) {
    form.setValue(`${prefix}_company` as const, value, { shouldDirty: true, shouldValidate: prefix === 'client' })

    const customer = customers.find((item) => item.name === value || customerSearchValue(item) === value)
    if (!customer) {
      form.setValue(`${prefix}_customer_id` as const, null)
      return
    }

    applyCustomer(prefix, String(customer.id))
  }

  function applyContact(prefix: 'client' | 'manufacturer' | 'maker', contact: { name: string; phone?: string | null }) {
    form.setValue(`${prefix}_contact` as const, contact.name, { shouldDirty: true })
    if (contact.phone) {
      form.setValue(`${prefix}_phone` as const, contact.phone, { shouldDirty: true })
    }
  }

  function setSameAsClientParty(prefix: 'manufacturer' | 'maker', checked: boolean) {
    setSameAsClient((current) => ({ ...current, [prefix]: checked }))
    if (checked) {
      copyClientToParty(prefix)
    }
  }

  function applyStandard(index: number, standardId: string) {
    const standard = standards.find((item) => String(item.id) === standardId)

    form.setValue(`standards.${index}.standard_id`, standard ? standard.id : null)

    if (!standard) {
      return
    }

    form.setValue(`standards.${index}.standard_code`, standard.std_no)
    form.setValue(`standards.${index}.standard_name`, standard.chinese_name)
  }

  return (
    <form className="space-y-4" onSubmit={form.handleSubmit(submit)}>
      {error ? <ErrorNotice error={error} fallback="Unable to save test order" /> : null}
      {form.formState.errors.root ? <ErrorNotice error={form.formState.errors.root.message} fallback="Unable to save test order" /> : null}

      <Panel title="Order profile">
        <div className="grid gap-3 md:grid-cols-4">
          <Field label="Contract no">
            <input className={inputClass} {...form.register('contract_no')} />
          </Field>
          <Field label="Order date">
            <input className={inputClass} type="date" {...form.register('order_date')} />
            {form.formState.errors.order_date ? <span className="mt-1 block text-xs text-red-600">{form.formState.errors.order_date.message}</span> : null}
          </Field>
          <Field label="Planned end date">
            <input className={inputClass} type="date" {...form.register('planned_end_date')} />
          </Field>
          <Field label="Urgency">
            <select className={inputClass} {...form.register('urgency')}>
              {['normal', 'urgent', 'critical'].map((value) => (
                <option value={value} key={value}>
                  {zhText(value)}
                </option>
              ))}
            </select>
          </Field>
          <Field label="Sample status">
            <select className={inputClass} {...form.register('sample_status')}>
              {['not_received', 'partially_received', 'received', 'testing', 'completed'].map((value) => (
                <option value={value} key={value}>
                  {zhText(value)}
                </option>
              ))}
            </select>
          </Field>
        </div>
      </Panel>

      <Panel title="Customer snapshots">
        <div className="grid gap-3 md:grid-cols-3">
          <PartyFields
            prefix="client"
            title="Client"
            form={form}
            customers={customers}
            onPick={applyCustomer}
            onCompanySearch={applyCustomerSearch}
            onContactPick={applyContact}
            required
          />
          <PartyFields
            prefix="manufacturer"
            title="Manufacturer"
            form={form}
            customers={customers}
            onPick={applyCustomer}
            onCompanySearch={applyCustomerSearch}
            onContactPick={applyContact}
            sameAsClient={sameAsClient.manufacturer}
            onSameAsClientChange={(checked) => setSameAsClientParty('manufacturer', checked)}
          />
          <PartyFields
            prefix="maker"
            title="Maker"
            form={form}
            customers={customers}
            onPick={applyCustomer}
            onCompanySearch={applyCustomerSearch}
            onContactPick={applyContact}
            sameAsClient={sameAsClient.maker}
            onSameAsClientChange={(checked) => setSameAsClientParty('maker', checked)}
          />
        </div>
      </Panel>

      <Panel title="Execution standards">
        <div className="space-y-3">
          {standardRows.fields.map((row, index) => (
            <div className="rounded-md border border-emerald-900/10 bg-slate-50/60 p-3" key={row.id}>
              <div className="mb-3 flex items-center justify-between gap-2">
                <span className="text-sm font-medium text-slate-900">#{index + 1}</span>
                <Button variant="ghost" onClick={() => standardRows.remove(index)} disabled={standardRows.fields.length === 1}>
                  <Trash2 className="size-4" aria-hidden="true" />
                  Remove
                </Button>
              </div>
              <div className="grid gap-3 md:grid-cols-4">
                <Field label="Standard library">
                  <select className={inputClass} value={watchedStandards?.[index]?.standard_id ?? ''} onChange={(event) => applyStandard(index, event.target.value)}>
                    <option value="">{zhText('Manual')}</option>
                    {standards.map((standard) => (
                      <option value={standard.id} key={standard.id}>
                        {standard.std_no}
                      </option>
                    ))}
                  </select>
                </Field>
                <Field label="Standard code">
                  <input className={inputClass} {...form.register(`standards.${index}.standard_code`)} />
                </Field>
                <Field label="Standard name">
                  <input className={inputClass} {...form.register(`standards.${index}.standard_name`)} />
                </Field>
                <Field label="Report language">
                  <select className={inputClass} {...form.register(`standards.${index}.report_language`)}>
                    <option value="">{zhText('Unset')}</option>
                    <option value="zh">zh</option>
                    <option value="en">en</option>
                  </select>
                </Field>
                <Field label="Qualifications">
                  <input className={inputClass} placeholder="CMA, CNAS" {...form.register(`standards.${index}.qualifications_text`)} />
                </Field>
                <Field label="Requirement" className="md:col-span-3">
                  <textarea className={textareaClass} {...form.register(`standards.${index}.requirement`)} />
                </Field>
              </div>
            </div>
          ))}
          <Button variant="secondary" onClick={() => standardRows.append(emptyStandardRow())}>
            <Plus className="size-4" aria-hidden="true" />
            Add standard
          </Button>
        </div>
      </Panel>

      <Panel title="Sample rows">
        <div className="space-y-3">
          {sampleRows.fields.map((row, index) => (
            <div className="rounded-md border border-emerald-900/10 bg-slate-50/60 p-3" key={row.id}>
              <div className="mb-3 flex items-center justify-between gap-2">
                <span className="text-sm font-medium text-slate-900">#{index + 1}</span>
                <Button variant="ghost" onClick={() => sampleRows.remove(index)} disabled={sampleRows.fields.length === 1}>
                  <Trash2 className="size-4" aria-hidden="true" />
                  Remove
                </Button>
              </div>
              <div className="grid gap-3 md:grid-cols-4">
                <Field label="Sample name">
                  <input className={inputClass} {...form.register(`samples.${index}.sample_name`)} />
                </Field>
                <Field label="Specification">
                  <input className={inputClass} {...form.register(`samples.${index}.specification`)} />
                </Field>
                <Field label="Model">
                  <input className={inputClass} {...form.register(`samples.${index}.model`)} />
                </Field>
                <Field label="Input voltage">
                  <input className={inputClass} placeholder="220V" {...form.register(`samples.${index}.input_voltage`)} />
                </Field>
                <Field label="Power">
                  <input className={inputClass} placeholder="60W" {...form.register(`samples.${index}.power`)} />
                </Field>
                <Field label="Quantity">
                  <input className={inputClass} type="number" min={1} {...form.register(`samples.${index}.quantity`, { valueAsNumber: true })} />
                </Field>
                <Field label="Status">
                  <select className={inputClass} {...form.register(`samples.${index}.status`)}>
                    {['pending', 'partially_received', 'received', 'rejected', 'cancelled'].map((value) => (
                      <option value={value} key={value}>
                        {zhText(value)}
                      </option>
                    ))}
                  </select>
                </Field>
                <Field label="Detail content" className="md:col-span-2">
                  <textarea className={textareaClass} {...form.register(`samples.${index}.detail_content`)} />
                </Field>
                <Field label="Remark">
                  <textarea className={textareaClass} {...form.register(`samples.${index}.remark`)} />
                </Field>
              </div>
            </div>
          ))}
          <Button variant="secondary" onClick={() => sampleRows.append(emptySampleRow())}>
            <Plus className="size-4" aria-hidden="true" />
            Add sample
          </Button>
        </div>
      </Panel>

      <Panel title="Report requirements">
        <div className="grid gap-3 md:grid-cols-4">
          <Field label="Report forms" className="md:col-span-2">
            <div className="grid min-h-20 gap-2 rounded-md border border-slate-300 px-3 py-2 text-sm sm:grid-cols-2">
              {reportFormOptions.map((item) => (
                <label className="flex items-center gap-2" key={item}>
                  <input className="size-4" type="checkbox" value={item} {...form.register('report_forms')} />
                  {zhText(item)}
                </label>
              ))}
            </div>
          </Field>
          <Field label="Report submission">
            <select className={inputClass} {...form.register('delivery_method')}>
              <option value="">{zhText('Unset')}</option>
              {reportSubmissionOptions.map((item) => (
                <option value={item} key={item}>
                  {zhText(item)}
                </option>
              ))}
            </select>
          </Field>
          <Field label="Outsourcing option">
            <select className={inputClass} {...form.register('outsourcing_option')}>
              <option value="">{zhText('Unset')}</option>
              {outsourcingOptions.map((item) => (
                <option value={item} key={item}>
                  {zhText(item)}
                </option>
              ))}
            </select>
          </Field>
          <Field label="Remark" className="md:col-span-4">
            <textarea className={textareaClass} {...form.register('remark')} />
          </Field>
        </div>
      </Panel>

      <Panel title="Delivery address and confirmations">
        <div className="grid gap-3 md:grid-cols-4">
          <Field label="Lab name">
            <input className={inputClass} {...form.register('address_lab_name')} />
          </Field>
          <Field label="Address contact">
            <input className={inputClass} {...form.register('address_contact')} />
          </Field>
          <Field label="Address phone">
            <input className={inputClass} {...form.register('address_phone')} />
          </Field>
          <Field label="Address detail">
            <input className={inputClass} {...form.register('address_detail')} />
          </Field>
          <Field label="Client signature">
            <input className={inputClass} {...form.register('client_signature')} />
          </Field>
          <Field label="Client sign date">
            <input className={inputClass} type="date" {...form.register('client_sign_date')} />
          </Field>
          <Field label="Department confirm">
            <input className={inputClass} {...form.register('dept_confirm')} />
          </Field>
          <Field label="Department confirm date">
            <input className={inputClass} type="date" {...form.register('dept_confirm_date')} />
          </Field>
          <Field label="Lab confirm">
            <input className={inputClass} {...form.register('lab_confirm')} />
          </Field>
          <Field label="Lab confirm date">
            <input className={inputClass} type="date" {...form.register('lab_confirm_date')} />
          </Field>
        </div>
      </Panel>

      <div className="flex justify-end gap-2 border-t border-slate-200 pt-4">
        <Button type="button" variant="ghost" onClick={onCancel}>
          <X className="size-4" aria-hidden="true" />
          Cancel
        </Button>
        <Button type="submit" variant="primary" disabled={submitting}>
          <Save className="size-4" aria-hidden="true" />
          Save
        </Button>
      </div>
    </form>
  )
}

function PartyFields({
  prefix,
  title,
  form,
  customers,
  onPick,
  onCompanySearch,
  onContactPick,
  required = false,
  sameAsClient = false,
  onSameAsClientChange,
}: {
  prefix: 'client' | 'manufacturer' | 'maker'
  title: string
  form: UseFormReturn<TestOrderFormValues>
  customers: Customer[]
  onPick: (prefix: 'client' | 'manufacturer' | 'maker', customerId: string) => void
  onCompanySearch: (prefix: 'client' | 'manufacturer' | 'maker', value: string) => void
  onContactPick: (prefix: 'client' | 'manufacturer' | 'maker', contact: { name: string; phone?: string | null }) => void
  required?: boolean
  sameAsClient?: boolean
  onSameAsClientChange?: (checked: boolean) => void
}) {
  const selectedCustomerId = useWatch({ control: form.control, name: `${prefix}_customer_id` })
  const companyValue = useWatch({ control: form.control, name: `${prefix}_company` })
  const contactValue = useWatch({ control: form.control, name: `${prefix}_contact` })
  const selectedCustomer = customers.find((customer) => customer.id === selectedCustomerId)
  const contactOptions = contactOptionsForCustomer(selectedCustomer, selectedCustomer?.contacts)
  const datalistId = `${prefix}-customer-options`
  const synced = prefix !== 'client' && sameAsClient

  return (
    <div className="space-y-3 rounded-md border border-emerald-900/10 bg-slate-50/60 p-3">
      <div className="flex items-center justify-between gap-3">
        <h3 className="text-sm font-medium text-slate-900">{zhText(title)}</h3>
        {prefix !== 'client' ? (
          <label className="inline-flex items-center gap-1.5 text-xs text-slate-600">
            <input className="size-3.5" type="checkbox" checked={sameAsClient} onChange={(event) => onSameAsClientChange?.(event.target.checked)} />
            同上
          </label>
        ) : null}
      </div>
      <Field label={`${title} master`}>
        <select className={inputClass} value={selectedCustomerId ?? ''} onChange={(event) => onPick(prefix, event.target.value)} disabled={synced}>
          <option value="">{zhText('Manual')}</option>
          {customers.map((customer) => (
            <option value={customer.id} key={customer.id}>
              {customer.name}
            </option>
          ))}
        </select>
      </Field>
      <Field label={`${title} company`}>
        <input
          className={inputClass}
          list={datalistId}
          value={companyValue ?? ''}
          onChange={(event) => onCompanySearch(prefix, event.target.value)}
          readOnly={synced}
          placeholder={zhText('Search') ?? undefined}
        />
        <datalist id={datalistId}>
          {customers.map((customer) => (
            <option value={customerSearchValue(customer)} key={customer.id}>
              {customer.name}
            </option>
          ))}
        </datalist>
        {required && form.formState.errors.client_company ? <span className="mt-1 block text-xs text-red-600">{form.formState.errors.client_company.message}</span> : null}
      </Field>
      <Field label={`${title} address`}>
        <input className={inputClass} readOnly={synced} {...form.register(`${prefix}_address`)} />
      </Field>
      <Field label={`${title} contact`}>
        <select
          className={inputClass}
          value={contactValue ?? ''}
          onChange={(event) => {
            const contact = contactOptions.find((item) => item.name === event.target.value)

            if (contact) {
              onContactPick(prefix, contact)
              return
            }

            form.setValue(`${prefix}_contact` as const, event.target.value, { shouldDirty: true })
          }}
          disabled={synced || !selectedCustomer}
        >
          <option value="">{zhText('Manual')}</option>
          {contactOptions.map((contact) => (
            <option value={contact.name} key={contact.id}>
              {contact.name}
            </option>
          ))}
        </select>
        <input className={`${inputClass} mt-2`} readOnly={synced} {...form.register(`${prefix}_contact`)} />
      </Field>
      <Field label={`${title} phone`}>
        <input className={inputClass} readOnly={synced} {...form.register(`${prefix}_phone`)} />
      </Field>
    </div>
  )
}

function defaultValues(order?: TestOrder | null): TestOrderFormValues {
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
    manufacturer_customer_id: order?.manufacturer_customer_id ?? null,
    manufacturer_company: order?.manufacturer_company ?? '',
    manufacturer_address: order?.manufacturer_address ?? '',
    manufacturer_contact: order?.manufacturer_contact ?? '',
    manufacturer_phone: order?.manufacturer_phone ?? '',
    maker_customer_id: order?.maker_customer_id ?? null,
    maker_company: order?.maker_company ?? '',
    maker_address: order?.maker_address ?? '',
    maker_contact: order?.maker_contact ?? '',
    maker_phone: order?.maker_phone ?? '',
    report_forms: order?.report_forms ?? ['formal_report', 'electronic_report'],
    delivery_method: order?.delivery_method ?? 'self_pick',
    outsourcing_option: order?.outsourcing_option ?? 'allowed',
    remark: order?.remark ?? '',
    sample_status: order?.sample_status ?? 'not_received',
    address_lab_name: order?.address_lab_name ?? '',
    address_contact: order?.address_contact ?? '',
    address_detail: order?.address_detail ?? '',
    address_phone: order?.address_phone ?? '',
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
          power: row.power ?? '',
          status: row.status,
          quantity: row.quantity,
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
    power: '',
    status: 'pending' as const,
    quantity: 1,
    detail_content: '',
    remark: '',
  }
}
