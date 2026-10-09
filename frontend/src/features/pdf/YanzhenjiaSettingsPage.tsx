import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useEffect, useState, type FormEvent } from 'react'
import { PermissionGate } from '../../components/app/PermissionGate'
import { api } from '../../lib/api'
import { Button, DataTable, EmptyState, ErrorNotice, Field, LoadingState, PageShell, Panel } from '../system/shared'
import { formatDateTime, inputClass } from '../system/utils'
import { YanzhenjiaSyncStatus } from './YanzhenjiaSyncStatus'

type YanzhenjiaSettings = {
  enabled: boolean
  appid: string | null
  has_secret: boolean
  api_url: string
}

type SettingsResponse = { data: YanzhenjiaSettings }

type SettingsForm = {
  enabled: boolean
  appid: string
  secret: string
}

type RecentSync = {
  id: number
  api_version: string
  file_id: string | null
  file_name: string | null
  report_number: string | null
  sha256: string | null
  status: string
  source_deleted_at: string | null
  updated_at: string | null
}

type RecentSyncsResponse = { data: RecentSync[] }

const queryKey = ['pdf', 'yanzhenjia-settings'] as const

export function YanzhenjiaSettingsPage() {
  const queryClient = useQueryClient()
  const [form, setForm] = useState<SettingsForm>({ enabled: false, appid: '', secret: '' })
  const [saved, setSaved] = useState(false)

  const settingsQuery = useQuery({
    queryKey,
    queryFn: async () => {
      const response = await api.get<SettingsResponse>('/api/pdf/yanzhenjia-settings')
      return response.data.data
    },
    staleTime: 30_000,
    refetchOnWindowFocus: false,
  })

  const recentSyncsQuery = useQuery({
    queryKey: ['pdf', 'yanzhenjia-recent-syncs'],
    queryFn: async () => {
      const response = await api.get<RecentSyncsResponse>('/api/pdf/yanzhenjia-settings/recent-syncs')
      return response.data.data
    },
    staleTime: 10_000,
    refetchInterval: 30_000,
  })

  useEffect(() => {
    if (!settingsQuery.data) return

    setForm({
      enabled: settingsQuery.data.enabled,
      appid: settingsQuery.data.appid ?? '',
      secret: '',
    })
  }, [settingsQuery.data])

  const save = useMutation({
    mutationFn: async () => {
      const response = await api.put<SettingsResponse>('/api/pdf/yanzhenjia-settings', {
        enabled: form.enabled,
        appid: form.appid.trim() || null,
        ...(form.secret ? { secret: form.secret } : {}),
      })
      return response.data.data
    },
    onSuccess: async (settings) => {
      setForm({ enabled: settings.enabled, appid: settings.appid ?? '', secret: '' })
      setSaved(true)
      queryClient.setQueryData(queryKey, settings)
      await queryClient.invalidateQueries({ queryKey })
    },
  })

  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setSaved(false)
    save.mutate()
  }

  return (
    <PageShell
      title="验真家同步"
      description="启用后自动登记新签章报告的摘要和元数据；PDF 原件保留在本系统。"
    >
      {settingsQuery.isError ? <ErrorNotice error={settingsQuery.error} fallback="无法读取验真家同步配置" /> : null}
      {save.isError ? <ErrorNotice error={save.error} fallback="无法保存验真家同步配置" /> : null}

      <Panel
        title="API 接入"
        description="请先在验真家后台创建 API 应用，再将 AppID 与 Secret 填写在此处。"
      >
        {settingsQuery.isPending ? (
          <LoadingState label="正在加载验真家配置" />
        ) : settingsQuery.data ? (
          <div className="space-y-5">
            <div className="flex flex-wrap items-center gap-2 text-sm">
              <span className="font-medium text-slate-700">自动同步</span>
              <span className={`rounded-full px-2.5 py-1 text-xs font-medium ${settingsQuery.data.enabled ? 'bg-emerald-50 text-emerald-800' : 'bg-slate-100 text-slate-600'}`}>
                {settingsQuery.data.enabled ? '已启用' : '已停用'}
              </span>
              <span className="break-all text-xs text-slate-500">{settingsQuery.data.api_url}</span>
            </div>

            <PermissionGate
              resource="pdf_yanzhenjia_settings"
              action="update"
              fallback={<p className="text-sm text-slate-500">当前账号只有查看权限，无法修改 API 配置。</p>}
            >
              <form className="max-w-2xl space-y-5" onSubmit={submit}>
                <label className="flex min-h-11 items-center gap-3 rounded-md border border-emerald-900/10 bg-emerald-50/40 px-3 py-2">
                  <input
                    aria-label="启用验真家自动同步"
                    checked={form.enabled}
                    className="size-4 rounded border-slate-300 text-emerald-700 focus:ring-emerald-600"
                    onChange={(event) => setForm((current) => ({ ...current, enabled: event.target.checked }))}
                    type="checkbox"
                  />
                  <span className="text-sm font-medium text-slate-800">自动登记新签章报告</span>
                </label>

                <div className="grid gap-4 sm:grid-cols-2">
                  <Field label="AppID">
                    <input
                      autoComplete="off"
                      className={inputClass}
                      inputMode="text"
                      maxLength={32}
                      onChange={(event) => setForm((current) => ({ ...current, appid: event.target.value }))}
                      pattern="[A-Fa-f0-9]{32}"
                      placeholder="32 位十六进制 AppID"
                      required={form.enabled}
                      spellCheck={false}
                      value={form.appid}
                    />
                  </Field>
                  <Field label="Secret">
                    <input
                      autoComplete="new-password"
                      className={inputClass}
                      maxLength={4096}
                      onChange={(event) => setForm((current) => ({ ...current, secret: event.target.value }))}
                      placeholder={settingsQuery.data.has_secret ? '已保存；留空以保留当前 Secret' : '请输入验真家生成的 Secret'}
                      required={form.enabled && !settingsQuery.data.has_secret}
                      type="password"
                      value={form.secret}
                    />
                  </Field>
                </div>

                <p className="text-xs leading-5 text-slate-500">
                  Secret 加密保存，不回显；仅发送启用后新报告的摘要和元数据，PDF 原件不会上传。
                </p>

                <div className="flex flex-wrap items-center gap-3">
                  <Button disabled={save.isPending} type="submit" variant="primary">
                    {save.isPending ? '保存中…' : '保存配置'}
                  </Button>
                  {saved ? <span className="text-sm text-emerald-700">配置已保存</span> : null}
                </div>
              </form>
            </PermissionGate>
          </div>
        ) : null}
      </Panel>

      <Panel
        title="最近同步文件"
        description="按状态更新时间排序，显示最近 20 条。"
        actions={<Button variant="ghost" disabled={recentSyncsQuery.isFetching} onClick={() => recentSyncsQuery.refetch()}>刷新</Button>}
      >
        {recentSyncsQuery.isError ? <ErrorNotice error={recentSyncsQuery.error} fallback="无法读取最近同步文件" /> : null}

        {recentSyncsQuery.isPending ? (
          <LoadingState label="正在加载最近同步文件" />
        ) : recentSyncsQuery.data?.length === 0 ? (
          <EmptyState title="暂无同步记录" description="完成签章并启用自动登记后，文件会出现在这里。" />
        ) : recentSyncsQuery.data ? (
          <>
            <DataTable>
              <thead className="bg-slate-50 text-left text-xs font-medium text-slate-500">
                <tr>
                  <th className="px-3 py-2">文件名</th>
                  <th className="px-3 py-2">报告编号</th>
                  <th className="px-3 py-2">SHA-256</th>
                  <th className="px-3 py-2">状态</th>
                  <th className="px-3 py-2">更新时间</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {recentSyncsQuery.data.map((row) => (
                  <tr key={row.id}>
                    <td className="max-w-56 px-3 py-2 text-slate-900">
                      <p className="break-words font-medium">{row.file_name ?? '-'}</p>
                      <p className="mt-0.5 break-all font-mono text-xs text-slate-500">{row.file_id ?? '-'}</p>
                    </td>
                    <td className="px-3 py-2 text-slate-700">{row.report_number ?? '-'}</td>
                    <td className="min-w-72 max-w-96 break-all px-3 py-2 font-mono text-xs text-slate-600">{row.sha256 ?? '-'}</td>
                    <td className="px-3 py-2"><YanzhenjiaSyncStatus row={row} /></td>
                    <td className="whitespace-nowrap px-3 py-2 text-slate-700">{formatDateTime(row.updated_at)}</td>
                  </tr>
                ))}
              </tbody>
            </DataTable>

            <div className="space-y-2 md:hidden">
              {recentSyncsQuery.data.map((row) => (
                <article className="rounded-lg border border-emerald-900/10 p-3" key={row.id}>
                  <div className="flex items-start justify-between gap-3">
                    <div className="min-w-0">
                      <p className="break-words text-sm font-medium text-slate-900">{row.file_name ?? '-'}</p>
                      <p className="mt-0.5 break-all font-mono text-xs text-slate-500">{row.file_id ?? '-'}</p>
                    </div>
                    <YanzhenjiaSyncStatus row={row} />
                  </div>
                  <p className="mt-2 text-xs text-slate-700">报告编号：{row.report_number ?? '-'}</p>
                  <p className="mt-1 break-all font-mono text-[11px] text-slate-600">SHA-256：{row.sha256 ?? '-'}</p>
                  <p className="mt-2 text-xs text-slate-500">更新时间：{formatDateTime(row.updated_at)}</p>
                </article>
              ))}
            </div>
          </>
        ) : null}
      </Panel>
    </PageShell>
  )
}
