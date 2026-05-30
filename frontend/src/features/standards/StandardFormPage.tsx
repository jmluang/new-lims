import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useNavigate, useRouterState } from '@tanstack/react-router'
import { ArrowLeft } from 'lucide-react'
import { api } from '../../lib/api'
import { ErrorNotice, LoadingState, PageShell, Panel } from '../system/shared'
import type { ApiResource } from '../system/utils'
import { StandardForm } from './StandardForm'
import type { FieldPermissionMeta, Standard } from './StandardListPage'
import type { StandardFormValues } from './standardSchema'

export function StandardFormPage() {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const pathname = useRouterState({ select: (state) => state.location.pathname })
  const standardId = standardIdFromPath(pathname)
  const isEditing = standardId !== null
  const standardQuery = useQuery({
    queryKey: ['standard', standardId],
    enabled: isEditing,
    queryFn: async () => {
      const response = await api.get<ApiResource<Standard> & { meta?: { fields?: FieldPermissionMeta } }>(`/api/standards/${standardId}`)

      return response.data
    },
  })
  const saveStandard = useMutation({
    mutationFn: async (values: Partial<StandardFormValues>) => {
      const payload = normalizeStandardPayload(values)

      if (isEditing) {
        await api.put(`/api/standards/${standardId}`, payload)
        return
      }

      await api.post('/api/standards', payload)
    },
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['standards'] })
      await navigate({ to: '/standards' })
    },
  })

  return (
    <PageShell
      title={isEditing ? 'Edit standard' : 'Create standard'}
      description="Maintain the standard library without mixing the large form into the list."
      actions={
        <Link className="inline-flex h-9 items-center justify-center gap-2 rounded-md px-3 text-sm font-medium text-slate-600 hover:bg-slate-100" to="/standards">
          <ArrowLeft className="size-4" aria-hidden="true" />
          返回列表
        </Link>
      }
    >
      <Panel title={isEditing ? 'Edit standard' : 'Create standard'}>
        {standardQuery.isError ? <ErrorNotice error={standardQuery.error} fallback="Unable to load standard" /> : null}
        {isEditing && standardQuery.isPending ? <LoadingState label="Loading standard" /> : null}
        {!isEditing || standardQuery.data ? (
          <StandardForm
            standard={standardQuery.data?.data ?? null}
            fieldPermissions={standardQuery.data?.meta?.fields}
            submitting={saveStandard.isPending}
            error={saveStandard.error}
            onSubmit={(values) => saveStandard.mutateAsync(values)}
            onCancel={() => navigate({ to: '/standards' })}
          />
        ) : null}
      </Panel>
    </PageShell>
  )
}

function standardIdFromPath(pathname: string) {
  const match = pathname.match(/^\/standards\/(\d+)\/edit$/)

  return match ? Number(match[1]) : null
}

function normalizeStandardPayload(values: Partial<StandardFormValues>) {
  return Object.fromEntries(Object.entries(values).map(([key, value]) => [key, value === '' ? null : value]))
}
