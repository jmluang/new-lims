import { createRootRoute, createRoute, Outlet, redirect } from '@tanstack/react-router'
import { DashboardPage } from '../features/dashboard/DashboardPage'
import { CustomerListPage } from '../features/customers/CustomerListPage'
import { EquipmentLabelPrintPage } from '../features/equipment/EquipmentLabelPrintPage'
import { EquipmentListPage } from '../features/equipment/EquipmentListPage'
import { EquipmentLocationTreePage } from '../features/equipment/EquipmentLocationTreePage'
import { getAuthToken } from '../lib/api'
import { LoginPage } from '../features/auth/LoginPage'
import { AuditLogListPage } from '../features/system/audit/AuditLogListPage'
import { BackupListPage } from '../features/system/backups/BackupListPage'
import { DictionaryListPage } from '../features/system/dictionaries/DictionaryListPage'
import { GroupListPage } from '../features/system/groups/GroupListPage'
import { UserListPage } from '../features/system/users/UserListPage'

export const rootRoute = createRootRoute({
  component: Outlet,
})

const protectedRoute = createRoute({
  getParentRoute: () => rootRoute,
  id: 'protected',
  beforeLoad: () => {
    if (!getAuthToken()) {
      throw redirect({ to: '/login' })
    }
  },
  component: Outlet,
})

export const indexRoute = createRoute({
  getParentRoute: () => protectedRoute,
  path: '/',
  component: DashboardPage,
})

export const loginRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/login',
  component: LoginPage,
})

const systemRoute = createRoute({
  getParentRoute: () => protectedRoute,
  path: '/system',
  component: UserListPage,
})

const systemGroupsRoute = createRoute({
  getParentRoute: () => protectedRoute,
  path: '/system/groups',
  component: GroupListPage,
})

const systemDictionariesRoute = createRoute({
  getParentRoute: () => protectedRoute,
  path: '/system/dictionaries',
  component: DictionaryListPage,
})

const customersRoute = createRoute({
  getParentRoute: () => protectedRoute,
  path: '/customers',
  component: CustomerListPage,
})

const equipmentRoute = createRoute({
  getParentRoute: () => protectedRoute,
  path: '/equipment',
  component: EquipmentListPage,
})

const equipmentLocationsRoute = createRoute({
  getParentRoute: () => protectedRoute,
  path: '/equipment/locations',
  component: EquipmentLocationTreePage,
})

const equipmentLabelsRoute = createRoute({
  getParentRoute: () => protectedRoute,
  path: '/equipment/labels',
  component: EquipmentLabelPrintPage,
})

const auditLogsRoute = createRoute({
  getParentRoute: () => protectedRoute,
  path: '/audit-logs',
  component: AuditLogListPage,
})

const backupsRoute = createRoute({
  getParentRoute: () => protectedRoute,
  path: '/backups',
  component: BackupListPage,
})

export const routeTree = rootRoute.addChildren([
  loginRoute,
  protectedRoute.addChildren([
    indexRoute,
    systemRoute,
    systemGroupsRoute,
    systemDictionariesRoute,
    customersRoute,
    equipmentRoute,
    equipmentLocationsRoute,
    equipmentLabelsRoute,
    auditLogsRoute,
    backupsRoute,
  ]),
])
