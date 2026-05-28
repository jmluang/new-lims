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
import { AppLayout } from '../components/app/AppLayout'
import { requireRoutePermission } from './routePermissions'

function ProtectedLayout() {
  return (
    <AppLayout>
      <Outlet />
    </AppLayout>
  )
}

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
  component: ProtectedLayout,
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
  beforeLoad: () => requireRoutePermission('system.users'),
  component: UserListPage,
})

const systemGroupsRoute = createRoute({
  getParentRoute: () => protectedRoute,
  path: '/system/groups',
  beforeLoad: () => requireRoutePermission('system.groups'),
  component: GroupListPage,
})

const systemDictionariesRoute = createRoute({
  getParentRoute: () => protectedRoute,
  path: '/system/dictionaries',
  beforeLoad: () => requireRoutePermission('system.dictionaries'),
  component: DictionaryListPage,
})

const customersRoute = createRoute({
  getParentRoute: () => protectedRoute,
  path: '/customers',
  beforeLoad: () => requireRoutePermission('customers'),
  component: CustomerListPage,
})

const equipmentRoute = createRoute({
  getParentRoute: () => protectedRoute,
  path: '/equipment',
  beforeLoad: () => requireRoutePermission('equipment'),
  component: EquipmentListPage,
})

const equipmentLocationsRoute = createRoute({
  getParentRoute: () => protectedRoute,
  path: '/equipment/locations',
  beforeLoad: () => requireRoutePermission('equipment_locations'),
  component: EquipmentLocationTreePage,
})

const equipmentLabelsRoute = createRoute({
  getParentRoute: () => protectedRoute,
  path: '/equipment/labels',
  beforeLoad: () => requireRoutePermission('equipment_labels', 'print'),
  component: EquipmentLabelPrintPage,
})

const auditLogsRoute = createRoute({
  getParentRoute: () => protectedRoute,
  path: '/audit-logs',
  beforeLoad: () => requireRoutePermission('system.audit_logs'),
  component: AuditLogListPage,
})

const backupsRoute = createRoute({
  getParentRoute: () => protectedRoute,
  path: '/backups',
  beforeLoad: () => requireRoutePermission('system.backups'),
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
