import type { FieldPermissionMeta } from './CustomerListPage'
import type { CustomerFormValues } from './customerSchema'

export function filterForbiddenCustomerFields(values: CustomerFormValues, permissions?: FieldPermissionMeta): CustomerFormValues {
  return {
    ...values,
    credit_code: canUpdate(permissions, 'credit_code') ? values.credit_code : undefined,
    phone: canUpdate(permissions, 'phone') ? values.phone : undefined,
    email: canUpdate(permissions, 'email') ? values.email : undefined,
  }
}

function canUpdate(permissions: FieldPermissionMeta | undefined, field: string) {
  return permissions?.[field]?.update !== false
}
