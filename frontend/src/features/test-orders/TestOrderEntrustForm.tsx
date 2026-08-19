import { zodResolver } from '@hookform/resolvers/zod'
import { Plus, Printer, Save, Trash2 } from 'lucide-react'
import { createContext, useContext, useEffect } from 'react'
import { Controller, type UseFormReturn, useFieldArray, useForm } from 'react-hook-form'
import { ErrorNotice, Button } from '../system/shared'
import type { Customer } from '../customers/CustomerListPage'
import type { Standard } from '../standards/StandardListPage'
import { zhText } from '../../lib/zh'
import type { TestOrder } from './TestOrderListPage'
import { CustomerCompanySearchInput } from './CustomerCompanySearchInput'
import { StandardSearchInput } from './StandardSearchInput'
import { testOrderDefaultValues } from './testOrderDefaults'
import {
  normalizeTestOrderPayload,
  reportFormOptions,
  sampleConditionOptions,
  testOrderSchema,
  customerForSearchValue,
  customerSnapshotValues,
  canonicalReportFormValue,
  type TestOrderFormValues,
} from './testOrderSchema'

type SubmitAction = 'save' | 'print'

const EntrustOrderCompactContext = createContext(false)

export function TestOrderEntrustForm({
  order,
  customers = [],
  standardOptions = [],
  editable,
  submitting,
  error,
  onSubmit,
}: {
  order: TestOrder
  customers?: Customer[]
  standardOptions?: Standard[]
  editable: boolean
  submitting: boolean
  error: unknown
  onSubmit: (payload: Record<string, unknown>, action: SubmitAction) => Promise<void>
}) {
  const form = useForm<TestOrderFormValues>({
    resolver: zodResolver(testOrderSchema),
    defaultValues: testOrderDefaultValues(order),
  })
  const standards = useFieldArray({ control: form.control, name: 'standards' })
  const samples = useFieldArray({ control: form.control, name: 'samples' })

  useEffect(() => {
    form.reset(testOrderDefaultValues(order))
  }, [form, order])

  async function submit(values: TestOrderFormValues, action: SubmitAction) {
    await onSubmit(normalizeTestOrderPayload(values), action)
  }

  function run(action: SubmitAction) {
    return form.handleSubmit((values) => submit(values, action))
  }

  function applyClientCustomerSearch(value: string) {
    const customer = customerForSearchValue(customers, value)
    const originalCustomerId = order.client_customer_id
    const isOriginalCustomer = customer !== null
      && (originalCustomerId !== null && originalCustomerId !== undefined
        ? String(customer.id) === String(originalCustomerId)
        : customer.name === order.client_company)
    const originalSnapshot = isOriginalCustomer
      ? {
          address: order.client_address,
          contact: order.client_contact,
          phone: order.client_phone,
          email: order.client_email,
        }
      : undefined

    Object.entries(customerSnapshotValues('client', customer, value, originalSnapshot)).forEach(([field, fieldValue]) => {
      form.setValue(field as keyof TestOrderFormValues, fieldValue as never, { shouldDirty: true, shouldValidate: false })
    })
  }

  function applyStandard(index: number, standard: Standard) {
    form.setValue(`standards.${index}.standard_id`, standard.id, { shouldDirty: true })
    form.setValue(`standards.${index}.standard_code`, standard.std_no, { shouldDirty: true })
    form.setValue(`standards.${index}.standard_name`, standard.chinese_name, { shouldDirty: true })
  }

  return (
    <form className="space-y-4" autoComplete="off" onSubmit={run('save')}>
      {error ? <ErrorNotice error={error} fallback="Unable to save test order" /> : null}
      {form.formState.errors.root ? <ErrorNotice error={form.formState.errors.root.message} fallback="Unable to save test order" /> : null}

      <div className="mx-auto w-full max-w-[210mm]" style={{ containerType: 'inline-size' }}>
        <section
          className="min-w-0 flow-root bg-white text-slate-900 shadow-[0_2px_8px_rgb(15_23_42/0.08)] sm:rounded-sm"
          style={{ minHeight: 'calc(100cqw * 297 / 210)' }}
        >
          <EntrustOrderCompactContext.Provider value={!editable}>
            <div className="min-w-0 overflow-hidden border border-emerald-900/15 md:m-[15mm]">
        <header className={`grid grid-cols-[1fr_10rem] border-b border-emerald-900/15 ${editable ? 'text-sm md:text-[9pt]' : 'text-[9pt]'}`}>
          {editable
            ? <input className="h-16 min-w-0 border-0 px-4 text-center text-lg font-semibold outline-none md:h-12 md:px-3 md:text-[14pt]" aria-label="实验室名称" {...form.register('address_lab_name')} />
            : <div className="flex h-12 items-center justify-center px-3 text-center text-[14pt] font-semibold">{form.watch('address_lab_name')}</div>}
          <div className={`border-l border-emerald-900/15 text-center text-slate-500 ${editable ? 'text-xs md:text-[9pt]' : 'text-[9pt]'}`}>
            <div className={`flex items-center justify-center border-b border-emerald-900/15 ${editable ? 'h-8 md:h-6' : 'h-6'}`}>表单编号：FO-12-01</div>
            <div className={`flex items-center justify-center ${editable ? 'h-8 md:h-6' : 'h-6'}`}>版本：v1.1</div>
          </div>
        </header>
        <h2 className={`${editable ? 'py-4 text-2xl md:py-2 md:text-[16pt]' : 'py-2 text-[16pt]'} text-center font-semibold tracking-[0.25em]`}>实验委托单</h2>

        <div className="border-t border-emerald-900/15">
          <div className="grid grid-cols-[7rem_minmax(0,1fr)] border-b border-emerald-900/15 md:grid-cols-[7rem_minmax(0,1fr)_7rem_minmax(0,1fr)]">
            <CellLabel>委托日期</CellLabel>
            <Cell><TextCell form={form} editable={editable} name={'order_date'} type="date" /></Cell>
            <CellLabel>紧急程度</CellLabel>
            <Cell className="border-r-0">
              <SelectCell form={form} editable={editable} name={'urgency'} placeholder="" options={urgencyOptions} ariaLabel="紧急程度" />
            </Cell>
          </div>
          <div className="grid grid-cols-[7rem_minmax(0,1fr)] border-b border-emerald-900/15 md:grid-cols-[7rem_minmax(0,1fr)_7rem_minmax(0,1fr)]">
            <CellLabel>计划结束时间</CellLabel>
            <Cell><TextCell form={form} editable={editable} name={'planned_end_date'} type="date" /></Cell>
            <CellLabel>样品状态</CellLabel>
            <Cell className="border-r-0">
              <SelectCell form={form} editable={editable} name={'sample_status'} placeholder="" options={sampleStatusOptions} ariaLabel="样品状态" />
            </Cell>
          </div>
          <div className="grid grid-cols-[7rem_minmax(0,1fr)] md:grid-cols-[7rem_minmax(0,1fr)_7rem_minmax(0,1fr)]">
            <CellLabel>委托编号</CellLabel><Cell><ReadOnly value={order.order_no} /></Cell>
            <CellLabel>合同编号</CellLabel><Cell className="border-r-0"><TextCell form={form} editable={editable} name={'contract_no'} /></Cell>
          </div>
        </div>

        <SectionTitle>委托单位信息</SectionTitle>
        <PartyRows form={form} editable={editable} prefix="client" title="委托单位*" customers={customers} onCompanySearch={applyClientCustomerSearch} />
        <PartyRows form={form} editable={editable} prefix="manufacturer" title="制造商" />
        <PartyRows form={form} editable={editable} prefix="maker" title="生产厂" />

        <SectionTitle
          action={editable ? <button className="inline-flex min-h-11 items-center gap-1 text-sm text-emerald-700 hover:text-emerald-900 md:min-h-6 md:text-[9pt]" type="button" onClick={() => standards.append(emptyStandard())}><Plus className="size-4 md:size-3" />新增标准</button> : null}
        >
          检测要求以及报告要求
        </SectionTitle>
        <div className="overflow-x-auto overflow-y-hidden">
          <table className="w-full min-w-[38rem] border-collapse text-sm">
            <thead><tr className="bg-slate-50"><TableHead className="w-[60%]">标准号及版本</TableHead><TableHead>资质要求</TableHead><TableHead>报告语言</TableHead>{editable ? <TableHead className="w-12">操作</TableHead> : null}</tr></thead>
            <tbody>
              {standards.fields.map((row, index) => (
                <tr key={row.id}>
                  <TableCell>{editable ? <StandardSearchInput value={[form.watch(`standards.${index}.standard_code`), form.watch(`standards.${index}.standard_name`)].filter(Boolean).join(' ')} standards={standardOptions} className={cellInput} ariaLabel={`搜索选择标准 ${index + 1}`} onSelect={(standard) => applyStandard(index, standard)} /> : <ReadOnly value={[form.watch(`standards.${index}.standard_code`), form.watch(`standards.${index}.standard_name`)].filter(Boolean).join(' ')} />}</TableCell>
                  <TableCell><TextCell form={form} editable={editable} name={`standards.${index}.qualifications_text`} placeholder="CMA, CNAS" readOnlyClassName="w-full text-center" /></TableCell>
                  <TableCell><SelectCell form={form} editable={editable} name={`standards.${index}.report_language`} placeholder="" options={reportLanguageOptions} readOnlyClassName="w-full text-center" ariaLabel={`标准 ${index + 1} 报告语言`} /></TableCell>
                  {editable ? <TableCell><button className="min-h-11 min-w-11 text-slate-500 hover:text-red-600 disabled:opacity-40 md:min-h-6 md:min-w-6" type="button" disabled={standards.fields.length === 1} onClick={() => standards.remove(index)} aria-label="移除标准"><Trash2 className="mx-auto size-4 md:size-3" /></button></TableCell> : null}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        <div className="border-t border-emerald-900/15 text-sm">
          <div className="grid grid-cols-[7rem_minmax(0,1fr)] border-b border-emerald-900/15 md:grid-cols-[minmax(0,15fr)_minmax(0,50fr)_minmax(0,15fr)_minmax(0,20fr)]">
            <CellLabel>报告形式</CellLabel>
            <Cell>{editable
              ? <div className="flex flex-wrap gap-x-2 gap-y-1 px-3 py-2 md:px-2 md:py-1 md:text-[9pt]">{reportFormOptions.map((option) => <label className="inline-flex min-h-11 items-center gap-1 md:min-h-6" key={option}><input type="checkbox" value={option} {...form.register('report_forms')} />{reportFormLabel(option)}</label>)}</div>
              : <ReadonlyChoiceList options={reportFormDisplayOptions} selectedValues={(form.watch('report_forms') ?? []).map(canonicalReportFormValue)} />}</Cell>
            <CellLabel>样品是否返还</CellLabel><Cell className="border-r-0">{editable ? <SelectCell form={form} editable name={'sample_return'} options={sampleReturnDisplayOptions} ariaLabel="样品是否返还" /> : <ReadonlyChoiceList options={sampleReturnDisplayOptions} selectedValues={[String(form.watch('sample_return') ?? '')]} />}</Cell>
          </div>
          <div className="grid grid-cols-[7rem_minmax(0,1fr)] border-b border-emerald-900/15 md:grid-cols-[minmax(0,15fr)_minmax(0,35fr)_minmax(0,15fr)_minmax(0,35fr)]">
            <CellLabel>报告提交</CellLabel><Cell>{editable ? <SelectCell form={form} editable name={'delivery_method'} options={reportSubmissionDisplayOptions} ariaLabel="报告提交" /> : <ReadonlyChoiceList options={reportSubmissionDisplayOptions} selectedValues={[String(form.watch('delivery_method') ?? '')]} />}</Cell>
            <CellLabel>准许检测分包</CellLabel><Cell className="border-r-0">{editable ? <SelectCell form={form} editable name={'outsourcing_option'} options={outsourcingDisplayOptions} ariaLabel="准许检测分包" /> : <ReadonlyChoiceList options={outsourcingDisplayOptions} selectedValues={[String(form.watch('outsourcing_option') ?? '')]} />}</Cell>
          </div>
          <div className="grid grid-cols-[7rem_minmax(0,1fr)]">
            <CellLabel>备注</CellLabel><Cell className="border-r-0"><TextCell form={form} editable={editable} name={'remark'} /></Cell>
          </div>
        </div>

        <SectionTitle
          action={editable ? <button className="inline-flex min-h-11 items-center gap-1 text-sm text-emerald-700 hover:text-emerald-900 md:min-h-6 md:text-[9pt]" type="button" onClick={() => samples.append(emptySample())}><Plus className="size-4 md:size-3" />新增样品</button> : null}
        >
          *样品信息
        </SectionTitle>
        {samples.fields.map((row, index) => (
          <div className="border-b border-emerald-900/15" key={row.id}>
            <div className="grid grid-cols-[6rem_minmax(0,1fr)] border-b border-emerald-900/15 md:grid-cols-[6rem_minmax(0,1fr)_6rem_minmax(0,1fr)_6rem_minmax(0,1fr)]">
              <CellLabel>名称*</CellLabel><Cell><TextCell form={form} editable={editable} name={`samples.${index}.sample_name`} /></Cell>
              <CellLabel>额定电流</CellLabel><Cell><TextCell form={form} editable={editable} name={`samples.${index}.rated_current`} /></Cell>
              <CellLabel>状态</CellLabel><Cell className="border-r-0"><SelectCell form={form} editable={editable} name={`samples.${index}.sample_condition`} options={sampleConditionOptions.map((option) => ({ value: option, label: option === 'good' ? '完好' : '异常' }))} ariaLabel={`样品 ${index + 1} 状态`} /></Cell>
            </div>
            <div className="grid grid-cols-[6rem_minmax(0,1fr)] border-b border-emerald-900/15 md:grid-cols-[6rem_minmax(0,1fr)_6rem_minmax(0,1fr)_6rem_minmax(0,1fr)]">
              <CellLabel>型号*</CellLabel><Cell><TextCell form={form} editable={editable} name={`samples.${index}.model`} /></Cell>
              <CellLabel>额定功率*</CellLabel><Cell><TextCell form={form} editable={editable} name={`samples.${index}.power`} /></Cell>
              <CellLabel>样品数量</CellLabel><Cell className="border-r-0">{editable
                ? <div className="flex"><input className={cellInput} type="number" min={1} {...form.register(`samples.${index}.quantity`, { valueAsNumber: true })} /><input className="min-h-11 w-12 border-l border-emerald-900/15 px-1 text-center text-sm outline-none focus:bg-emerald-50/70 md:min-h-6 md:text-[9pt]" {...form.register(`samples.${index}.quantity_unit`)} /></div>
                : <ReadOnly value={`${form.watch(`samples.${index}.quantity`) ?? ''} ${form.watch(`samples.${index}.quantity_unit`) ?? ''}`.trim()} />}</Cell>
            </div>
            <div className="grid grid-cols-[6rem_minmax(0,1fr)] border-b border-emerald-900/15 md:grid-cols-[6rem_minmax(0,1fr)_6rem_minmax(0,1fr)_6rem_minmax(0,1fr)]">
              <CellLabel>额定电压*</CellLabel><Cell><TextCell form={form} editable={editable} name={`samples.${index}.input_voltage`} /></Cell>
              <CellLabel>额定频率</CellLabel><Cell><TextCell form={form} editable={editable} name={`samples.${index}.rated_frequency`} /></Cell>
              <CellLabel>异常说明</CellLabel><Cell className="border-r-0"><TextCell form={form} editable={editable} name={`samples.${index}.sample_condition_note`} /></Cell>
            </div>
            <div className="grid grid-cols-[6rem_minmax(0,1fr)]"><CellLabel>备注</CellLabel><Cell className="flex gap-2"><TextCell form={form} editable={editable} name={`samples.${index}.remark`} />{editable ? <button className="min-h-11 min-w-11 px-2 text-slate-500 hover:text-red-600 disabled:opacity-40 md:min-h-6 md:min-w-6 md:px-1" type="button" disabled={samples.fields.length === 1} onClick={() => samples.remove(index)} aria-label="移除样品"><Trash2 className="size-4 md:size-3" /></button> : null}</Cell></div>
          </div>
        ))}

        <SectionTitle>*样品寄送地址</SectionTitle>
        <div className="grid grid-cols-[7rem_minmax(0,1fr)] border-b border-emerald-900/15"><CellLabel>实验室名称</CellLabel><Cell className="border-r-0"><TextCell form={form} editable={editable} name={'address_lab_name'} /></Cell></div>
        <div className="grid grid-cols-[7rem_minmax(0,1fr)] border-b border-emerald-900/15"><CellLabel>实验室地址</CellLabel><Cell className="border-r-0"><AreaCell form={form} editable={editable} name={'address_detail'} minHeight="min-h-16" /></Cell></div>
        <div className="grid grid-cols-[7rem_minmax(0,1fr)] border-b border-emerald-900/15"><CellLabel>联系人</CellLabel><Cell className="border-r-0"><TextCell form={form} editable={editable} name={'address_contact'} /></Cell></div>
        <div className="grid grid-cols-[7rem_minmax(0,1fr)] border-b border-emerald-900/15"><CellLabel>联系电话</CellLabel><Cell className="border-r-0"><TextCell form={form} editable={editable} name={'address_phone'} /></Cell></div>
        <div className="grid grid-cols-[7rem_minmax(0,1fr)]"><CellLabel>特别说明</CellLabel><Cell className="border-r-0"><AreaCell form={form} editable={editable} name={'shipping_notes'} minHeight="min-h-20" /></Cell></div>

        <div className={`border-t border-emerald-900/15 ${editable ? 'p-4 md:p-2' : 'p-2'}`}><div className="grid items-center gap-4 md:grid-cols-[minmax(0,1fr)_15rem] md:gap-2"><p className={`${editable ? 'text-base md:text-[16pt]' : 'text-[16pt]'} font-medium`}>委托单位声明：上述提供资料正确无误！</p><label className={editable ? 'text-sm md:text-[10pt]' : 'text-[10pt]'}>委托人（客户）签字{editable ? <input className="mt-1 min-h-11 w-full border-b border-emerald-900/15 px-2 py-1 outline-none md:min-h-6" {...form.register('client_signature')} /> : <div className="mt-1 min-h-8 border-b border-emerald-900/15 px-2 py-1">{form.watch('client_signature')}</div>}</label></div><div className={`${editable ? 'mt-3 md:mt-1' : 'mt-1'} flex justify-start md:justify-end`}><label className={editable ? 'text-sm md:text-[10pt]' : 'text-[10pt]'}>日期{editable ? <input type="date" className="ml-2 min-h-11 border-b border-emerald-900/15 px-2 py-1 outline-none md:min-h-6" {...form.register('client_sign_date')} /> : <span className="ml-2 inline-block min-w-28 border-b border-emerald-900/15 px-2 py-1">{form.watch('client_sign_date')}</span>}</label></div></div>
            <div className="divide-y divide-emerald-900/15 border-t border-emerald-900/15 text-center text-sm">
              <ConfirmationRow form={form} editable={editable} title="实验室资源满足*" confirmLabel="综合部确认" confirmName="dept_confirm" dateName="dept_confirm_date" />
              <ConfirmationRow form={form} editable={editable} title="客户要求的评审*" confirmLabel="检测部确认" confirmName="lab_confirm" dateName="lab_confirm_date" />
            </div>
            </div>
          </EntrustOrderCompactContext.Provider>
        </section>
      </div>

      {editable ? <div className="mx-auto flex max-w-[210mm] flex-wrap justify-end gap-2"><Button type="submit" variant="primary" disabled={submitting}><Save className="size-4" />保存</Button><Button type="button" variant="secondary" disabled={submitting} onClick={run('print')}><Printer className="size-4" />保存并重新打印</Button></div> : null}
    </form>
  )
}

function PartyRows({
  form,
  editable,
  prefix,
  title,
  customers = [],
  onCompanySearch,
}: {
  form: UseFormReturn<TestOrderFormValues>
  editable: boolean
  prefix: 'client' | 'manufacturer' | 'maker'
  title: string
  customers?: Customer[]
  onCompanySearch?: (value: string) => void
}) {
  const compact = useContext(EntrustOrderCompactContext)
  const companyValue = String(form.watch(`${prefix}_company`) ?? '')
  const addressValue = String(form.watch(`${prefix}_address`) ?? '')

  return <div className={`border-t border-emerald-900/15 ${compact ? 'text-[9pt]' : 'text-sm md:text-[9pt]'}`}><div className="grid grid-cols-[7rem_minmax(0,1fr)] border-b border-emerald-900/15 md:grid-cols-[minmax(0,15fr)_minmax(0,40fr)_minmax(0,7fr)_minmax(0,15fr)_minmax(0,7fr)_minmax(0,16fr)]"><CellLabel>{title}</CellLabel><Cell>{editable && prefix === 'client' && onCompanySearch ? <CustomerCompanySearchInput prefix="client" className={cellInput} value={companyValue} customers={customers} onChange={onCompanySearch} /> : <TextCell form={form} editable={editable} name={`${prefix}_company`} readOnlySingleLine />}</Cell><CellLabel>联系人</CellLabel><Cell><TextCell form={form} editable={editable} name={`${prefix}_contact`} readOnlySingleLine controlled={prefix === 'client'} /></Cell><CellLabel>电话</CellLabel><Cell className="border-r-0"><TextCell form={form} editable={editable} name={`${prefix}_phone`} readOnlySingleLine controlled={prefix === 'client'} /></Cell></div><div className="grid grid-cols-[7rem_minmax(0,1fr)] md:grid-cols-[minmax(0,15fr)_minmax(0,56fr)_minmax(0,7fr)_minmax(0,22fr)]"><CellLabel>地址</CellLabel><Cell>{editable && prefix === 'client' ? <input className={`${cellInput} cursor-not-allowed bg-slate-100 text-slate-600`} value={addressValue} readOnly aria-label="委托单位地址（由所选公司同步）" /> : <TextCell form={form} editable={editable} name={`${prefix}_address`} />}</Cell><CellLabel>邮箱</CellLabel><Cell className="border-r-0"><TextCell form={form} editable={editable} name={`${prefix}_email`} type="email" readOnlySingleLine controlled={prefix === 'client'} /></Cell></div></div>
}

function ConfirmationRow({
  form,
  editable,
  title,
  confirmLabel,
  confirmName,
  dateName,
}: {
  form: UseFormReturn<TestOrderFormValues>
  editable: boolean
  title: string
  confirmLabel: string
  confirmName: 'dept_confirm' | 'lab_confirm'
  dateName: 'dept_confirm_date' | 'lab_confirm_date'
}) {
  return (
    <div className="grid grid-cols-[7rem_minmax(0,1fr)] md:grid-cols-5">
      <CellLabel>{title}</CellLabel>
      <div className="grid grid-cols-[4rem_minmax(0,1fr)] md:contents">
        <CellLabel>{confirmLabel}</CellLabel>
        <Cell><TextCell form={form} editable={editable} name={confirmName} /></Cell>
        <CellLabel>日期</CellLabel>
        <Cell className="border-r-0"><TextCell form={form} editable={editable} name={dateName} type="date" /></Cell>
      </div>
    </div>
  )
}


/**
 * A cell that stops being a control when nobody is editing.
 *
 * Disabled inputs made the detail page read as a form waiting to be filled in:
 * an untouched date showed 年/月/日 and every value sat in a box with an input's
 * affordance. Reading a commission order is the common case, so that case gets
 * plain text and the controls appear only once editing is entered.
 */
function ReadOnly({ value, className = '', singleLine = false }: { value: unknown; className?: string; singleLine?: boolean }) {
  const compact = useContext(EntrustOrderCompactContext)
  const text = value === null || value === undefined || value === '' ? '' : String(value)

  return (
    <span
      className={`min-w-0 text-slate-800 ${compact ? 'px-2 py-1 text-[9pt] leading-[12pt]' : 'px-3 py-1.5 text-sm md:px-2 md:py-1 md:text-[9pt] md:leading-[12pt]'} ${singleLine ? 'block w-full truncate whitespace-nowrap' : 'whitespace-pre-wrap break-words'} ${className}`}
      title={singleLine && text ? text : undefined}
    >
      {text || <span className="text-slate-300">—</span>}
    </span>
  )
}

type CellProps = {
  form: UseFormReturn<TestOrderFormValues>
  editable: boolean
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  name: any
  type?: string
  placeholder?: string
  readOnlySingleLine?: boolean
  readOnlyClassName?: string
  ariaLabel?: string
  controlled?: boolean
}

function TextCell({ form, editable, name, type = 'text', placeholder, readOnlySingleLine = false, readOnlyClassName = '', controlled = false }: CellProps) {
  if (!editable) {
    return <ReadOnly value={form.watch(name)} singleLine={readOnlySingleLine} className={readOnlyClassName} />
  }

  if (controlled) {
    return (
      <Controller
        control={form.control}
        name={name}
        render={({ field }) => (
          <input
            {...field}
            className={cellInput}
            type={type}
            placeholder={placeholder}
            value={String(field.value ?? '')}
          />
        )}
      />
    )
  }

  return <input className={cellInput} type={type} placeholder={placeholder} {...form.register(name)} />
}

function AreaCell({ form, editable, name, minHeight }: CellProps & { minHeight: string }) {
  if (!editable) {
    return <ReadOnly value={form.watch(name)} className="py-2" />
  }

  return <textarea className={`${minHeight} w-full resize-y px-3 py-2 text-sm outline-none md:min-h-6 md:px-2 md:py-1 md:text-[9pt] md:leading-[12pt]`} {...form.register(name)} />
}

function SelectCell({
  form,
  editable,
  name,
  options,
  placeholder = '未设置',
  readOnlyClassName = '',
  ariaLabel,
}: CellProps & { options: Array<{ value: string; label: string }> }) {
  if (!editable) {
    const current = String(form.watch(name) ?? '')

    return <ReadOnly value={options.find((option) => option.value === current)?.label ?? ''} className={readOnlyClassName} />
  }

  return (
    <select className={cellInput} aria-label={ariaLabel} {...form.register(name)}>
      {placeholder ? <option value="">{placeholder}</option> : null}
      {options.map((option) => (
        <option value={option.value} key={option.value}>{option.label}</option>
      ))}
    </select>
  )
}

function ReadonlyChoiceList({ options, selectedValues }: { options: Array<{ value: string; label: string }>; selectedValues: string[] }) {
  const selected = new Set(selectedValues)

  return (
    <div className="flex min-w-0 flex-wrap gap-x-2 gap-y-0.5 px-2 py-1 text-[9pt] leading-[12pt]">
      {options.map((option) => {
        const checked = selected.has(option.value)

        return (
          <span aria-label={`${option.label}${checked ? '已选择' : '未选择'}`} key={option.value}>
            <span aria-hidden="true">{checked ? '■' : '□'}</span>{option.label}
          </span>
        )
      })}
    </div>
  )
}

function SectionTitle({ children, action }: { children: string; action?: React.ReactNode }) { const compact = useContext(EntrustOrderCompactContext); return <div className={`flex items-center justify-between gap-2 border-y border-emerald-900/15 bg-slate-50 px-3 ${compact ? 'py-1' : 'py-2 md:py-1'}`}><h3 className={`font-semibold text-slate-900 ${compact ? 'text-[10pt] leading-[12pt]' : 'text-sm md:text-[10pt] md:leading-[12pt]'}`}>{children}</h3>{action ? <div className="shrink-0">{action}</div> : null}</div> }
function CellLabel({ children, className = '' }: { children: React.ReactNode; className?: string }) { const compact = useContext(EntrustOrderCompactContext); return <div className={`flex items-center justify-center border-r border-emerald-900/15 bg-slate-50 px-1 text-center font-medium text-slate-600 ${compact ? 'min-h-6 text-[9pt] leading-[12pt]' : 'min-h-10 text-xs md:min-h-6 md:text-[9pt] md:leading-[12pt]'} ${className}`}>{children}</div> }
function Cell({ children, className = '' }: { children: React.ReactNode; className?: string }) { const compact = useContext(EntrustOrderCompactContext); return <div className={`flex min-w-0 items-center border-r border-emerald-900/15 ${compact ? 'min-h-6' : 'min-h-10 md:min-h-6'} ${className}`}>{children}</div> }
function TableHead({ children, className = '' }: { children: React.ReactNode; className?: string }) { const compact = useContext(EntrustOrderCompactContext); return <th className={`border border-emerald-900/15 px-2 text-center font-medium text-slate-600 ${compact ? 'py-1 text-[9pt] leading-[12pt]' : 'py-2 text-xs md:py-1 md:text-[9pt] md:leading-[12pt]'} ${className}`}>{children}</th> }
function TableCell({ children, className = '' }: { children: React.ReactNode; className?: string }) { const compact = useContext(EntrustOrderCompactContext); return <td className={`border border-emerald-900/15 ${compact ? 'p-0' : 'p-1 md:p-0'} ${className}`}>{children}</td> }

const urgencyOptions = [
  { value: 'normal', label: '常规' },
  { value: 'urgent', label: '加急' },
  { value: 'critical', label: '特急' },
]

const sampleStatusOptions = [
  { value: 'not_received', label: '未收样' },
  { value: 'partially_received', label: '部分收样' },
  { value: 'received', label: '已收样' },
  { value: 'testing', label: '检测中' },
  { value: 'completed', label: '已完成' },
]

const reportLanguageOptions = [
  { value: 'zh', label: '中文' },
  { value: 'en', label: '英文' },
]

const reportFormDisplayOptions = [
  { value: 'electronic_report', label: '电子档' },
  { value: 'paper_report', label: '纸本' },
  { value: 'formal_report', label: '正式报告' },
  { value: 'simple_report', label: '简版报告' },
  { value: 'english_report', label: '英文报告' },
]

const sampleReturnDisplayOptions = [
  { value: 'return', label: '是' },
  { value: 'destroy', label: '否（销毁处理）' },
]

const reportSubmissionDisplayOptions = [
  { value: 'self_pick', label: '自取' },
  { value: 'mail', label: '邮寄' },
]

const outsourcingDisplayOptions = [
  { value: 'allowed', label: '允许' },
  { value: 'not_allowed', label: '不允许' },
]

const cellInput = 'h-11 min-w-0 w-full rounded-none border-0 bg-transparent px-3 text-sm text-slate-900 outline-none focus:bg-emerald-50/70 focus:ring-1 focus:ring-inset focus:ring-emerald-600/30 md:h-6 md:px-2 md:text-[9pt] md:leading-[12pt]'

/**
 * Two vocabularies reach this field. The form offers `*_report` codes while
 * orders created through the API carry `electronic` and `paper`, and the
 * backend validates neither, so both are stored in practice. Falling through to
 * the shared dictionary means whatever is stored still reads as Chinese.
 */
function reportFormLabel(value: string) {
  return ({ formal_report: '正式报告', simple_report: '简版报告', electronic_report: '电子档', paper_report: '纸本', english_report: '英文报告' } as Record<string, string>)[value]
    ?? zhText(value)
    ?? value
}
function emptyStandard() { return { standard_id: null, standard_code: '', standard_name: '', report_language: 'zh', qualifications_text: '', requirement: '' } }
function emptySample() { return { sample_name: '', specification: '', model: '', input_voltage: '', rated_current: '', power: '', rated_frequency: '', status: 'pending' as const, quantity: 1, quantity_unit: '个', sample_condition: 'good' as const, sample_condition_note: '', detail_content: '', remark: '' } }
