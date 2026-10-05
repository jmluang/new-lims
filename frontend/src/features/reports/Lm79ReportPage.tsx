import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useLoaderData, useNavigate, useRouterState } from '@tanstack/react-router'
import { useEffect, useState } from 'react'
import { RefreshCw, Trash2 } from 'lucide-react'
import { PermissionGate } from '../../components/app/PermissionGate'
import { api } from '../../lib/api'
import { useEffectivePermissions } from '../auth/useCurrentUser'
import { confirmAndFinalizeSigningSource } from '../pdf/handwrittenApi'
import { Button, ErrorNotice, Field, LoadingState, PageShell, Panel } from '../system/shared'
import { errorMessage, inputClass, textareaClass } from '../system/utils'
import { attachmentTypes, generateReportNumber, parseMeasurementFile, previewReportPdf, reportFormData, saveReport, type EquipmentRow, type MeasurementImportResult, type MeasurementKind, type Report, type ReportData, type ReportNumberAllocation, type ReportOptions, type SampleOption } from './lm79Api'

import { ReportSectionTabs } from './ReportSectionTabs'
import { ReportEquipmentFields } from './ReportEquipmentFields'
import { ReportFileField } from './ReportFileField'
import { ReportSampleSelect } from './ReportSampleSelect'
import { reportSections, sectionForGroup, type ReportSectionId } from './reportSections'

const BASE = '/api/lm79-reports'

export function Lm79ReportPage() {
  const pathname = useRouterState({ select: state => state.location.pathname })
  const id = pathname.match(/^\/reports\/lm79\/(\d+)$/)?.[1]
  const numberAllocation = useLoaderData({ strict: false }) as ReportNumberAllocation | undefined
  const selectedSampleId = Number(new URLSearchParams(window.location.search).get('sample')) || null
  const options = useQuery({ queryKey: ['lm79-options'], queryFn: async () => (await api.get<{ data: ReportOptions }>(`${BASE}/form-options`)).data.data })
  const report = useQuery({ queryKey: ['lm79-report', id], enabled: Boolean(id), queryFn: async () => (await api.get<{ data: Report }>(`${BASE}/${id}`)).data.data })
  const selectedSample = useQuery({ queryKey: ['lm79-selected-sample', selectedSampleId], enabled: !id && Boolean(selectedSampleId), queryFn: async () => (await api.get<{ data: SampleOption[] }>(`${BASE}/sample-options`, { params: { sample_id: selectedSampleId } })).data.data[0] })
  return <PageShell title={id ? '编制 LM-79 报告' : '新建 LM-79 报告'} description="" actions={<Link className="text-sm text-emerald-800 underline" to="/reports/lm79">返回报告列表</Link>}>
    {options.isPending || (id && report.isPending) || (!id && selectedSampleId && selectedSample.isPending) ? <LoadingState label="正在加载报告表单" /> : null}
    {options.isError || report.isError || selectedSample.isError ? <ErrorNotice error={options.error ?? report.error ?? selectedSample.error} fallback="表单加载失败" /> : null}
    {options.data && (!id || report.data) && (!selectedSampleId || id || !selectedSample.isPending) ? <ReportEditor key={id ?? 'new'} options={options.data} initial={report.data} selectedSample={selectedSample.data} numberAllocation={!id ? numberAllocation : undefined} /> : null}
  </PageShell>
}

function ReportEditor({ options, initial, selectedSample, numberAllocation }: { options: ReportOptions; initial?: Report; selectedSample?: SampleOption; numberAllocation?: ReportNumberAllocation }) {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const permissions = useEffectivePermissions().data
  const canEdit = permissions?.resources.lm79_reports?.actions[initial ? 'update' : 'create'] ?? false
  const [saved, setSaved] = useState(initial)
  const [data, setData] = useState<ReportData>(initial?.data ?? selectedSample?.data ?? { values: options.defaults, standards: [], equipment: [] })
  const [sampleId, setSampleId] = useState(initial?.sample_id ?? selectedSample?.id ?? 0)
  const [sampleSearch, setSampleSearch] = useState('')
  const [sampleQuery, setSampleQuery] = useState('')
  const [chosenSample, setChosenSample] = useState<SampleOption | null>(selectedSample ?? null)
  useEffect(() => {
    const timeout = window.setTimeout(() => setSampleQuery(sampleSearch), 250)
    return () => window.clearTimeout(timeout)
  }, [sampleSearch])
  const [reportNumberOverride, setReportNumber] = useState<string | null>(initial?.report_number ?? null)
  const [retained, setRetained] = useState(initial?.media.map(m => m.id) ?? [])
  const [files, setFiles] = useState<Record<string, File[]>>({})
  const [fileKey, setFileKey] = useState(0)
  const [preview, setPreview] = useState<string | null>(null)
  const [notice, setNotice] = useState('')
  const [equipmentNotice, setEquipmentNotice] = useState('')
  const [imports, setImports] = useState<Partial<Record<MeasurementKind, MeasurementImportResult>>>({})
  const [importErrors, setImportErrors] = useState<Partial<Record<MeasurementKind, string>>>({})
  const [selectedSection, setSelectedSection] = useState<ReportSectionId>('basic')
  const locked = Boolean(saved?.locked || initial?.locked)
  const reportNumber = reportNumberOverride ?? numberAllocation?.report_number ?? ''
  const numberGeneration = useMutation({
    mutationFn: generateReportNumber,
    onSuccess: number => { setReportNumber(number); setPreview(null) },
  })
  const generateNumber = numberGeneration.mutate
  const generatingNumber = numberGeneration.isPending
  const samples = useQuery({ queryKey: ['lm79-samples', sampleQuery], enabled: !saved, queryFn: async () => (await api.get<{ data: SampleOption[] }>(`${BASE}/sample-options`, { params: { search: sampleQuery } })).data.data })
  useEffect(() => () => { if (preview) URL.revokeObjectURL(preview) }, [preview])

  function updateData(change: ReportData | ((current: ReportData) => ReportData)) { setPreview(null); setData(change) }
  function updateFiles(change: Record<string, File[]> | ((current: Record<string, File[]>) => Record<string, File[]>)) { setPreview(null); setFiles(change) }
  function updateRetained(change: number[] | ((current: number[]) => number[])) { setPreview(null); setRetained(change) }

  function value(name: string, text: string) {
    updateData(current => ({ ...current, values: { ...current.values, [name]: text } }))
  }
  async function persist() {
    if (locked || !canEdit) {
      if (!saved) throw new Error('没有保存报告的权限')
      return saved
    }
    if (!sampleId && !saved) throw new Error('请选择一个实际样品')
    if (!saved && !reportNumber.trim()) throw new Error('请重新生成或填写报告编号')
    if (Object.values(imports).some(result => result && result.selected_record === null)) throw new Error('请先选择原始文件中本次报告采用的检测记录')
    const row = await saveReport(saved?.id, reportFormData(sampleId, reportNumber, data, retained, files))
    setSaved(row); setReportNumber(row.report_number); updateData(row.data); setEquipmentNotice(''); updateRetained(row.media.map(m => m.id)); updateFiles({}); setFileKey(k => k + 1)
    await queryClient.invalidateQueries({ queryKey: ['lm79-reports'] })
    return row
  }
  const action = useMutation({ mutationFn: async (kind: 'save' | 'calculate' | 'preview' | 'sign') => {
    setNotice('')
    const row = await persist()
    if (kind === 'save') {
      setNotice('草稿已保存')
      if (!initial) await navigate({ to: '/reports/lm79/$reportId', params: { reportId: String(row.id) }, replace: true })
    } else if (kind === 'calculate') {
      const response = await api.post<{ data: { values: Record<string, string>; photometry_calculated: boolean; photometry_source: 'gos' | 'ies' | 'manual' } }>(`${BASE}/${row.id}/calculate`)
      updateData(current => ({ ...current, values: response.data.data.values }))
      setNotice(response.data.data.photometry_calculated ? `已按${response.data.data.photometry_source === 'gos' ? ' GOS 原始角度' : response.data.data.photometry_source === 'ies' ? ' IES ' : '手填配光数据'}计算，请核对并保存。` : '光效已计算，请核对并保存。')
    } else if (kind === 'preview') {
      const pdf = await previewReportPdf(row.id)
      setPreview(URL.createObjectURL(pdf))
    } else {
      const response = await api.post<{ data: { source_uuid: string; document_uuid: string; report_number: string } }>(`${BASE}/${row.id}/signing-source`)
      setSaved(current => current ? { ...current, locked: true, document_uuid: response.data.data.document_uuid } : current)
      await confirmAndFinalizeSigningSource({ sourceUuid: response.data.data.source_uuid, reportNumber: response.data.data.report_number })
      window.location.assign(`/pdf/handwritten-signing?document=${encodeURIComponent(response.data.data.document_uuid)}#plan`)
    }
  } })
  const equipmentLookup = useMutation({
    mutationFn: async (code: string) => (await api.get<{ data: EquipmentRow }>(`${BASE}/equipment-lookup`, { params: { code } })).data.data,
    onMutate: () => setEquipmentNotice(''),
    onSuccess: device => {
      if (data.equipment.some(row => row.equipment_id === device.equipment_id || (row.equipment_no && row.equipment_no === device.equipment_no))) {
        setEquipmentNotice('该设备已添加；如需更新为台账当前资料，请先移除旧记录再扫码。')
        return
      }
      updateData(current => ({ ...current, equipment: [...current.equipment, device] }))
      setEquipmentNotice(`已添加 ${device.equipment_no} · ${device.name}，保存草稿后生效。`)
    },
  })
  const measurementImport = useMutation({
    mutationFn: ({ kind, file, record }: { kind: MeasurementKind; file?: File; record?: number }) => parseMeasurementFile(kind, file, saved?.id, record),
    onMutate: ({ kind }) => setImportErrors(current => ({ ...current, [kind]: undefined })),
    onSuccess: result => {
      setImports(current => ({ ...current, [result.kind]: result }))
      updateData(current => {
        const records = { ...current.measurement_records }
        if (result.selected_record === null) delete records[result.kind]
        else records[result.kind] = result.selected_record
        return { ...current, values: { ...current.values, ...result.values }, measurement_records: records }
      })
    },
    onError: (error, { kind }) => setImportErrors(current => ({ ...current, [kind]: errorMessage(error, '文件解析失败，请核对原始文件') })),
  })
  const busy = action.isPending || equipmentLookup.isPending || generatingNumber || measurementImport.isPending
  function uploadField(type: typeof attachmentTypes[number], showLabel = true) {
    const kind = type.name === 'gos' || type.name === 'haas' ? type.name : undefined
    return <ReportFileField key={type.name} type={type} showLabel={showLabel} fileKey={fileKey} editable={!locked && !busy && canEdit} reportId={saved?.id}
      media={saved?.media.filter(media => media.collection === type.name && retained.includes(media.id)) ?? []} selected={files[type.name] ?? []}
      onSelect={uploads => {
        updateFiles(current => ({ ...current, [type.name]: uploads }))
        if (uploads.length && type.name !== 'photos') updateRetained(current => current.filter(id => !saved?.media.some(media => media.id === id && media.collection === type.name)))
        if (kind) {
          setImports(current => ({ ...current, [kind]: undefined })); setImportErrors(current => ({ ...current, [kind]: undefined }))
          updateData(current => { const records = { ...current.measurement_records }; delete records[kind]; return { ...current, measurement_records: records } })
          if (uploads[0]) measurementImport.mutate({ kind, file: uploads[0] })
        }
      }}
      parsing={kind && measurementImport.isPending && measurementImport.variables?.kind === kind}
      imported={kind ? imports[kind] : undefined} record={kind ? data.measurement_records?.[kind] : undefined} parseError={kind ? importErrors[kind] : undefined}
      onParse={kind ? record => measurementImport.mutate({ kind, file: files[kind]?.[0], record }) : undefined}
      onRemove={id => {
        updateRetained(current => current.filter(retainedId => retainedId !== id))
        if (kind) { setImports(current => ({ ...current, [kind]: undefined })); setImportErrors(current => ({ ...current, [kind]: undefined })); updateData(current => { const records = { ...current.measurement_records }; delete records[kind]; return { ...current, measurement_records: records } }) }
      }} />
  }
  function changeEquipment(index: number, field: keyof EquipmentRow, text: string) {
    updateData(current => ({ ...current, equipment: current.equipment.map((e, i) => i === index ? { ...e, [field]: text } : e) }))
  }
  return <div className="space-y-5">
    {action.isError ? <ErrorNotice error={action.error} fallback="报告操作失败" /> : null}
    {numberGeneration.isError || (numberAllocation?.error && reportNumber === '') ? <ErrorNotice error={numberGeneration.error ?? numberAllocation?.error} fallback="报告编号生成失败，请点击重新生成重试" /> : null}
    {notice ? <p role="status" className="rounded-md bg-emerald-50 px-4 py-3 text-sm text-emerald-900">{notice}</p> : null}
    {locked ? <p className="rounded-md bg-slate-100 px-4 py-3 text-sm">报告已交接签署，原始资料已冻结。{saved?.document_uuid ? <a className="ml-2 text-emerald-800 underline" href={`/pdf/handwritten-signing?document=${encodeURIComponent(saved.document_uuid)}#plan`}>进入签署文档</a> : null}</p> : null}
    <fieldset disabled={locked || busy || !canEdit} className="space-y-5 disabled:opacity-75">
      <Panel title="关联样品与报告编号"><div className="grid gap-4 md:grid-cols-2">
        <Field label="报告编号"><div className="flex flex-wrap items-center gap-2"><input aria-label="报告编号" className={`${inputClass} flex-1`} value={reportNumber} placeholder={generatingNumber ? '正在生成报告编号…' : '请输入报告编号'} onChange={e => { setPreview(null); setReportNumber(e.target.value) }} maxLength={128} />{!initial && !saved ? <Button variant="secondary" onClick={() => generateNumber()}><RefreshCw aria-hidden="true" className={`size-4 ${generatingNumber ? 'animate-spin' : ''}`} />重新生成</Button> : null}</div>{!saved ? <p className="mt-1 text-xs text-slate-500">格式：XPDYYYYMMDD-001，重新生成会使用新的流水号。</p> : null}</Field>
        {saved ? <Field label="实际样品"><p className="py-2 text-sm">{saved.sample_snapshot.sample_no} · {saved.sample_snapshot.sample_name}（固定 1 个）</p></Field> : <div className="space-y-2"><Field label="实际样品（已接收）"><ReportSampleSelect selected={chosenSample} options={samples.data ?? []} pending={sampleSearch !== sampleQuery || samples.isFetching} disabled={locked || busy || !canEdit} onSearch={setSampleSearch} onSelect={sample => { setChosenSample(sample); setSampleId(sample?.id ?? 0); if (sample) updateData(current => ({ ...current, standards: sample.data.standards, values: { ...current.values, ...Object.fromEntries(['product_name', 'model', 'rated_voltage', 'rated_power', 'applicant', 'applicant_address', 'manufacturer', 'manufacturer_address', 'test_date'].map(field => [field, sample.data.values[field] ?? ''])) } })) }} /></Field>{samples.isError ? <ErrorNotice error={samples.error} fallback="样品加载失败" /> : null}</div>}
      </div></Panel>
    </fieldset>
    <ReportSectionTabs selected={selectedSection} onChange={setSelectedSection} />
    {reportSections.map(section => <section key={section.id} role="tabpanel" id={`report-panel-${section.id}`} aria-labelledby={`report-tab-${section.id}`} hidden={selectedSection !== section.id} tabIndex={0} className="space-y-5 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600">
    <div className="space-y-5">
      {options.groups.filter(group => sectionForGroup(group.fields[0]?.name) === section.id).map((group, i) => <section key={group.title} id={`report-fields-${section.id}-${i}`} className="scroll-mt-24"><Panel title={group.title}>
        {attachmentTypes.filter(type => type.group === group.fields[0]?.name).map(type => <div key={type.name} className="mb-4 border-b border-slate-200 pb-4">{uploadField(type)}</div>)}
        <fieldset disabled={locked || busy || !canEdit} className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 disabled:opacity-75">
        {group.fields.map(field => <Field key={field.name} label={field.label} className={field.type === 'textarea' ? 'sm:col-span-2 lg:col-span-3' : undefined}>
          {field.type === 'textarea' ? <textarea className={textareaClass} rows={field.name === 'cri_r1_r15' ? 2 : 6} value={data.values[field.name] ?? ''} onChange={e => value(field.name, e.target.value)} /> : <input className={inputClass} type={field.type === 'date' ? 'date' : 'text'} inputMode={field.numeric ? 'decimal' : undefined} value={data.values[field.name] ?? ''} onChange={e => value(field.name, e.target.value)} />}
        </Field>)}
      </fieldset></Panel></section>)}
      {section.id === 'basic' ? <fieldset disabled={locked || busy || !canEdit}>
      <Panel title="引用标准"><div className="space-y-2">{data.standards.map((s, i) => <div key={i} className="flex items-center gap-2"><input className={`${inputClass} min-w-0 flex-1`} aria-label={`标准 ${i + 1}`} value={s} onChange={e => updateData(current => ({ ...current, standards: current.standards.map((v, j) => i === j ? e.target.value : v) }))} /><Button variant="ghost" className="size-11 shrink-0 px-0 text-slate-500 hover:bg-red-50 hover:text-red-600" title="移除此标准" aria-label={`移除标准 ${i + 1}`} onClick={() => updateData(current => ({ ...current, standards: current.standards.filter((_, j) => i !== j) }))}><Trash2 aria-hidden="true" className="size-4" /></Button></div>)}<Button variant="secondary" onClick={() => updateData(current => ({ ...current, standards: [...current.standards, ''] }))}>添加标准</Button></div></Panel>
      </fieldset> : null}
      {section.id === 'equipment' ? <fieldset disabled={locked || busy || !canEdit}>
      <ReportEquipmentFields
        devices={data.equipment} active={selectedSection === 'equipment'} editable={!locked && !busy && canEdit}
        pending={equipmentLookup.isPending} error={equipmentLookup.error} notice={equipmentNotice}
        onCode={code => equipmentLookup.mutate(code)}
        onRemove={index => { updateData(current => ({ ...current, equipment: current.equipment.filter((_, i) => i !== index) })); setEquipmentNotice(''); equipmentLookup.reset() }}
        onCalibrationChange={changeEquipment}
      />
      </fieldset> : null}
      {section.id === 'files' ? <>
      <section id="report-attachments" className="scroll-mt-24 space-y-4">
        <p className="text-xs text-slate-500">照片与附录合计 ≤16 MB，全部文件合计 ≤20 MB。</p>
        {attachmentTypes.filter(type => type.group === null).map(type => <Panel key={type.name} title={type.label}>{uploadField(type, false)}</Panel>)}
      </section>
      </> : null}
    </div>
    </section>)}
    <div className="sticky bottom-0 z-10 flex flex-wrap items-center justify-end gap-2 border-t border-slate-200 bg-white/95 py-3">
      {!locked ? <><PermissionGate resource="lm79_reports" action={saved ? 'update' : 'create'}><Button variant="primary" disabled={busy} onClick={() => action.mutate('save')}>保存草稿</Button></PermissionGate><PermissionGate resource="lm79_reports" action="update"><Button variant="secondary" disabled={busy || !saved} onClick={() => action.mutate('calculate')}>计算配光与光效</Button></PermissionGate><PermissionGate resource="lm79_reports" action="print"><Button variant="secondary" disabled={busy || !saved} onClick={() => action.mutate('preview')}>预览 PDF</Button><PermissionGate resource="pdf.workflow" action="create"><Button variant="secondary" disabled={busy || !saved} onClick={() => action.mutate('sign')}>提交签署</Button></PermissionGate></PermissionGate></> : <PermissionGate resource="pdf.workflow" action="create"><Button variant="secondary" disabled={busy} onClick={() => action.mutate('sign')}>继续定稿并进入签署</Button></PermissionGate>}
      {busy ? <span role="status" className="text-sm text-slate-600">正在处理，请稍候…</span> : null}
    </div>
    {preview ? <Panel title="报告 PDF 预览"><a className="mb-3 inline-block text-sm text-emerald-800 underline" href={preview} download={`${reportNumber}.pdf`}>下载完整 PDF</a><iframe title="LM-79 报告 PDF" src={preview} className="h-[75vh] w-full rounded-md border border-slate-200" /></Panel> : null}
  </div>
}
