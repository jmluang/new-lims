import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { renderToStaticMarkup } from 'react-dom/server'
import { describe, expect, it, vi } from 'vitest'
import { allowsRoute } from '../../../app/routePermissions'
import { navGroups } from '../../../components/app/navigation'
import { EquipmentLedgerCards, EquipmentLedgerTable } from '../InspectionSharedFields'
import {
  CalibrationRecordCards,
  CalibrationRecordDetail,
  CalibrationRecordFormFields,
  CalibrationRecordTable,
  PhotometricCurveCalibrationPage,
} from '../PhotometricCurveCalibrationPage'
import {
  photometricCurveCalibrationEquipmentQueryKey,
  photometricCurveCalibrationMutationHandlers,
  photometricCurveCalibrationRecordsQueryKey,
} from '../photometricCurveCalibrationQueries'
import {
  emptyPhotometricCurveCalibrationForm,
  type PhotometricCurveCalibrationRecord,
} from '../photometricCurveCalibrationSchema'
import { photometricCurveEquipmentQueryKey } from '../photometricCurveQueries'

vi.stubGlobal('localStorage', {
  getItem: () => 'mock-token',
  setItem: () => {},
  removeItem: () => {},
})

vi.mock('../../auth/useCurrentUser', () => ({
  useEffectivePermissions: () => ({
    data: {
      resources: {
        photometric_curve_calibration_records: {
          actions: { read: true, create: true, update: true, delete: true },
          fields: {},
        },
      },
    },
  }),
}))

const mockRecord: PhotometricCurveCalibrationRecord = {
  id: 12,
  standard_equipment_id: 5,
  standard_no: 'XPD-L-030',
  standard_name: '标准灯',
  standard_manufacturer: 'OSRAM',
  standard_model: '400W',
  standard_serial_no: 'SN-STD',
  standard_next_calibration_date: '2027-06-01',
  equipment_system_id: 2,
  system_code: 'sys-01',
  system_name: '系统1',
  probe: 'far_field',
  test_distance: '26.2314',
  calibration_coefficient: '1.0024',
  peak_luminous_intensity: '221.0',
  luminous_flux: '1674.0',
  voltage: '220.8',
  current: '0.1189',
  power: '14.2400',
  power_factor: '0.5422',
  frequency: 50,
  remark: '定标备注',
  recorded_at: '2026-08-21 12:00:00',
  operator_id: 1,
  operator_name: '张工',
  created_at: '2026-08-21 12:00:00',
  updated_at: '2026-08-21 12:00:00',
  equipment: [
    {
      id: 101,
      equipment_id: 10,
      equipment_no: 'XPD-S-001',
      equipment_name: '智能电源',
      manufacturer: '杭州远方',
      model: 'DPS1060',
      serial_no: 'SN123',
      next_calibration_date: '2027-01-01',
    },
  ],
  photos: [],
  files: [],
}

const mockLedgerRow = {
  id: 100,
  calibration_record_id: 12,
  equipment_id: 50,
  equipment_no: 'XPD-S-002',
  equipment_name: '光度计',
  manufacturer: '远方',
  model: 'PM100',
  serial_no: 'SN-9800',
  next_calibration_date: '2027-05-01',
  recorded_at: '2026-08-21 10:00:00',
  operator_name: '李工',
}

function renderWithClient(ui: React.ReactElement) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })

  return renderToStaticMarkup(<QueryClientProvider client={queryClient}>{ui}</QueryClientProvider>)
}

function renderEditor(overrides: Partial<Parameters<typeof CalibrationRecordFormFields>[0]> = {}) {
  return renderToStaticMarkup(
    <CalibrationRecordFormFields
      form={emptyPhotometricCurveCalibrationForm()}
      recordId={null}
      fieldErrors={{}}
      equipmentLookupFailed={false}
      standardLookupFailed={false}
      systemLookupFailed={false}
      onEquipmentCode={() => {}}
      onStandardCode={() => {}}
      onSystemCode={() => {}}
      onRemoveEquipment={() => {}}
      onChange={() => {}}
      {...overrides}
    />,
  )
}

describe('PhotometricCurveCalibrationPage views', () => {
  it('renders the create action and the two independent views', () => {
    const html = renderWithClient(<PhotometricCurveCalibrationPage />)

    expect(html).toContain('配光曲线定标记录')
    expect(html).toContain('新增定标记录')
    expect(html).toContain('role="tablist"')
    expect(html).toContain('data-photometric-curve-calibration-views')
    expect(html).toContain('定标记录总表')
    expect(html).toContain('使用设备总表')
    expect(html).toContain('全部探头')
    expect(html).toContain('近场')
    expect(html).toContain('远场')
  })

  it('renders desktop record rows with the coefficient and every headline value', () => {
    const html = renderWithClient(
      <CalibrationRecordTable records={[mockRecord]} canDelete onDetail={() => {}} onEdit={() => {}} onDelete={() => {}} />,
    )

    expect(html).toContain('定标系数')
    expect(html).toContain('data-record-calibration-coefficient')
    expect(html).toContain('1.0024')
    expect(html).toContain('XPD-L-030')
    expect(html).toContain('sys-01')
    expect(html).toContain('远场')
    expect(html).toContain('26.2314')
    expect(html).toContain('221.0')
    expect(html).toContain('1674.0')
    expect(html).toContain('2026-08-21 12:00:00')
    expect(html).toContain('张工')
    expect(html).toContain('删除')
  })

  it('hides the delete control when the resource permission is absent', () => {
    const html = renderWithClient(
      <CalibrationRecordTable records={[mockRecord]} canDelete={false} onDetail={() => {}} onEdit={() => {}} onDelete={() => {}} />,
    )

    expect(html).toContain('编辑')
    expect(html).not.toContain('删除')
  })

  it('renders mobile record cards carrying the coefficient, power factor and frequency', () => {
    const html = renderWithClient(
      <CalibrationRecordCards records={[mockRecord]} canDelete={false} onDetail={() => {}} onEdit={() => {}} onDelete={() => {}} />,
    )

    expect(html).toContain('data-mobile-photometric-curve-calibration-records')
    expect(html).toContain('md:hidden')
    expect(html).toContain('标准件 XPD-L-030')
    expect(html).toContain('系统编码 sys-01')
    expect(html).toContain('定标系数')
    expect(html).toContain('1.0024')
    expect(html).toContain('26.2314 m')
    expect(html).toContain('0.5422')
    expect(html).toContain('50 Hz')
  })

  it('renders the used-equipment ledger keyed on calibration_record_id in both layouts', () => {
    const table = renderToStaticMarkup(<EquipmentLedgerTable rows={[mockLedgerRow]} recordLabel="定标记录ID" />)

    expect(table).toContain('定标记录ID')
    expect(table).toContain('XPD-S-002')
    expect(table).toContain('12')

    const cards = renderToStaticMarkup(
      <EquipmentLedgerCards
        rows={[mockLedgerRow]}
        recordLabel="定标记录ID"
        marker="data-mobile-photometric-curve-calibration-equipment"
      />,
    )

    expect(cards).toContain('data-mobile-photometric-curve-calibration-equipment')
    expect(cards).toContain('md:hidden')
    expect(cards).toContain('XPD-S-002')
    expect(cards).toContain('李工')
  })

  it('renders every stored snapshot, measurement and attachment panel in the detail view', () => {
    const html = renderToStaticMarkup(<CalibrationRecordDetail record={mockRecord} />)

    expect(html).toContain('XPD-L-030')
    expect(html).toContain('OSRAM')
    expect(html).toContain('SN-STD')
    expect(html).toContain('2027-06-01')
    expect(html).toContain('系统1')
    expect(html).toContain('远场')
    expect(html).toContain('26.2314 m')
    expect(html).toContain('定标系数')
    expect(html).toContain('1.0024')
    expect(html).toContain('221.0 cd')
    expect(html).toContain('1674.0 lm')
    expect(html).toContain('220.8 V')
    expect(html).toContain('0.1189 A')
    expect(html).toContain('14.2400 W')
    expect(html).toContain('0.5422')
    expect(html).toContain('50 Hz')
    expect(html).toContain('张工')
    expect(html).toContain('定标备注')
    // The used-device snapshots and the private attachment panels.
    expect(html).toContain('data-inspection-equipment-snapshots')
    expect(html).toContain('XPD-S-001')
    expect(html).toContain('data-record-attachments')
  })
})

describe('PhotometricCurveCalibrationPage editor', () => {
  it('keeps the scanner order equipment, system, standard, then probe and measurements', () => {
    const html = renderEditor()

    const order = ['使用设备（先录入）', '系统编码', '标准件编号', '探头与测量值'].map((label) => html.indexOf(label))

    expect(order.every((position) => position >= 0)).toBe(true)
    expect(order).toEqual([...order].sort((a, b) => a - b))
  })

  it('renders the probe select and every measurement input with its unit and scale hint', () => {
    const html = renderEditor()

    expect(html).toContain('探头')
    expect(html).toContain('近场')
    expect(html).toContain('远场')
    expect(html).toContain('测试距离（m）')
    expect(html).toContain('定标系数')
    expect(html).toContain('峰值光强（cd）')
    expect(html).toContain('光通量（lm）')
    expect(html).toContain('电压（V）')
    expect(html).toContain('电流（A）')
    expect(html).toContain('功率（W）')
    expect(html).toContain('功率因数')
    expect(html).toContain('频率（Hz）')
    expect(html).toContain('placeholder="0.0000"')
    expect(html).toContain('placeholder="0.0"')
    expect(html).toContain('inputMode="numeric"')
    expect(html).toContain('inputMode="decimal"')
    expect(html).toContain('data-inspection-attachments')
  })

  it('shows inline validation messages on the exact inputs that must be corrected', () => {
    const html = renderEditor({
      fieldErrors: {
        equipment: '请至少录入一台设备',
        system: '请录入系统编码',
        standard: '请录入标准件编号',
        calibration_coefficient: '定标系数不能为空',
        photos: '最多保留 10 个附件',
      },
    })

    expect(html).toContain('请至少录入一台设备')
    expect(html).toContain('请录入系统编码')
    expect(html).toContain('请录入标准件编号')
    expect(html).toContain('定标系数不能为空')
    expect(html).toContain('最多保留 10 个附件')
  })

  it('renders selected equipment as a desktop table and mobile cards without horizontal overflow', () => {
    const html = renderEditor({
      form: {
        ...emptyPhotometricCurveCalibrationForm(),
        equipment: [
          {
            child_id: 1,
            equipment_id: 10,
            equipment_no: 'XPD-S-001',
            equipment_name: '智能电源',
            manufacturer: '远方',
            model: 'DPS1060',
            serial_no: 'SN123',
            next_calibration_date: '2027-01-01',
          },
        ],
      },
      recordId: 12,
    })

    expect(html).toContain('data-selected-equipment-details')
    expect(html).toContain('hidden overflow-x-auto')
    expect(html).toContain('md:block')
    expect(html).toContain('data-selected-equipment-cards')
    expect(html).toContain('md:hidden')
    expect(html).toContain('XPD-S-001')
    expect(html).toContain('DPS1060')
  })

  it('marks a retained standard and an orphaned one differently', () => {
    const retained = renderEditor({
      form: {
        ...emptyPhotometricCurveCalibrationForm(),
        standard: {
          source: 'retained',
          equipment_id: 5,
          standard_no: 'XPD-L-030',
          standard_name: '标准灯',
          manufacturer: null,
          model: null,
          serial_no: null,
          next_calibration_date: null,
        },
      },
    })

    expect(retained).toContain('data-retained-standard-notice')

    const orphaned = renderEditor({
      form: {
        ...emptyPhotometricCurveCalibrationForm(),
        standard: {
          source: 'retained',
          equipment_id: null,
          standard_no: 'XPD-L-030',
          standard_name: '标准灯',
          manufacturer: null,
          model: null,
          serial_no: null,
          next_calibration_date: null,
        },
      },
    })

    expect(orphaned).toContain('data-orphan-standard-notice')

    const selected = renderEditor({
      form: {
        ...emptyPhotometricCurveCalibrationForm(),
        standard: {
          source: 'selected',
          equipment_id: 9,
          standard_no: 'XPD-L-031',
          standard_name: '新标准灯',
          manufacturer: null,
          model: null,
          serial_no: null,
          next_calibration_date: null,
        },
      },
    })

    expect(selected).toContain('data-selected-standard-notice')
  })
})

describe('photometric curve calibration query invalidation', () => {
  it('refreshes both the record list and the equipment ledger before closing the editor', async () => {
    const queryClient = new QueryClient()
    const order: string[] = []
    const keys: unknown[] = []

    vi.spyOn(queryClient, 'invalidateQueries').mockImplementation(async (filters) => {
      order.push('invalidate')
      keys.push(filters?.queryKey)
    })

    const handlers = photometricCurveCalibrationMutationHandlers(queryClient, () => order.push('close'))

    await handlers.saveSuccess()

    expect(order).toEqual(['invalidate', 'invalidate', 'close'])
    expect(keys).toEqual([photometricCurveCalibrationRecordsQueryKey, photometricCurveCalibrationEquipmentQueryKey])
  })

  it('refreshes both views after a delete without touching the editor', async () => {
    const queryClient = new QueryClient()
    const order: string[] = []

    vi.spyOn(queryClient, 'invalidateQueries').mockImplementation(async () => {
      order.push('invalidate')
    })

    const handlers = photometricCurveCalibrationMutationHandlers(queryClient, () => order.push('close'))

    await handlers.deleteSuccess()

    expect(order).toEqual(['invalidate', 'invalidate'])
  })

  it('keeps its query keys distinct from the photometric-curve inspection workflow', () => {
    expect(photometricCurveCalibrationRecordsQueryKey).not.toEqual(photometricCurveEquipmentQueryKey)
    expect(photometricCurveCalibrationRecordsQueryKey).toEqual(['photometric-curve-calibration-records'])
    expect(photometricCurveCalibrationEquipmentQueryKey).toEqual(['photometric-curve-calibration-equipment'])
  })
})

describe('photometric curve calibration navigation and route permission', () => {
  it('adds exactly one equipment navigation entry guarded by the read permission', () => {
    const items = navGroups.flatMap((group) => group.items).filter((item) => item.to.includes('photometric-curve-calibrations'))

    expect(items).toHaveLength(1)
    expect(items[0]).toMatchObject({
      label: '配光曲线定标记录',
      to: '/equipment/photometric-curve-calibrations',
      resource: 'photometric_curve_calibration_records',
      action: 'read',
    })
    expect(navGroups.find((group) => group.items.includes(items[0]))?.label).toBe('设备管理')
  })

  it('leaves the photometric-curve inspection entry untouched', () => {
    const items = navGroups.flatMap((group) => group.items).filter((item) => item.to.includes('photometric-curve-inspections'))

    expect(items).toHaveLength(1)
    expect(items[0]).toMatchObject({
      label: '配光曲线点检记录',
      resource: 'photometric_curve_inspection_records',
      action: 'read',
    })
  })

  it('hides the route from a user without the read permission', () => {
    const allowed = { resources: { photometric_curve_calibration_records: { actions: { read: true } } } }
    const denied = { resources: {} }

    expect(allowsRoute(allowed, 'photometric_curve_calibration_records', 'read')).toBe(true)
    expect(allowsRoute(denied, 'photometric_curve_calibration_records', 'read')).toBe(false)
  })
})
