import type { EquipmentLocation } from './EquipmentListPage'

export type EquipmentLocationOption = {
  id: number
  label: string
}

export function activeLocationOptions(locations: EquipmentLocation[]): EquipmentLocationOption[] {
  return flattenLocationOptions(locations)
    .filter((option) => option.status !== 'disabled')
    .map(({ id, label }) => ({ id, label }))
}

function flattenLocationOptions(
  locations: EquipmentLocation[],
  parents: string[] = [],
): Array<EquipmentLocationOption & { status: EquipmentLocation['status'] }> {
  return locations.flatMap((location) => {
    const path = [...parents, location.name]
    const option = {
      id: location.id,
      label: path.join(' / '),
      status: location.status,
    }

    return [option, ...flattenLocationOptions(location.children ?? [], path)]
  })
}
