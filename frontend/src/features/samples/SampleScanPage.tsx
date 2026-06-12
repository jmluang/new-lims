import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Check } from 'lucide-react'
import { useState } from 'react'
import { QrScannerPanel } from '../../components/app/QrScannerPanel'
import { api, isUnauthorizedError } from '../../lib/api'
import { zhText } from '../../lib/zh'
import { Button, ErrorNotice, Field, PageShell, Panel, StatusBadge } from '../system/shared'
import { type ApiResource, inputClass, textareaClass } from '../system/utils'
import {
  buildSampleScanFlowPayload,
  sampleScanActionRequiresHolder,
  SampleScanFlowValidationError,
  type SampleScanAction,
} from './sampleScanSchema'

type ScanSample = {
  id: number
  sample_no: string
  sample_name?: string | null
  specification?: string | null
  model?: string | null
  status: string
  current_holder?: string | null
  current_location?: string | null
}

type ScanLookup = {
  sample: ScanSample
  available_actions: SampleScanAction[]
}

const emptyForm = {
  action_type: null as SampleScanAction | null,
  holder_to: '',
  location_to: '',
  remark: '',
}

function isNotFoundError(error: unknown): boolean {
  return typeof error === 'object' && error !== null && 'response' in error && (error as { response?: { status?: number } }).response?.status === 404
}

export function SampleScanPage() {
  const queryClient = useQueryClient()
  const [lookup, setLookup] = useState<ScanLookup | null>(null)
  const [form, setForm] = useState(emptyForm)
  const [validationError, setValidationError] = useState<string | null>(null)
  const [done, setDone] = useState<string | null>(null)

  const lookupMutation = useMutation({
    mutationFn: async (sampleNo: string) => {
      const response = await api.get<ApiResource<ScanLookup>>('/api/samples/scan-lookup', { params: { sample_no: sampleNo } })

      return response.data.data
    },
    onSuccess: (data) => {
      setLookup(data)
      setForm(emptyForm)
      setValidationError(null)
      setDone(null)
    },
  })

  const flowMutation = useMutation({
    mutationFn: async () => {
      if (!lookup || !form.action_type) {
        throw new SampleScanFlowValidationError('请先选择流转动作')
      }

      const payload = buildSampleScanFlowPayload(form)
      await api.post(`/api/samples/${lookup.sample.id}/scan-flow`, payload)
    },
    onSuccess: async () => {
      if (lookup) {
        setDone(lookup.sample.sample_no)
        await queryClient.invalidateQueries({ queryKey: ['samples'] })
        await queryClient.invalidateQueries({ queryKey: ['sample-flows', lookup.sample.id] })
        await queryClient.invalidateQueries({ queryKey: ['sample-flow-card', lookup.sample.id] })
      }

      setLookup(null)
      setForm(emptyForm)
    },
    onError: (error) => {
      if (error instanceof SampleScanFlowValidationError) {
        setValidationError(error.message)
      }
    },
  })

  function handleDetected(sampleNo: string) {
    setValidationError(null)
    lookupMutation.mutate(sampleNo)
  }

  function selectAction(action: SampleScanAction) {
    setForm({ ...emptyForm, action_type: action, location_to: lookup?.sample.current_location ?? '' })
    setValidationError(null)
  }

  const sample = lookup?.sample

  return (
    <PageShell title="Scan sample flow" description="Scan or type a sample number, then record an allowed flow action.">
      <div className="space-y-4">
        <QrScannerPanel title="Scan sample flow" placeholder={zhText('sample no') ?? '样品编号'} onDetected={handleDetected} />

        {lookupMutation.isError ? (
          isNotFoundError(lookupMutation.error) ? (
            <ErrorNotice error="未找到样品" fallback="未找到样品" />
          ) : isUnauthorizedError(lookupMutation.error) ? null : (
            <ErrorNotice error={lookupMutation.error} fallback="样品查询失败" />
          )
        ) : null}
        {flowMutation.error && !(flowMutation.error instanceof SampleScanFlowValidationError) ? (
          <ErrorNotice error={flowMutation.error} fallback="流转操作失败" />
        ) : null}
        {validationError ? <ErrorNotice error={validationError} fallback={validationError} /> : null}
        {done ? <div className="rounded-md border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800">{`${zhText('Saved')}: ${done}`}</div> : null}

        {sample ? (
          <Panel title="Sample profile">
            <div className="grid gap-3 text-sm md:grid-cols-4">
              <Detail label="Sample no" value={sample.sample_no} />
              <Detail label="Sample name" value={sample.sample_name} />
              <Detail label="Model" value={sample.model} />
              <div>
                <div className="text-xs font-medium uppercase text-slate-500">{zhText('Status')}</div>
                <div className="mt-1">
                  <StatusBadge status={sample.status} />
                </div>
              </div>
              <Detail label="Holder" value={sample.current_holder} />
              <Detail label="Location" value={sample.current_location} />
            </div>

            <div className="mt-4">
              <div className="text-xs font-medium uppercase text-slate-500">{zhText('Action type')}</div>
              {lookup.available_actions.length === 0 ? (
                <p className="mt-2 text-sm text-slate-500">{zhText('No actions available for current state')}</p>
              ) : (
                <div className="mt-2 flex flex-wrap gap-2">
                  {lookup.available_actions.map((action) => (
                    <Button key={action} variant={form.action_type === action ? 'primary' : 'secondary'} onClick={() => selectAction(action)}>
                      {zhText(action)}
                    </Button>
                  ))}
                </div>
              )}
            </div>

            {form.action_type ? (
              <div className="mt-4 grid gap-3 md:grid-cols-3">
                <Field label="Location to">
                  <input className={inputClass} value={form.location_to} onChange={(event) => setForm({ ...form, location_to: event.target.value })} />
                </Field>
                {sampleScanActionRequiresHolder(form.action_type) ? (
                  <Field label="Holder to">
                    <input className={inputClass} value={form.holder_to} onChange={(event) => setForm({ ...form, holder_to: event.target.value })} />
                  </Field>
                ) : null}
                <Field label="Remark">
                  <textarea className={textareaClass} value={form.remark} onChange={(event) => setForm({ ...form, remark: event.target.value })} />
                </Field>
                <div className="md:col-span-3 flex justify-end">
                  <Button variant="primary" onClick={() => flowMutation.mutate()} disabled={flowMutation.isPending}>
                    <Check className="size-4" aria-hidden="true" />
                    {zhText('Confirm')}
                  </Button>
                </div>
              </div>
            ) : null}
          </Panel>
        ) : null}
      </div>
    </PageShell>
  )
}

function Detail({ label, value }: { label: string; value?: string | number | null }) {
  return (
    <div>
      <div className="text-xs font-medium uppercase text-slate-500">{zhText(label)}</div>
      <div className="mt-1 text-slate-900">{value || '-'}</div>
    </div>
  )
}
