import { zodResolver } from '@hookform/resolvers/zod'
import { Plus, Printer, Save, Trash2 } from 'lucide-react'
import { useEffect } from 'react'
import { type UseFormReturn, useFieldArray, useForm } from 'react-hook-form'
import { ErrorNotice, Button } from '../system/shared'
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

      <section className="mx-auto max-w-[210mm] overflow-hidden border border-slate-500 bg-white text-slate-900 shadow-sm">
        <header className="grid grid-cols-[1fr_10rem] border-b border-slate-500 text-sm">
          <input className="h-16 min-w-0 border-0 px-4 text-center text-lg font-semibold outline-none" aria-label="实验室名称" disabled={!editable} {...form.register('address_lab_name')} />
          <div className="border-l border-slate-500 text-center text-xs">
            <div className="flex h-8 items-center justify-center border-b border-slate-500">表单编号：FO-12-01</div>
            <div className="flex h-8 items-center justify-center">版本：v1.1</div>
          </div>
        </header>
        <h2 className="py-4 text-center text-2xl font-semibold tracking-[0.25em]">实验委托单</h2>

        <div className="border-t border-slate-500">
          <div className="grid grid-cols-[7rem_1fr_7rem_1fr] border-b border-slate-500">
            <CellLabel>委托日期</CellLabel>
            <Cell><input type="date" className={cellInput} disabled={!editable} {...form.register('order_date')} /></Cell>
            <CellLabel>紧急程度</CellLabel>
            <Cell>
              <select className={cellInput} disabled={!editable} {...form.register('urgency')}>
                <option value="normal">常规</option><option value="urgent">加急</option><option value="critical">特急</option>
              </select>
            </Cell>
          </div>
          <div className="grid grid-cols-[7rem_1fr_7rem_1fr] border-b border-slate-500">
            <CellLabel>计划结束时间</CellLabel>
            <Cell><input type="date" className={cellInput} disabled={!editable} {...form.register('planned_end_date')} /></Cell>
            <CellLabel>样品状态</CellLabel>
            <Cell>
              <select className={cellInput} disabled={!editable} {...form.register('sample_status')}>
                <option value="not_received">未收样</option><option value="partially_received">部分收样</option><option value="received">已收样</option><option value="testing">检测中</option><option value="completed">已完成</option>
              </select>
            </Cell>
          </div>
          <div className="grid grid-cols-[7rem_1fr_7rem_1fr]">
            <CellLabel>委托编号</CellLabel><Cell><span className="px-3">{order.order_no}</span></Cell>
            <CellLabel>合同编号</CellLabel><Cell><input className={cellInput} disabled={!editable} {...form.register('contract_no')} /></Cell>
          </div>
        </div>

        <SectionTitle>委托单位信息</SectionTitle>
        <PartyRows form={form} editable={editable} prefix="client" title="委托单位*" />
        <PartyRows form={form} editable={editable} prefix="manufacturer" title="制造商" />
        <PartyRows form={form} editable={editable} prefix="maker" title="生产厂" />

        <SectionTitle>检测要求以及报告要求</SectionTitle>
        <div className="overflow-x-auto">
          <table className="w-full min-w-[38rem] border-collapse text-sm">
            <thead><tr className="bg-slate-100"><TableHead className="w-[60%]">标准号及版本</TableHead><TableHead>资质要求</TableHead><TableHead>报告语言</TableHead><TableHead className="w-12">操作</TableHead></tr></thead>
            <tbody>
              {standards.fields.map((row, index) => (
                <tr key={row.id}>
                  <TableCell><div className="grid grid-cols-2 gap-1"><input className={cellInput} placeholder="标准号" disabled={!editable} {...form.register(`standards.${index}.standard_code`)} /><input className={cellInput} placeholder="标准名称" disabled={!editable} {...form.register(`standards.${index}.standard_name`)} /></div></TableCell>
                  <TableCell><input className={cellInput} placeholder="CMA, CNAS" disabled={!editable} {...form.register(`standards.${index}.qualifications_text`)} /></TableCell>
                  <TableCell><select className={cellInput} disabled={!editable} {...form.register(`standards.${index}.report_language`)}><option value="zh">中文</option><option value="en">英文</option></select></TableCell>
                  <TableCell><button className="text-slate-500 hover:text-red-600 disabled:opacity-40" type="button" disabled={!editable || standards.fields.length === 1} onClick={() => standards.remove(index)} aria-label="移除标准"><Trash2 className="mx-auto size-4" /></button></TableCell>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        {editable ? <button className="m-2 inline-flex items-center gap-1 text-sm text-emerald-700 hover:text-emerald-900" type="button" onClick={() => standards.append(emptyStandard())}><Plus className="size-4" />新增标准</button> : null}
        <div className="grid grid-cols-2 border-t border-slate-500 text-sm">
          <CellLabel>报告形式</CellLabel>
          <Cell className="border-r-0"><div className="flex flex-wrap gap-x-3 gap-y-1 px-3 py-2">{reportFormOptions.map((option) => <label className="inline-flex items-center gap-1" key={option}><input type="checkbox" value={option} disabled={!editable} {...form.register('report_forms')} />{reportFormLabel(option)}</label>)}</div></Cell>
          <CellLabel>样品是否返还</CellLabel><Cell><select className={cellInput} disabled={!editable} {...form.register('sample_return')}><option value="">未设置</option>{sampleReturnOptions.map((option) => <option value={option} key={option}>{option === 'return' ? '是' : '否（销毁处理）'}</option>)}</select></Cell>
          <CellLabel>报告提交</CellLabel><Cell><select className={cellInput} disabled={!editable} {...form.register('delivery_method')}><option value="">未设置</option>{reportSubmissionOptions.map((option) => <option value={option} key={option}>{option === 'self_pick' ? '自取' : '邮寄'}</option>)}</select></Cell>
          <CellLabel>准许检测分包</CellLabel><Cell><select className={cellInput} disabled={!editable} {...form.register('outsourcing_option')}><option value="">未设置</option>{outsourcingOptions.map((option) => <option value={option} key={option}>{option === 'allowed' ? '允许' : '不允许'}</option>)}</select></Cell>
          <CellLabel>备注</CellLabel><Cell className="col-span-3"><input className={cellInput} disabled={!editable} {...form.register('remark')} /></Cell>
        </div>

        <SectionTitle>*样品信息</SectionTitle>
        {samples.fields.map((row, index) => (
          <div className="border-b border-slate-500" key={row.id}>
            <div className="grid grid-cols-[6rem_1fr_6rem_1fr_6rem_1fr]">
              <CellLabel>名称*</CellLabel><Cell><input className={cellInput} disabled={!editable} {...form.register(`samples.${index}.sample_name`)} /></Cell>
              <CellLabel>额定电流</CellLabel><Cell><input className={cellInput} disabled={!editable} {...form.register(`samples.${index}.rated_current`)} /></Cell>
              <CellLabel>状态</CellLabel><Cell><select className={cellInput} disabled={!editable} {...form.register(`samples.${index}.sample_condition`)}><option value="">未设置</option>{sampleConditionOptions.map((option) => <option key={option} value={option}>{option === 'good' ? '完好' : '异常'}</option>)}</select></Cell>
            </div>
            <div className="grid grid-cols-[6rem_1fr_6rem_1fr_6rem_1fr]">
              <CellLabel>型号*</CellLabel><Cell><input className={cellInput} disabled={!editable} {...form.register(`samples.${index}.model`)} /></Cell>
              <CellLabel>额定功率*</CellLabel><Cell><input className={cellInput} disabled={!editable} {...form.register(`samples.${index}.power`)} /></Cell>
              <CellLabel>样品数量</CellLabel><Cell><div className="flex"><input className={cellInput} type="number" min={1} disabled={!editable} {...form.register(`samples.${index}.quantity`, { valueAsNumber: true })} /><input className="w-12 border-l border-slate-300 px-1 text-center text-sm" disabled={!editable} {...form.register(`samples.${index}.quantity_unit`)} /></div></Cell>
            </div>
            <div className="grid grid-cols-[6rem_1fr_6rem_1fr_6rem_1fr]">
              <CellLabel>额定电压*</CellLabel><Cell><input className={cellInput} disabled={!editable} {...form.register(`samples.${index}.input_voltage`)} /></Cell>
              <CellLabel>额定频率</CellLabel><Cell><input className={cellInput} disabled={!editable} {...form.register(`samples.${index}.rated_frequency`)} /></Cell>
              <CellLabel>异常说明</CellLabel><Cell><input className={cellInput} disabled={!editable} {...form.register(`samples.${index}.sample_condition_note`)} /></Cell>
            </div>
            <div className="grid grid-cols-[6rem_1fr]"><CellLabel>备注</CellLabel><Cell className="flex gap-2"><input className={cellInput} disabled={!editable} {...form.register(`samples.${index}.remark`)} />{editable ? <button className="px-2 text-slate-500 hover:text-red-600 disabled:opacity-40" type="button" disabled={samples.fields.length === 1} onClick={() => samples.remove(index)} aria-label="移除样品"><Trash2 className="size-4" /></button> : null}</Cell></div>
          </div>
        ))}
        {editable ? <button className="m-2 inline-flex items-center gap-1 text-sm text-emerald-700 hover:text-emerald-900" type="button" onClick={() => samples.append(emptySample())}><Plus className="size-4" />新增样品</button> : null}

        <SectionTitle>*样品寄送地址</SectionTitle>
        <div className="grid grid-cols-[7rem_1fr] border-b border-slate-500"><CellLabel>实验室名称</CellLabel><Cell><input className={cellInput} disabled={!editable} {...form.register('address_lab_name')} /></Cell></div>
        <div className="grid grid-cols-[7rem_1fr] border-b border-slate-500"><CellLabel>实验室地址</CellLabel><Cell><textarea className="min-h-16 w-full resize-y px-3 py-2 text-sm outline-none" disabled={!editable} {...form.register('address_detail')} /></Cell></div>
        <div className="grid grid-cols-[7rem_1fr] border-b border-slate-500"><CellLabel>联系人</CellLabel><Cell><input className={cellInput} disabled={!editable} {...form.register('address_contact')} /></Cell></div>
        <div className="grid grid-cols-[7rem_1fr] border-b border-slate-500"><CellLabel>联系电话</CellLabel><Cell><input className={cellInput} disabled={!editable} {...form.register('address_phone')} /></Cell></div>
        <div className="grid grid-cols-[7rem_1fr]"><CellLabel>特别说明</CellLabel><Cell><textarea className="min-h-20 w-full resize-y px-3 py-2 text-sm outline-none" disabled={!editable} {...form.register('shipping_notes')} /></Cell></div>

        <div className="border-t border-slate-500 p-4"><div className="grid grid-cols-[1fr_15rem] items-center gap-4"><p className="text-base font-medium">委托单位声明：上述提供资料正确无误！</p><label className="text-sm">委托人（客户）签字<input className="mt-1 w-full border-b border-slate-500 px-2 py-1 outline-none" disabled={!editable} {...form.register('client_signature')} /></label></div><div className="mt-3 flex justify-end"><label className="text-sm">日期<input type="date" className="ml-2 border-b border-slate-500 px-2 py-1 outline-none" disabled={!editable} {...form.register('client_sign_date')} /></label></div></div>
        <div className="grid grid-cols-5 border-t border-slate-500 text-center text-sm"><CellLabel>实验室资源满足*</CellLabel><CellLabel>综合部确认</CellLabel><Cell><input className={cellInput} disabled={!editable} {...form.register('dept_confirm')} /></Cell><CellLabel>日期</CellLabel><Cell><input type="date" className={cellInput} disabled={!editable} {...form.register('dept_confirm_date')} /></Cell><CellLabel>客户要求的评审*</CellLabel><CellLabel>检测部确认</CellLabel><Cell><input className={cellInput} disabled={!editable} {...form.register('lab_confirm')} /></Cell><CellLabel>日期</CellLabel><Cell><input type="date" className={cellInput} disabled={!editable} {...form.register('lab_confirm_date')} /></Cell></div>
      </section>

      {editable ? <div className="mx-auto flex max-w-[210mm] justify-end gap-2"><Button type="submit" variant="primary" disabled={submitting}><Save className="size-4" />保存</Button><Button type="button" variant="secondary" disabled={submitting} onClick={run('print')}><Printer className="size-4" />保存并重新打印</Button></div> : null}
    </form>
  )
}

function PartyRows({ form, editable, prefix, title }: { form: UseFormReturn<TestOrderFormValues>; editable: boolean; prefix: 'client' | 'manufacturer' | 'maker'; title: string }) {
  return <div className="border-t border-slate-500 text-sm"><div className="grid grid-cols-[7rem_1fr_4rem_1fr_3rem_1fr]"><CellLabel>{title}</CellLabel><Cell><input className={cellInput} disabled={!editable} {...form.register(`${prefix}_company`)} /></Cell><CellLabel>联系人</CellLabel><Cell><input className={cellInput} disabled={!editable} {...form.register(`${prefix}_contact`)} /></Cell><CellLabel>电话</CellLabel><Cell><input className={cellInput} disabled={!editable} {...form.register(`${prefix}_phone`)} /></Cell></div><div className="grid grid-cols-[7rem_1fr_3rem_1fr]"><CellLabel>地址</CellLabel><Cell><input className={cellInput} disabled={!editable} {...form.register(`${prefix}_address`)} /></Cell><CellLabel>邮箱</CellLabel><Cell><input className={cellInput} type="email" disabled={!editable} {...form.register(`${prefix}_email`)} /></Cell></div></div>
}

function SectionTitle({ children }: { children: string }) { return <h3 className="border-y border-slate-500 bg-slate-100 px-3 py-2 text-sm font-semibold">{children}</h3> }
function CellLabel({ children, className = '' }: { children: React.ReactNode; className?: string }) { return <div className={`flex min-h-10 items-center justify-center border-r border-slate-500 bg-slate-100 px-2 text-center text-xs font-medium ${className}`}>{children}</div> }
function Cell({ children, className = '' }: { children: React.ReactNode; className?: string }) { return <div className={`flex min-h-10 min-w-0 items-center border-r border-slate-500 ${className}`}>{children}</div> }
function TableHead({ children, className = '' }: { children: React.ReactNode; className?: string }) { return <th className={`border border-slate-500 px-2 py-2 text-center text-xs font-medium ${className}`}>{children}</th> }
function TableCell({ children, className = '' }: { children: React.ReactNode; className?: string }) { return <td className={`border border-slate-500 p-1 ${className}`}>{children}</td> }

const cellInput = 'h-9 min-w-0 w-full border-0 bg-transparent px-3 text-sm outline-none focus:bg-emerald-50 disabled:cursor-default disabled:text-slate-700'

function reportFormLabel(value: string) { return ({ formal_report: '正式报告', simple_report: '简版报告', electronic_report: '电子档', paper_report: '纸本', english_report: '英文报告' } as Record<string, string>)[value] ?? value }
function emptyStandard() { return { standard_id: null, standard_code: '', standard_name: '', report_language: 'zh', qualifications_text: '', requirement: '' } }
function emptySample() { return { sample_name: '', specification: '', model: '', input_voltage: '', rated_current: '', power: '', rated_frequency: '', status: 'pending' as const, quantity: 1, quantity_unit: '个', sample_condition: 'good' as const, sample_condition_note: '', detail_content: '', remark: '' } }
