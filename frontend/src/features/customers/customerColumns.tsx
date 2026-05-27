import type { Customer, FieldPermissionMeta } from './CustomerListPage'

export type CustomerColumn = {
  key: keyof Customer
  label: string
  sensitive?: boolean
}

export const customerColumns: CustomerColumn[] = [
  { key: 'name', label: 'Name' },
  { key: 'credit_code', label: 'Credit code', sensitive: true },
  { key: 'type', label: 'Type' },
  { key: 'level', label: 'Level' },
  { key: 'source', label: 'Source' },
  { key: 'industry', label: 'Industry' },
  { key: 'phone', label: 'Phone', sensitive: true },
  { key: 'email', label: 'Email', sensitive: true },
  { key: 'status', label: 'Status' },
]

export function visibleCustomerColumns(fields?: FieldPermissionMeta) {
  return customerColumns.filter((column) => !column.sensitive || !fields?.[column.key as string]?.hidden)
}

export function visibleCustomerMobileFields(fields?: FieldPermissionMeta) {
  return {
    defaultContact: true,
    phone: !fields?.phone?.hidden,
  }
}
