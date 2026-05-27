import { createRootRoute, createRoute, Outlet, redirect } from '@tanstack/react-router'
import { PlaceholderPage } from '../components/app/PlaceholderPage'
import { DashboardPage } from '../features/dashboard/DashboardPage'
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
  component: () => <PlaceholderPage title="Customers" resource="customers" />,
})

const equipmentRoute = createRoute({
  getParentRoute: () => protectedRoute,
  path: '/equipment',
  component: () => <PlaceholderPage title="Equipment" resource="equipment" />,
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
    auditLogsRoute,
    backupsRoute,
  ]),
])
