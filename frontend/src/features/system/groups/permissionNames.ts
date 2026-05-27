export function resourcePermissionName(resource: string, action: string) {
  return `${resource}.${action}`
}

export function fieldPermissionName(resource: string, field: string, action: string) {
  return `${resource}.field.${field}.${action}`
}
