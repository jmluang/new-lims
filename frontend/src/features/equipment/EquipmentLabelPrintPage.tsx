import { useMutation } from '@tanstack/react-query'
import { Printer } from 'lucide-react'
import { useEffect, useState } from 'react'
import { api } from '../../lib/api'
import { Button, EmptyState, ErrorNotice, Field, LoadingState, PageShell, Panel } from '../system/shared'
import { inputClass } from '../system/utils'
import { EquipmentLabelPrintArea, EquipmentLabelPrintStyles, type LabelPreview } from './EquipmentLabelPrintArea'
import { equipmentLabelSpec } from './equipmentLabelSpec'

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
      <EquipmentLabelPrintStyles />

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

      <EquipmentLabelPrintArea labels={preview.data?.data ?? []} />
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
