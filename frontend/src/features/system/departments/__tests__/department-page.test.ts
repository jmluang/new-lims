import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'

const departmentPageSource = readFileSync(
  fileURLToPath(new URL('../DepartmentListPage.tsx', import.meta.url)),
  'utf8',
)
const routesSource = readFileSync(fileURLToPath(new URL('../../../../app/routes.tsx', import.meta.url)), 'utf8')

describe('department management page', () => {
  it('is registered as a protected system route', () => {
    expect(routesSource).toContain("path: '/system/departments'")
    expect(routesSource).toContain("requireRoutePermission('system.departments')")
    expect(routesSource).toContain('component: DepartmentListPage')
  })

  it('uses Chinese form item labels in the department editor', () => {
    expect(departmentPageSource).toContain('<Field label="上级部门">')
    expect(departmentPageSource).toContain('<Field label="部门名称">')
    expect(departmentPageSource).toContain('<Field label="部门编码">')
    expect(departmentPageSource).toContain('<Field label="排序">')
    expect(departmentPageSource).toContain('<Field label="状态">')
    expect(departmentPageSource).not.toContain('<Field label="Name">')
    expect(departmentPageSource).not.toContain('<Field label="Code">')
    expect(departmentPageSource).not.toContain('<Field label="Status">')
  })
})
