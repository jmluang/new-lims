import { useMutation } from '@tanstack/react-query'
import { CheckCircle2, Loader2, Plus, Send, Trash2 } from 'lucide-react'
import { useState } from 'react'
import { api } from '../../lib/api'
import { Button, ErrorNotice, Field } from '../system/shared'
import { inputClass } from '../system/utils'

type SampleRow = {
  sample_name: string
  specification: string
  model: string
  input_voltage: string
  power: string
}

type SubmissionResult = {
  id: number
  submission_no: string
  client_company: string
  samples_count: number
  status: string
}

const emptySample: SampleRow = {
  sample_name: '',
  specification: '',
  model: '',
  input_voltage: '',
  power: '',
}

const compactInputClass = inputClass.replace('h-9', 'h-8').replace('text-sm', 'text-sm')

export function PublicTestOrderSubmissionPage() {
  const [clientCompany, setClientCompany] = useState('')
  const [clientAddress, setClientAddress] = useState('')
  const [clientPhone, setClientPhone] = useState('')
  const [clientContact, setClientContact] = useState('')
  const [samples, setSamples] = useState<SampleRow[]>([{ ...emptySample }])
  const [result, setResult] = useState<SubmissionResult | null>(null)

  const submit = useMutation({
    mutationFn: async () => {
      const response = await api.post<{ data: SubmissionResult }>('/api/public/test-order-submissions', {
        client_company: clientCompany,
        client_address: clientAddress,
        client_contact: clientContact,
        client_phone: clientPhone,
        samples: samples.map((sample) => ({ ...sample })),
      })

      return response.data.data
    },
    onSuccess: (data) => setResult(data),
  })

  function updateSample(index: number, patch: Partial<SampleRow>) {
    setSamples((current) => current.map((row, rowIndex) => (rowIndex === index ? { ...row, ...patch } : row)))
  }

  if (result) {
    return (
      <main className="min-h-screen bg-gradient-to-b from-emerald-50 via-white to-white px-4 py-8 text-slate-900 sm:px-6 lg:px-8">
        <section className="mx-auto max-w-xl rounded-2xl border border-emerald-100 bg-white p-6 text-center shadow-sm sm:p-8">
          <div className="mx-auto flex size-14 items-center justify-center rounded-full bg-emerald-100 text-emerald-700">
            <CheckCircle2 className="size-8" aria-hidden="true" />
          </div>
          <h1 className="mt-4 text-2xl font-semibold">资料提交成功</h1>
          <p className="mt-2 text-sm leading-6 text-slate-600">我们已收到您的委托资料，工作人员会尽快处理。</p>
          <div className="mt-5 rounded-xl bg-slate-50 p-4 text-left text-sm">
            <div className="flex justify-between gap-4">
              <span className="text-slate-500">提交编号</span>
              <span className="font-medium text-slate-900">{result.submission_no}</span>
            </div>
            <div className="mt-2 flex justify-between gap-4">
              <span className="text-slate-500">委托方</span>
              <span className="font-medium text-slate-900">{result.client_company}</span>
            </div>
            <div className="mt-2 flex justify-between gap-4">
              <span className="text-slate-500">样品数量</span>
              <span className="font-medium text-slate-900">{result.samples_count}</span>
            </div>
          </div>
          <Button className="mt-6 w-full" variant="primary" onClick={() => window.location.reload()}>
            继续填写
          </Button>
        </section>
      </main>
    )
  }

  return (
    <main className="min-h-screen bg-gradient-to-b from-emerald-50 via-white to-white px-3 py-3 text-slate-900 sm:px-6 sm:py-5 lg:px-8">
      <form
        className="mx-auto max-w-3xl space-y-3"
        onSubmit={(event) => {
          event.preventDefault()
          submit.mutate()
        }}
      >
        <header className="rounded-xl bg-emerald-700 px-4 py-4 text-white shadow-sm sm:px-6 sm:py-5">
          <p className="text-[11px] font-medium uppercase tracking-[0.18em] text-emerald-100">LIMS 委托资料</p>
          <h1 className="mt-1 text-xl font-semibold sm:text-2xl">客户资料填写</h1>
        </header>

        {submit.error ? <ErrorNotice error={submit.error} fallback="资料提交失败" /> : null}

        <section className="relative rounded-xl border border-emerald-100 bg-white p-3 shadow-sm sm:p-4">
          <div className="grid grid-cols-1 gap-2 sm:gap-3">
            <Field label="联系电话">
              <input className={compactInputClass} inputMode="tel" value={clientPhone} onChange={(event) => setClientPhone(event.target.value)} placeholder="请优先填写" required />
            </Field>
            <Field label="联系人">
              <input className={compactInputClass} value={clientContact} onChange={(event) => setClientContact(event.target.value)} placeholder="联系人姓名" />
            </Field>
            <Field label="公司名称">
              <input className={compactInputClass} value={clientCompany} onChange={(event) => setClientCompany(event.target.value)} required />
            </Field>
            <Field label="公司地址">
              <input className={compactInputClass} value={clientAddress} onChange={(event) => setClientAddress(event.target.value)} />
            </Field>
          </div>
        </section>

        <section className="rounded-xl border border-emerald-100 bg-white p-3 shadow-sm sm:p-4">
          <div className="mb-3 flex items-center justify-between gap-3">
            <div>
              <h2 className="text-base font-semibold">样品信息</h2>
            </div>
            <Button className="h-8 px-2.5" variant="secondary" onClick={() => setSamples((current) => (current.length >= 20 ? current : [...current, { ...emptySample }]))} disabled={samples.length >= 20}>
              <Plus className="size-4" aria-hidden="true" />
              添加
            </Button>
          </div>

          <div className="space-y-2.5">
            {samples.map((sample, index) => (
              <article className="rounded-lg border border-slate-200 bg-slate-50/70 p-3" key={index}>
                <div className="mb-2 flex items-center justify-between gap-3">
                  <h3 className="text-sm font-semibold text-slate-900">样品 #{index + 1}</h3>
                  <Button className="h-8 px-2.5" variant="ghost" onClick={() => setSamples((current) => current.filter((_, rowIndex) => rowIndex !== index))} disabled={samples.length === 1}>
                    <Trash2 className="size-4" aria-hidden="true" />
                    删除
                  </Button>
                </div>
                <div className="grid grid-cols-2 gap-2 sm:grid-cols-5 sm:gap-3">
                  <Field label="样品名称" className="col-span-2 sm:col-span-1">
                    <input className={compactInputClass} value={sample.sample_name} onChange={(event) => updateSample(index, { sample_name: event.target.value })} required />
                  </Field>
                  <Field label="规格">
                    <input className={compactInputClass} value={sample.specification} onChange={(event) => updateSample(index, { specification: event.target.value })} />
                  </Field>
                  <Field label="型号">
                    <input className={compactInputClass} value={sample.model} onChange={(event) => updateSample(index, { model: event.target.value })} />
                  </Field>
                  <Field label="输入电压">
                    <input className={compactInputClass} value={sample.input_voltage} onChange={(event) => updateSample(index, { input_voltage: event.target.value })} placeholder="如 220V" />
                  </Field>
                  <Field label="功率">
                    <input className={compactInputClass} value={sample.power} onChange={(event) => updateSample(index, { power: event.target.value })} placeholder="如 60W" />
                  </Field>
                </div>
              </article>
            ))}
          </div>
        </section>

        <div className="pt-1">
          <Button className="h-10 w-full text-base sm:w-auto sm:px-6" variant="primary" type="submit" disabled={submit.isPending}>
            {submit.isPending ? <Loader2 className="size-4 animate-spin" aria-hidden="true" /> : <Send className="size-4" aria-hidden="true" />}
            提交资料
          </Button>
        </div>
      </form>
    </main>
  )
}
