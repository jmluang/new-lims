import { useQuery } from '@tanstack/react-query'
import { Link, useRouterState } from '@tanstack/react-router'
import { ArrowLeft } from 'lucide-react'
import { api } from '../../lib/api'
import { zhText } from '../../lib/zh'
import { ErrorNotice, LoadingState, PageShell, Panel, StatusBadge } from '../system/shared'
import type { ApiResource } from '../system/utils'
import { StandardCatalogTree } from './StandardCatalogTree'
import { StandardItemList } from './StandardItemList'
import type { Standard } from './StandardListPage'

export function StandardDetailPage() {
  const pathname = useRouterState({ select: (state) => state.location.pathname })
  const standardId = standardIdFromPath(pathname)
  const standardQuery = useQuery({
    queryKey: ['standard', standardId],
    enabled: standardId !== null,
    queryFn: async () => {
      const response = await api.get<ApiResource<Standard>>(`/api/standards/${standardId}`)

      return response.data.data
    },
  })
  const standard = standardQuery.data

  return (
    <PageShell
      title="Standard detail"
      description="Review lifecycle metadata, catalog rows and test items."
      actions={
        <Link className="inline-flex h-9 items-center justify-center gap-2 rounded-md px-3 text-sm font-medium text-slate-600 hover:bg-slate-100" to="/standards">
          <ArrowLeft className="size-4" aria-hidden="true" />
          返回列表
        </Link>
      }
    >
      {standardQuery.isError ? <ErrorNotice error={standardQuery.error} fallback="Unable to load standard" /> : null}
      {standardQuery.isPending ? <LoadingState label="Loading standard" /> : null}
      {standard ? (
        <div className="space-y-4">
          <Panel title={standard.std_no} description={standard.chinese_name}>
            <dl className="grid gap-3 text-sm sm:grid-cols-3">
              <div>
                <dt className="text-xs font-medium uppercase text-slate-500">{zhText('Status')}</dt>
                <dd className="mt-1">
                  <StatusBadge status={standard.status} />
                </dd>
              </div>
              <Detail label="Publish date" value={standard.publish_date} />
              <Detail label="Implement date" value={standard.implement_date} />
              <Detail label="Category" value={standard.category} />
              <Detail label="Language" value={standard.language} />
              <Detail label="Corresponding standard" value={standard.corresponding_std} />
            </dl>
          </Panel>
          <Panel title="Catalogs">
            <StandardCatalogTree catalogs={standard.catalogs ?? []} />
          </Panel>
          <Panel title="Test items">
            <StandardItemList items={standard.items ?? []} />
          </Panel>
        </div>
      ) : null}
    </PageShell>
  )
}

function Detail({ label, value }: { label: string; value?: string | null }) {
  return (
    <div>
      <dt className="text-xs font-medium uppercase text-slate-500">{label}</dt>
      <dd className="mt-1 text-slate-900">{value || '-'}</dd>
    </div>
  )
}

function standardIdFromPath(pathname: string) {
  const match = pathname.match(/^\/standards\/(\d+)$/)

  return match ? Number(match[1]) : null
}
