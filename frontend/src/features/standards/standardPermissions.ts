import type { FieldPermissionMeta } from '../customers/CustomerListPage'
import type { StandardFormValues } from './standardSchema'

export function filterForbiddenStandardFields(values: StandardFormValues, fieldPermissions?: FieldPermissionMeta) {
  if (!fieldPermissions) {
    return values
  }

  return Object.fromEntries(
    Object.entries(values).filter(([field]) => fieldPermissions[field]?.update !== false),
  ) as Partial<StandardFormValues>
}
