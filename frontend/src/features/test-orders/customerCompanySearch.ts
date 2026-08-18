import type { Customer } from '../customers/CustomerListPage'
import { customerSearchValue } from './testOrderSchema'

export function filterCustomerOptions(customers: Customer[], query: string) {
  const normalizedQuery = query.trim().toLocaleLowerCase()

  if (!normalizedQuery) {
    return customers.slice(0, 20)
  }

  return customers
    .filter((customer) => customerSearchValue(customer).toLocaleLowerCase().includes(normalizedQuery))
    .slice(0, 20)
}
