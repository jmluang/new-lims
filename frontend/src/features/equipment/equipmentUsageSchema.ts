import { z } from 'zod'

export const equipmentUsageStartSchema = z.object({
  equipment_ids: z.array(z.number().int().positive()).min(1, '请选择设备'),
  sample_ids: z.array(z.number().int().positive()).min(1, '请选择样品'),
  start_time: z.string().optional(),
  remark: z.string().optional(),
})

export type EquipmentUsageStartValues = z.infer<typeof equipmentUsageStartSchema>

export function buildEquipmentUsageStartPayload(values: EquipmentUsageStartValues) {
  const parsed = equipmentUsageStartSchema.parse(values)

  return {
    ...parsed,
    remark: parsed.remark?.trim() ? parsed.remark.trim() : null,
  }
}

export function equipmentUsageStatus(endTime?: string | null) {
  return endTime ? 'finished' : 'using'
}
