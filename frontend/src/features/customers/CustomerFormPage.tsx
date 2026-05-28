import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useNavigate, useRouterState } from '@tanstack/react-router'
import { ArrowLeft } from 'lucide-react'
import { api } from '../../lib/api'
import { ErrorNotice, LoadingState, PageShell, Panel } from '../system/shared'
import type { ApiCollection, ApiResource } from '../system/utils'
import { CustomerForm } from './CustomerForm'
import type { Customer, DictionarySet, FieldPermissionMeta } from './CustomerListPage'
import type { CustomerFormValues } from './customerSchema'

export function CustomerFormPage() {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const pathname = useRouterState({ select: (state) => state.location.pathname })
  const customerId = customerIdFromPath(pathname)
  const isEditing = customerId !== null
  const dictionariesQuery = useQuery({
    queryKey: ['dictionary-options'],
    queryFn: async () => {
      const response = await api.get<ApiCollection<DictionarySet>>('/api/dictionary-options')

      return response.data.data
    },
  })
  const customerQuery = useQuery({
    queryKey: ['customer', customerId],
    enabled: isEditing,
    queryFn: async () => {
      const response = await api.get<ApiResource<Customer> & { meta?: { fields?: FieldPermissionMeta } }>(`/api/customers/${customerId}`)

      return response.data
    },
  })
  const createMetaQuery = useQuery({
    queryKey: ['customers-form-meta'],
    enabled: !isEditing,
    queryFn: async () => {
      const response = await api.get<ApiCollection<Customer>>('/api/customers', { params: { per_page: 1 } })

      return response.data.meta?.fields as FieldPermissionMeta | undefined
    },
  })
  const saveCustomer = useMutation({
    mutationFn: async (values: CustomerFormValues) => {
      const payload = normalizeCustomerPayload(values)

      if (isEditing) {
        await api.put(`/api/customers/${customerId}`, payload)
        return
      }

      await api.post('/api/customers', payload)
    },
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['customers'] })
      await navigate({ to: '/customers' })
    },
  })
  const fieldPermissions = customerQuery.data?.meta?.fields ?? createMetaQuery.data
  const loading =
    dictionariesQuery.isPending ||
    (isEditing && customerQuery.isPending) ||
    (!isEditing && createMetaQuery.isPending)

  return (
    <PageShell
      title={isEditing ? 'Edit customer' : 'Create customer'}
      description="Customer registry, sensitive fields, contacts and filtered export."
      actions={
        <Link className="inline-flex h-9 items-center justify-center gap-2 rounded-md px-3 text-sm font-medium text-slate-600 hover:bg-slate-100" to="/customers">
          <ArrowLeft className="size-4" aria-hidden="true" />
          返回列表
        </Link>
      }
    >
      <Panel title={isEditing ? 'Edit customer' : 'Create customer'}>
        {dictionariesQuery.isError ? <ErrorNotice error={dictionariesQuery.error} fallback="Unable to load dictionaries" /> : null}
        {customerQuery.isError ? <ErrorNotice error={customerQuery.error} fallback="Unable to load customers" /> : null}
        {createMetaQuery.isError ? <ErrorNotice error={createMetaQuery.error} fallback="Unable to load customers" /> : null}
        {loading ? <LoadingState label="Loading data" /> : null}
        {!loading && !dictionariesQuery.isError && !customerQuery.isError && !createMetaQuery.isError ? (
          <CustomerForm
            customer={customerQuery.data?.data ?? null}
            dictionaries={dictionariesQuery.data ?? []}
            fieldPermissions={fieldPermissions}
            submitting={saveCustomer.isPending}
            error={saveCustomer.error}
            onSubmit={(values) => saveCustomer.mutateAsync(values)}
            onCancel={() => navigate({ to: '/customers' })}
          />
        ) : null}
      </Panel>
    </PageShell>
  )
}

function customerIdFromPath(pathname: string) {
  const match = pathname.match(/^\/customers\/(\d+)\/edit$/)

  return match ? Number(match[1]) : null
}

function normalizeCustomerPayload(values: CustomerFormValues) {
  return Object.fromEntries(Object.entries(values).map(([key, value]) => [key, value === '' ? null : value]))
}
