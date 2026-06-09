import { QRCodeSVG } from 'qrcode.react'
import { createPortal } from 'react-dom'
import { equipmentLabelSpec } from './equipmentLabelSpec'

export type LabelPreview = {
  equipment_no: string
  name: string
  qr_text: string
  footer: 'XPD_LIMS'
}

export function EquipmentLabelPrintStyles() {
  return (
    <style>{`
      @page {
        size: ${equipmentLabelSpec.widthMm}mm ${equipmentLabelSpec.heightMm}mm;
        margin: 0;
      }

      @media print {
        html,
        body {
          width: ${equipmentLabelSpec.widthMm}mm;
          margin: 0 !important;
          padding: 0 !important;
        }

        body > :not(.label-print-area) {
          display: none !important;
        }

        .label-screen-area {
          display: none !important;
        }

        .label-print-area {
          display: block !important;
          position: static !important;
          margin: 0 !important;
          padding: 0 !important;
        }

        .equipment-label {
          box-shadow: none !important;
        }
      }

      .label-print-area {
        display: none;
      }

      .equipment-label {
        width: ${equipmentLabelSpec.widthMm}mm;
        height: ${equipmentLabelSpec.heightMm}mm;
        page-break-after: always;
        break-after: page;
      }

      .equipment-label:last-child {
        page-break-after: avoid;
        break-after: auto;
      }
    `}</style>
  )
}

export function EquipmentLabelPrintArea({ labels, screenHidden = false }: { labels: LabelPreview[]; screenHidden?: boolean }) {
  if (labels.length === 0) {
    return null
  }

  const screenPreview = screenHidden ? null : <div className="label-screen-area flex flex-wrap gap-4">{renderLabels(labels)}</div>
  const printPortal = typeof document === 'undefined' ? null : createPortal(<div className="label-print-area">{renderLabels(labels)}</div>, document.body)

  return (
    <>
      {screenPreview}
      {printPortal}
    </>
  )
}

function renderLabels(labels: LabelPreview[]) {
  return labels.map((label) => (
    <article className="equipment-label flex flex-col items-center justify-between border border-slate-300 bg-white p-3 text-center shadow-sm" key={label.equipment_no}>
      <div>
        <div className="text-[10px] font-semibold text-slate-500">{label.equipment_no}</div>
        <div className="mt-1 max-h-10 overflow-hidden text-sm font-semibold leading-tight text-slate-950">{label.name}</div>
      </div>
      <QRCodeSVG value={label.qr_text} size={96} />
      <div className="text-[10px] font-semibold text-slate-600">{label.footer}</div>
    </article>
  ))
}
