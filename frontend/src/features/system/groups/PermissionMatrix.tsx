import { Save } from 'lucide-react'
import { useMemo, useState } from 'react'
import { Button } from '../shared'
import { fieldPermissionName, resourcePermissionName } from './permissionNames'

export type PermissionCatalog = {
  resources: Record<
    string,
    {
      actions: string[]
      fields: Record<string, string[]>
    }
  >
}

export function PermissionMatrix({
  catalog,
  selectedPermissions,
  saving,
  onSave,
}: {
  catalog: PermissionCatalog
  selectedPermissions: string[]
  saving: boolean
  onSave: (permissions: string[]) => void
}) {
  const [selected, setSelected] = useState(() => new Set(selectedPermissions))
  const resources = useMemo(() => Object.entries(catalog.resources), [catalog.resources])

  function toggle(permission: string) {
    const next = new Set(selected)

    if (next.has(permission)) {
      next.delete(permission)
    } else {
      next.add(permission)
    }

    setSelected(next)
  }

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between gap-3">
        <div className="text-xs text-slate-500">{selected.size} permissions selected</div>
        <Button variant="primary" disabled={saving} onClick={() => onSave(Array.from(selected).sort())}>
          <Save className="size-4" aria-hidden="true" />
          Save matrix
        </Button>
      </div>

      <div className="space-y-3">
        {resources.map(([resource, config]) => (
          <section className="rounded-md border border-slate-200 bg-white" key={resource}>
            <div className="border-b border-slate-200 bg-slate-50 px-3 py-2 text-sm font-semibold text-slate-900">{resource}</div>
            <div className="p-3">
              <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                {config.actions.map((action) => {
                  const permission = resourcePermissionName(resource, action)
                  return (
                    <PermissionCheckbox
                      label={action}
                      permission={permission}
                      selected={selected.has(permission)}
                      onToggle={toggle}
                      key={permission}
                    />
                  )
                })}
              </div>

              {Object.keys(config.fields).length > 0 ? (
                <div className="mt-4 space-y-2">
                  <div className="text-xs font-medium uppercase text-slate-500">Fields</div>
                  {Object.entries(config.fields).map(([field, actions]) => (
                    <div className="grid gap-2 rounded-md border border-slate-100 p-2 md:grid-cols-[160px_minmax(0,1fr)]" key={field}>
                      <div className="text-sm font-medium text-slate-700">{field}</div>
                      <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                        {actions.map((action) => {
                          const permission = fieldPermissionName(resource, field, action)
                          return (
                            <PermissionCheckbox
                              label={action}
                              permission={permission}
                              selected={selected.has(permission)}
                              onToggle={toggle}
                              key={permission}
                            />
                          )
                        })}
                      </div>
                    </div>
                  ))}
                </div>
              ) : null}
            </div>
          </section>
        ))}
      </div>
    </div>
  )
}

function PermissionCheckbox({
  label,
  permission,
  selected,
  onToggle,
}: {
  label: string
  permission: string
  selected: boolean
  onToggle: (permission: string) => void
}) {
  return (
    <label className="flex min-h-9 items-center gap-2 rounded-md border border-slate-200 px-3 py-2 text-sm text-slate-700">
      <input
        className="size-4 rounded border-slate-300 text-emerald-600"
        type="checkbox"
        checked={selected}
        onChange={() => onToggle(permission)}
      />
      <span className="truncate" title={permission}>
        {label}
      </span>
    </label>
  )
}
