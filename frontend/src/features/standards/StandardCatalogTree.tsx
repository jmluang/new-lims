import { EmptyState } from '../system/shared'
import type { StandardCatalog } from './StandardListPage'

export function StandardCatalogTree({ catalogs }: { catalogs: StandardCatalog[] }) {
  if (catalogs.length === 0) {
    return <EmptyState title="No catalogs" description="This standard has no catalog rows." />
  }

  const byParent = new Map<number | null, StandardCatalog[]>()

  catalogs.forEach((catalog) => {
    const key = catalog.parent_id ?? null
    byParent.set(key, [...(byParent.get(key) ?? []), catalog])
  })

  return <div className="space-y-2">{renderCatalogs(byParent, null, 0)}</div>
}

function renderCatalogs(byParent: Map<number | null, StandardCatalog[]>, parentId: number | null, level: number) {
  return (byParent.get(parentId) ?? []).map((catalog) => (
    <div className="rounded-md border border-slate-200 bg-white p-3" style={{ marginLeft: level * 16 }} key={catalog.id}>
      <div className="flex items-start justify-between gap-3">
        <div>
          <div className="text-sm font-semibold text-slate-900">
            {catalog.code} {catalog.name}
          </div>
          {catalog.content ? <p className="mt-1 whitespace-pre-wrap text-sm text-slate-600">{catalog.content}</p> : null}
        </div>
        <span className="text-xs text-slate-500">#{catalog.sort_order}</span>
      </div>
      {renderCatalogs(byParent, catalog.id, level + 1)}
    </div>
  ))
}
