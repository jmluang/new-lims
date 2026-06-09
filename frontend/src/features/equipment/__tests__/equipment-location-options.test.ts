import { describe, expect, it } from 'vitest'
import { activeLocationOptions } from '../equipmentLocationOptions'
import type { EquipmentLocation } from '../EquipmentListPage'

const locations: EquipmentLocation[] = [
  {
    id: 1,
    name: '总部',
    code: 'root',
    status: 'active',
    children: [
      { id: 2, parent_id: 1, name: '安规室', code: 'ag', status: 'active' },
      {
        id: 3,
        parent_id: 1,
        name: '光学',
        code: 'gx',
        status: 'active',
        children: [{ id: 4, parent_id: 3, name: '暗室', code: 'dark', status: 'active' }],
      },
      { id: 5, parent_id: 1, name: '停用位置', code: 'disabled', status: 'disabled' },
    ],
  },
]

describe('equipment location options', () => {
  it('formats nested locations as readable full paths', () => {
    expect(activeLocationOptions(locations)).toEqual([
      { id: 1, label: '总部' },
      { id: 2, label: '总部 / 安规室' },
      { id: 3, label: '总部 / 光学' },
      { id: 4, label: '总部 / 光学 / 暗室' },
    ])
  })
})
