import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { renderToStaticMarkup } from 'react-dom/server'
import { describe, expect, it, vi } from 'vitest'
import { allowsRoute } from '../../../app/routePermissions'
import { navGroups } from '../../../components/app/navigation'
import {
  EquipmentLedgerCards,
  EquipmentLedgerTable,
} from '../InspectionSharedFields'
import {
  CalibrationRecordCards,
  CalibrationRecordDetail,
  CalibrationRecordFormFields,
  CalibrationRecordTable,
  IntegratingSphereCalibrationPage,
} from '../IntegratingSphereCalibrationPage'
import {
  emptyIntegratingSphereCalibrationForm,
  type IntegratingSphereCalibrationRecord,
} from '../integratingSphereCalibrationSchema'

vi.stubGlobal('localStorage', {
  getItem: () => 'mock-token',
  setItem: () => {},
  removeItem: () => {},
})

vi.mock('../../auth/useCurrentUser', () => ({
  useEffectivePermissions: () => ({
    data: {
      resources: {
        integrating_sphere_calibration_records: {
          actions: { read: true, create: true, update: true, delete: true },
          fields: {},
        },
      },
    },
  }),
}))

const mockRecord: IntegratingSphereCalibrationRecord = {
  id: 12,
  standard_equipment_id: 5,
  standard_no: 'STD-001',
  standard_name: '标准灯',
  standard_manufacturer: 'OSRAM',
  standard_model: '400W',
  standard_serial_no: 'SN-STD',
  standard_next_calibration_date: '2027-06-01',
  equipment_system_id: 2,
  system_code: 'SYS-01',
  system_name: '系统1',
  mode_code: 'precise',
  mode_label: '精准',
  sensitivity_code: 'high',
  sensitivity_label: '高',
  color_temperature: 4360,
  color_rendering_index: '88.4',
  luminous_flux: '1674.0',
  voltage: '220.80',
  current: '0.1189',
  power: '14.2400',
  power_factor: '0.5422',
  frequency: 50,
  remark: null,
  recorded_at: '2026-08-21 12:00:00',
  operator_id: 1,
  operator_name: '张工',
  created_at: '2026-08-21T12:00:00Z',
  updated_at: '2026-08-21T12:00:00Z',
  equipment: [],
  photos: [],
  files: [],
}

const mockLedgerRow = {
  id: 100,
  calibration_record_id: 123,
  equipment_id: 50,
  equipment_no: 'EQ-CAL-01',
  equipment_name: '数字功率计',
  manufacturer: '远方',
  model: 'PF9800',
  serial_no: 'SN-9800',
  next_calibration_date: '2027-05-01',
  recorded_at: '2026-08-21 10:00:00',
  operator_name: '李工',
}

function renderWithClient(ui: React.ReactElement) {
  const queryClient = new QueryClient({
    defaultOptions: {
      queries: { retry: false },
    },
  })

  return renderToStaticMarkup(<QueryClientProvider client={queryClient}>{ui}</QueryClientProvider>)
}

describe('IntegratingSphereCalibrationPage components', () => {
  it('renders create button via actions prop on PageShell and exposes role=tablist tab navigation', () => {
    const pageMarkup = renderWithClient(<IntegratingSphereCalibrationPage />)

    expect(pageMarkup).toContain('新增定标记录')
    expect(pageMarkup).toContain('role="tablist"')
    expect(pageMarkup).toContain('data-integrating-sphere-calibration-views')
    expect(pageMarkup).toContain('role="tab"')
    expect(pageMarkup).toContain('aria-selected="true"')
  })

  it('renders desktop table rows for calibration records', () => {
    const html = renderWithClient(
      <CalibrationRecordTable
        records={[mockRecord]}
        canDelete={true}
        onDetail={() => {}}
        onEdit={() => {}}
        onDelete={() => {}}
      />,
    )

    expect(html).toContain('STD-001')
    expect(html).toContain('SYS-01')
    expect(html).toContain('精准')
    expect(html).toContain('高')
    expect(html).toContain('4360')
    expect(html).toContain('88.4')
    expect(html).toContain('1674.0')
  })

  it('renders mobile card entries for calibration records', () => {
    const html = renderWithClient(
      <CalibrationRecordCards
        records={[mockRecord]}
        canDelete={false}
        onDetail={() => {}}
        onEdit={() => {}}
        onDelete={() => {}}
      />,
    )

    expect(html).toContain('data-mobile-integrating-sphere-calibration-records')
    expect(html).toContain('标准件 STD-001')
    expect(html).toContain('系统编码 SYS-01')
    expect(html).toContain('0.5422')
    expect(html).toContain('50 Hz')
  })

  it('renders equipment ledger table and cards with calibration_record_id', () => {
    const tableHtml = renderToStaticMarkup(
      <EquipmentLedgerTable rows={[mockLedgerRow]} recordLabel="定标记录ID" />,
    )
    expect(tableHtml).toContain('123')
    expect(tableHtml).toContain('EQ-CAL-01')
    expect(tableHtml).toContain('定标记录ID')

    const cardsHtml = renderToStaticMarkup(
      <EquipmentLedgerCards rows={[mockLedgerRow]} recordLabel="定标记录ID" marker="data-mobile-test" />,
    )
    expect(cardsHtml).toContain('123')
    expect(cardsHtml).toContain('EQ-CAL-01')
    expect(cardsHtml).toContain('data-mobile-test')
  })

  it('renders detail modal content', () => {
    const html = renderToStaticMarkup(<CalibrationRecordDetail record={mockRecord} />)

    expect(html).toContain('STD-001')
    expect(html).toContain('OSRAM')
    expect(html).toContain('0.5422')
    expect(html).toContain('50 Hz')
  })

  it('renders editor form fields with retained label when option is removed from catalog', () => {
    const form = emptyIntegratingSphereCalibrationForm()
    form.mode = { source: 'retained', code: 'legacy_mode', label: '旧模式' }
    form.sensitivity = { source: 'selected', code: 'high', label: '高' }

    const catalogOptions = {
      modes: [{ code: 'precise', label: '精准' }],
      sensitivities: [{ code: 'high', label: '高' }],
    }

    const html = renderToStaticMarkup(
      <CalibrationRecordFormFields
        form={form}
        recordId={12}
        fieldErrors={{}}
        catalogOptions={catalogOptions}
        equipmentLookupFailed={false}
        standardLookupFailed={false}
        systemLookupFailed={false}
        onEquipmentCode={() => {}}
        onStandardCode={() => {}}
        onSystemCode={() => {}}
        onRemoveEquipment={() => {}}
        onChange={() => {}}
      />,
    )

    expect(html).toContain('旧模式（历史快照，已自目录移除）')
    expect(html).toContain('data-retained-removed-mode-notice')
  })

  it('renders responsive mobile equipment cards alongside desktop detail table in calibration form', () => {
    const form = {
      ...emptyIntegratingSphereCalibrationForm(),
      equipment: [
        {
          child_id: 1,
          equipment_id: 10,
          equipment_no: 'EQ-001',
          equipment_name: '高压表',
          manufacturer: '远方',
          model: 'DPS1060',
          serial_no: 'SN123',
          next_calibration_date: '2027-01-01',
        },
      ],
    }
    const catalogOptions = { modes: [], sensitivities: [] }

    const html = renderToStaticMarkup(
      <CalibrationRecordFormFields
        form={form}
        recordId={1}
        fieldErrors={{}}
        catalogOptions={catalogOptions}
        equipmentLookupFailed={false}
        standardLookupFailed={false}
        systemLookupFailed={false}
        onEquipmentCode={() => {}}
        onStandardCode={() => {}}
        onSystemCode={() => {}}
        onRemoveEquipment={() => {}}
        onChange={() => {}}
      />,
    )

    expect(html).toContain('data-selected-equipment-details')
    expect(html).toContain('hidden overflow-x-auto')
    expect(html).toContain('md:block')

    expect(html).toContain('data-selected-equipment-cards')
    expect(html).toContain('md:hidden')
    expect(html).toContain('EQ-001')
    expect(html).toContain('高压表')
    expect(html).toContain('DPS1060')
    expect(html).toContain('SN123')
    expect(html).toContain('2027-01-01')
  })
})

describe('integrating sphere calibration navigation and route permission', () => {
  it('adds exactly one equipment navigation entry guarded by the read permission', () => {
    const items = navGroups.flatMap((group) => group.items).filter((item) => item.to.includes('integrating-sphere-calibration'))

    expect(items).toHaveLength(1)
    expect(items[0]).toMatchObject({
      label: '积分球定标记录',
      to: '/equipment/integrating-sphere-calibrations',
      resource: 'integrating_sphere_calibration_records',
      action: 'read',
    })
    expect(navGroups.find((group) => group.items.includes(items[0]))?.label).toBe('设备管理')
  })

  it('hides the route from a user without the read permission', () => {
    const allowed = {
      resources: {
        integrating_sphere_calibration_records: {
          actions: { read: true },
        },
      },
    }

    const denied = {
      resources: {},
    }

    expect(allowsRoute(allowed, 'integrating_sphere_calibration_records', 'read')).toBe(true)
    expect(allowsRoute(denied, 'integrating_sphere_calibration_records', 'read')).toBe(false)
  })
})
