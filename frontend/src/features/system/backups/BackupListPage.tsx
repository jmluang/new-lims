import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { DatabaseBackup, RotateCcw } from 'lucide-react'
import { useState } from 'react'
import { PermissionGate } from '../../../components/app/PermissionGate'
import { api } from '../../../lib/api'
import {
  Button,
  DataTable,
  EmptyState,
  ErrorNotice,
  LoadingState,
  PageShell,
  PaginationControls,
  Panel,
  StatusBadge,
} from '../shared'
import { type ApiCollection, formatBytes, formatDateTime, paginationParams } from '../utils'

type BackupRun = {
  id: number
  type: string
  status: string
  database_path?: string | null
  files_path?: string | null
  size_bytes?: number | null
  error_message?: string | null
  started_at?: string | null
  finished_at?: string | null
}

export function BackupListPage() {
  const queryClient = useQueryClient()
  const [page, setPage] = useState(1)
  const [perPage, setPerPage] = useState(15)
  const backupsQuery = useQuery({
    queryKey: ['backups', page, perPage],
    queryFn: async () => {
      const response = await api.get<ApiCollection<BackupRun>>('/api/backups', { params: paginationParams(page, perPage) })

      return response.data
    },
  })
  const runBackup = useMutation({
    mutationFn: async () => {
      await api.post('/api/backups', { type: 'manual' })
    },
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['backups'] }),
  })
  const restoreBackup = useMutation({
    mutationFn: async (backup: BackupRun) => {
      await api.post(`/api/backups/${backup.id}/restore`)
    },
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['backups'] }),
  })
  const backups = backupsQuery.data?.data ?? []

  return (
    <PageShell
      title="Backup Runs"
      description="Manual backup run history, generated artifacts and permission-gated restore actions."
      actions={
        <PermissionGate resource="system.backups" action="create">
          <Button variant="primary" disabled={runBackup.isPending} onClick={() => runBackup.mutate()}>
            <DatabaseBackup className="size-4" aria-hidden="true" />
            Run backup
          </Button>
        </PermissionGate>
      }
    >
      {runBackup.error ? <ErrorNotice error={runBackup.error} fallback="Unable to start backup" /> : null}
      {restoreBackup.error ? <ErrorNotice error={restoreBackup.error} fallback="Unable to restore backup" /> : null}
      {backupsQuery.isPending ? <LoadingState label="Loading backup runs" /> : null}
      {backupsQuery.isError ? <ErrorNotice error={backupsQuery.error} fallback="Unable to load backups" /> : null}
      {!backupsQuery.isPending && backups.length === 0 ? (
        <EmptyState title="No backup runs" description="Run the first manual backup to create a recorded checkpoint." />
      ) : null}

      <DataTable>
        <thead className="bg-slate-50 text-left text-xs uppercase text-slate-500">
          <tr>
            <th className="px-3 py-2 font-medium">Run</th>
            <th className="px-3 py-2 font-medium">Status</th>
            <th className="px-3 py-2 font-medium">Artifacts</th>
            <th className="px-3 py-2 font-medium">Size</th>
            <th className="px-3 py-2 font-medium">Time</th>
            <th className="px-3 py-2 font-medium">Actions</th>
          </tr>
        </thead>
        <tbody className="divide-y divide-slate-200">
          {backups.map((backup) => (
            <tr key={backup.id}>
              <td className="px-3 py-3">
                <div className="font-medium text-slate-900">#{backup.id}</div>
                <div className="text-xs text-slate-500">{backup.type}</div>
              </td>
              <td className="px-3 py-3">
                <StatusBadge status={backup.status} />
                {backup.error_message ? <div className="mt-1 text-xs text-red-600">{backup.error_message}</div> : null}
              </td>
              <td className="px-3 py-3 text-xs text-slate-600">
                <div>{backup.database_path ?? '-'}</div>
                <div>{backup.files_path ?? '-'}</div>
              </td>
              <td className="px-3 py-3 text-slate-700">{formatBytes(backup.size_bytes)}</td>
              <td className="px-3 py-3 text-xs text-slate-600">
                <div>{formatDateTime(backup.started_at)}</div>
                <div>{formatDateTime(backup.finished_at)}</div>
              </td>
              <td className="px-3 py-3">
                <RestoreButton backup={backup} pending={restoreBackup.isPending} onRestore={(target) => restoreBackup.mutate(target)} />
              </td>
            </tr>
          ))}
        </tbody>
      </DataTable>

      <div className="space-y-3 md:hidden">
        {backups.map((backup) => (
          <Panel title={`Backup #${backup.id}`} description={backup.type} key={backup.id}>
            <div className="grid grid-cols-2 gap-3 text-sm">
              <div>
                <div className="text-xs text-slate-500">Status</div>
                <StatusBadge status={backup.status} />
              </div>
              <div>
                <div className="text-xs text-slate-500">Size</div>
                <div className="font-medium text-slate-900">{formatBytes(backup.size_bytes)}</div>
              </div>
              <div className="col-span-2">
                <div className="text-xs text-slate-500">Started</div>
                <div className="font-medium text-slate-900">{formatDateTime(backup.started_at)}</div>
              </div>
              <div className="col-span-2">
                <RestoreButton backup={backup} pending={restoreBackup.isPending} onRestore={(target) => restoreBackup.mutate(target)} />
              </div>
            </div>
          </Panel>
        ))}
      </div>

      <PaginationControls
        meta={backupsQuery.data?.meta}
        page={page}
        perPage={perPage}
        onPageChange={setPage}
        onPerPageChange={(nextPerPage) => {
          setPerPage(nextPerPage)
          setPage(1)
        }}
      />
    </PageShell>
  )
}

function RestoreButton({ backup, pending, onRestore }: { backup: BackupRun; pending: boolean; onRestore: (backup: BackupRun) => void }) {
  return (
    <PermissionGate resource="system.backups" action="restore">
      <Button variant="secondary" disabled={pending || backup.status !== 'succeeded'} onClick={() => onRestore(backup)}>
        <RotateCcw className="size-4" aria-hidden="true" />
        Restore
      </Button>
    </PermissionGate>
  )
}
