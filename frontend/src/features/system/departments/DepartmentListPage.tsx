import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Edit3, Plus, Trash2 } from 'lucide-react'
import { useState } from 'react'
import { PermissionGate } from '../../../components/app/PermissionGate'
import { api } from '../../../lib/api'
import { Button, DataTable, EmptyState, ErrorNotice, Field, LoadingState, Modal, PageShell, Panel, StatusBadge } from '../shared'
import { type ApiCollection, inputClass } from '../utils'

export type Department = {
  id: number
  parent_id?: number | null
  name: string
  code: string
  sort_order?: number | null
  status: 'active' | 'disabled'
  children?: Department[]
}

type DepartmentRow = Department & {
  depth: number
  path: string
}

type DepartmentForm = {
  parent_id: string
  name: string
  code: string
  sort_order: string
  status: Department['status']
}

const emptyForm: DepartmentForm = {
  parent_id: '',
  name: '',
  code: '',
  sort_order: '0',
  status: 'active',
}

export function DepartmentListPage() {
  const queryClient = useQueryClient()
  const [formOpen, setFormOpen] = useState(false)
  const [editingDepartment, setEditingDepartment] = useState<Department | null>(null)
  const [form, setForm] = useState<DepartmentForm>(emptyForm)
  const departmentsQuery = useQuery({
    queryKey: ['system-departments'],
    queryFn: async () => {
      const response = await api.get<ApiCollection<Department>>('/api/system/departments')

      return response.data.data
    },
  })
  const saveDepartment = useMutation({
    mutationFn: async () => {
      const payload = departmentPayload(form)

      if (editingDepartment) {
        await api.put(`/api/system/departments/${editingDepartment.id}`, payload)
        return
      }

      await api.post('/api/system/departments', payload)
    },
    onSuccess: async () => {
      closeForm()
      await queryClient.invalidateQueries({ queryKey: ['system-departments'] })
    },
  })
  const deleteDepartment = useMutation({
    mutationFn: async (department: Department) => {
      await api.delete(`/api/system/departments/${department.id}`)
    },
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['system-departments'] })
    },
  })
  const departments = departmentsQuery.data ?? []
  const rows = flattenDepartments(departments)
  const parentOptions = rows.filter((department) => !editingDepartment || !blockedParentIds(editingDepartment).has(department.id))

  function openCreate(parent?: Department) {
    setEditingDepartment(null)
    setForm({ ...emptyForm, parent_id: parent ? String(parent.id) : '' })
    setFormOpen(true)
    saveDepartment.reset()
  }

  function openEdit(department: Department) {
    setEditingDepartment(department)
    setForm({
      parent_id: department.parent_id ? String(department.parent_id) : '',
      name: department.name,
      code: department.code,
      sort_order: String(department.sort_order ?? 0),
      status: department.status,
    })
    setFormOpen(true)
    saveDepartment.reset()
  }

  function closeForm() {
    setFormOpen(false)
    setEditingDepartment(null)
    setForm(emptyForm)
  }

  return (
    <PageShell
      title="部门管理"
      description="维护用户可选择的部门层级、编码、排序和启用状态。"
      actions={
        <PermissionGate resource="system.departments" action="create">
          <Button variant="primary" onClick={() => openCreate()}>
            <Plus className="size-4" aria-hidden="true" />
            新建部门
          </Button>
        </PermissionGate>
      }
    >
      <Panel title="部门列表">
        {departmentsQuery.isPending ? <LoadingState label="正在加载部门" /> : null}
        {departmentsQuery.isError ? <ErrorNotice error={departmentsQuery.error} fallback="Unable to load departments" /> : null}
        {deleteDepartment.error ? <ErrorNotice error={deleteDepartment.error} fallback="无法删除部门" /> : null}
        {!departmentsQuery.isPending && rows.length === 0 ? (
          <EmptyState title="暂无部门" description="新建部门后，用户表单即可选择所属部门。" />
        ) : null}
        {rows.length > 0 ? (
          <DataTable>
            <thead className="bg-slate-50 text-left text-xs uppercase text-slate-500">
              <tr>
                <th className="px-3 py-2 font-medium">部门名称</th>
                <th className="px-3 py-2 font-medium">部门编码</th>
                <th className="px-3 py-2 font-medium">排序</th>
                <th className="px-3 py-2 font-medium">状态</th>
                <th className="px-3 py-2 font-medium">操作</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-200">
              {rows.map((department) => (
                <tr key={department.id}>
                  <td className="px-3 py-3 text-sm font-medium text-slate-900">
                    <span style={{ paddingLeft: `${department.depth * 1.25}rem` }}>{department.name}</span>
                  </td>
                  <td className="px-3 py-3 text-sm text-slate-700">{department.code}</td>
                  <td className="px-3 py-3 text-sm text-slate-700">{department.sort_order ?? 0}</td>
                  <td className="px-3 py-3">
                    <StatusBadge status={department.status} />
                  </td>
                  <td className="px-3 py-3">
                    <div className="flex flex-wrap gap-2">
                      <PermissionGate resource="system.departments" action="create">
                        <Button variant="secondary" onClick={() => openCreate(department)}>
                          <Plus className="size-4" aria-hidden="true" />
                          新建下级
                        </Button>
                      </PermissionGate>
                      <PermissionGate resource="system.departments" action="update">
                        <Button variant="secondary" onClick={() => openEdit(department)}>
                          <Edit3 className="size-4" aria-hidden="true" />
                          编辑
                        </Button>
                      </PermissionGate>
                      <PermissionGate resource="system.departments" action="delete">
                        <Button variant="danger" disabled={deleteDepartment.isPending} onClick={() => deleteDepartment.mutate(department)}>
                          <Trash2 className="size-4" aria-hidden="true" />
                          删除
                        </Button>
                      </PermissionGate>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </DataTable>
        ) : null}
      </Panel>

      <Modal title={editingDepartment ? '编辑部门' : '新建部门'} open={formOpen} onClose={closeForm}>
        {saveDepartment.error ? <ErrorNotice error={saveDepartment.error} fallback="无法保存部门" /> : null}
        <form className="space-y-3" onSubmit={(event) => event.preventDefault()}>
          <Field label="上级部门">
            <select className={inputClass} value={form.parent_id} onChange={(event) => setForm({ ...form, parent_id: event.target.value })}>
              <option value="">无上级部门</option>
              {parentOptions.map((department) => (
                <option value={department.id} key={department.id}>
                  {department.path}
                </option>
              ))}
            </select>
          </Field>
          <div className="grid gap-3 sm:grid-cols-2">
            <Field label="部门名称">
              <input className={inputClass} value={form.name} onChange={(event) => setForm({ ...form, name: event.target.value })} />
            </Field>
            <Field label="部门编码">
              <input className={inputClass} value={form.code} onChange={(event) => setForm({ ...form, code: event.target.value })} />
            </Field>
            <Field label="排序">
              <input className={inputClass} type="number" min="0" value={form.sort_order} onChange={(event) => setForm({ ...form, sort_order: event.target.value })} />
            </Field>
            <Field label="状态">
              <select className={inputClass} value={form.status} onChange={(event) => setForm({ ...form, status: event.target.value as Department['status'] })}>
                <option value="active">启用</option>
                <option value="disabled">禁用</option>
              </select>
            </Field>
          </div>
          <div className="flex justify-end gap-2 border-t border-slate-200 pt-3">
            <Button type="button" variant="ghost" onClick={closeForm}>
              取消
            </Button>
            <PermissionGate resource="system.departments" action={editingDepartment ? 'update' : 'create'}>
              <Button variant="primary" disabled={saveDepartment.isPending || form.name.trim() === '' || form.code.trim() === ''} onClick={() => saveDepartment.mutate()}>
                保存
              </Button>
            </PermissionGate>
          </div>
        </form>
      </Modal>
    </PageShell>
  )
}

function flattenDepartments(departments: Department[], depth = 0, parents: string[] = []): DepartmentRow[] {
  return departments.flatMap((department) => {
    const path = [...parents, department.name].join(' / ')
    const row = { ...department, depth, path }

    return [row, ...flattenDepartments(department.children ?? [], depth + 1, [...parents, department.name])]
  })
}

function blockedParentIds(department: Department): Set<number> {
  return new Set([department.id, ...flattenDepartments(department.children ?? []).map((child) => child.id)])
}

function departmentPayload(form: DepartmentForm) {
  return {
    parent_id: form.parent_id ? Number(form.parent_id) : null,
    name: form.name.trim(),
    code: form.code.trim(),
    sort_order: form.sort_order.trim() ? Number(form.sort_order) : 0,
    status: form.status,
  }
}
