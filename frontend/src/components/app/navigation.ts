import {
  BookOpen,
  Building2,
  ClipboardCheck,
  ClipboardList,
  DatabaseBackup,
  FileCheck2,
  FileSignature,
  FilePenLine,
  FileStack,
  FileText,
  LayoutDashboard,
  MapPinned,
  PackageCheck,
  Printer,
  Ruler,
  ScanLine,
  ScrollText,
  Settings,
  Shapes,
  ShieldCheck,
  Stamp,
  Timer,
  Thermometer,
  Users,
  Workflow,
  type LucideIcon,
} from 'lucide-react'

export type NavItem = {
  label: string
  to: string
  icon: LucideIcon
  resource?: string
  action?: string
  anyPermissions?: Array<{ resource: string; action?: string }>
}

export type NavGroup = {
  label: string
  items: NavItem[]
}

export const navGroups: NavGroup[] = [
  {
    label: '工作台',
    items: [{ label: '仪表盘', to: '/', icon: LayoutDashboard }],
  },
  {
    label: '系统管理',
    items: [
      { label: '用户管理', to: '/system', icon: Settings, resource: 'system.users', action: 'read' },
      { label: '部门管理', to: '/system/departments', icon: Building2, resource: 'system.departments', action: 'read' },
      { label: '角色组', to: '/system/groups', icon: ShieldCheck, resource: 'system.groups', action: 'read' },
      { label: '位置名称', to: '/equipment/locations', icon: MapPinned, resource: 'equipment_locations', action: 'read' },
      { label: '定标项目', to: '/system/calibration-projects', icon: Ruler, resource: 'calibration_projects', action: 'read' },
    ],
  },
  {
    label: '业务管理',
    items: [
      { label: '客户管理', to: '/customers', icon: Users, resource: 'customers', action: 'read' },
      { label: '检测标准库', to: '/standards', icon: BookOpen, resource: 'standards', action: 'read' },
      { label: '委托试验单', to: '/test-orders', icon: ClipboardList, resource: 'test_orders', action: 'read' },
      { label: '公开委托提交', to: '/public-test-order-submissions', icon: ClipboardCheck, resource: 'test_orders', action: 'read' },
      { label: '样品信息', to: '/samples', icon: PackageCheck, resource: 'samples', action: 'read' },
      { label: '样品流转记录', to: '/samples/flow-records', icon: Workflow, resource: 'sample_flows', action: 'read' },
      { label: '扫码流转', to: '/samples/scan', icon: ScanLine, resource: 'sample_flows', action: 'create' },
    ],
  },
  {
    label: '设备管理',
    items: [
      { label: '设备台账', to: '/equipment', icon: ClipboardList, resource: 'equipment', action: 'read' },
      { label: '设备系统', to: '/equipment/systems', icon: Workflow, resource: 'equipment_systems', action: 'read' },
      { label: '设备标签', to: '/equipment/labels', icon: Printer, resource: 'equipment_labels', action: 'print' },
      { label: '设备使用记录', to: '/equipment/usage-records', icon: Timer, resource: 'equipment_usage_records', action: 'read' },
      { label: '设备定标记录', to: '/equipment/calibrations', icon: ClipboardCheck, resource: 'equipment_calibrations', action: 'read' },
      { label: '温湿度记录', to: '/equipment/temp-humidity', icon: Thermometer, resource: 'temp_humidity_records', action: 'read' },
    ],
  },
  {
    label: 'PDF 防篡改',
    items: [
      {
        label: '手写数字签名',
        to: '/pdf/handwritten-signing',
        icon: FilePenLine,
        anyPermissions: [
          { resource: 'pdf.request', action: 'read' },
          { resource: 'pdf.workflow', action: 'create' },
        ],
      },
      { label: '签署文档', to: '/pdf/documents', icon: FileText, resource: 'pdf.document', action: 'read' },
      { label: 'PDF防篡改系统', to: '/pdf/signing', icon: FileSignature, resource: 'pdf_signing', action: 'read' },
      { label: '文件验证', to: '/pdf/verify', icon: FileCheck2, resource: 'pdf_verification', action: 'read' },
      { label: '签章台账', to: '/pdf/files', icon: FileStack, resource: 'pdf_files', action: 'read' },
      { label: '验证日志', to: '/pdf/verification-logs', icon: ScrollText, resource: 'pdf_verification_logs', action: 'read' },
    ],
  },
  {
    label: 'PDF 签章配置',
    items: [
      { label: '首页盖章', to: '/pdf/digital-signatures', icon: Stamp, resource: 'pdf_digital_signatures', action: 'read' },
      { label: '骑缝章', to: '/pdf/perforation-stamps', icon: Stamp, resource: 'pdf_perforation_stamps', action: 'read' },
      { label: '首页功能章', to: '/pdf/function-stamps', icon: Shapes, resource: 'pdf_function_stamps', action: 'read' },
      { label: '声明页模板', to: '/pdf/certificate-templates', icon: FileStack, resource: 'pdf_certificate_templates', action: 'read' },
    ],
  },
  {
    label: '运维审计',
    items: [
      { label: '审计日志', to: '/audit-logs', icon: ScrollText, resource: 'system.audit_logs', action: 'read' },
      { label: '备份管理', to: '/backups', icon: DatabaseBackup, resource: 'system.backups', action: 'read' },
    ],
  },
]

export const navPaths = navGroups.flatMap((group) => group.items.map((item) => item.to))

export type NavPermissions = {
  resources: Record<string, { actions: Record<string, boolean> }>
}

export function visibleNavGroups(permissions?: NavPermissions): NavGroup[] {
  if (!permissions) {
    return navGroups
  }

  const hasAnyGrantedAction = Object.values(permissions.resources).some((resource) =>
    Object.values(resource.actions).some(Boolean),
  )

  return navGroups
    .map((group) => ({
      ...group,
      items: group.items.filter((item) => {
        if (item.anyPermissions) {
          return item.anyPermissions.some(({ resource, action = 'read' }) =>
            Boolean(permissions.resources[resource]?.actions[action]),
          )
        }

        if (!item.resource) {
          return hasAnyGrantedAction
        }

        return Boolean(permissions.resources[item.resource]?.actions[item.action ?? 'read'])
      }),
    }))
    .filter((group) => group.items.length > 0)
}

export function isActivePath(pathname: string, to: string, paths: string[] = navPaths) {
  if (pathname === to) {
    return true
  }

  if (to === '/' || !pathname.startsWith(`${to}/`)) {
    return false
  }

  return !paths.some((path) => path !== to && path.startsWith(`${to}/`) && (pathname === path || pathname.startsWith(`${path}/`)))
}
