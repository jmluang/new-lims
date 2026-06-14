import type { EquipmentLocation } from '../equipment/EquipmentListPage'

export type LocationSelectionOption = {
  id: string
  name: string
  label: string
}

export type LocationSelectionLevel = {
  depth: number
  options: LocationSelectionOption[]
  selectedId: string
}

export type LocationSelection = {
  levels: LocationSelectionLevel[]
  selectedNodes: EquipmentLocation[]
  value: string
}

export function buildLocationSelection(locations: EquipmentLocation[], selectedIds: string[]): LocationSelection {
  const levels: LocationSelectionLevel[] = []
  const selectedNodes: EquipmentLocation[] = []
  let options = activeLocations(locations)
  let parents: string[] = []

  for (let depth = 0; options.length > 0; depth += 1) {
    const selectedId = selectedIds[depth] ?? ''
    const selected = options.find((location) => String(location.id) === selectedId)

    levels.push({
      depth,
      selectedId: selected ? selectedId : '',
      options: options.map((location) => ({
        id: String(location.id),
        name: location.name,
        label: [...parents, location.name].join(' / '),
      })),
    })

    if (!selected) {
      break
    }

    selectedNodes.push(selected)
    parents = [...parents, selected.name]
    options = activeLocations(selected.children ?? [])
  }

  return {
    levels,
    selectedNodes,
    value: selectedNodes.map((location) => location.name).join(' / '),
  }
}

export function changeLocationSelection(locations: EquipmentLocation[], selectedIds: string[], depth: number, nextId: string): LocationSelection {
  const nextSelectedIds = [...selectedIds.slice(0, depth), nextId].filter(Boolean)

  return buildLocationSelection(locations, nextSelectedIds)
}

export function findLocationSelectionIdsByLabel(locations: EquipmentLocation[], label?: string | null): string[] {
  const normalized = label?.trim()

  if (!normalized) {
    return []
  }

  const matches = collectLocationMatches(activeLocations(locations), normalized)
  const exactPath = matches.find((match) => match.label === normalized)

  return exactPath?.ids ?? matches.find((match) => match.name === normalized)?.ids ?? []
}

function activeLocations(locations: EquipmentLocation[]): EquipmentLocation[] {
  return locations.filter((location) => location.status !== 'disabled')
}

function collectLocationMatches(
  locations: EquipmentLocation[],
  label: string,
  parents: string[] = [],
  parentIds: string[] = [],
): Array<{ ids: string[]; label: string; name: string }> {
  return locations.flatMap((location) => {
    const path = [...parents, location.name]
    const ids = [...parentIds, String(location.id)]
    const current = { ids, label: path.join(' / '), name: location.name }

    return [current, ...collectLocationMatches(activeLocations(location.children ?? []), label, path, ids)]
  })
}
