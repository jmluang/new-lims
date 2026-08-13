import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useNavigate } from '@tanstack/react-router'
import { ArrowLeft, Plus, Save, Trash2 } from 'lucide-react'
import { useEffect, useRef, useState } from 'react'
import { api } from '../../lib/api'
import { zhText } from '../../lib/zh'
import { Button, ErrorNotice, Field, LoadingState, PageShell, Panel } from '../system/shared'
import { type ApiCollection, inputClass, localDateInputValue, textareaClass } from '../system/utils'
import type { TestOrder } from '../test-orders/TestOrderListPage'
import {
  acceptedReceiveRowCount,
  buildReceiveSamplesPayload,
  defaultReceiveLocation,
  expandExpectedReceiveRows,
  type ReceiveExpectedSampleOption,
  type ReceiveLocationOption,
  type ReceiveSampleRowValues,
} from './sampleSchema'
import { saveSampleLabelIds } from './sampleLabelPrintState'

type SampleOption = ReceiveExpectedSampleOption

type SampleOptionsResponse = {
  data: {
    order: {
      id: number
      order_no: string
      client_company: string
    }
    samples: SampleOption[]
  }
}

type ReceiveOptionsResponse = ApiCollection<TestOrder> & {
  meta?: ApiCollection<TestOrder>['meta'] & {
    locations?: ReceiveLocationOption[]
  }
}

const emptyRow: ReceiveSampleRowValues = {
  test_order_sample_id: null,
  sample_name: '',
  specification: '',
  model: '',
  input_voltage: '',
  rated_current: '',
  rated_frequency: '',
  power: '',
  appearance_check: '',
  remark: '',
  reject_reason: '',
}

export function SampleReceivePage() {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [testOrderId, setTestOrderId] = useState(0)
  const [receivedDate, setReceivedDate] = useState(localDateInputValue())
  const [storageCondition, setStorageCondition] = useState('常温')
  const [currentLocation, setCurrentLocation] = useState(defaultReceiveLocation([]))
  const [batchNo, setBatchNo] = useState('')
  const [rows, setRows] = useState<ReceiveSampleRowValues[]>([{ ...emptyRow }])
  const autoLoadedOrderId = useRef<number | null>(null)
  const ordersQuery = useQuery({
    queryKey: ['receive-test-orders'],
    queryFn: async () => {
      const response = await api.get<ReceiveOptionsResponse>('/api/samples/receive-options', { params: { limit: 100 } })

      return response.data
    },
  })
  const optionsQuery = useQuery({
    queryKey: ['receive-sample-options', testOrderId],
    enabled: testOrderId > 0,
    queryFn: async () => {
      const response = await api.get<SampleOptionsResponse>(`/api/test-orders/${testOrderId}/sample-options`)

      return response.data.data
    },
  })
  const receiveSamples = useMutation({
    mutationFn: async () => {
      const payload = buildReceiveSamplesPayload({
        test_order_id: testOrderId,
        received_date: receivedDate,
        storage_condition: storageCondition,
        current_location: currentLocation,
        batch_no: batchNo,
        samples: rows,
      })

      const response = await api.post<{ data: Array<{ id: number }> }>('/api/samples/receive', payload)

      return response.data.data
    },
    onSuccess: async (receivedSamples) => {
      await queryClient.invalidateQueries({ queryKey: ['samples'] })
      await queryClient.invalidateQueries({ queryKey: ['test-orders'] })
      saveSampleLabelIds(receivedSamples.map((sample) => sample.id))
      await navigate({ to: '/samples/labels' })
    },
  })

  useEffect(() => {
    if (!optionsQuery.data || optionsQuery.data.order.id !== testOrderId || autoLoadedOrderId.current === testOrderId) {
      return
    }

    setRows(expandExpectedReceiveRows(optionsQuery.data.samples))
    autoLoadedOrderId.current = testOrderId
  }, [optionsQuery.data, testOrderId])

  function selectOrder(value: string) {
    const id = Number(value)

    setTestOrderId(Number.isFinite(id) ? id : 0)
    autoLoadedOrderId.current = null
    setRows([{ ...emptyRow }])
  }

  function loadExpectedRows() {
    const optionRows = optionsQuery.data?.samples ?? []

    setRows(optionRows.length > 0 ? expandExpectedReceiveRows(optionRows) : [{ ...emptyRow }])
  }

  function updateRow(index: number, patch: Partial<ReceiveSampleRowValues>) {
    setRows((current) => current.map((row, rowIndex) => (rowIndex === index ? { ...row, ...patch } : row)))
  }

  const locationOptions = ordersQuery.data?.meta?.locations ?? []
  const currentLocationExists = locationOptions.some((location) => location.name === currentLocation)
  const acceptedCount = acceptedReceiveRowCount(rows)

  return (
    <PageShell
      title="Receive samples"
      description="Create physical sample records from one test order delivery."
      actions={
        <Link className="inline-flex h-9 items-center justify-center gap-2 rounded-md px-3 text-sm font-medium text-slate-600 hover:bg-slate-100" to="/samples">
          <ArrowLeft className="size-4" aria-hidden="true" />
          {zhText('Back to list')}
        </Link>
      }
    >
      {ordersQuery.isError ? <ErrorNotice error={ordersQuery.error} fallback="Unable to load test orders" /> : null}
      {optionsQuery.isError ? <ErrorNotice error={optionsQuery.error} fallback="Unable to load sample options" /> : null}
      {receiveSamples.error ? <ErrorNotice error={receiveSamples.error} fallback="Unable to receive samples" /> : null}
      {ordersQuery.isPending ? <LoadingState label="Loading test orders" /> : null}

      <Panel title="Delivery">
        <div className="grid gap-3 md:grid-cols-5">
          <Field label="Test order">
            <select className={inputClass} value={testOrderId || ''} onChange={(event) => selectOrder(event.target.value)}>
              <option value="">{zhText('Select order')}</option>
              {(ordersQuery.data?.data ?? []).map((order) => (
                <option value={order.id} key={order.id}>
                  {order.order_no} - {order.client_company}
                </option>
              ))}
            </select>
          </Field>
          <Field label="Received date">
            <input className={inputClass} type="date" value={receivedDate} onChange={(event) => setReceivedDate(event.target.value)} />
          </Field>
          <Field label="Current location">
            <select className={inputClass} value={currentLocation} onChange={(event) => setCurrentLocation(event.target.value)}>
              {!currentLocationExists ? <option value={currentLocation}>{currentLocation}</option> : null}
              {locationOptions.map((location) => (
                <option value={location.name} key={location.id}>
                  {location.label}
                </option>
              ))}
            </select>
          </Field>
          <Field label="Storage condition">
            <input className={inputClass} value={storageCondition} onChange={(event) => setStorageCondition(event.target.value)} />
          </Field>
          <Field label="Batch no">
            <input className={inputClass} value={batchNo} onChange={(event) => setBatchNo(event.target.value)} />
          </Field>
        </div>
        {optionsQuery.data ? (
          <div className="mt-3 flex flex-wrap items-center gap-2 text-sm text-slate-600">
            <span>
              {optionsQuery.data.order.order_no} / {optionsQuery.data.order.client_company}
            </span>
            <Button variant="secondary" onClick={loadExpectedRows}>
              按委托单数量重新加载
            </Button>
          </div>
        ) : null}
      </Panel>

      <Panel title="Received rows" description={`本次接收 ${acceptedCount} 个样品；填写拒收原因的行只进入审计，不占用样品编号。`}>
        <div className="space-y-3">
          {rows.map((row, index) => (
            <div className="rounded-md border border-emerald-900/10 bg-slate-50/60 p-3" key={index}>
              <div className="mb-3 flex items-center justify-between gap-2">
                <span className="text-sm font-medium text-slate-900">#{index + 1}</span>
                <Button variant="ghost" onClick={() => setRows((current) => current.filter((_, rowIndex) => rowIndex !== index))} disabled={rows.length === 1}>
                  <Trash2 className="size-4" aria-hidden="true" />
                  {zhText('Remove')}
                </Button>
              </div>
              <div className="grid gap-3 md:grid-cols-4">
                <Field label="Expected sample">
                  <select
                    className={inputClass}
                    value={row.test_order_sample_id ?? ''}
                    onChange={(event) => {
                      const option = optionsQuery.data?.samples.find((item) => String(item.id) === event.target.value)
                      updateRow(index, {
                        test_order_sample_id: option?.id ?? null,
                        sample_name: option?.sample_name ?? row.sample_name,
                        specification: option?.specification ?? row.specification,
                        model: option?.model ?? row.model,
                        input_voltage: option?.input_voltage ?? row.input_voltage,
                        rated_current: option?.rated_current ?? row.rated_current,
                        rated_frequency: option?.rated_frequency ?? row.rated_frequency,
                        power: option?.power ?? row.power,
                        remark: option?.remark ?? row.remark,
                      })
                    }}
                  >
                    <option value="">{zhText('Manual')}</option>
                    {(optionsQuery.data?.samples ?? []).map((sample) => (
                      <option value={sample.id} key={sample.id}>
                        {sample.sample_name}
                      </option>
                    ))}
                  </select>
                </Field>
                <Field label="Sample name">
                  <input className={inputClass} value={row.sample_name} onChange={(event) => updateRow(index, { sample_name: event.target.value })} />
                </Field>
                <Field label="Specification">
                  <input className={inputClass} value={row.specification ?? ''} onChange={(event) => updateRow(index, { specification: event.target.value })} />
                </Field>
                <Field label="Model">
                  <input className={inputClass} value={row.model ?? ''} onChange={(event) => updateRow(index, { model: event.target.value })} />
                </Field>
                <Field label="Input voltage">
                  <input className={inputClass} value={row.input_voltage ?? ''} onChange={(event) => updateRow(index, { input_voltage: event.target.value })} />
                </Field>
                <Field label="Rated current">
                  <input className={inputClass} value={row.rated_current ?? ''} onChange={(event) => updateRow(index, { rated_current: event.target.value })} />
                </Field>
                <Field label="Power">
                  <input className={inputClass} value={row.power ?? ''} onChange={(event) => updateRow(index, { power: event.target.value })} />
                </Field>
                <Field label="Rated frequency">
                  <input className={inputClass} value={row.rated_frequency ?? ''} onChange={(event) => updateRow(index, { rated_frequency: event.target.value })} />
                </Field>
                <Field label="Appearance check" className="md:col-span-2">
                  <textarea className={textareaClass} value={row.appearance_check ?? ''} onChange={(event) => updateRow(index, { appearance_check: event.target.value })} />
                </Field>
                <Field label="Sample remark" className="md:col-span-2">
                  <textarea className={textareaClass} value={row.remark ?? ''} onChange={(event) => updateRow(index, { remark: event.target.value })} />
                </Field>
                <Field label="Reject reason" className="md:col-span-2">
                  <textarea className={textareaClass} value={row.reject_reason ?? ''} onChange={(event) => updateRow(index, { reject_reason: event.target.value })} />
                </Field>
              </div>
            </div>
          ))}
          <Button variant="secondary" onClick={() => setRows((current) => [...current, { ...emptyRow }])}>
            <Plus className="size-4" aria-hidden="true" />
            Add row
          </Button>
        </div>
      </Panel>

      <div className="flex justify-end border-t border-slate-200 pt-4">
        <Button variant="primary" onClick={() => receiveSamples.mutate()} disabled={receiveSamples.isPending}>
          <Save className="size-4" aria-hidden="true" />
          Receive
        </Button>
      </div>
    </PageShell>
  )
}
