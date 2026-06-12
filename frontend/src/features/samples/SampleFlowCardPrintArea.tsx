import { createPortal } from 'react-dom'
import { zhText } from '../../lib/zh'

export type SampleFlowCardData = {
  sample: {
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
  flows: Array<{
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
  }>
}

export function SampleFlowCardPrintStyles() {
  return (
    <style>{`
      @media print {
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
          padding: 16mm !important;
          background: white !important;
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
    <article className="space-y-5 text-slate-900">
      <header className="text-center">
        <h1 className="text-lg font-semibold">{zhText('Sample flow card')}</h1>
        <p className="mt-1 text-sm text-slate-500">{sample.sample_no}</p>
      </header>

      <section>
        <h2 className="mb-2 border-b border-slate-300 pb-1 text-sm font-semibold">{zhText('Sample profile')}</h2>
        <div className="grid grid-cols-2 gap-x-6 gap-y-1 text-sm">
          <CardField label="Sample no" value={sample.sample_no} />
          <CardField label="Sample name" value={sample.sample_name} />
          <CardField label="Order no" value={sample.order_no} />
          <CardField label="Client company" value={sample.client_company} />
          <CardField label="Specification" value={sample.specification} />
          <CardField label="Model" value={sample.model} />
          <CardField label="Status" value={sample.status ? zhText(sample.status) : null} />
          <CardField label="Holder" value={sample.current_holder} />
          <CardField label="Location" value={sample.current_location} />
          <CardField label="Storage condition" value={sample.storage_condition} />
          <CardField label="Received date" value={sample.received_date} />
          <CardField label="Batch no" value={sample.batch_no} />
        </div>
      </section>

      <section>
        <h2 className="mb-2 border-b border-slate-300 pb-1 text-sm font-semibold">{zhText('Flow history')}</h2>
        <ol className="space-y-2 text-sm">
          {flows.map((flow) => (
            <li className="border-l-2 border-emerald-600 pl-3" key={flow.id}>
              <div className="flex flex-wrap items-baseline justify-between gap-2">
                <span className="font-medium">{zhText(flow.action_type)}</span>
                <span className="text-xs text-slate-500">{flow.action_time ?? '-'}</span>
              </div>
              <div className="text-slate-700">
                {(flow.holder_from ?? '-')} → {(flow.holder_to ?? '-')} · {(flow.location_from ?? '-')} → {(flow.location_to ?? '-')}
              </div>
              <div className="text-xs text-slate-500">
                {zhText('Operator')}: {flow.action_by_name ?? '-'}
                {flow.remark ? ` · ${flow.remark}` : ''}
              </div>
            </li>
          ))}
        </ol>
      </section>
    </article>
  )
}

function CardField({ label, value }: { label: string; value?: string | number | null }) {
  return (
    <div className="flex gap-2">
      <span className="text-slate-500">{zhText(label)}:</span>
      <span className="font-medium">{value || '-'}</span>
    </div>
  )
}
