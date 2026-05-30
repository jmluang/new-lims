import { createRootRoute, createRoute, Outlet, redirect } from '@tanstack/react-router'
import { DashboardPage } from '../features/dashboard/DashboardPage'
import { CustomerListPage } from '../features/customers/CustomerListPage'
import { CustomerFormPage } from '../features/customers/CustomerFormPage'
import { EquipmentLabelPrintPage } from '../features/equipment/EquipmentLabelPrintPage'
import { EquipmentFormPage } from '../features/equipment/EquipmentFormPage'
import { EquipmentListPage } from '../features/equipment/EquipmentListPage'
import { EquipmentLocationTreePage } from '../features/equipment/EquipmentLocationTreePage'
import { getAuthToken } from '../lib/api'
import { LoginPage } from '../features/auth/LoginPage'
import { StandardDetailPage } from '../features/standards/StandardDetailPage'
import { StandardFormPage } from '../features/standards/StandardFormPage'
import { StandardListPage } from '../features/standards/StandardListPage'
import { AuditLogListPage } from '../features/system/audit/AuditLogListPage'
import { BackupListPage } from '../features/system/backups/BackupListPage'
import { DictionaryListPage } from '../features/system/dictionaries/DictionaryListPage'
import { GroupListPage } from '../features/system/groups/GroupListPage'
import { UserFormPage } from '../features/system/users/UserFormPage'
import { UserListPage } from '../features/system/users/UserListPage'
import { ProtectedLayout } from './ProtectedLayout'
import { requireRoutePermission } from './routePermissions'

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

const systemUserCreateRoute = createRoute({
  getParentRoute: () => protectedRoute,
  path: '/system/users/new',
  beforeLoad: () => requireRoutePermission('system.users', 'create'),
  component: UserFormPage,
})

const systemUserEditRoute = createRoute({
  getParentRoute: () => protectedRoute,
  path: '/system/users/$userId/edit',
  beforeLoad: () => requireRoutePermission('system.users', 'update'),
  component: UserFormPage,
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

const standardsRoute = createRoute({
  getParentRoute: () => protectedRoute,
  path: '/standards',
  beforeLoad: () => requireRoutePermission('standards'),
  component: StandardListPage,
})

const standardCreateRoute = createRoute({
  getParentRoute: () => protectedRoute,
  path: '/standards/new',
  beforeLoad: () => requireRoutePermission('standards', 'create'),
  component: StandardFormPage,
})

const standardEditRoute = createRoute({
  getParentRoute: () => protectedRoute,
  path: '/standards/$standardId/edit',
  beforeLoad: () => requireRoutePermission('standards', 'update'),
  component: StandardFormPage,
})

const standardDetailRoute = createRoute({
  getParentRoute: () => protectedRoute,
  path: '/standards/$standardId',
  beforeLoad: () => requireRoutePermission('standards'),
  component: StandardDetailPage,
})

const customerCreateRoute = createRoute({
  getParentRoute: () => protectedRoute,
  path: '/customers/new',
  beforeLoad: () => requireRoutePermission('customers', 'create'),
  component: CustomerFormPage,
})

const customerEditRoute = createRoute({
  getParentRoute: () => protectedRoute,
  path: '/customers/$customerId/edit',
  beforeLoad: () => requireRoutePermission('customers', 'update'),
  component: CustomerFormPage,
})

const equipmentRoute = createRoute({
  getParentRoute: () => protectedRoute,
  path: '/equipment',
  beforeLoad: () => requireRoutePermission('equipment'),
  component: EquipmentListPage,
})

const equipmentCreateRoute = createRoute({
  getParentRoute: () => protectedRoute,
  path: '/equipment/new',
  beforeLoad: () => requireRoutePermission('equipment', 'create'),
  component: EquipmentFormPage,
})

const equipmentEditRoute = createRoute({
  getParentRoute: () => protectedRoute,
  path: '/equipment/$equipmentId/edit',
  beforeLoad: () => requireRoutePermission('equipment', 'update'),
  component: EquipmentFormPage,
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
    systemUserCreateRoute,
    systemUserEditRoute,
    systemGroupsRoute,
    systemDictionariesRoute,
    customersRoute,
    standardsRoute,
    standardCreateRoute,
    standardEditRoute,
    standardDetailRoute,
    customerCreateRoute,
    customerEditRoute,
    equipmentRoute,
    equipmentCreateRoute,
    equipmentEditRoute,
    equipmentLocationsRoute,
    equipmentLabelsRoute,
    auditLogsRoute,
    backupsRoute,
  ]),
])
