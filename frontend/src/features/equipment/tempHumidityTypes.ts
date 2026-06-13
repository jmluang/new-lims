export type TempHumidityEquipmentLookup = {
  id: number
  equipment_no: string
  name: string
  model?: string | null
  status: string
  calibration_date?: string | null
  next_calibration_date?: string | null
  location_site?: string | null
  location_room?: string | null
}
