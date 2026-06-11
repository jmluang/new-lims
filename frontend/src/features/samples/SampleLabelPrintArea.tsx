import { QRCodeSVG } from 'qrcode.react'
import { createPortal } from 'react-dom'
import { sampleLabelSpec } from './sampleLabelSpec'

export type SampleLabelPreview = {
  sample_no: string
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
    <article className="sample-label flex flex-col items-center justify-center gap-3 border border-slate-300 bg-white p-3 text-center shadow-sm" key={label.sample_no}>
      <div className="max-w-full break-all text-sm font-semibold leading-tight text-slate-950">{label.sample_no}</div>
      <QRCodeSVG value={label.qr_text} size={104} />
    </article>
  ))
}
