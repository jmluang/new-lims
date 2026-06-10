export function getSystemEquipmentActionState({
  equipmentLoaded,
  equipmentIds,
  busy,
}: {
  equipmentLoaded: boolean
  equipmentIds: number[]
  busy: boolean
}) {
  if (!equipmentLoaded) {
    return {
      canManageEquipment: false,
      canPrintLabels: false,
      manageLabel: '加载设备...',
      printLabel: '加载设备...',
    }
  }

  if (busy) {
    return {
      canManageEquipment: false,
      canPrintLabels: false,
      manageLabel: '加载中...',
      printLabel: '加载中...',
    }
  }

  return {
    canManageEquipment: true,
    canPrintLabels: equipmentIds.length > 0,
    manageLabel: '管理设备',
    printLabel: '打印标签',
  }
}
