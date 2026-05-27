import { PermissionGate } from './PermissionGate'
import { AppLayout } from './AppLayout'

export function PlaceholderPage({ title, resource }: { title: string; resource?: string }) {
  return (
    <AppLayout>
      <section className="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
        <h1 className="text-lg font-semibold text-slate-950">{title}</h1>
        <p className="mt-2 max-w-2xl text-sm leading-6 text-slate-500">
          This workspace is ready for the next implementation task. The API foundation, permissions, and audit hooks are already wired.
        </p>
        {resource ? (
          <div className="mt-4">
            <PermissionGate resource={resource} action="create">
              <button className="rounded-md bg-emerald-600 px-3 py-2 text-sm font-medium text-white">Create</button>
            </PermissionGate>
          </div>
        ) : null}
      </section>
    </AppLayout>
  )
}
