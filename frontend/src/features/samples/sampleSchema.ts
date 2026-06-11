import { z } from 'zod'

export const sampleReceiveOrderPermissions = ['samples.receive'] as const

export const receiveSampleRowSchema = z.object({
  test_order_sample_id: z.number().nullable().optional(),
  sample_name: z.string().min(1, '请填写样品名称'),
  specification: z.string().optional(),
  model: z.string().optional(),
  appearance_check: z.string().optional(),
  reject_reason: z.string().optional(),
})

export const receiveSamplesSchema = z.object({
  test_order_id: z.number().min(1, '请选择委托单'),
  received_date: z.string().optional(),
  storage_condition: z.string().optional(),
  current_location: z.string().min(1, '请填写当前位置'),
  batch_no: z.string().optional(),
  samples: z.array(receiveSampleRowSchema).min(1, '至少需要一条接收明细'),
})

export const sampleFlowSchema = z.object({
  action_type: z.enum(['lend', 'transfer', 'return_room', 'send_out', 'receive_back', 'return_client', 'scrap', 'position_change']),
  holder_to: z.string().optional(),
  location_to: z.string().optional(),
  remark: z.string().optional(),
})

export type ReceiveSamplesValues = z.infer<typeof receiveSamplesSchema>
export type ReceiveSampleRowValues = z.infer<typeof receiveSampleRowSchema>
export type SampleFlowValues = z.infer<typeof sampleFlowSchema>
export type ReceiveLocationOption = {
  id: number
  label: string
  name: string
}

export class ReceiveSamplesValidationError extends Error {
  constructor(message: string) {
    super(message)
    this.name = 'ReceiveSamplesValidationError'
  }
}

export function buildReceiveSamplesPayload(values: unknown) {
  const parsed = receiveSamplesSchema.safeParse(values)

  if (!parsed.success) {
    throw new ReceiveSamplesValidationError(parsed.error.issues[0]?.message ?? 'Invalid receive payload')
  }

  return normalizeReceivePayload(parsed.data)
}

export function normalizeReceivePayload(values: ReceiveSamplesValues) {
  return cleanEmptyValues({
    ...values,
    samples: values.samples.map((row) => cleanEmptyValues(row)),
  })
}

export function acceptedReceiveRowCount(rows: ReceiveSampleRowValues[]) {
  return rows.filter((row) => !row.reject_reason?.trim()).length
}

export function defaultReceiveLocation(locations: ReceiveLocationOption[]) {
  return locations.find((location) => location.name === '样品室')?.name ?? '样品室'
}

function cleanEmptyValues<T extends Record<string, unknown>>(payload: T): T {
  return Object.fromEntries(
    Object.entries(payload).map(([key, value]) => {
      if (value === '') {
        return [key, null]
      }

      return [key, value]
    }),
  ) as T
}
