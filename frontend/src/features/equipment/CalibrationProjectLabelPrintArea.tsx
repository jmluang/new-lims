import { QRCodeSVG } from 'qrcode.react'
import { createPortal } from 'react-dom'
import { calibrationProjectLabelSpec } from './calibrationProjectLabelSpec'

export type CalibrationProjectLabelPreview = {
  project_no: string
  project_name: string
  qr_text: string
  footer: 'XPD_LIMS'
}

export function CalibrationProjectLabelPrintStyles() {
  return (
    <style>{`
      @page {
        size: ${calibrationProjectLabelSpec.widthMm}mm ${calibrationProjectLabelSpec.heightMm}mm;
        margin: 0;
      }

      @media print {
        html,
        body {
          width: ${calibrationProjectLabelSpec.widthMm}mm;
          margin: 0 !important;
          padding: 0 !important;
        }

        body > :not(.calibration-project-label-print-area) {
          display: none !important;
        }

        .calibration-project-label-screen-area {
          display: none !important;
        }

        .calibration-project-label-print-area {
          display: block !important;
          position: static !important;
          margin: 0 !important;
          padding: 0 !important;
        }

        .calibration-project-label {
          box-shadow: none !important;
        }
      }

      .calibration-project-label-print-area {
        display: none;
      }

      .calibration-project-label {
        width: ${calibrationProjectLabelSpec.widthMm}mm;
        height: ${calibrationProjectLabelSpec.heightMm}mm;
        page-break-after: always;
        break-after: page;
      }

      .calibration-project-label:last-child {
        page-break-after: avoid;
        break-after: auto;
      }
    `}</style>
  )
}

export function CalibrationProjectLabelPrintArea({ labels, screenHidden = false }: { labels: CalibrationProjectLabelPreview[]; screenHidden?: boolean }) {
  if (labels.length === 0) {
    return null
  }

  const screenPreview = screenHidden ? null : <div className="calibration-project-label-screen-area flex flex-wrap gap-4">{renderLabels(labels)}</div>
  const printPortal = typeof document === 'undefined' ? null : createPortal(<div className="calibration-project-label-print-area">{renderLabels(labels)}</div>, document.body)

  return (
    <>
      {screenPreview}
      {printPortal}
    </>
  )
}

function renderLabels(labels: CalibrationProjectLabelPreview[]) {
  return labels.map((label) => (
    <article className="calibration-project-label flex flex-col items-center justify-between border border-slate-300 bg-white p-3 text-center shadow-sm" key={label.project_no}>
      <div>
        <div className="text-[10px] font-semibold text-slate-500">{label.project_no}</div>
        <div className="mt-1 max-h-10 overflow-hidden text-sm font-semibold leading-tight text-slate-950">{label.project_name}</div>
      </div>
      <QRCodeSVG value={label.qr_text} size={96} />
      <div className="text-[10px] font-semibold text-slate-600">{label.footer}</div>
    </article>
  ))
}
