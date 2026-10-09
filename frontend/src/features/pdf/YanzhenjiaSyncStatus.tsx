type SyncStatusRow = {
  status: string
  source_deleted_at: string | null
  api_version: string
}

const syncStatus: Record<string, { label: string; className: string }> = {
  pending: { label: '待同步', className: 'bg-slate-100 text-slate-700' },
  queued: { label: '排队中', className: 'bg-blue-50 text-blue-700' },
  running: { label: '同步中', className: 'bg-blue-50 text-blue-700' },
  succeeded: { label: '成功', className: 'bg-emerald-50 text-emerald-700' },
  failed: { label: '失败', className: 'bg-red-50 text-red-700' },
}

export function YanzhenjiaSyncStatus({ row }: { row: SyncStatusRow }) {
  let status = syncStatus[row.status] ?? { label: row.status, className: 'bg-slate-100 text-slate-700' }
  let deletionDetail = '源文件已删除'

  // Deletion stops scheduling and retries, but cannot recall an HTTP request
  // already in flight. Preserve its eventual confirmed success if it arrives.
  if (row.source_deleted_at) {
    if (row.status === 'pending' || row.status === 'queued') {
      status = { label: '已取消', className: 'bg-slate-100 text-slate-700' }
    } else if (row.status === 'running' || row.status === 'failed') {
      status = { label: '已停止重试', className: 'bg-slate-100 text-slate-700' }
      deletionDetail = '源文件已删除；远端结果未确认'
    }
  }

  return (
    <div className="space-y-1">
      <span className={`inline-flex rounded-full px-2 py-0.5 text-xs font-medium ${status.className}`}>{status.label}</span>
      {row.source_deleted_at ? <p className="text-xs text-amber-700">{deletionDetail}</p> : null}
      <p className="text-xs text-slate-500">{row.api_version === 'v1' ? '公司 API' : '旧接口'}</p>
    </div>
  )
}
