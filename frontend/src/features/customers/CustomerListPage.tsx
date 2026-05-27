import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Download, Edit3, Plus, Search, Trash2 } from 'lucide-react'
import { useState } from 'react'
import { PermissionGate } from '../../components/app/PermissionGate'
import { api } from '../../lib/api'
import {
  Button,
  DataTable,
  EmptyState,
  ErrorNotice,
  Field,
  LoadingState,
  PageShell,
  Panel,
  StatusBadge,
} from '../system/shared'
import { type ApiCollection, inputClass } from '../system/utils'
import { CustomerContactList } from './CustomerContactList'
import { CustomerForm } from './CustomerForm'
import { visibleCustomerColumns, visibleCustomerMobileFields } from './customerColumns'
import type { CustomerFormValues } from './customerSchema'

export type FieldPermissionMeta = Record<string, { read?: boolean; update?: boolean; export?: boolean; hidden?: boolean }>

export type Customer = {
  id: number
  name: string
  credit_code?: string | null
  type?: string | null
  level?: string | null
  source?: string | null
  industry?: string | null
  phone?: string | null
  email?: string | null
  address?: string | null
  remark?: string | null
  status: 'active' | 'disabled'
  default_contact?: {
    id: number
    name: string
    phone?: string | null
    email?: string | null
    is_default: boolean
    status: 'active' | 'disabled'
  } | null
  _field_permissions?: FieldPermissionMeta
}

export type DictionarySet = {
  id: number
  code: string
  name: string
  status: 'active' | 'disabled'
  items: Array<{ id: number; label: string; value: string; status: 'active' | 'disabled' }>
}

type CustomerFilters = {
  search: string
  type: string
  level: string
  source: string
  industry: string
  status: string
}

const emptyFilters: CustomerFilters = {
  search: '',
  type: '',
  level: '',
  source: '',
  industry: '',
  status: '',
}

export function CustomerListPage() {
  const queryClient = useQueryClient()
  const [filters, setFilters] = useState<CustomerFilters>(emptyFilters)
  const [selectedCustomer, setSelectedCustomer] = useState<Customer | null>(null)
  const [formOpen, setFormOpen] = useState(false)
  const customersQuery = useQuery({
    queryKey: ['customers', filters],
    queryFn: async () => {
      const response = await api.get<ApiCollection<Customer>>('/api/customers', { params: cleanParams(filters) })

      return response.data
    },
  })
  const dictionariesQuery = useQuery({
    queryKey: ['dictionary-options'],
    queryFn: async () => {
      const response = await api.get<ApiCollection<DictionarySet>>('/api/dictionary-options')

      return response.data.data
    },
  })
  const saveCustomer = useMutation({
    mutationFn: async (values: CustomerFormValues) => {
      const payload = normalizeCustomerPayload(values)

      if (selectedCustomer) {
        await api.put(`/api/customers/${selectedCustomer.id}`, payload)
        return
      }

      await api.post('/api/customers', payload)
    },
    onSuccess: async () => {
      setFormOpen(false)
      await queryClient.invalidateQueries({ queryKey: ['customers'] })
    },
  })
  const deleteCustomer = useMutation({
    mutationFn: async (customer: Customer) => {
      await api.delete(`/api/customers/${customer.id}`)
    },
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['customers'] }),
  })
  const customers = customersQuery.data?.data ?? []
  const fieldPermissions = (customersQuery.data?.meta?.fields ?? selectedCustomer?._field_permissions) as FieldPermissionMeta | undefined
  const columns = visibleCustomerColumns(fieldPermissions)
  const mobileFields = visibleCustomerMobileFields(fieldPermissions)

  function startCreate() {
    setSelectedCustomer(null)
    setFormOpen(true)
    saveCustomer.reset()
  }

  function startEdit(customer: Customer) {
    setSelectedCustomer(customer)
    setFormOpen(true)
    saveCustomer.reset()
  }

  async function exportCustomers() {
    const response = await api.get<{ headers: string[]; data: Record<string, unknown>[] }>('/api/customers/export', {
      params: cleanParams(filters),
    })
    const blob = new Blob([JSON.stringify(response.data, null, 2)], { type: 'application/json' })
    const url = URL.createObjectURL(blob)
    const link = document.createElement('a')

    link.href = url
    link.download = `customers-${new Date().toISOString().slice(0, 10)}.json`
    link.click()
    URL.revokeObjectURL(url)
  }

  return (
    <PageShell
      title="Customer Management"
      description="Customer registry, sensitive fields, contacts and filtered export."
      actions={
        <>
          <PermissionGate resource="customers" action="export">
            <Button variant="secondary" onClick={() => void exportCustomers()}>
              <Download className="size-4" aria-hidden="true" />
              Export
            </Button>
          </PermissionGate>
          <PermissionGate resource="customers" action="create">
            <Button variant="primary" onClick={startCreate}>
              <Plus className="size-4" aria-hidden="true" />
              New customer
            </Button>
          </PermissionGate>
        </>
      }
    >
      <Panel title="Filters">
        <div className="grid gap-3 md:grid-cols-6">
          <Field label="Search">
            <div className="relative">
              <Search className="pointer-events-none absolute left-3 top-2.5 size-4 text-slate-400" aria-hidden="true" />
              <input
                className={`${inputClass} pl-9`}
                value={filters.search}
                onChange={(event) => setFilters({ ...filters, search: event.target.value })}
                placeholder="name, credit, phone"
              />
            </div>
          </Field>
          <DictionaryFilter label="Type" code="customer.type" dictionaries={dictionariesQuery.data ?? []} value={filters.type} onChange={(type) => setFilters({ ...filters, type })} />
          <DictionaryFilter label="Level" code="customer.level" dictionaries={dictionariesQuery.data ?? []} value={filters.level} onChange={(level) => setFilters({ ...filters, level })} />
          <DictionaryFilter label="Source" code="customer.source" dictionaries={dictionariesQuery.data ?? []} value={filters.source} onChange={(source) => setFilters({ ...filters, source })} />
          <DictionaryFilter label="Industry" code="customer.industry" dictionaries={dictionariesQuery.data ?? []} value={filters.industry} onChange={(industry) => setFilters({ ...filters, industry })} />
          <DictionaryFilter
            label="Status"
            code="customer.status"
            dictionaries={dictionariesQuery.data ?? []}
            fallbackOptions={['active', 'disabled']}
            value={filters.status}
            onChange={(status) => setFilters({ ...filters, status })}
          />
        </div>
      </Panel>

      {customersQuery.isError ? <ErrorNotice error={customersQuery.error} fallback="Unable to load customers" /> : null}
      {deleteCustomer.error ? <ErrorNotice error={deleteCustomer.error} fallback="Unable to delete customer" /> : null}

      <div className="grid gap-4 xl:grid-cols-[minmax(0,1fr)_440px]">
        <section>
          {customersQuery.isPending ? <LoadingState label="Loading customers" /> : null}
          {!customersQuery.isPending && customers.length === 0 ? (
            <EmptyState title="No customers found" description="Adjust filters or create a new customer." />
          ) : null}
          {customers.length > 0 ? (
            <>
              <DataTable>
                <thead className="bg-slate-50 text-left text-xs uppercase text-slate-500">
                  <tr>
                    {columns.map((column) => (
                      <th className="px-3 py-2 font-medium" key={column.key}>
                        {column.label}
                      </th>
                    ))}
                    <th className="px-3 py-2 font-medium">Default contact</th>
                    <th className="px-3 py-2 font-medium">Actions</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-200">
                  {customers.map((customer) => (
                    <tr className="align-top" key={customer.id}>
                      {columns.map((column) => (
                        <td className="px-3 py-3 text-sm text-slate-700" key={column.key}>
                          {column.key === 'status' ? <StatusBadge status={customer.status} /> : String(customer[column.key] ?? '-')}
                        </td>
                      ))}
                      <td className="px-3 py-3 text-sm text-slate-700">
                        <div className="font-medium text-slate-900">{customer.default_contact?.name ?? '-'}</div>
                        <div className="text-xs text-slate-500">{customer.default_contact?.phone ?? ''}</div>
                      </td>
                      <td className="px-3 py-3">
                        <CustomerActions customer={customer} onEdit={startEdit} onSelect={setSelectedCustomer} onDelete={(target) => deleteCustomer.mutate(target)} />
                      </td>
                    </tr>
                  ))}
                </tbody>
              </DataTable>

              <div className="space-y-3 md:hidden">
                {customers.map((customer) => (
                  <article className="rounded-lg border border-slate-200 bg-white p-4 shadow-sm" key={customer.id}>
                    <div className="flex items-start justify-between gap-3">
                      <div className="min-w-0">
                        <h2 className="truncate text-sm font-semibold text-slate-950">{customer.name}</h2>
                        <p className="truncate text-xs text-slate-500">
                          {[customer.level, customer.type, customer.industry].filter(Boolean).join(' · ') || 'Unclassified'}
                        </p>
                      </div>
                      <StatusBadge status={customer.status} />
                    </div>
                    <dl className="mt-3 grid grid-cols-2 gap-2 text-xs">
                      <div>
                        <dt className="text-slate-500">Default contact</dt>
                        <dd className="font-medium text-slate-800">{customer.default_contact?.name ?? '-'}</dd>
                      </div>
                      {mobileFields.phone ? (
                        <div>
                          <dt className="text-slate-500">Phone</dt>
                          <dd className="font-medium text-slate-800">{customer.phone ?? '-'}</dd>
                        </div>
                      ) : null}
                    </dl>
                    <div className="mt-3">
                      <CustomerActions customer={customer} onEdit={startEdit} onSelect={setSelectedCustomer} onDelete={(target) => deleteCustomer.mutate(target)} />
                    </div>
                  </article>
                ))}
              </div>
            </>
          ) : null}
        </section>

        <div className="space-y-4">
          {formOpen ? (
            <Panel title={selectedCustomer ? 'Edit customer' : 'Create customer'}>
              <CustomerForm
                customer={selectedCustomer}
                dictionaries={dictionariesQuery.data ?? []}
                fieldPermissions={fieldPermissions}
                submitting={saveCustomer.isPending}
                error={saveCustomer.error}
                onSubmit={(values) => saveCustomer.mutateAsync(values)}
                onCancel={() => setFormOpen(false)}
              />
            </Panel>
          ) : null}

          <Panel title="Contacts">
            <CustomerContactList customer={selectedCustomer} />
          </Panel>
        </div>
      </div>
    </PageShell>
  )
}

function CustomerActions({
  customer,
  onEdit,
  onSelect,
  onDelete,
}: {
  customer: Customer
  onEdit: (customer: Customer) => void
  onSelect: (customer: Customer) => void
  onDelete: (customer: Customer) => void
}) {
  return (
    <div className="flex flex-wrap gap-2">
      <Button variant="secondary" onClick={() => onSelect(customer)}>
        Contacts
      </Button>
      <PermissionGate resource="customers" action="update">
        <Button variant="secondary" onClick={() => onEdit(customer)}>
          <Edit3 className="size-4" aria-hidden="true" />
          Edit
        </Button>
      </PermissionGate>
      <PermissionGate resource="customers" action="delete">
        <Button variant="danger" onClick={() => onDelete(customer)}>
          <Trash2 className="size-4" aria-hidden="true" />
          Disable
        </Button>
      </PermissionGate>
    </div>
  )
}

function DictionaryFilter({
  label,
  code,
  dictionaries,
  value,
  onChange,
  fallbackOptions = [],
}: {
  label: string
  code: string
  dictionaries: DictionarySet[]
  value: string
  onChange: (value: string) => void
  fallbackOptions?: string[]
}) {
  const options = dictionaries.find((dictionary) => dictionary.code === code)?.items ?? []
  const renderedOptions = options.length > 0 ? options : fallbackOptions.map((option) => ({ label: option, value: option, status: 'active' }))

  return (
    <Field label={label}>
      <select className={inputClass} value={value} onChange={(event) => onChange(event.target.value)}>
        <option value="">All</option>
        {renderedOptions.map((option) => (
          <option value={option.value} key={option.value}>
            {option.label}
          </option>
        ))}
      </select>
    </Field>
  )
}

function cleanParams(filters: CustomerFilters) {
  return Object.fromEntries(Object.entries(filters).filter(([, value]) => value !== ''))
}

function normalizeCustomerPayload(values: CustomerFormValues) {
  return Object.fromEntries(Object.entries(values).map(([key, value]) => [key, value === '' ? null : value]))
}
