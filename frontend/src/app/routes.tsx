import { createRootRoute, createRoute, Outlet, redirect } from '@tanstack/react-router'
import { DashboardPage } from '../features/dashboard/DashboardPage'
import { CustomerListPage } from '../features/customers/CustomerListPage'
import { CustomerFormPage } from '../features/customers/CustomerFormPage'
import { EquipmentLabelPrintPage } from '../features/equipment/EquipmentLabelPrintPage'
import { EquipmentFormPage } from '../features/equipment/EquipmentFormPage'
import { EquipmentListPage } from '../features/equipment/EquipmentListPage'
import { EquipmentLocationTreePage } from '../features/equipment/EquipmentLocationTreePage'
import { EquipmentSystemPage } from '../features/equipment/EquipmentSystemPage'
import { EquipmentUsageRecordPage } from '../features/equipment/EquipmentUsageRecordPage'
import { CalibrationProjectPage } from '../features/equipment/CalibrationProjectPage'
import { EquipmentCalibrationListPage } from '../features/equipment/EquipmentCalibrationListPage'
import { EquipmentCalibrationFormPage } from '../features/equipment/EquipmentCalibrationFormPage'
import { EquipmentCalibrationDetailPage } from '../features/equipment/EquipmentCalibrationDetailPage'
import { TempHumidityListPage } from '../features/equipment/TempHumidityListPage'
import { SampleDetailPage } from '../features/samples/SampleDetailPage'
import { SampleFlowRecordsPage } from '../features/samples/SampleFlowRecordsPage'
import { SampleListPage } from '../features/samples/SampleListPage'
import { SampleReceivePage } from '../features/samples/SampleReceivePage'
import { SampleScanPage } from '../features/samples/SampleScanPage'
import { getAuthToken } from '../lib/api'
import { LoginPage } from '../features/auth/LoginPage'
import { RegisterPage } from '../features/auth/RegisterPage'
import { StandardDetailPage } from '../features/standards/StandardDetailPage'
import { StandardFormPage } from '../features/standards/StandardFormPage'
import { StandardListPage } from '../features/standards/StandardListPage'
import { TestOrderDetailPage } from '../features/test-orders/TestOrderDetailPage'
import { TestOrderFormPage } from '../features/test-orders/TestOrderFormPage'
import { TestOrderListPage } from '../features/test-orders/TestOrderListPage'
import { AuditLogListPage } from '../features/system/audit/AuditLogListPage'
import { BackupListPage } from '../features/system/backups/BackupListPage'
import { DepartmentListPage } from '../features/system/departments/DepartmentListPage'
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

export const registerRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/register',
  component: RegisterPage,
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

const systemDepartmentsRoute = createRoute({
  getParentRoute: () => protectedRoute,
  path: '/system/departments',
  beforeLoad: () => requireRoutePermission('system.departments'),
  component: DepartmentListPage,
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

const testOrdersRoute = createRoute({
  getParentRoute: () => protectedRoute,
  path: '/test-orders',
  beforeLoad: () => requireRoutePermission('test_orders'),
  component: TestOrderListPage,
})

const testOrderCreateRoute = createRoute({
  getParentRoute: () => protectedRoute,
  path: '/test-orders/new',
  beforeLoad: () => requireRoutePermission('test_orders', 'create'),
  component: TestOrderFormPage,
})

const testOrderEditRoute = createRoute({
  getParentRoute: () => protectedRoute,
  path: '/test-orders/$testOrderId/edit',
  beforeLoad: () => requireRoutePermission('test_orders', 'update'),
  component: TestOrderFormPage,
})

const testOrderDetailRoute = createRoute({
  getParentRoute: () => protectedRoute,
  path: '/test-orders/$testOrderId',
  beforeLoad: () => requireRoutePermission('test_orders'),
  component: TestOrderDetailPage,
})

const samplesRoute = createRoute({
  getParentRoute: () => protectedRoute,
  path: '/samples',
  beforeLoad: () => requireRoutePermission('samples'),
  component: SampleListPage,
})

const sampleReceiveRoute = createRoute({
  getParentRoute: () => protectedRoute,
  path: '/samples/receive',
  beforeLoad: () => requireRoutePermission('samples', 'receive'),
  component: SampleReceivePage,
})

const sampleScanRoute = createRoute({
  getParentRoute: () => protectedRoute,
  path: '/samples/scan',
  beforeLoad: () => requireRoutePermission('sample_flows', 'create'),
  component: SampleScanPage,
})

const sampleFlowRecordsRoute = createRoute({
  getParentRoute: () => protectedRoute,
  path: '/samples/flow-records',
  beforeLoad: () => requireRoutePermission('sample_flows'),
  component: SampleFlowRecordsPage,
})

const sampleDetailRoute = createRoute({
  getParentRoute: () => protectedRoute,
  path: '/samples/$sampleId',
  beforeLoad: () => requireRoutePermission('samples'),
  component: SampleDetailPage,
})

const calibrationProjectsRoute = createRoute({
  getParentRoute: () => protectedRoute,
  path: '/system/calibration-projects',
  beforeLoad: () => requireRoutePermission('calibration_projects'),
  component: CalibrationProjectPage,
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

const equipmentSystemsRoute = createRoute({
  getParentRoute: () => protectedRoute,
  path: '/equipment/systems',
  beforeLoad: () => requireRoutePermission('equipment_systems'),
  component: EquipmentSystemPage,
})

const equipmentLabelsRoute = createRoute({
  getParentRoute: () => protectedRoute,
  path: '/equipment/labels',
  beforeLoad: () => requireRoutePermission('equipment_labels', 'print'),
  component: EquipmentLabelPrintPage,
})

const equipmentUsageRecordsRoute = createRoute({
  getParentRoute: () => protectedRoute,
  path: '/equipment/usage-records',
  beforeLoad: () => requireRoutePermission('equipment_usage_records'),
  component: EquipmentUsageRecordPage,
})

const equipmentCalibrationsRoute = createRoute({
  getParentRoute: () => protectedRoute,
  path: '/equipment/calibrations',
  beforeLoad: () => requireRoutePermission('equipment_calibrations'),
  component: EquipmentCalibrationListPage,
})

const equipmentCalibrationCreateRoute = createRoute({
  getParentRoute: () => protectedRoute,
  path: '/equipment/calibrations/new',
  beforeLoad: () => requireRoutePermission('equipment_calibrations', 'create'),
  component: EquipmentCalibrationFormPage,
})

const equipmentCalibrationDetailRoute = createRoute({
  getParentRoute: () => protectedRoute,
  path: '/equipment/calibrations/$calibrationId',
  beforeLoad: () => requireRoutePermission('equipment_calibrations'),
  component: EquipmentCalibrationDetailPage,
})

const equipmentCalibrationEditRoute = createRoute({
  getParentRoute: () => protectedRoute,
  path: '/equipment/calibrations/$calibrationId/edit',
  beforeLoad: () => requireRoutePermission('equipment_calibrations', 'update'),
  component: EquipmentCalibrationFormPage,
})

const tempHumidityRoute = createRoute({
  getParentRoute: () => protectedRoute,
  path: '/equipment/temp-humidity',
  beforeLoad: () => requireRoutePermission('temp_humidity_records'),
  component: TempHumidityListPage,
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
  registerRoute,
  protectedRoute.addChildren([
    indexRoute,
    systemRoute,
    systemUserCreateRoute,
    systemUserEditRoute,
    systemDepartmentsRoute,
    systemGroupsRoute,
    customersRoute,
    standardsRoute,
    standardCreateRoute,
    standardEditRoute,
    standardDetailRoute,
    customerCreateRoute,
    customerEditRoute,
    testOrdersRoute,
    testOrderCreateRoute,
    testOrderEditRoute,
    testOrderDetailRoute,
    samplesRoute,
    sampleReceiveRoute,
    sampleScanRoute,
    sampleFlowRecordsRoute,
    sampleDetailRoute,
    calibrationProjectsRoute,
    equipmentRoute,
    equipmentCreateRoute,
    equipmentEditRoute,
    equipmentLocationsRoute,
    equipmentSystemsRoute,
    equipmentLabelsRoute,
    equipmentUsageRecordsRoute,
    equipmentCalibrationsRoute,
    equipmentCalibrationCreateRoute,
    equipmentCalibrationDetailRoute,
    equipmentCalibrationEditRoute,
    tempHumidityRoute,
    auditLogsRoute,
    backupsRoute,
  ]),
])
