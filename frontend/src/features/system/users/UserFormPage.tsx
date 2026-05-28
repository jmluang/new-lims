import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useNavigate, useRouterState } from '@tanstack/react-router'
import { ArrowLeft } from 'lucide-react'
import { api } from '../../../lib/api'
import { ErrorNotice, LoadingState, PageShell, Panel } from '../shared'
import type { ApiCollection, ApiResource } from '../utils'
import { type DepartmentOption, type SystemUser, UserForm, type UserFormValues, type UserGroupOption } from './UserForm'

export function UserFormPage() {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const pathname = useRouterState({ select: (state) => state.location.pathname })
  const userId = userIdFromPath(pathname)
  const isEditing = userId !== null
  const groupsQuery = useQuery({
    queryKey: ['system-groups'],
    queryFn: async () => {
      const response = await api.get<ApiCollection<UserGroupOption>>('/api/system/groups')

      return response.data.data
    },
  })
  const departmentsQuery = useQuery({
    queryKey: ['system-departments'],
    queryFn: async () => {
      const response = await api.get<ApiCollection<DepartmentOption>>('/api/system/departments')

      return response.data.data
    },
  })
  const userQuery = useQuery({
    queryKey: ['system-user', userId],
    enabled: isEditing,
    queryFn: async () => {
      const response = await api.get<ApiResource<SystemUser>>(`/api/system/users/${userId}`)

      return response.data.data
    },
  })
  const saveUser = useMutation({
    mutationFn: async (values: UserFormValues) => {
      const payload = userPayload(values)

      if (isEditing) {
        await api.put(`/api/system/users/${userId}`, payload)
        return
      }

      await api.post('/api/system/users', payload)
    },
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['system-users'] })
      await navigate({ to: '/system' })
    },
  })

  const loading = groupsQuery.isPending || departmentsQuery.isPending || (isEditing && userQuery.isPending)

  return (
    <PageShell
      title={isEditing ? 'Edit user' : 'Create user'}
      description="Accounts, departments, groups, lock state and first-login password controls."
      actions={
        <Link className="inline-flex h-9 items-center justify-center gap-2 rounded-md px-3 text-sm font-medium text-slate-600 hover:bg-slate-100" to="/system">
          <ArrowLeft className="size-4" aria-hidden="true" />
          返回列表
        </Link>
      }
    >
      <Panel title={isEditing ? 'Edit user' : 'Create user'}>
        {groupsQuery.isError ? <ErrorNotice error={groupsQuery.error} fallback="Unable to load groups" /> : null}
        {departmentsQuery.isError ? <ErrorNotice error={departmentsQuery.error} fallback="Unable to load users" /> : null}
        {userQuery.isError ? <ErrorNotice error={userQuery.error} fallback="Unable to load users" /> : null}
        {loading ? <LoadingState label="Loading data" /> : null}
        {!loading && !groupsQuery.isError && !departmentsQuery.isError && !userQuery.isError ? (
          <UserForm
            user={userQuery.data ?? null}
            groups={groupsQuery.data ?? []}
            departments={departmentsQuery.data ?? []}
            submitting={saveUser.isPending}
            error={saveUser.error}
            onSubmit={(values) => saveUser.mutateAsync(values)}
            onCancel={() => navigate({ to: '/system' })}
          />
        ) : null}
      </Panel>
    </PageShell>
  )
}

function userIdFromPath(pathname: string) {
  const match = pathname.match(/^\/system\/users\/(\d+)\/edit$/)

  return match ? Number(match[1]) : null
}

function userPayload(values: UserFormValues) {
  return {
    ...values,
    department_id: values.department_id ? Number(values.department_id) : null,
    phone: values.phone || null,
    password: values.password || undefined,
  }
}
