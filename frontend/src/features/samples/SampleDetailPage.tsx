import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useRouterState } from '@tanstack/react-router'
import { ArrowLeft, Plus, Printer } from 'lucide-react'
import { useState } from 'react'
import { PermissionGate } from '../../components/app/PermissionGate'
import { api } from '../../lib/api'
import { zhText } from '../../lib/zh'
import { useEffectivePermissions } from '../auth/useCurrentUser'
import { Button, ErrorNotice, Field, LoadingState, PageShell, Panel, StatusBadge } from '../system/shared'
import { type ApiCollection, type ApiResource, inputClass, textareaClass } from '../system/utils'
import { SampleFlowCardPrintArea, SampleFlowCardPrintStyles, SampleFlowLedgerTable, type SampleFlowCardData } from './SampleFlowCardPrintArea'
import { visibleSampleFlowActions } from './sampleFlowPermissions'
import { sampleFlowSchema, type SampleFlowValues } from './sampleSchema'
import type { Sample } from './SampleListPage'

type SampleFlow = {
  id: number
  sample_id: number
  action_type: string
  action_by?: number | null
  action_time?: string | null
  holder_from?: string | null
  holder_to?: string | null
  location_from?: string | null
  location_to?: string | null
  remark?: string | null
}

const emptyFlow: SampleFlowValues = {
  action_type: 'lend',
  holder_to: '',
  location_to: '',
  remark: '',
}

const sampleFlowActions: SampleFlowValues['action_type'][] = ['lend', 'transfer', 'return_room', 'send_out', 'receive_back', 'return_client', 'scrap', 'position_change']

export function SampleDetailPage() {
  const pathname = useRouterState({ select: (state) => state.location.pathname })
  const sampleId = sampleIdFromPath(pathname)
  const queryClient = useQueryClient()
  const permissions = useEffectivePermissions()
  const [flowForm, setFlowForm] = useState<SampleFlowValues>(emptyFlow)
  const [validationError, setValidationError] = useState<string | null>(null)
  const sampleQuery = useQuery({
    queryKey: ['sample', sampleId],
    queryFn: async () => {
      const response = await api.get<ApiResource<Sample>>(`/api/samples/${sampleId}`)

      return response.data.data
    },
  })
  const flowsQuery = useQuery({
    queryKey: ['sample-flows', sampleId],
    queryFn: async () => {
      const response = await api.get<ApiCollection<SampleFlow>>(`/api/samples/${sampleId}/flows`)

      return response.data.data
    },
  })
  const flowCardQuery = useQuery({
    queryKey: ['sample-flow-card', sampleId],
    queryFn: async () => {
      const response = await api.get<ApiResource<SampleFlowCardData>>(`/api/samples/${sampleId}/flow-card`)

      return response.data.data
    },
    enabled: sampleId !== null,
  })
  const createFlow = useMutation({
    mutationFn: async () => {
      const parsed = sampleFlowSchema.safeParse(flowForm)

      if (!parsed.success) {
        setValidationError(parsed.error.issues[0]?.message ?? 'Invalid flow payload')
        return
      }

      setValidationError(null)
      await api.post(`/api/samples/${sampleId}/flows`, parsed.data)
    },
    onSuccess: async () => {
      setFlowForm(emptyFlow)
      await queryClient.invalidateQueries({ queryKey: ['sample', sampleId] })
      await queryClient.invalidateQueries({ queryKey: ['sample-flows', sampleId] })
      await queryClient.invalidateQueries({ queryKey: ['sample-flow-card', sampleId] })
      await queryClient.invalidateQueries({ queryKey: ['samples'] })
    },
  })
  const sample = sampleQuery.data
  const flows = flowsQuery.data ?? []
  const visibleFlowActions = visibleSampleFlowActions(sampleFlowActions, permissions.data)
  const printFlowCardAction = flowCardQuery.data ? (
    <PermissionGate resource="sample_flows" action="read">
      <Button variant="secondary" onClick={() => window.print()}>
        <Printer className="size-4" aria-hidden="true" />
        {zhText('Print flow card')}
      </Button>
    </PermissionGate>
  ) : null

  return (
    <PageShell
      title="Sample detail"
      description="Inspect physical sample state and append flow records."
      actions={
        <Link className="inline-flex h-9 items-center justify-center gap-2 rounded-md px-3 text-sm font-medium text-slate-600 hover:bg-slate-100" to="/samples">
          <ArrowLeft className="size-4" aria-hidden="true" />
          {zhText('Back to list')}
        </Link>
      }
    >
      {sampleQuery.isError ? <ErrorNotice error={sampleQuery.error} fallback="Unable to load sample" /> : null}
      {flowsQuery.isError ? <ErrorNotice error={flowsQuery.error} fallback="Unable to load sample flows" /> : null}
      {createFlow.error ? <ErrorNotice error={createFlow.error} fallback="Unable to save sample flow" /> : null}
      {validationError ? <ErrorNotice error={validationError} fallback={validationError} /> : null}
      {sampleQuery.isPending ? <LoadingState label="Loading sample" /> : null}
      {flowCardQuery.data ? (
        <>
          <SampleFlowCardPrintStyles />
          <SampleFlowCardPrintArea card={flowCardQuery.data} screenHidden />
        </>
      ) : null}
      {sample ? (
        <div className="space-y-4">
          <Panel title="Sample profile">
            <div className="grid gap-3 text-sm md:grid-cols-4">
              <Detail label="Sample no" value={sample.sample_no} />
              <Detail label="Sample name" value={sample.sample_name} />
              <Detail label="Order no" value={sample.order_no} />
              <Detail label="Client company" value={sample.client_company} />
              <Detail label="Specification" value={sample.specification} />
              <Detail label="Model" value={sample.model} />
              <Detail label="Quantity" value={sample.quantity} />
              <div>
                <div className="text-xs font-medium uppercase text-slate-500">{zhText('Status')}</div>
                <div className="mt-1">
                  <StatusBadge status={sample.status} />
                </div>
              </div>
              <Detail label="Holder" value={sample.current_holder} />
              <Detail label="Location" value={sample.current_location} />
              <Detail label="Storage condition" value={sample.storage_condition} />
              <Detail label="Received date" value={sample.received_date} />
              <Detail label="Batch no" value={sample.batch_no} />
              <Detail label="Appearance check" value={sample.appearance_check} />
            </div>
          </Panel>

          <PermissionGate resource="sample_flows" action="create">
            <Panel title="New flow">
              <div className="grid gap-3 md:grid-cols-4">
                <Field label="Action type">
                  <select className={inputClass} value={flowForm.action_type} onChange={(event) => setFlowForm({ ...flowForm, action_type: event.target.value as SampleFlowValues['action_type'] })}>
                    {visibleFlowActions.map((action) => (
                      <option value={action} key={action}>
                        {zhText(action)}
                      </option>
                    ))}
                  </select>
                </Field>
                <Field label="Holder to">
                  <input className={inputClass} value={flowForm.holder_to ?? ''} onChange={(event) => setFlowForm({ ...flowForm, holder_to: event.target.value })} />
                </Field>
                <Field label="Location to">
                  <input className={inputClass} value={flowForm.location_to ?? ''} onChange={(event) => setFlowForm({ ...flowForm, location_to: event.target.value })} />
                </Field>
                <Field label="Remark">
                  <textarea className={textareaClass} value={flowForm.remark ?? ''} onChange={(event) => setFlowForm({ ...flowForm, remark: event.target.value })} />
                </Field>
              </div>
              <div className="mt-3 flex justify-end">
                <Button variant="primary" onClick={() => createFlow.mutate()} disabled={createFlow.isPending}>
                  <Plus className="size-4" aria-hidden="true" />
                  Add flow
                </Button>
              </div>
            </Panel>
          </PermissionGate>

          <Panel title="Flow records" actions={printFlowCardAction}>
            {flowsQuery.isPending ? <LoadingState label="Loading sample flows" /> : null}
            {sample ? <SampleFlowLedgerTable flows={flows} sample={sample} /> : null}
          </Panel>
        </div>
      ) : null}
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

function sampleIdFromPath(pathname: string) {
  const match = pathname.match(/^\/samples\/(\d+)$/)

  return match ? Number(match[1]) : null
}
