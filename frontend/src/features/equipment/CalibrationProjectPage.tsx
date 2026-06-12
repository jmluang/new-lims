import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Edit3, Plus, Printer, Search } from 'lucide-react'
import { useEffect, useState } from 'react'
import { PermissionGate } from '../../components/app/PermissionGate'
import { api } from '../../lib/api'
import { zhText } from '../../lib/zh'
import { Button, DataTable, EmptyState, ErrorNotice, Field, LoadingState, Modal, PageShell, Panel, StatusBadge } from '../system/shared'
import { inputClass, textareaClass } from '../system/utils'
import {
  CalibrationProjectLabelPrintArea,
  CalibrationProjectLabelPrintStyles,
  calibrationProjectLabelSpec,
  type CalibrationProjectLabelPreview,
} from './CalibrationProjectLabelPrintArea'

type CalibrationProject = {
  id: number
  project_no: string
  project_name: string
  status: string
  sort_order: number
  remark?: string | null
}

type ProjectForm = {
  project_no: string
  project_name: string
  sort_order: string
  remark: string
}

const emptyForm: ProjectForm = {
  project_no: '',
  project_name: '',
  sort_order: '0',
  remark: '',
}

export function CalibrationProjectPage() {
  const queryClient = useQueryClient()
  const [search, setSearch] = useState('')
  const [selectedIds, setSelectedIds] = useState<number[]>([])
  const [editing, setEditing] = useState<CalibrationProject | null>(null)
  const [modalOpen, setModalOpen] = useState(false)
  const [form, setForm] = useState<ProjectForm>(emptyForm)
  const [printLabels, setPrintLabels] = useState<CalibrationProjectLabelPreview[]>([])
  const [shouldPrint, setShouldPrint] = useState(false)

  const projectsQuery = useQuery({
    queryKey: ['calibration-projects', search],
    queryFn: async () => {
      const response = await api.get<{ data: CalibrationProject[] }>('/api/calibration-projects', { params: search ? { search } : {} })

      return response.data.data
    },
  })

  const saveProject = useMutation({
    mutationFn: async () => {
      const payload = {
        project_no: form.project_no,
        project_name: form.project_name,
        sort_order: Number(form.sort_order) || 0,
        remark: form.remark || null,
      }

      if (editing) {
        await api.put(`/api/calibration-projects/${editing.id}`, payload)
      } else {
        await api.post('/api/calibration-projects', payload)
      }
    },
    onSuccess: async () => {
      setModalOpen(false)
      setEditing(null)
      setForm(emptyForm)
      await queryClient.invalidateQueries({ queryKey: ['calibration-projects'] })
    },
  })

  const disableProject = useMutation({
    mutationFn: async (project: CalibrationProject) => {
      await api.delete(`/api/calibration-projects/${project.id}`)
    },
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['calibration-projects'] })
    },
  })

  const previewLabels = useMutation({
    mutationFn: async (projectIds: number[]) => {
      const response = await api.post<{ data: CalibrationProjectLabelPreview[] }>('/api/calibration-project-labels/preview', {
        project_ids: projectIds,
        label_width_mm: calibrationProjectLabelSpec.widthMm,
        label_height_mm: calibrationProjectLabelSpec.heightMm,
      })

      return response.data.data
    },
    onSuccess: (labels) => {
      setPrintLabels(labels)
      setShouldPrint(true)
    },
  })

  const projects = projectsQuery.data ?? []

  useEffect(() => {
    if (!shouldPrint || printLabels.length === 0) {
      return
    }

    const timeout = window.setTimeout(() => {
      window.print()
      setShouldPrint(false)
    })

    return () => window.clearTimeout(timeout)
  }, [printLabels, shouldPrint])

  function openCreate() {
    setEditing(null)
    setForm(emptyForm)
    setModalOpen(true)
  }

  function openEdit(project: CalibrationProject) {
    setEditing(project)
    setForm({
      project_no: project.project_no,
      project_name: project.project_name,
      sort_order: String(project.sort_order),
      remark: project.remark ?? '',
    })
    setModalOpen(true)
  }

  function toggleSelected(id: number) {
    setSelectedIds((current) => (current.includes(id) ? current.filter((value) => value !== id) : [...current, id]))
  }

  return (
    <PageShell
      title="Calibration projects"
      description="Maintain calibration project catalog and print project labels."
      actions={
        <>
          <PermissionGate resource="calibration_project_labels" action="print">
            <Button variant="secondary" disabled={selectedIds.length === 0} onClick={() => previewLabels.mutate(selectedIds)}>
              <Printer className="size-4" aria-hidden="true" />
              {`${zhText('定标项目标签') ?? '定标项目标签'} (${selectedIds.length})`}
            </Button>
          </PermissionGate>
          <PermissionGate resource="calibration_projects" action="create">
            <Button variant="primary" onClick={openCreate}>
              <Plus className="size-4" aria-hidden="true" />
              {zhText('新建定标项目')}
            </Button>
          </PermissionGate>
        </>
      }
    >
      <CalibrationProjectLabelPrintStyles />
      <Panel title="Filters">
        <div className="grid gap-3 md:grid-cols-3">
          <Field label="Search">
            <div className="relative">
              <Search className="pointer-events-none absolute left-3 top-2.5 size-4 text-slate-400" aria-hidden="true" />
              <input className={`${inputClass} pl-9`} value={search} onChange={(event) => setSearch(event.target.value)} placeholder="编号/名称" />
            </div>
          </Field>
        </div>
      </Panel>

      {projectsQuery.isError ? <ErrorNotice error={projectsQuery.error} fallback="无法加载定标项目" /> : null}
      {saveProject.error ? <ErrorNotice error={saveProject.error} fallback="无法保存定标项目" /> : null}
      {disableProject.error ? <ErrorNotice error={disableProject.error} fallback="无法停用定标项目" /> : null}
      {previewLabels.error ? <ErrorNotice error={previewLabels.error} fallback="无法生成定标项目标签" /> : null}
      {projectsQuery.isPending ? <LoadingState label="正在加载定标项目" /> : null}
      {!projectsQuery.isPending && projects.length === 0 ? <EmptyState title="暂无定标项目" description="新建定标项目后会显示在此处。" /> : null}

      {projects.length > 0 ? (
        <DataTable>
          <thead className="bg-slate-50 text-left text-xs uppercase text-slate-500">
            <tr>
              <th className="px-3 py-2 font-medium">选择</th>
              <th className="px-3 py-2 font-medium">项目编号</th>
              <th className="px-3 py-2 font-medium">项目名称</th>
              <th className="px-3 py-2 font-medium">状态</th>
              <th className="px-3 py-2 font-medium">排序</th>
              <th className="px-3 py-2 font-medium">操作</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-200">
            {projects.map((project) => (
              <tr key={project.id}>
                <td className="px-3 py-3">
                  <input className="size-4 rounded border-slate-300 text-emerald-600" type="checkbox" checked={selectedIds.includes(project.id)} onChange={() => toggleSelected(project.id)} />
                </td>
                <td className="px-3 py-3 text-sm font-medium text-slate-900">{project.project_no}</td>
                <td className="px-3 py-3 text-sm text-slate-700">{project.project_name}</td>
                <td className="px-3 py-3 text-sm">
                  <StatusBadge status={project.status} />
                </td>
                <td className="px-3 py-3 text-sm text-slate-700">{project.sort_order}</td>
                <td className="px-3 py-3">
                  <div className="flex flex-wrap gap-2">
                    <PermissionGate resource="calibration_projects" action="update">
                      <Button variant="secondary" onClick={() => openEdit(project)}>
                        <Edit3 className="size-4" aria-hidden="true" />
                        编辑
                      </Button>
                    </PermissionGate>
                    <PermissionGate resource="calibration_projects" action="delete">
                      <Button variant="danger" disabled={project.status === 'disabled' || disableProject.isPending} onClick={() => disableProject.mutate(project)}>
                        停用
                      </Button>
                    </PermissionGate>
                  </div>
                </td>
              </tr>
            ))}
          </tbody>
        </DataTable>
      ) : null}

      <Modal
        title={editing ? '编辑定标项目' : '新建定标项目'}
        open={modalOpen}
        onClose={() => {
          setModalOpen(false)
          setEditing(null)
          saveProject.reset()
        }}
      >
        <div className="space-y-3">
          <div className="grid gap-3 md:grid-cols-2">
            <Field label="项目编号">
              <input className={inputClass} value={form.project_no} onChange={(event) => setForm({ ...form, project_no: event.target.value })} />
            </Field>
            <Field label="项目名称">
              <input className={inputClass} value={form.project_name} onChange={(event) => setForm({ ...form, project_name: event.target.value })} />
            </Field>
            <Field label="排序">
              <input className={inputClass} type="number" value={form.sort_order} onChange={(event) => setForm({ ...form, sort_order: event.target.value })} />
            </Field>
          </div>
          <Field label="备注">
            <textarea className={textareaClass} value={form.remark} onChange={(event) => setForm({ ...form, remark: event.target.value })} />
          </Field>
          {saveProject.error ? <ErrorNotice error={saveProject.error} fallback="无法保存定标项目" /> : null}
          <div className="flex justify-end gap-2">
            <Button variant="ghost" onClick={() => setModalOpen(false)}>
              取消
            </Button>
            <Button variant="primary" onClick={() => saveProject.mutate()} disabled={saveProject.isPending}>
              保存
            </Button>
          </div>
        </div>
      </Modal>

      <CalibrationProjectLabelPrintArea labels={printLabels} screenHidden />
    </PageShell>
  )
}
