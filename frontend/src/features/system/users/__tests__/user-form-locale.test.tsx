import { createElement } from 'react'
import { renderToStaticMarkup } from 'react-dom/server'
import { describe, expect, it, vi } from 'vitest'
import { UserForm } from '../UserForm'

vi.mock('../../../auth/useCurrentUser', () => ({
  useEffectivePermissions: () => ({
    data: {
      resources: {
        'system.users': {
          fields: {
            phone: {
              update: false,
            },
          },
        },
      },
    },
  }),
}))

describe('user form locale', () => {
  it('renders create-user controls in Chinese', () => {
    const html = renderToStaticMarkup(
      createElement(UserForm, {
        groups: [{ id: 1, name: '系统管理员' }],
        departments: [{ id: 1, name: '检测部' }],
        submitting: false,
        error: null,
        onSubmit: async () => undefined,
        onCancel: () => undefined,
      }),
    )

    expect(html).toContain('初始密码')
    expect(html).toContain('必须修改密码')
    expect(html).toContain('无部门')
    expect(html).toContain('无更新权限')
    expect(html).toContain('角色组')
    expect(html).toContain('启用')
    expect(html).toContain('禁用')
    expect(html).toContain('锁定')
    expect(html).toContain('保存')
    expect(html).toContain('取消')
    expect(html).not.toContain('Initial password')
    expect(html).not.toContain('Must change password')
    expect(html).not.toContain('No department')
    expect(html).not.toContain('No update permission')
    expect(html).not.toContain('Groups')
    expect(html).not.toContain('>active<')
    expect(html).not.toContain('>disabled<')
    expect(html).not.toContain('>locked<')
  })
})
