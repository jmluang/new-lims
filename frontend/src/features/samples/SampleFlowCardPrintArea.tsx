import { createPortal } from 'react-dom'
import { zhText } from '../../lib/zh'

export type SampleFlowCardData = {
  sample: SampleFlowLedgerSample
  flows: SampleFlowLedgerFlow[]
}

export type SampleFlowLedgerSample = {
    sample_no: string
    sample_name?: string | null
    specification?: string | null
    model?: string | null
    order_no?: string | null
    client_company?: string | null
    status?: string | null
    current_holder?: string | null
    current_location?: string | null
    received_date?: string | null
    storage_condition?: string | null
    batch_no?: string | null
}

export type SampleFlowLedgerFlow = {
    id: number
    action_type: string
    action_by?: number | null
    action_by_name?: string | null
    action_time?: string | null
    holder_from?: string | null
    holder_to?: string | null
    location_from?: string | null
    location_to?: string | null
    remark?: string | null
}

export function SampleFlowCardPrintStyles() {
  return (
    <style>{`
      @media print {
        @page {
          size: A4 landscape;
          margin: 8mm;
        }

        body > :not(.sample-flow-card-print-area) {
          display: none !important;
        }

        .sample-flow-card-screen-area {
          display: none !important;
        }

        .sample-flow-card-print-area {
          display: block !important;
          position: static !important;
          margin: 0 !important;
          padding: 8mm !important;
          background: white !important;
        }

        .sample-flow-card-print-area table {
          font-size: 10px !important;
        }
      }

      .sample-flow-card-print-area {
        display: none;
      }
    `}</style>
  )
}

export function SampleFlowCardPrintArea({ card, screenHidden = false }: { card: SampleFlowCardData; screenHidden?: boolean }) {
  const screenPreview = screenHidden ? null : <div className="sample-flow-card-screen-area">{renderCard(card)}</div>
  const printPortal = typeof document === 'undefined' ? null : createPortal(<div className="sample-flow-card-print-area">{renderCard(card)}</div>, document.body)

  return (
    <>
      {screenPreview}
      {printPortal}
    </>
  )
}

function renderCard(card: SampleFlowCardData) {
  const { sample, flows } = card

  return (
    <article className="space-y-4 text-slate-900">
      <header className="text-center">
        <h1 className="text-lg font-semibold">样品流转记录流水单</h1>
      </header>

      <SampleFlowLedgerTable flows={flows} sample={sample} />
    </article>
  )
}

export function SampleFlowLedgerTable({ sample, flows }: { sample: SampleFlowLedgerSample; flows: SampleFlowLedgerFlow[] }) {
  return (
    <div className="overflow-x-auto">
      <table className="w-full min-w-[980px] border-collapse text-left text-xs">
        <thead>
          <tr>
            {['客户名称', '样品名称', '样品型号', '样品编号', '样品状态', '时间', '流转类型', '原位置', '现位置', '原持有人', '持有人'].map((label) => (
              <th className="border border-slate-300 bg-slate-50 px-2 py-1.5 font-semibold text-slate-700" key={label}>
                {label}
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {flows.length === 0 ? <SampleFlowLedgerRow flow={null} sample={sample} /> : flows.map((flow) => <SampleFlowLedgerRow flow={flow} sample={sample} key={flow.id} />)}
        </tbody>
      </table>
    </div>
  )
}

function SampleFlowLedgerRow({ sample, flow }: { sample: SampleFlowLedgerSample; flow: SampleFlowLedgerFlow | null }) {
  return (
    <tr className="align-top">
      <LedgerCell value={sample.client_company} />
      <LedgerCell value={sample.sample_name} />
      <LedgerCell value={sample.model} />
      <LedgerCell value={sample.sample_no} />
      <LedgerCell value={sampleStatusText(sample.status)} />
      <LedgerCell value={flow?.action_time} />
      <LedgerCell value={flow?.action_type ? zhText(flow.action_type) : null} />
      <LedgerCell value={flow?.location_from} />
      <LedgerCell value={flow?.location_to} />
      <LedgerCell value={flow?.holder_from} />
      <LedgerCell value={flow?.holder_to} />
    </tr>
  )
}

function LedgerCell({ value }: { value?: string | number | null }) {
  return <td className="border border-slate-300 px-2 py-1.5">{value || '-'}</td>
}

function sampleStatusText(status?: string | null) {
  if (status === 'pending') {
    return '待检'
  }

  if (status === 'testing') {
    return '在检'
  }

  if (status === 'completed') {
    return '检毕'
  }

  return status ? zhText(status) : null
}
