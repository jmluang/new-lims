export function canUpdateUserPhone(permissions?: { resources: Record<string, { fields: Record<string, Record<string, boolean>> }> }) {
  return Boolean(permissions?.resources['system.users']?.fields.phone?.update)
}
