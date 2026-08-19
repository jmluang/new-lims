import type { Standard } from '../standards/StandardListPage'

export function standardSearchValue(standard: Standard) {
  return [standard.std_no, standard.chinese_name, standard.category].filter(Boolean).join(' ')
}

export function filterStandardOptions(standards: Standard[], query: string) {
  const normalizedQuery = query.trim().toLocaleLowerCase()

  if (!normalizedQuery) {
    return standards.slice(0, 20)
  }

  return standards
    .filter((standard) => standardSearchValue(standard).toLocaleLowerCase().includes(normalizedQuery))
    .slice(0, 20)
}

export function isSelectedStandardValue(value: string, standard: Standard) {
  return value === standard.std_no || value === [standard.std_no, standard.chinese_name].filter(Boolean).join(' ')
}
