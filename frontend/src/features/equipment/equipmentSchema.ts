import { z } from 'zod'

export const equipmentSchema = z.object({
  equipment_no: z.string().min(1, '请填写设备编号'),
  name: z.string().min(1, '请填写名称'),
  manufacturer: z.string().optional(),
  model: z.string().optional(),
  serial_no: z.string().optional(),
  location_id: z.string().optional(),
  purchase_date: z.string().optional(),
  enable_date: z.string().optional(),
  calibration_date: z.string().optional(),
  calibration_duration: z.string().optional(),
  next_calibration_date: z.string().optional(),
  status: z.enum(['active', 'disabled', 'maintenance', 'retired']),
  device_image: z.string().optional(),
  manual_files: z.string().optional(),
  instruction_files: z.string().optional(),
  calibration_files: z.string().optional(),
  other_files: z.string().optional(),
  remark: z.string().optional(),
})

export type EquipmentFormValues = z.infer<typeof equipmentSchema>
