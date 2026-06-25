import { z } from 'zod'

export const sampleScanActions = ['lend', 'transfer', 'return_room', 'receive_back'] as const

export type SampleScanAction = (typeof sampleScanActions)[number]

export const sampleScanFlowSchema = z.object({
  action_type: z.enum(sampleScanActions),
  holder_to: z.string().optional(),
  location_to: z.string().min(1, '请选择位置名称'),
  remark: z.string().optional(),
})

export type SampleScanFlowValues = z.infer<typeof sampleScanFlowSchema>

export type SampleScanActionDefaultValues = {
  action_type: SampleScanAction
  holder_to: string
  location_to: string
  remark: string
}

export class SampleScanFlowValidationError extends Error {
  constructor(message: string) {
    super(message)
    this.name = 'SampleScanFlowValidationError'
  }
}

/**
 * Actions that move a sample to a named person require a holder; room returns do not.
 */
export function sampleScanActionRequiresHolder(action: SampleScanAction): boolean {
  return action === 'lend' || action === 'transfer'
}

export function sampleScanActionDefaults(
  action: SampleScanAction,
  currentLocation?: string | null,
  currentUserName?: string | null,
): SampleScanActionDefaultValues {
  return {
    action_type: action,
    holder_to: sampleScanActionRequiresHolder(action) ? (currentUserName ?? '') : '',
    location_to: currentLocation ?? '',
    remark: '',
  }
}

export function buildSampleScanFlowPayload(values: unknown): Record<string, string> {
  const parsed = sampleScanFlowSchema.safeParse(values)

  if (!parsed.success) {
    throw new SampleScanFlowValidationError(parsed.error.issues[0]?.message ?? 'Invalid scan flow payload')
  }

  const payload: Record<string, string> = {
    action_type: parsed.data.action_type,
    location_to: parsed.data.location_to,
  }

  if (!sampleScanActionRequiresHolder(parsed.data.action_type) && (parsed.data.holder_to ?? '').trim() !== '') {
    payload.holder_to = parsed.data.holder_to!.trim()
  }

  if ((parsed.data.remark ?? '').trim() !== '') {
    payload.remark = parsed.data.remark!.trim()
  }

  return payload
}
