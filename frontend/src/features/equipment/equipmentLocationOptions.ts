import type { EquipmentLocation } from './EquipmentListPage'

export type EquipmentLocationOption = {
  id: number
  name: string
  label: string
}

export function activeLocationOptions(locations: EquipmentLocation[]): EquipmentLocationOption[] {
  return flattenLocationOptions(locations)
    .filter((option) => option.status !== 'disabled')
    .map(({ id, label, name }) => ({ id, label, name }))
}

function flattenLocationOptions(
  locations: EquipmentLocation[],
  parents: string[] = [],
): Array<EquipmentLocationOption & { status: EquipmentLocation['status'] }> {
  return locations.flatMap((location) => {
    const path = [...parents, location.name]
    const option = {
      id: location.id,
      name: location.name,
      label: path.join(' / '),
      status: location.status,
    }

    return [option, ...flattenLocationOptions(location.children ?? [], path)]
  })
}
