import { QRCodeSVG } from 'qrcode.react'
import { createPortal } from 'react-dom'
import { sampleLabelSpec } from './sampleLabelSpec'

export type SampleLabelPreview = {
  client_company?: string | null
  sample_name?: string | null
  model?: string | null
  sample_no: string
  status?: string | null
  qr_text: string
}

export function SampleLabelPrintStyles() {
  return (
    <style>{`
      @page {
        size: ${sampleLabelSpec.widthMm}mm ${sampleLabelSpec.heightMm}mm;
        margin: 0;
      }

      @media print {
        html,
        body {
          width: ${sampleLabelSpec.widthMm}mm;
          margin: 0 !important;
          padding: 0 !important;
        }

        body > :not(.sample-label-print-area) {
          display: none !important;
        }

        .sample-label-screen-area {
          display: none !important;
        }

        .sample-label-print-area {
          display: block !important;
          position: static !important;
          margin: 0 !important;
          padding: 0 !important;
        }

        .sample-label {
          box-shadow: none !important;
        }
      }

      .sample-label-print-area {
        display: none;
      }

      .sample-label {
        width: ${sampleLabelSpec.widthMm}mm;
        height: ${sampleLabelSpec.heightMm}mm;
        page-break-after: always;
        break-after: page;
      }

      .sample-label:last-child {
        page-break-after: avoid;
        break-after: auto;
      }
    `}</style>
  )
}

export function SampleLabelPrintArea({ labels, screenHidden = false }: { labels: SampleLabelPreview[]; screenHidden?: boolean }) {
  if (labels.length === 0) {
    return null
  }

  const screenPreview = screenHidden ? null : <div className="sample-label-screen-area flex flex-wrap gap-4">{renderLabels(labels)}</div>
  const printPortal = typeof document === 'undefined' ? null : createPortal(<div className="sample-label-print-area">{renderLabels(labels)}</div>, document.body)

  return (
    <>
      {screenPreview}
      {printPortal}
    </>
  )
}

function renderLabels(labels: SampleLabelPreview[]) {
  return labels.map((label) => (
    <article
      className="sample-label flex flex-col items-center justify-center border border-slate-300 bg-white px-[2.5mm] py-[2mm] text-center text-slate-950 shadow-sm"
      key={label.sample_no}
    >
      <div className="flex max-w-full flex-col items-center gap-[1.13mm] text-[9px] leading-tight">
        <div className="max-w-full truncate">{label.client_company || '-'}</div>
        <div className="max-w-full truncate">{label.sample_name || '-'}</div>
        <div className="max-w-full truncate">{label.model || '-'}</div>
      </div>

      <div className="sample-label-qr my-[1mm] flex shrink-0 items-center justify-center">
        <QRCodeSVG value={label.qr_text} size={92} />
      </div>

      <div className="max-w-full break-all text-[9px] leading-tight">{label.sample_no}</div>
      <div className="mt-[0.8mm] flex max-w-full items-center justify-center gap-[1.6mm] whitespace-nowrap text-[9px] leading-none">
        {sampleStatusChecks(label.status).map((item) => (
          <span key={item.label}>{item.checked ? '☑' : '□'}{item.label}</span>
        ))}
      </div>
      <div className="mt-[1mm] max-w-full truncate text-[9px] leading-none">中山市鑫普达检测有限公司</div>
    </article>
  ))
}

function sampleStatusChecks(status?: string | null) {
  return [
    { label: '待检', checked: status === 'pending' },
    { label: '在检', checked: status === 'testing' },
    { label: '检毕', checked: status === 'completed' },
  ]
}
