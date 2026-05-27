import { createRootRoute, createRoute, Outlet, redirect } from '@tanstack/react-router'
import { PlaceholderPage } from '../components/app/PlaceholderPage'
import { DashboardPage } from '../features/dashboard/DashboardPage'
import { getAuthToken } from '../lib/api'
import { LoginPage } from '../features/auth/LoginPage'

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
  component: () => <PlaceholderPage title="System Management" resource="system.users" />,
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
  component: () => <PlaceholderPage title="Audit Logs" resource="system.audit_logs" />,
})

const backupsRoute = createRoute({
  getParentRoute: () => protectedRoute,
  path: '/backups',
  component: () => <PlaceholderPage title="Backups" resource="system.backups" />,
})

export const routeTree = rootRoute.addChildren([
  loginRoute,
  protectedRoute.addChildren([indexRoute, systemRoute, customersRoute, equipmentRoute, auditLogsRoute, backupsRoute]),
])
