import { z } from 'zod'

const optionalNumericString = (message: string) =>
  z.string().optional().refine((value) => {
    if (value === undefined || value.trim() === '') {
      return true
    }

    return Number.isFinite(Number(value))
  }, message)

export const tempHumiditySchema = z.object({
  location_site: z.string().min(1, '请填写放置场所'),
  location_room: z.string().min(1, '请填写放置房间'),
  equip_no: z.string().optional(),
  temperature: optionalNumericString('温度必须为数字'),
  humidity: optionalNumericString('湿度必须为数字'),
  record_person: z.string().min(1, '请填写记录人'),
  remark: z.string().optional(),
  record_time: z.string().optional(),
})

export type TempHumidityFormValues = z.infer<typeof tempHumiditySchema>
