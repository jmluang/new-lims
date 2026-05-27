import { useMutation } from '@tanstack/react-query'
import { Printer } from 'lucide-react'
import { useEffect, useState } from 'react'
import { QRCodeSVG } from 'qrcode.react'
import { api } from '../../lib/api'
import { Button, EmptyState, ErrorNotice, Field, LoadingState, PageShell, Panel } from '../system/shared'
import { inputClass } from '../system/utils'
import { equipmentLabelSpec } from './equipmentLabelSpec'

type LabelPreview = {
  equipment_no: string
  name: string
  qr_text: string
  footer: 'XPD_LIMS'
}

export function EquipmentLabelPrintPage() {
  const [ids, setIds] = useState(() => storedLabelIds().join(','))
  const preview = useMutation({
    mutationFn: async (equipmentIds: number[]) => {
      const response = await api.post<{ data: LabelPreview[]; meta: { label_width_mm: 40; label_height_mm: 60 } }>('/api/equipment-labels/preview', {
        equipment_ids: equipmentIds,
        label_width_mm: equipmentLabelSpec.widthMm,
        label_height_mm: equipmentLabelSpec.heightMm,
      })

      return response.data
    },
  })

  useEffect(() => {
    const storedIds = ids
      .split(',')
      .map((id) => Number(id.trim()))
      .filter((id) => Number.isInteger(id) && id > 0)

    if (storedIds.length > 0) {
      preview.mutate(storedIds)
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  function runPreview() {
    const equipmentIds = ids
      .split(',')
      .map((id) => Number(id.trim()))
      .filter((id) => Number.isInteger(id) && id > 0)

    if (equipmentIds.length > 0) {
      preview.mutate(equipmentIds)
    }
  }

  return (
    <PageShell
      title="Equipment Labels"
      description="40mm x 60mm equipment labels with QR code and XPD_LIMS footer."
      actions={
        <Button variant="primary" disabled={!preview.data?.data.length} onClick={() => window.print()}>
          <Printer className="size-4" aria-hidden="true" />
          Print
        </Button>
      }
    >
      <style>{`
        @page {
          size: ${equipmentLabelSpec.widthMm}mm ${equipmentLabelSpec.heightMm}mm;
          margin: 0;
        }

        @media print {
          body * {
            visibility: hidden;
          }

          .label-print-area,
          .label-print-area * {
            visibility: visible;
          }

          .label-print-area {
            position: absolute;
            inset: 0 auto auto 0;
          }

          .equipment-label {
            box-shadow: none !important;
          }
        }

        .equipment-label {
          width: ${equipmentLabelSpec.widthMm}mm;
          height: ${equipmentLabelSpec.heightMm}mm;
          page-break-after: always;
        }

        .equipment-label:last-child {
          page-break-after: avoid;
        }
      `}</style>

      <Panel title="Preview source">
        <div className="grid gap-3 md:grid-cols-[1fr_auto]">
          <Field label="Equipment IDs">
            <input className={inputClass} value={ids} onChange={(event) => setIds(event.target.value)} placeholder="1,2,3" />
          </Field>
          <div className="flex items-end">
            <Button variant="secondary" onClick={runPreview}>
              Preview
            </Button>
          </div>
        </div>
      </Panel>

      {preview.isPending ? <LoadingState label="Loading labels" /> : null}
      {preview.error ? <ErrorNotice error={preview.error} fallback="Unable to create label preview" /> : null}
      {!preview.isPending && !preview.data?.data.length ? (
        <EmptyState title="No labels" description="Select equipment in the ledger or enter IDs to render labels." />
      ) : null}

      {preview.data?.data.length ? (
        <div className="label-print-area flex flex-wrap gap-4">
          {preview.data.data.map((label) => (
            <article className="equipment-label flex flex-col items-center justify-between border border-slate-300 bg-white p-3 text-center shadow-sm" key={label.equipment_no}>
              <div>
                <div className="text-[10px] font-semibold text-slate-500">{label.equipment_no}</div>
                <div className="mt-1 max-h-10 overflow-hidden text-sm font-semibold leading-tight text-slate-950">{label.name}</div>
              </div>
              <QRCodeSVG value={label.qr_text} size={96} />
              <div className="text-[10px] font-semibold text-slate-600">{label.footer}</div>
            </article>
          ))}
        </div>
      ) : null}
    </PageShell>
  )
}

function storedLabelIds() {
  try {
    const value = JSON.parse(localStorage.getItem('new_lims_label_equipment_ids') || '[]') as unknown

    return Array.isArray(value) ? value.filter((id): id is number => Number.isInteger(id)) : []
  } catch {
    return []
  }
}
