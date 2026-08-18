import { zodResolver } from '@hookform/resolvers/zod'
import { Plus, Printer, Save, Trash2 } from 'lucide-react'
import { useEffect } from 'react'
import { type UseFormReturn, useFieldArray, useForm } from 'react-hook-form'
import { ErrorNotice, Button } from '../system/shared'
import { zhText } from '../../lib/zh'
import type { TestOrder } from './TestOrderListPage'
import { testOrderDefaultValues } from './testOrderDefaults'
import {
  normalizeTestOrderPayload,
  outsourcingOptions,
  reportFormOptions,
  reportSubmissionOptions,
  sampleConditionOptions,
  sampleReturnOptions,
  testOrderSchema,
  type TestOrderFormValues,
} from './testOrderSchema'

type SubmitAction = 'save' | 'print'

export function TestOrderEntrustForm({
  order,
  editable,
  submitting,
  error,
  onSubmit,
}: {
  order: TestOrder
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

  return (
    <form className="space-y-4" onSubmit={run('save')}>
      {error ? <ErrorNotice error={error} fallback="Unable to save test order" /> : null}
      {form.formState.errors.root ? <ErrorNotice error={form.formState.errors.root.message} fallback="Unable to save test order" /> : null}

      <section className="mx-auto max-w-[210mm] overflow-hidden rounded-lg border border-emerald-900/15 bg-white text-slate-900 shadow-[0_1px_2px_rgb(15_23_42/0.05)]">
        <header className="grid grid-cols-[1fr_10rem] border-b border-emerald-900/15 text-sm">
          {editable
            ? <input className="h-16 min-w-0 border-0 px-4 text-center text-lg font-semibold outline-none" aria-label="实验室名称" {...form.register('address_lab_name')} />
            : <div className="flex h-16 items-center justify-center px-4 text-center text-lg font-semibold">{form.watch('address_lab_name')}</div>}
          <div className="border-l border-emerald-900/15 text-center text-xs text-slate-500">
            <div className="flex h-8 items-center justify-center border-b border-emerald-900/15">表单编号：FO-12-01</div>
            <div className="flex h-8 items-center justify-center">版本：v1.1</div>
          </div>
        </header>
        <h2 className="py-4 text-center text-2xl font-semibold tracking-[0.25em]">实验委托单</h2>

        <div className="border-t border-emerald-900/15">
          <div className="grid grid-cols-[7rem_1fr_7rem_1fr] border-b border-emerald-900/15">
            <CellLabel>委托日期</CellLabel>
            <Cell><TextCell form={form} editable={editable} name={'order_date'} type="date" /></Cell>
            <CellLabel>紧急程度</CellLabel>
            <Cell className="border-r-0">
              <SelectCell form={form} editable={editable} name={'urgency'} placeholder="" options={urgencyOptions} />
            </Cell>
          </div>
          <div className="grid grid-cols-[7rem_1fr_7rem_1fr] border-b border-emerald-900/15">
            <CellLabel>计划结束时间</CellLabel>
            <Cell><TextCell form={form} editable={editable} name={'planned_end_date'} type="date" /></Cell>
            <CellLabel>样品状态</CellLabel>
            <Cell className="border-r-0">
              <SelectCell form={form} editable={editable} name={'sample_status'} placeholder="" options={sampleStatusOptions} />
            </Cell>
          </div>
          <div className="grid grid-cols-[7rem_1fr_7rem_1fr]">
            <CellLabel>委托编号</CellLabel><Cell><span className="px-3">{order.order_no}</span></Cell>
            <CellLabel>合同编号</CellLabel><Cell className="border-r-0"><TextCell form={form} editable={editable} name={'contract_no'} /></Cell>
          </div>
        </div>

        <SectionTitle>委托单位信息</SectionTitle>
        <PartyRows form={form} editable={editable} prefix="client" title="委托单位*" />
        <PartyRows form={form} editable={editable} prefix="manufacturer" title="制造商" />
        <PartyRows form={form} editable={editable} prefix="maker" title="生产厂" />

        <SectionTitle>检测要求以及报告要求</SectionTitle>
        <div className="overflow-x-auto">
          <table className="w-full min-w-[38rem] border-collapse text-sm">
            <thead><tr className="bg-slate-50"><TableHead className="w-[60%]">标准号及版本</TableHead><TableHead>资质要求</TableHead><TableHead>报告语言</TableHead>{editable ? <TableHead className="w-12">操作</TableHead> : null}</tr></thead>
            <tbody>
              {standards.fields.map((row, index) => (
                <tr key={row.id}>
                  <TableCell><div className="grid grid-cols-2 gap-1"><TextCell form={form} editable={editable} name={`standards.${index}.standard_code`} placeholder="标准号" /><TextCell form={form} editable={editable} name={`standards.${index}.standard_name`} placeholder="标准名称" /></div></TableCell>
                  <TableCell><TextCell form={form} editable={editable} name={`standards.${index}.qualifications_text`} placeholder="CMA, CNAS" /></TableCell>
                  <TableCell><SelectCell form={form} editable={editable} name={`standards.${index}.report_language`} placeholder="" options={reportLanguageOptions} /></TableCell>
                  {editable ? <TableCell><button className="text-slate-500 hover:text-red-600 disabled:opacity-40" type="button" disabled={standards.fields.length === 1} onClick={() => standards.remove(index)} aria-label="移除标准"><Trash2 className="mx-auto size-4" /></button></TableCell> : null}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        {editable ? <button className="m-2 inline-flex items-center gap-1 text-sm text-emerald-700 hover:text-emerald-900" type="button" onClick={() => standards.append(emptyStandard())}><Plus className="size-4" />新增标准</button> : null}
        <div className="border-t border-emerald-900/15 text-sm">
          <div className="grid grid-cols-[7rem_1fr] border-b border-emerald-900/15">
            <CellLabel>报告形式</CellLabel>
            <Cell className="border-r-0">{editable
              ? <div className="flex flex-wrap gap-x-3 gap-y-1 px-3 py-2">{reportFormOptions.map((option) => <label className="inline-flex items-center gap-1" key={option}><input type="checkbox" value={option} {...form.register('report_forms')} />{reportFormLabel(option)}</label>)}</div>
              : <ReadOnly value={(form.watch('report_forms') ?? []).map(reportFormLabel).join('、')} />}</Cell>
          </div>
          <div className="grid grid-cols-[7rem_1fr_7rem_1fr] border-b border-emerald-900/15">
            <CellLabel>样品是否返还</CellLabel><Cell><SelectCell form={form} editable={editable} name={'sample_return'} options={sampleReturnOptions.map((option) => ({ value: option, label: option === 'return' ? '是' : '否（销毁处理）' }))} /></Cell>
            <CellLabel>报告提交</CellLabel><Cell className="border-r-0"><SelectCell form={form} editable={editable} name={'delivery_method'} options={reportSubmissionOptions.map((option) => ({ value: option, label: option === 'self_pick' ? '自取' : '邮寄' }))} /></Cell>
          </div>
          <div className="grid grid-cols-[7rem_1fr] border-b border-emerald-900/15">
            <CellLabel>准许检测分包</CellLabel><Cell className="border-r-0"><SelectCell form={form} editable={editable} name={'outsourcing_option'} options={outsourcingOptions.map((option) => ({ value: option, label: option === 'allowed' ? '允许' : '不允许' }))} /></Cell>
          </div>
          <div className="grid grid-cols-[7rem_1fr]">
            <CellLabel>备注</CellLabel><Cell className="border-r-0"><TextCell form={form} editable={editable} name={'remark'} /></Cell>
          </div>
        </div>

        <SectionTitle>*样品信息</SectionTitle>
        {samples.fields.map((row, index) => (
          <div className="border-b border-emerald-900/15" key={row.id}>
            <div className="grid grid-cols-[6rem_1fr_6rem_1fr_6rem_1fr] border-b border-emerald-900/15">
              <CellLabel>名称*</CellLabel><Cell><TextCell form={form} editable={editable} name={`samples.${index}.sample_name`} /></Cell>
              <CellLabel>额定电流</CellLabel><Cell><TextCell form={form} editable={editable} name={`samples.${index}.rated_current`} /></Cell>
              <CellLabel>状态</CellLabel><Cell className="border-r-0"><SelectCell form={form} editable={editable} name={`samples.${index}.sample_condition`} options={sampleConditionOptions.map((option) => ({ value: option, label: option === 'good' ? '完好' : '异常' }))} /></Cell>
            </div>
            <div className="grid grid-cols-[6rem_1fr_6rem_1fr_6rem_1fr] border-b border-emerald-900/15">
              <CellLabel>型号*</CellLabel><Cell><TextCell form={form} editable={editable} name={`samples.${index}.model`} /></Cell>
              <CellLabel>额定功率*</CellLabel><Cell><TextCell form={form} editable={editable} name={`samples.${index}.power`} /></Cell>
              <CellLabel>样品数量</CellLabel><Cell className="border-r-0">{editable
                ? <div className="flex"><input className={cellInput} type="number" min={1} {...form.register(`samples.${index}.quantity`, { valueAsNumber: true })} /><input className="w-12 border-l border-emerald-900/15 px-1 text-center text-sm outline-none focus:bg-emerald-50/70" {...form.register(`samples.${index}.quantity_unit`)} /></div>
                : <ReadOnly value={`${form.watch(`samples.${index}.quantity`) ?? ''} ${form.watch(`samples.${index}.quantity_unit`) ?? ''}`.trim()} />}</Cell>
            </div>
            <div className="grid grid-cols-[6rem_1fr_6rem_1fr_6rem_1fr] border-b border-emerald-900/15">
              <CellLabel>额定电压*</CellLabel><Cell><TextCell form={form} editable={editable} name={`samples.${index}.input_voltage`} /></Cell>
              <CellLabel>额定频率</CellLabel><Cell><TextCell form={form} editable={editable} name={`samples.${index}.rated_frequency`} /></Cell>
              <CellLabel>异常说明</CellLabel><Cell className="border-r-0"><TextCell form={form} editable={editable} name={`samples.${index}.sample_condition_note`} /></Cell>
            </div>
            <div className="grid grid-cols-[6rem_1fr]"><CellLabel>备注</CellLabel><Cell className="flex gap-2"><TextCell form={form} editable={editable} name={`samples.${index}.remark`} />{editable ? <button className="px-2 text-slate-500 hover:text-red-600 disabled:opacity-40" type="button" disabled={samples.fields.length === 1} onClick={() => samples.remove(index)} aria-label="移除样品"><Trash2 className="size-4" /></button> : null}</Cell></div>
          </div>
        ))}
        {editable ? <button className="m-2 inline-flex items-center gap-1 text-sm text-emerald-700 hover:text-emerald-900" type="button" onClick={() => samples.append(emptySample())}><Plus className="size-4" />新增样品</button> : null}

        <SectionTitle>*样品寄送地址</SectionTitle>
        <div className="grid grid-cols-[7rem_1fr] border-b border-emerald-900/15"><CellLabel>实验室名称</CellLabel><Cell className="border-r-0"><TextCell form={form} editable={editable} name={'address_lab_name'} /></Cell></div>
        <div className="grid grid-cols-[7rem_1fr] border-b border-emerald-900/15"><CellLabel>实验室地址</CellLabel><Cell className="border-r-0"><AreaCell form={form} editable={editable} name={'address_detail'} minHeight="min-h-16" /></Cell></div>
        <div className="grid grid-cols-[7rem_1fr] border-b border-emerald-900/15"><CellLabel>联系人</CellLabel><Cell className="border-r-0"><TextCell form={form} editable={editable} name={'address_contact'} /></Cell></div>
        <div className="grid grid-cols-[7rem_1fr] border-b border-emerald-900/15"><CellLabel>联系电话</CellLabel><Cell className="border-r-0"><TextCell form={form} editable={editable} name={'address_phone'} /></Cell></div>
        <div className="grid grid-cols-[7rem_1fr]"><CellLabel>特别说明</CellLabel><Cell className="border-r-0"><AreaCell form={form} editable={editable} name={'shipping_notes'} minHeight="min-h-20" /></Cell></div>

        <div className="border-t border-emerald-900/15 p-4"><div className="grid grid-cols-[1fr_15rem] items-center gap-4"><p className="text-base font-medium">委托单位声明：上述提供资料正确无误！</p><label className="text-sm">委托人（客户）签字{editable ? <input className="mt-1 w-full border-b border-emerald-900/15 px-2 py-1 outline-none" {...form.register('client_signature')} /> : <div className="mt-1 min-h-8 border-b border-emerald-900/15 px-2 py-1">{form.watch('client_signature')}</div>}</label></div><div className="mt-3 flex justify-end"><label className="text-sm">日期{editable ? <input type="date" className="ml-2 border-b border-emerald-900/15 px-2 py-1 outline-none" {...form.register('client_sign_date')} /> : <span className="ml-2 inline-block min-w-28 border-b border-emerald-900/15 px-2 py-1">{form.watch('client_sign_date')}</span>}</label></div></div>
        <div className="grid grid-cols-5 border-t border-emerald-900/15 text-center text-sm"><CellLabel>实验室资源满足*</CellLabel><CellLabel>综合部确认</CellLabel><Cell><TextCell form={form} editable={editable} name={'dept_confirm'} /></Cell><CellLabel>日期</CellLabel><Cell><TextCell form={form} editable={editable} name={'dept_confirm_date'} type="date" /></Cell><CellLabel>客户要求的评审*</CellLabel><CellLabel>检测部确认</CellLabel><Cell><TextCell form={form} editable={editable} name={'lab_confirm'} /></Cell><CellLabel>日期</CellLabel><Cell><TextCell form={form} editable={editable} name={'lab_confirm_date'} type="date" /></Cell></div>
      </section>

      {editable ? <div className="mx-auto flex max-w-[210mm] justify-end gap-2"><Button type="submit" variant="primary" disabled={submitting}><Save className="size-4" />保存</Button><Button type="button" variant="secondary" disabled={submitting} onClick={run('print')}><Printer className="size-4" />保存并重新打印</Button></div> : null}
    </form>
  )
}

function PartyRows({ form, editable, prefix, title }: { form: UseFormReturn<TestOrderFormValues>; editable: boolean; prefix: 'client' | 'manufacturer' | 'maker'; title: string }) {
  return <div className="border-t border-emerald-900/15 text-sm"><div className="grid grid-cols-[7rem_1fr_4rem_1fr_3rem_1fr] border-b border-emerald-900/15"><CellLabel>{title}</CellLabel><Cell><TextCell form={form} editable={editable} name={`${prefix}_company`} /></Cell><CellLabel>联系人</CellLabel><Cell><TextCell form={form} editable={editable} name={`${prefix}_contact`} /></Cell><CellLabel>电话</CellLabel><Cell className="border-r-0"><TextCell form={form} editable={editable} name={`${prefix}_phone`} /></Cell></div><div className="grid grid-cols-[7rem_1fr_3rem_1fr]"><CellLabel>地址</CellLabel><Cell><TextCell form={form} editable={editable} name={`${prefix}_address`} /></Cell><CellLabel>邮箱</CellLabel><Cell className="border-r-0"><TextCell form={form} editable={editable} name={`${prefix}_email`} type="email" /></Cell></div></div>
}


/**
 * A cell that stops being a control when nobody is editing.
 *
 * Disabled inputs made the detail page read as a form waiting to be filled in:
 * an untouched date showed 年/月/日 and every value sat in a box with an input's
 * affordance. Reading a commission order is the common case, so that case gets
 * plain text and the controls appear only once editing is entered.
 */
function ReadOnly({ value, className = '' }: { value: unknown; className?: string }) {
  const text = value === null || value === undefined || value === '' ? '' : String(value)

  return (
    <span className={`min-w-0 whitespace-pre-wrap break-words px-3 py-1.5 text-sm text-slate-800 ${className}`}>
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
}

function TextCell({ form, editable, name, type = 'text', placeholder }: CellProps) {
  if (!editable) {
    return <ReadOnly value={form.watch(name)} />
  }

  return <input className={cellInput} type={type} placeholder={placeholder} {...form.register(name)} />
}

function AreaCell({ form, editable, name, minHeight }: CellProps & { minHeight: string }) {
  if (!editable) {
    return <ReadOnly value={form.watch(name)} className="py-2" />
  }

  return <textarea className={`${minHeight} w-full resize-y px-3 py-2 text-sm outline-none`} {...form.register(name)} />
}

function SelectCell({
  form,
  editable,
  name,
  options,
  placeholder = '未设置',
}: CellProps & { options: Array<{ value: string; label: string }> }) {
  if (!editable) {
    const current = String(form.watch(name) ?? '')

    return <ReadOnly value={options.find((option) => option.value === current)?.label ?? ''} />
  }

  return (
    <select className={cellInput} {...form.register(name)}>
      {placeholder ? <option value="">{placeholder}</option> : null}
      {options.map((option) => (
        <option value={option.value} key={option.value}>{option.label}</option>
      ))}
    </select>
  )
}

function SectionTitle({ children }: { children: string }) { return <h3 className="border-y border-emerald-900/15 bg-slate-50 px-3 py-2 text-sm font-semibold text-slate-900">{children}</h3> }
function CellLabel({ children, className = '' }: { children: React.ReactNode; className?: string }) { return <div className={`flex min-h-10 items-center justify-center border-r border-emerald-900/15 bg-slate-50 px-2 text-center text-xs font-medium text-slate-600 ${className}`}>{children}</div> }
function Cell({ children, className = '' }: { children: React.ReactNode; className?: string }) { return <div className={`flex min-h-10 min-w-0 items-center border-r border-emerald-900/15 ${className}`}>{children}</div> }
function TableHead({ children, className = '' }: { children: React.ReactNode; className?: string }) { return <th className={`border border-emerald-900/15 px-2 py-2 text-center text-xs font-medium text-slate-600 ${className}`}>{children}</th> }
function TableCell({ children, className = '' }: { children: React.ReactNode; className?: string }) { return <td className={`border border-emerald-900/15 p-1 ${className}`}>{children}</td> }

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

const cellInput = 'h-9 min-w-0 w-full rounded-none border-0 bg-transparent px-3 text-sm text-slate-900 outline-none focus:bg-emerald-50/70 focus:ring-1 focus:ring-inset focus:ring-emerald-600/30'

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
