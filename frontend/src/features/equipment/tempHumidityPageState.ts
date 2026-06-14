import { paginationParams } from '../system/utils'
import type { TempHumidityFormValues } from './tempHumiditySchema'
import type { TempHumidityEquipmentLookup } from './tempHumidityTypes'

export type TempHumidityFilters = {
  search: string
  record_time_from: string
  record_time_to: string
  temperature_min: string
  temperature_max: string
  humidity_min: string
  humidity_max: string
}

export const emptyTempHumidityFilters: TempHumidityFilters = {
  search: '',
  record_time_from: '',
  record_time_to: '',
  temperature_min: '',
  temperature_max: '',
  humidity_min: '',
  humidity_max: '',
}

export function buildTempHumidityListParams(filters: TempHumidityFilters, page: number, perPage: number) {
  return cleanParams({ ...filters, ...paginationParams(page, perPage) })
}

export function applyDetectedEquipmentCode(values: TempHumidityFormValues, equipNo: string): TempHumidityFormValues {
  return {
    ...values,
    equip_no: equipNo,
  }
}

export function applyLookupEquipment(
  values: TempHumidityFormValues,
  lookupEquipment: TempHumidityEquipmentLookup | null,
  isEditing: boolean,
): TempHumidityFormValues {
  if (!lookupEquipment || isEditing) {
    return values
  }

  return {
    ...values,
    equip_no: lookupEquipment.equipment_no,
    location_site: lookupEquipment.location_site ?? values.location_site,
    location_room: lookupEquipment.location_room ?? values.location_room,
  }
}

export function equipmentLookupErrorText(error: unknown) {
  const apiError = error as { response?: { status?: number } }

  if (apiError.response?.status === 404) {
    return '未找到设备'
  }

  return null
}

export function randomReadingDefault(min: number, max: number, random = Math.random) {
  const ratio = Math.min(Math.max(random(), 0), 1)

  return (min + (max - min) * ratio).toFixed(1)
}

function cleanParams(filters: Record<string, string | number>) {
  return Object.fromEntries(Object.entries(filters).filter(([, value]) => value !== ''))
}
