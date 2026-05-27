import { renderToStaticMarkup } from 'react-dom/server'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { PermissionGate } from '../../../components/app/PermissionGate'

type TestPermissions = {
  resources: Record<
    string,
    {
      actions: Record<string, boolean>
      fields: Record<string, Record<string, boolean>>
    }
  >
}

const permissionState = vi.hoisted((): { data?: TestPermissions } => ({}))

vi.mock('../../auth/useCurrentUser', () => ({
  useEffectivePermissions: () => permissionState,
}))

describe('PermissionGate', () => {
  beforeEach(() => {
    permissionState.data = {
      resources: {
        customers: {
          actions: {
            create: true,
            delete: false,
          },
          fields: {
            phone: {
              read: false,
              update: true,
            },
          },
        },
      },
    }
  })

  it('renders children when the resource action is granted', () => {
    const html = renderToStaticMarkup(
      <PermissionGate resource="customers" action="create">
        <button type="button">Create customer</button>
      </PermissionGate>,
    )

    expect(html).toContain('Create customer')
  })

  it('renders fallback when the resource action is denied', () => {
    const html = renderToStaticMarkup(
      <PermissionGate resource="customers" action="delete" fallback={<span>Denied</span>}>
        <button type="button">Delete customer</button>
      </PermissionGate>,
    )

    expect(html).toContain('Denied')
    expect(html).not.toContain('Delete customer')
  })

  it('uses field permissions when a field is provided', () => {
    const hiddenHtml = renderToStaticMarkup(
      <PermissionGate resource="customers" action="read" field="phone" fallback={<span>Phone hidden</span>}>
        <span>13800000000</span>
      </PermissionGate>,
    )

    const editableHtml = renderToStaticMarkup(
      <PermissionGate resource="customers" action="update" field="phone" fallback={<span>Phone readonly</span>}>
        <input defaultValue="13800000000" />
      </PermissionGate>,
    )

    expect(hiddenHtml).toContain('Phone hidden')
    expect(hiddenHtml).not.toContain('13800000000')
    expect(editableHtml).toContain('13800000000')
  })
})
