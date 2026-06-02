import { z } from 'zod'

export const tempHumiditySchema = z.object({
  location_site: z.string().min(1, '请填写放置场所'),
  location_room: z.string().min(1, '请填写放置房间'),
  equip_no: z.string().optional(),
  temperature: z.string().optional(),
  humidity: z.string().optional(),
  record_person: z.string().min(1, '请填写记录人'),
  remark: z.string().optional(),
  record_time: z.string().optional(),
})

export type TempHumidityFormValues = z.infer<typeof tempHumiditySchema>
