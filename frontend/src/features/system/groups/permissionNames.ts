import { zhText } from '../../../lib/zh'

const resourceLabels: Record<string, string> = {
  'system.users': '用户',
  'system.departments': '部门',
  'system.groups': '角色组',
  'system.audit_logs': '审计日志',
  'system.backups': '备份',
  customers: '客户',
  customer_contacts: '客户联系人',
  standards: '检测标准',
  standard_catalogs: '标准目录',
  standard_items: '检测项目',
  test_orders: '委托试验单',
  test_order_standards: '委托单标准',
  test_order_samples: '委托单样品',
  samples: '样品',
  sample_labels: '样品标签',
  sample_flows: '样品流转',
  equipment: '设备',
  equipment_locations: '位置名称',
  equipment_systems: '设备系统',
  equipment_labels: '设备标签',
  equipment_usage_records: '设备使用记录',
  temp_humidity_records: '温湿度记录',
  calibration_projects: '定标项目',
  calibration_project_labels: '定标项目标签',
  equipment_calibrations: '设备定标记录',
}

const actionLabels: Record<string, string> = {
  read: '查看',
  create: '新建',
  update: '编辑',
  delete: '删除',
  export: '导出',
  restore: '恢复',
  receive: '接收',
  print: '打印',
  return_room: '归还样品室',
}

const fieldLabels: Record<string, string> = {
  phone: '电话',
  email: '邮箱',
  credit_code: '统一社会信用代码',
  serial_no: '序列号',
  device_image: '设备图片',
  manual_files: '说明书文件',
  instruction_files: '操作规程文件',
  calibration_files: '校准文件',
  other_files: '其他文件',
  attachment_files: '附件',
  photo_files: '现场照片',
}

export function resourcePermissionName(resource: string, action: string) {
  return `${resource}.${action}`
}

export function fieldPermissionName(resource: string, field: string, action: string) {
  return `${resource}.field.${field}.${action}`
}

export function permissionResourceLabel(resource: string) {
  return resourceLabels[resource] ?? resource
}

export function permissionActionLabel(action: string) {
  return actionLabels[action] ?? zhText(action) ?? action
}

export function permissionFieldLabel(field: string) {
  return fieldLabels[field] ?? zhText(field) ?? field
}
