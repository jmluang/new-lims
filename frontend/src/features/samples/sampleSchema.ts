import { z } from 'zod'

export const receiveSampleRowSchema = z.object({
  test_order_sample_id: z.number().nullable().optional(),
  sample_name: z.string().min(1, 'Sample name is required'),
  specification: z.string().optional(),
  model: z.string().optional(),
  appearance_check: z.string().optional(),
  reject_reason: z.string().optional(),
})

export const receiveSamplesSchema = z.object({
  test_order_id: z.number().min(1, 'Test order is required'),
  received_date: z.string().optional(),
  storage_condition: z.string().optional(),
  current_location: z.string().min(1, 'Current location is required'),
  batch_no: z.string().optional(),
  samples: z.array(receiveSampleRowSchema).min(1, 'At least one sample row is required'),
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

export function normalizeReceivePayload(values: ReceiveSamplesValues) {
  return cleanEmptyValues({
    ...values,
    samples: values.samples.map((row) => cleanEmptyValues(row)),
  })
}

export function acceptedReceiveRowCount(rows: ReceiveSampleRowValues[]) {
  return rows.filter((row) => !row.reject_reason?.trim()).length
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
