import { z } from 'zod'

export const calibrationRowSchema = z.object({
  equipment_id: z.number().int().positive().optional(),
  equipment_no: z.string().optional(),
  equipment_name: z.string().optional(),
  calibration_date: z.string().optional(),
  remark: z.string().optional(),
})

export type CalibrationRowValues = z.infer<typeof calibrationRowSchema>

export const equipmentCalibrationSchema = z.object({
  calibration_project_id: z.number().int().positive().nullable().optional(),
  calibration_name: z.string().min(1, '请填写定标名称'),
  calibration_time: z.string().min(1, '请选择定标时间'),
  result: z.string().optional(),
  remark: z.string().optional(),
  attachment_files: z.array(z.string()).optional(),
  photo_files: z.array(z.string()).optional(),
  devices: z.array(calibrationRowSchema).optional(),
  standards: z.array(calibrationRowSchema).optional(),
})

export type EquipmentCalibrationValues = z.infer<typeof equipmentCalibrationSchema>

export class EquipmentCalibrationValidationError extends Error {
  constructor(message: string) {
    super(message)
    this.name = 'EquipmentCalibrationValidationError'
  }
}

function cleanRow(row: CalibrationRowValues): CalibrationRowValues {
  return Object.fromEntries(Object.entries(row).filter(([, value]) => value !== undefined && value !== '')) as CalibrationRowValues
}

export function buildEquipmentCalibrationPayload(values: unknown) {
  const parsed = equipmentCalibrationSchema.safeParse(values)

  if (!parsed.success) {
    throw new EquipmentCalibrationValidationError(parsed.error.issues[0]?.message ?? 'Invalid calibration payload')
  }

  const data = parsed.data

  return {
    calibration_project_id: data.calibration_project_id ?? null,
    calibration_name: data.calibration_name,
    calibration_time: data.calibration_time,
    result: data.result?.trim() ? data.result.trim() : 'qualified',
    remark: data.remark?.trim() ? data.remark.trim() : null,
    attachment_files: (data.attachment_files ?? []).filter((value) => value.trim() !== ''),
    photo_files: (data.photo_files ?? []).filter((value) => value.trim() !== ''),
    devices: (data.devices ?? []).map(cleanRow),
    standards: (data.standards ?? []).map(cleanRow),
  }
}

export const equipmentCalibrationResults = ['qualified', 'unqualified'] as const
