import { DataTable, EmptyState } from '../system/shared'
import type { StandardItem } from './StandardListPage'

export function StandardItemList({ items }: { items: StandardItem[] }) {
  if (items.length === 0) {
    return <EmptyState title="No test items" description="This standard has no test item rows." />
  }

  return (
    <>
      <DataTable>
        <thead className="bg-slate-50 text-left text-xs uppercase text-slate-500">
          <tr>
            <th className="px-3 py-2 font-medium">Item number</th>
            <th className="px-3 py-2 font-medium">Item name</th>
            <th className="px-3 py-2 font-medium">Requirement</th>
            <th className="px-3 py-2 font-medium">Unit</th>
            <th className="px-3 py-2 font-medium">Method</th>
          </tr>
        </thead>
        <tbody className="divide-y divide-slate-200">
          {items.map((item) => (
            <tr key={item.id}>
              <td className="px-3 py-3 text-sm font-medium text-slate-900">{item.item_no}</td>
              <td className="px-3 py-3 text-sm text-slate-700">{item.item_name}</td>
              <td className="whitespace-pre-wrap px-3 py-3 text-sm text-slate-700">{item.requirement ?? '-'}</td>
              <td className="px-3 py-3 text-sm text-slate-700">{item.unit ?? '-'}</td>
              <td className="px-3 py-3 text-sm text-slate-700">{item.method ?? '-'}</td>
            </tr>
          ))}
        </tbody>
      </DataTable>
      <div className="space-y-3 md:hidden">
        {items.map((item) => (
          <article className="rounded-lg border border-slate-200 bg-white p-4 shadow-sm" key={item.id}>
            <h3 className="text-sm font-semibold text-slate-950">
              {item.item_no} {item.item_name}
            </h3>
            <p className="mt-1 whitespace-pre-wrap text-xs text-slate-500">{item.requirement ?? '-'}</p>
          </article>
        ))}
      </div>
    </>
  )
}
