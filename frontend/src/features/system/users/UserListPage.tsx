import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useNavigate } from '@tanstack/react-router'
import { Edit3, Lock, Plus, RotateCcw, Search, Unlock } from 'lucide-react'
import { useState } from 'react'
import { PermissionGate } from '../../../components/app/PermissionGate'
import { api } from '../../../lib/api'
import { zhText } from '../../../lib/zh'
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
} from '../shared'
import { type ApiCollection, formatDateTime, inputClass } from '../utils'
import { type DepartmentOption, type SystemUser, type UserGroupOption } from './UserForm'

type UserFilters = {
  search: string
  status: string
  department_id: string
  group_id: string
}

export function UserListPage() {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [filters, setFilters] = useState<UserFilters>({ search: '', status: '', department_id: '', group_id: '' })
  const usersQuery = useQuery({
    queryKey: ['system-users', filters],
    queryFn: async () => {
      const response = await api.get<ApiCollection<SystemUser>>('/api/system/users', { params: cleanParams(filters) })

      return response.data
    },
  })
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
  const lockUser = useMutation({
    mutationFn: async (user: SystemUser) => {
      await api.post(`/api/system/users/${user.id}/lock`, { reason: 'Manual administrator lock' })
    },
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['system-users'] }),
  })
  const unlockUser = useMutation({
    mutationFn: async (user: SystemUser) => {
      await api.post(`/api/system/users/${user.id}/unlock`)
    },
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['system-users'] }),
  })
  const resetPassword = useMutation({
    mutationFn: async (user: SystemUser) => {
      await api.post(`/api/system/users/${user.id}/reset-password`, {
        password: 'ChangeMe123!',
        must_change_password: true,
      })
    },
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['system-users'] }),
  })
  const users = usersQuery.data?.data ?? []

  function startCreate() {
    void navigate({ to: '/system/users/new' })
  }

  function startEdit(user: SystemUser) {
    void navigate({ to: '/system/users/$userId/edit', params: { userId: String(user.id) } })
  }

  function renderUsers() {
    if (usersQuery.isPending) {
      return <LoadingState label="Loading users" />
    }

    if (usersQuery.isError) {
      return <ErrorNotice error={usersQuery.error} fallback="Unable to load users" />
    }

    if (users.length === 0) {
      return <EmptyState title="No users found" description="Adjust filters or create the first operator account." />
    }

    return (
      <>
        <DataTable>
          <thead className="bg-slate-50 text-left text-xs uppercase text-slate-500">
            <tr>
              <th className="px-3 py-2 font-medium">Account</th>
              <th className="px-3 py-2 font-medium">Department</th>
              <th className="px-3 py-2 font-medium">Groups</th>
              <th className="px-3 py-2 font-medium">Security</th>
              <th className="px-3 py-2 font-medium">Actions</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-200">
            {users.map((user) => (
              <tr className="align-top" key={user.id}>
                <td className="px-3 py-3">
                  <div className="font-medium text-slate-900">{user.name}</div>
                  <div className="text-xs text-slate-500">{user.email}</div>
                  <div className="text-xs text-slate-500">{user.phone ?? 'phone hidden'}</div>
                </td>
                <td className="px-3 py-3 text-slate-700">{user.department?.name ?? '-'}</td>
                <td className="px-3 py-3">
                  <div className="flex flex-wrap gap-1">
                    {user.groups.map((group) => (
                      <span className="rounded border border-slate-200 px-2 py-0.5 text-xs text-slate-600" key={group.id}>
                        {group.name}
                      </span>
                    ))}
                  </div>
                </td>
                <td className="px-3 py-3">
                  <StatusBadge status={user.status} />
                  <div className="mt-1 text-xs text-slate-500">
                    {user.must_change_password ? 'first login password change' : 'password ready'}
                  </div>
                  <div className="text-xs text-slate-500">{formatDateTime(user.locked_at)}</div>
                </td>
                <td className="px-3 py-3">
                  <UserActions
                    user={user}
                    onEdit={startEdit}
                    onLock={(target) => lockUser.mutate(target)}
                    onUnlock={(target) => unlockUser.mutate(target)}
                    onReset={(target) => resetPassword.mutate(target)}
                  />
                </td>
              </tr>
            ))}
          </tbody>
        </DataTable>

        <div className="space-y-3 md:hidden">
          {users.map((user) => (
            <article className="rounded-lg border border-slate-200 bg-white p-4 shadow-sm" key={user.id}>
              <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                  <h2 className="truncate text-sm font-semibold text-slate-950">{user.name}</h2>
                  <p className="truncate text-xs text-slate-500">{user.email}</p>
                </div>
                <StatusBadge status={user.status} />
              </div>
              <dl className="mt-3 grid grid-cols-2 gap-2 text-xs">
                <div>
                  <dt className="text-slate-500">Department</dt>
                  <dd className="font-medium text-slate-800">{user.department?.name ?? '-'}</dd>
                </div>
                <div>
                  <dt className="text-slate-500">Phone</dt>
                  <dd className="font-medium text-slate-800">{user.phone ?? 'hidden'}</dd>
                </div>
              </dl>
              <div className="mt-3 flex flex-wrap gap-1">
                {user.groups.map((group) => (
                  <span className="rounded border border-slate-200 px-2 py-0.5 text-xs text-slate-600" key={group.id}>
                    {group.name}
                  </span>
                ))}
              </div>
              <div className="mt-3">
                <UserActions
                  user={user}
                  onEdit={startEdit}
                  onLock={(target) => lockUser.mutate(target)}
                  onUnlock={(target) => unlockUser.mutate(target)}
                  onReset={(target) => resetPassword.mutate(target)}
                />
              </div>
            </article>
          ))}
        </div>
      </>
    )
  }

  return (
    <PageShell
      title="User Management"
      description="Accounts, departments, groups, lock state and first-login password controls."
      actions={
        <PermissionGate resource="system.users" action="create">
          <Button variant="primary" onClick={startCreate}>
            <Plus className="size-4" aria-hidden="true" />
            New user
          </Button>
        </PermissionGate>
      }
    >
      <Panel title="Filters">
        <div className="grid gap-3 md:grid-cols-4">
          <Field label="Search">
            <div className="relative">
              <Search className="pointer-events-none absolute left-3 top-2.5 size-4 text-slate-400" aria-hidden="true" />
              <input
                className={`${inputClass} pl-9`}
                value={filters.search}
                onChange={(event) => setFilters({ ...filters, search: event.target.value })}
                placeholder={zhText('name or email') ?? undefined}
              />
            </div>
          </Field>
          <Field label="Status">
            <select className={inputClass} value={filters.status} onChange={(event) => setFilters({ ...filters, status: event.target.value })}>
              <option value="">All</option>
              <option value="active">active</option>
              <option value="disabled">disabled</option>
              <option value="locked">locked</option>
            </select>
          </Field>
          <Field label="Department">
            <select
              className={inputClass}
              value={filters.department_id}
              onChange={(event) => setFilters({ ...filters, department_id: event.target.value })}
            >
              <option value="">All</option>
              {departmentsQuery.data?.map((department) => (
                <option value={department.id} key={department.id}>
                  {department.name}
                </option>
              ))}
            </select>
          </Field>
          <Field label="Group">
            <select className={inputClass} value={filters.group_id} onChange={(event) => setFilters({ ...filters, group_id: event.target.value })}>
              <option value="">All</option>
              {groupsQuery.data?.map((group) => (
                <option value={group.id} key={group.id}>
                  {group.name}
                </option>
              ))}
            </select>
          </Field>
        </div>
      </Panel>

      {renderUsers()}
      {lockUser.error || unlockUser.error || resetPassword.error ? (
        <ErrorNotice error={lockUser.error ?? unlockUser.error ?? resetPassword.error} fallback="User operation failed" />
      ) : null}
    </PageShell>
  )
}

function UserActions({
  user,
  onEdit,
  onLock,
  onUnlock,
  onReset,
}: {
  user: SystemUser
  onEdit: (user: SystemUser) => void
  onLock: (user: SystemUser) => void
  onUnlock: (user: SystemUser) => void
  onReset: (user: SystemUser) => void
}) {
  return (
    <div className="flex flex-wrap gap-2">
      <PermissionGate resource="system.users" action="update">
        <Button variant="secondary" onClick={() => onEdit(user)}>
          <Edit3 className="size-4" aria-hidden="true" />
          Edit
        </Button>
        {user.status === 'locked' ? (
          <Button variant="secondary" onClick={() => onUnlock(user)}>
            <Unlock className="size-4" aria-hidden="true" />
            Unlock
          </Button>
        ) : (
          <Button variant="secondary" onClick={() => onLock(user)}>
            <Lock className="size-4" aria-hidden="true" />
            Lock
          </Button>
        )}
        <Button variant="ghost" onClick={() => onReset(user)}>
          <RotateCcw className="size-4" aria-hidden="true" />
          Reset
        </Button>
      </PermissionGate>
    </div>
  )
}

function cleanParams(filters: UserFilters) {
  return Object.fromEntries(Object.entries(filters).filter(([, value]) => value !== ''))
}
