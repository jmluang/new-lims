import {
  BookOpen,
  ClipboardList,
  DatabaseBackup,
  LayoutDashboard,
  MapPinned,
  PackageCheck,
  Printer,
  ScrollText,
  Settings,
  ShieldCheck,
  Workflow,
  Thermometer,
  Users,
  type LucideIcon,
} from 'lucide-react'

export type NavItem = {
  label: string
  to: string
  icon: LucideIcon
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
      { label: '用户管理', to: '/system', icon: Settings },
      { label: '角色组', to: '/system/groups', icon: ShieldCheck },
      { label: '数据字典', to: '/system/dictionaries', icon: BookOpen },
    ],
  },
  {
    label: '业务管理',
    items: [
      { label: '客户管理', to: '/customers', icon: Users },
      { label: '检测标准库', to: '/standards', icon: BookOpen },
      { label: '委托试验单', to: '/test-orders', icon: ClipboardList },
      { label: '样品信息', to: '/samples', icon: PackageCheck },
    ],
  },
  {
    label: '设备管理',
    items: [
      { label: '设备台账', to: '/equipment', icon: ClipboardList },
      { label: '设备位置', to: '/equipment/locations', icon: MapPinned },
      { label: '设备系统', to: '/equipment/systems', icon: Workflow },
      { label: '设备标签', to: '/equipment/labels', icon: Printer },
      { label: '温湿度记录', to: '/equipment/temp-humidity', icon: Thermometer },
    ],
  },
  {
    label: '运维审计',
    items: [
      { label: '审计日志', to: '/audit-logs', icon: ScrollText },
      { label: '备份管理', to: '/backups', icon: DatabaseBackup },
    ],
  },
]

export const navPaths = navGroups.flatMap((group) => group.items.map((item) => item.to))

export function isActivePath(pathname: string, to: string, paths: string[] = navPaths) {
  if (pathname === to) {
    return true
  }

  if (to === '/' || !pathname.startsWith(`${to}/`)) {
    return false
  }

  return !paths.some((path) => path !== to && path.startsWith(`${to}/`) && (pathname === path || pathname.startsWith(`${path}/`)))
}
