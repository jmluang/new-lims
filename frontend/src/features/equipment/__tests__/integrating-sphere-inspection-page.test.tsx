import { QueryClient } from '@tanstack/react-query'
import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { renderToStaticMarkup } from 'react-dom/server'
import { describe, expect, it, vi } from 'vitest'
import { navGroups } from '../../../components/app/navigation'
import { allowsRoute } from '../../../app/routePermissions'
import {
  InspectionEquipmentCards,
  InspectionEquipmentTable,
  InspectionRecordCards,
  InspectionRecordDetail,
  InspectionRecordFormFields,
  InspectionRecordTable,
} from '../IntegratingSphereInspectionPage'
import {
  integratingSphereEquipmentQueryKey,
  integratingSphereMutationHandlers,
  integratingSphereRecordsQueryKey,
  invalidateIntegratingSphereLists,
} from '../integratingSphereQueries'
import {
  emptyIntegratingSphereInspectionForm,
  type IntegratingSphereEquipmentLedgerRow,
  type IntegratingSphereInspectionRecord,
} from '../integratingSphereInspectionSchema'

const pageSource = readFileSync(fileURLToPath(new URL('../IntegratingSphereInspectionPage.tsx', import.meta.url)), 'utf8')
const routesSource = readFileSync(fileURLToPath(new URL('../../../app/routes.tsx', import.meta.url)), 'utf8')
const queriesSource = readFileSync(fileURLToPath(new URL('../integratingSphereQueries.ts', import.meta.url)), 'utf8')
const sharedFieldsSource = readFileSync(fileURLToPath(new URL('../InspectionSharedFields.tsx', import.meta.url)), 'utf8')

vi.mock('../../auth/useCurrentUser', () => ({
  useEffectivePermissions: () => ({
    data: {
      resources: {
        integrating_sphere_inspection_records: {
          actions: { read: true, create: true, update: true, delete: false },
          fields: {},
        },
      },
    },
  }),
}))

const record: IntegratingSphereInspectionRecord = {
  id: 1,
  sample_id: 3,
  sample_no: '26010058874-1-1/1',
  equipment_system_id: 5,
  system_code: 'sys-01',
  chromaticity_x: '0.3633',
  chromaticity_y: '0.3549',
  dominant_wavelength: '580.5',
  peak_wavelength: '601.2',
  color_temperature: 4360,
  color_rendering_index: '88.4',
  luminous_flux: '1234.5',
  voltage: '220.0',
  current: '0.0451',
  power: '9.8765',
  power_factor: '0.9876',
  frequency: 50,
  remark: '首件点检',
  recorded_at: '2026-08-20 12:27:00',
  operator_id: 2,
  operator_name: '点检员',
  equipment: [
    {
      id: 11,
      equipment_id: 7,
      equipment_no: 'XPD-S-001',
      equipment_name: '积分球',
      manufacturer: '杭州远方',
      model: 'HAAS-2000',
      serial_no: 'SN-XPD-001',
      next_calibration_date: '2027-03-01',
    },
    {
      id: 12,
      equipment_id: 8,
      equipment_no: 'XPD-S-002',
      equipment_name: '光谱仪',
      manufacturer: '虹昌电子',
      model: 'SPC-100',
      serial_no: 'SN-XPD-002',
      next_calibration_date: '2027-05-20',
    },
  ],
}

const noop = () => {}
const actionProps = { canDelete: false, onDetail: noop, onEdit: noop, onDelete: noop }
const formProps = {
  fieldErrors: {},
  equipmentLookupFailed: false,
  sampleLookupFailed: false,
  systemLookupFailed: false,
  onEquipmentCode: noop,
  onSampleCode: noop,
  onSystemCode: noop,
  onRemoveEquipment: noop,
  onChange: noop,
}

describe('integrating sphere inspection list rendering', () => {
  it('shows the record id, measurement columns, date and operator in the desktop table', () => {
    const html = renderToStaticMarkup(<InspectionRecordTable records={[record]} {...actionProps} />)

    expect(html).toContain('>ID</th>')
    expect(html).toContain('>1</td>')
    expect(html).toContain('26010058874-1-1/1')
    expect(html).toContain('0.3633')
    expect(html).toContain('0.3549')
    expect(html).toContain('580.5')
    expect(html).toContain('601.2')
    expect(html).toContain('4360')
    expect(html).toContain('88.4')
    expect(html).toContain('1234.5')
    expect(html).toContain('2026-08-20 12:27:00')
    expect(html).toContain('点检员')
  })

  it('places the system code immediately after the sample number and drops the used-equipment column', () => {
    const html = renderToStaticMarkup(<InspectionRecordTable records={[record]} {...actionProps} />)
    const headers = [...html.matchAll(/<th[^>]*>([^<]*)<\/th>/g)].map((match) => match[1])

    expect(headers.slice(0, 3)).toEqual(['ID', '样品编号', '系统编码'])
    expect(headers).not.toContain('使用设备')
    expect(html).toContain('sys-01')
    // The device numbers belong to the detail view and the global ledger now.
    expect(html).not.toContain('XPD-S-001')
    expect(html).not.toContain('XPD-S-002')
  })

  it('falls back to a placeholder for a legacy record that carries no system code', () => {
    const html = renderToStaticMarkup(
      <InspectionRecordTable records={[{ ...record, equipment_system_id: null, system_code: null }]} {...actionProps} />,
    )

    expect(html).toContain('>系统编码</th>')
    expect(html).toContain('>-</td>')
  })

  it('keeps the records readable on mobile with a card list instead of the table', () => {
    const html = renderToStaticMarkup(<InspectionRecordCards records={[record]} {...actionProps} />)

    expect(html).toContain('data-mobile-integrating-sphere-records')
    expect(html).toContain('md:hidden')
    expect(html).toContain('>ID</dt>')
    expect(html).toContain('>1</dd>')
    expect(html).toContain('26010058874-1-1/1')
    expect(html).toContain('4360 K')
    expect(html).toContain('1234.5 lm')
    expect(html).toContain('点检员')
    expect(html).not.toContain('<table')
  })

  it('shows the system code on mobile cards instead of the used-equipment summary', () => {
    const html = renderToStaticMarkup(<InspectionRecordCards records={[record]} {...actionProps} />)

    expect(html).toContain('data-mobile-system-code')
    expect(html).toContain('sys-01')
    expect(html).not.toContain('XPD-S-001')
    expect(html).not.toContain('XPD-S-002')
  })

  it('renders the granted actions and leaves out the ungranted delete action', () => {
    const withoutDelete = renderToStaticMarkup(<InspectionRecordTable records={[record]} {...actionProps} />)
    const withDelete = renderToStaticMarkup(<InspectionRecordTable records={[record]} {...actionProps} canDelete />)

    expect(withoutDelete).toContain('详情')
    expect(withoutDelete).toContain('编辑')
    expect(withoutDelete).not.toContain('删除')
    expect(withDelete).toContain('删除')
  })

  it('lists every used device with its full snapshot in the detail view', () => {
    const html = renderToStaticMarkup(<InspectionRecordDetail record={record} />)

    expect(html).toContain('data-inspection-equipment-snapshots')
    expect(html).toContain('XPD-S-001')
    expect(html).toContain('杭州远方')
    expect(html).toContain('HAAS-2000')
    expect(html).toContain('SN-XPD-001')
    expect(html).toContain('2027-03-01')
    expect(html).toContain('XPD-S-002')
    expect(html).toContain('虹昌电子')
    expect(html).toContain('SPC-100')
    expect(html).toContain('SN-XPD-002')
    expect(html).toContain('2027-05-20')
    expect(html).toContain('首件点检')
  })
})

describe('integrating sphere inspection form', () => {
  it('puts equipment entry first, then the sample and the system, all on the shared QR scanner', () => {
    const html = renderToStaticMarkup(
      <InspectionRecordFormFields form={emptyIntegratingSphereInspectionForm('2026-08-20T12:27')} {...formProps} />,
    )

    expect(html.indexOf('使用设备（先录入）')).toBeGreaterThan(-1)
    expect(html.indexOf('使用设备（先录入）')).toBeLessThan(html.indexOf('样品编号'))
    // The system scanner is an independent input that follows the sample scanner.
    expect(html.indexOf('样品编号')).toBeLessThan(html.indexOf('系统编码'))
    expect(html.indexOf('系统编码')).toBeLessThan(html.indexOf('测量值'))
    expect(html.match(/data-scanner-selection/g)).toHaveLength(3)
    expect(html).toContain('扫码/手输系统编码')
    expect(html).toContain('打开扫码')
    expect(html).toContain('尚未录入设备')
    expect(html).toContain('尚未录入样品')
    expect(html).toContain('尚未录入系统编码')
  })

  it('shows the resolved system code with its name and a lookup failure notice', () => {
    const base = emptyIntegratingSphereInspectionForm('2026-08-20T12:27')
    const html = renderToStaticMarkup(
      <InspectionRecordFormFields
        form={{ ...base, system: { source: 'selected', id: 5, code: 'sys-01', name: '系统1' } }}
        {...formProps}
      />,
    )
    const failed = renderToStaticMarkup(<InspectionRecordFormFields form={base} {...formProps} systemLookupFailed />)

    expect(html).toContain('sys-01')
    expect(html).toContain('系统1')
    expect(failed).toContain('未找到系统编码')
  })

  it('tells the operator whether the system code will be kept as a snapshot or replaced', () => {
    const base = emptyIntegratingSphereInspectionForm('2026-08-20T12:27')
    const retained = renderToStaticMarkup(
      <InspectionRecordFormFields form={{ ...base, system: { source: 'retained', id: 5, code: 'sys-01' } }} {...formProps} />,
    )
    const orphaned = renderToStaticMarkup(
      <InspectionRecordFormFields form={{ ...base, system: { source: 'retained', id: null, code: 'sys-01' } }} {...formProps} />,
    )
    const replaced = renderToStaticMarkup(
      <InspectionRecordFormFields form={{ ...base, system: { source: 'selected', id: 9, code: 'sys-02' } }} {...formProps} />,
    )

    // A live ledger row is not enough to re-declare the system: the record's own code
    // stays until the operator scans a replacement.
    expect(retained).toContain('data-retained-system-notice')
    expect(retained).not.toContain('data-selected-system-notice')
    expect(retained).not.toContain('data-orphan-system-notice')
    expect(orphaned).toContain('data-orphan-system-notice')
    expect(orphaned).not.toContain('data-retained-system-notice')
    expect(orphaned).not.toContain('data-selected-system-notice')
    expect(replaced).toContain('data-selected-system-notice')
    expect(replaced).not.toContain('data-retained-system-notice')
    expect(replaced).not.toContain('data-orphan-system-notice')
  })

  it('renders every measurement input with its scale hint and shows resolved device details', () => {
    const form = {
      ...emptyIntegratingSphereInspectionForm('2026-08-20T12:27'),
      equipment: [
        {
          child_id: null,
          equipment_id: 7,
          equipment_no: 'XPD-S-001',
          equipment_name: '积分球',
          manufacturer: '杭州远方',
          model: 'HAAS-2000',
          serial_no: 'SN-XPD-001',
          next_calibration_date: '2027-03-01',
        },
      ],
      sample: { source: 'selected' as const, id: 3, sample_no: '26010058874-1-1/1', sample_name: '灯具', model: 'LD-1' },
      chromaticity_x: '0.3633',
    }
    const html = renderToStaticMarkup(
      <InspectionRecordFormFields form={form} {...formProps} fieldErrors={{ chromaticity_y: '最多保留 4 位小数' }} />,
    )

    expect(html).toContain('data-selected-equipment-details')
    expect(html).toContain('杭州远方')
    expect(html).toContain('HAAS-2000')
    expect(html).toContain('SN-XPD-001')
    expect(html).toContain('2027-03-01')
    expect(html).toContain('26010058874-1-1/1')
    expect(html).toContain('placeholder="0.0000"')
    expect(html).toContain('placeholder="0.0"')
    expect(html).toContain('placeholder="0"')
    expect(html).toContain('主波长（nm）')
    expect(html).toContain('色温（K）')
    expect(html).toContain('功率因数')
    expect(html).toContain('最多保留 4 位小数')
    expect(html).toContain('value="0.3633"')
  })

  it('marks a retained snapshot whose ledger row was deleted instead of hiding it', () => {
    const form = {
      ...emptyIntegratingSphereInspectionForm('2026-08-20T12:27'),
      sample: { source: 'retained' as const, id: null, sample_no: 'S-HISTORY' },
      equipment: [
        {
          child_id: 11,
          equipment_id: 7,
          equipment_no: 'XPD-S-001',
          equipment_name: '积分球',
          manufacturer: '杭州远方',
          model: 'HAAS-2000',
          serial_no: 'SN-XPD-001',
          next_calibration_date: '2027-03-01',
        },
        {
          child_id: 12,
          equipment_id: null,
          equipment_no: 'XPD-S-002',
          equipment_name: '光谱仪',
          manufacturer: '虹昌电子',
          model: 'SPC-100',
          serial_no: 'SN-XPD-002',
          next_calibration_date: '2027-05-20',
        },
      ],
    }
    const html = renderToStaticMarkup(<InspectionRecordFormFields form={form} {...formProps} />)

    // The orphaned device and sample must still be visible and explained, otherwise
    // the operator cannot tell that saving keeps them.
    expect(html).toContain('XPD-S-002')
    expect(html).toContain('光谱仪')
    expect(html).toContain('虹昌电子')
    expect(html).toContain('SN-XPD-002')
    expect(html).toContain('2027-05-20')
    expect(html).toContain('data-orphan-equipment-notice')
    expect(html).toContain('台账已删除')
    expect(html).toContain('S-HISTORY')
    expect(html).toContain('data-orphan-sample-notice')
    expect(html).toContain('设备台账')
  })

  it('tells the operator whether the sample will be kept as a snapshot or replaced', () => {
    const base = emptyIntegratingSphereInspectionForm('2026-08-20T12:27')
    const retained = renderToStaticMarkup(
      <InspectionRecordFormFields
        form={{ ...base, sample: { source: 'retained', id: 3, sample_no: '26010058874-1-1/1' } }}
        {...formProps}
      />,
    )
    const replaced = renderToStaticMarkup(
      <InspectionRecordFormFields
        form={{ ...base, sample: { source: 'selected', id: 9, sample_no: '26010058874-2-1/1' } }}
        {...formProps}
      />,
    )

    // A live ledger row is not enough to re-declare the sample: the record's own
    // number stays until the operator scans a replacement.
    expect(retained).toContain('data-retained-sample-notice')
    expect(retained).not.toContain('data-selected-sample-notice')
    expect(retained).not.toContain('data-orphan-sample-notice')
    expect(replaced).toContain('data-selected-sample-notice')
    expect(replaced).not.toContain('data-retained-sample-notice')
  })

  it('does not ship a second scanner implementation', () => {
    // The scanner blocks moved into the shared inspection fields when the second
    // inspection workflow was added, so the guarantee is now that neither this page
    // nor the shared module reimplements the camera.
    expect(sharedFieldsSource).toContain("import { QrScannerPanel } from '../../components/app/QrScannerPanel'")

    for (const source of [pageSource, sharedFieldsSource]) {
      expect(source).not.toContain('html5-qrcode')
      expect(source).not.toContain('getUserMedia')
    }
  })
})

describe('integrating sphere inspection navigation and route permission', () => {
  it('adds one equipment navigation entry guarded by the inspection read permission', () => {
    const items = navGroups.flatMap((group) => group.items).filter((item) => item.label === '积分球点检记录')

    expect(items).toHaveLength(1)
    expect(items[0]).toMatchObject({
      to: '/equipment/integrating-sphere-inspections',
      resource: 'integrating_sphere_inspection_records',
      action: 'read',
    })
    expect(navGroups.find((group) => group.items.includes(items[0]))?.label).toBe('设备管理')
  })

  it('guards the route with the same resource permission', () => {
    expect(routesSource).toContain("path: '/equipment/integrating-sphere-inspections'")
    expect(routesSource).toContain("requireRoutePermission('integrating_sphere_inspection_records')")

    const granted = { resources: { integrating_sphere_inspection_records: { actions: { read: true } } } }
    const denied = { resources: { integrating_sphere_inspection_records: { actions: { read: false } } } }

    expect(allowsRoute(granted, 'integrating_sphere_inspection_records', 'read')).toBe(true)
    expect(allowsRoute(denied, 'integrating_sphere_inspection_records', 'read')).toBe(false)
    expect(allowsRoute({ resources: {} }, 'integrating_sphere_inspection_records', 'read')).toBe(false)
  })
})

const ledgerRows: IntegratingSphereEquipmentLedgerRow[] = [
  {
    id: 11,
    inspection_record_id: 4,
    equipment_id: 7,
    equipment_no: 'XPD-S-001',
    equipment_name: '积分球',
    manufacturer: '杭州远方',
    model: 'HAAS-2000',
    serial_no: 'SN-XPD-001',
    next_calibration_date: '2027-03-01',
    recorded_at: '2026-08-20 12:27:00',
    operator_name: '点检员',
  },
  {
    id: 12,
    inspection_record_id: 4,
    equipment_id: null,
    equipment_no: 'XPD-S-002',
    equipment_name: '光谱仪',
    manufacturer: '虹昌电子',
    model: 'SPC-100',
    serial_no: 'SN-XPD-002',
    next_calibration_date: '2027-05-20',
    recorded_at: '2026-08-20 12:27:00',
    operator_name: '点检员',
  },
]

describe('global used-equipment ledger view', () => {
  it('renders every requested column for each association row', () => {
    const html = renderToStaticMarkup(<InspectionEquipmentTable rows={ledgerRows} />)

    for (const header of ['ID', '点检记录ID', '设备台账ID', '设备编号', '名称', '制造商', '型号', '序列号', '下次校准', '日期', '操作人']) {
      expect(html).toContain(header)
    }

    expect(html).toContain('XPD-S-001')
    expect(html).toContain('杭州远方')
    expect(html).toContain('HAAS-2000')
    expect(html).toContain('SN-XPD-001')
    expect(html).toContain('2027-03-01')
    expect(html).toContain('2026-08-20 12:27:00')
    expect(html).toContain('点检员')
    expect(html).toContain('虹昌电子')
    expect(html).toContain('SPC-100')
  })

  it('keeps a deleted live equipment row visible with its snapshot', () => {
    const html = renderToStaticMarkup(<InspectionEquipmentTable rows={[ledgerRows[1]]} />)

    expect(html).toContain('XPD-S-002')
    expect(html).toContain('已删除')
    expect(html).toContain('SN-XPD-002')
  })

  it('shows all three ids plus date and operator on mobile cards', () => {
    const html = renderToStaticMarkup(<InspectionEquipmentCards rows={ledgerRows} />)

    expect(html).toContain('data-mobile-integrating-sphere-equipment')
    expect(html).toContain('md:hidden')
    expect(html).toContain('>11<')
    expect(html).toContain('点检记录ID')
    expect(html).toContain('>4<')
    expect(html).toContain('设备台账ID')
    expect(html).toContain('>7<')
    expect(html).toContain('2026-08-20 12:27:00')
    expect(html).toContain('点检员')
    // Cards must not fall back to the wide table on a phone.
    expect(html).not.toContain('<table')
  })

  it('renders the two association rows of one record with distinct child and equipment ids', () => {
    const html = renderToStaticMarkup(<InspectionEquipmentTable rows={ledgerRows} />)
    const recordIdCells = html.match(/>4</g) ?? []

    expect(recordIdCells.length).toBeGreaterThanOrEqual(2)
    expect(html).toContain('>11<')
    expect(html).toContain('>12<')
  })
})

describe('integrating sphere page views', () => {
  it('offers exactly the two page level views on one route', () => {
    expect(pageSource).toContain('data-integrating-sphere-views')
    expect(pageSource).toContain('点检记录总表')
    expect(pageSource).toContain('使用设备总表')
    expect(pageSource).toContain("{view === 'records' ? (")
    expect(pageSource).toContain("useState<IntegratingSphereView>('records')")
    // One route, one nav entry: the ledger must not add either.
    expect(routesSource.match(/path: '[^']*integrating-sphere[^']*'/g) ?? []).toEqual([
      "path: '/equipment/integrating-sphere-inspections'",
    ])
    expect(routesSource.match(/component: IntegratingSphereInspectionPage/g) ?? []).toHaveLength(1)
  })

  it('requests the ledger endpoint only while its view is active', () => {
    expect(pageSource).toContain("'/api/integrating-sphere-inspection-records/equipment'")
    expect(pageSource).toContain("enabled: view === 'equipment'")
    expect(pageSource).toContain('buildIntegratingSphereEquipmentListParams(equipmentFilters, equipmentPage, equipmentPerPage)')
  })

  it('shows the system code on the record detail next to the sample number', () => {
    const html = renderToStaticMarkup(<InspectionRecordDetail record={record} />)
    const legacy = renderToStaticMarkup(<InspectionRecordDetail record={{ ...record, equipment_system_id: null, system_code: null }} />)

    expect(html).toContain('系统编码')
    expect(html).toContain('sys-01')
    expect(html.indexOf('样品编号')).toBeLessThan(html.indexOf('系统编码'))
    expect(legacy).toContain('系统编码')
  })

  it('keeps the global used-equipment ledger table untouched by the system code', () => {
    const html = renderToStaticMarkup(<InspectionEquipmentTable rows={ledgerRows} />)

    expect(html).not.toContain('系统编码')
    expect(html).toContain('XPD-S-001')
  })

  it('announces all three scanned inputs in the page description so the system code is visible on entry', () => {
    const description = /<PageShell[\s\S]*?description="([^"]*)"/.exec(pageSource)?.[1] ?? ''

    // Every one of the three lookups is an independent scan-or-type step, and the
    // system code is required, so the entry copy must not stop at the devices.
    expect(description).toContain('设备编号')
    expect(description).toContain('样品编号')
    expect(description).toContain('系统编码')
    expect(description.indexOf('系统编码')).toBeLessThan(description.indexOf('测量值'))
    expect(pageSource).not.toContain('扫码或手输设备编号后选择样品')
  })

  it('mentions the system code in the empty state that previews the list columns', () => {
    const description = /<EmptyState title="暂无积分球点检记录" description="([^"]*)"/.exec(pageSource)?.[1] ?? ''

    expect(description).toContain('样品编号')
    expect(description).toContain('系统编码')
    // The used-equipment column is gone from the list, so the preview must not promise it.
    expect(description).not.toContain('使用设备')
  })

  it('names both the sample number and the system code in the record list search field', () => {
    expect(pageSource).toContain('<Field label="样品/系统编码">')
    expect(pageSource).toContain('placeholder="样品编号/系统编码"')
    // The search still reaches the backend unchanged, which keeps matching devices.
    expect(pageSource).toContain('buildIntegratingSphereInspectionListParams(filters, page, perPage)')
  })

  it('keeps the per-record detail snapshot table unchanged', () => {
    const html = renderToStaticMarkup(<InspectionRecordDetail record={record} />)

    expect(html).toContain('data-inspection-equipment-snapshots')
    expect(html).toContain('设备编号')
    expect(html).toContain('生产厂家')
    expect(html).toContain('出厂编号')
    expect(html).toContain('下次校准日期')
    // The detail table is per-record, so it must not carry the global ledger ids.
    expect(html).not.toContain('点检记录ID')
    expect(html).not.toContain('设备台账ID')
  })

  it('still exposes a single navigation entry for both views', () => {
    const entries = navGroups.flatMap((group) => group.items).filter((item) => item.to.includes('integrating-sphere'))

    expect(entries).toHaveLength(1)
    expect(entries[0].label).toBe('积分球点检记录')
  })
})

describe('mutation cache invalidation', () => {
  function seededClient() {
    // Mirrors the app's real client: a 30s staleTime is exactly what let the
    // association ledger keep serving stale rows after a record mutation.
    const client = new QueryClient({ defaultOptions: { queries: { staleTime: 30_000, retry: false } } })

    // The page's keys carry filters and pagination, so invalidation has to match by
    // prefix rather than by the exact key.
    client.setQueryData([...integratingSphereRecordsQueryKey, { search: '' }, 1, 15], { data: [] })
    client.setQueryData([...integratingSphereEquipmentQueryKey, { search: '' }, 2, 30], { data: [] })
    client.setQueryData(['equipment-usage-records', {}, 1, 15], { data: [] })

    return client
  }

  const states = (client: QueryClient) => ({
    records: client.getQueryState([...integratingSphereRecordsQueryKey, { search: '' }, 1, 15])?.isInvalidated,
    equipment: client.getQueryState([...integratingSphereEquipmentQueryKey, { search: '' }, 2, 30])?.isInvalidated,
    unrelated: client.getQueryState(['equipment-usage-records', {}, 1, 15])?.isInvalidated,
  })

  it('starts from fresh cache entries so the assertions below mean something', () => {
    expect(states(seededClient())).toEqual({ records: false, equipment: false, unrelated: false })
  })

  it('invalidates both list families and nothing else', async () => {
    const client = seededClient()

    await invalidateIntegratingSphereLists(client)

    expect(states(client)).toEqual({ records: true, equipment: true, unrelated: false })
  })

  it('covers both query families on the save success path', async () => {
    const client = seededClient()
    let closed = false
    const handlers = integratingSphereMutationHandlers(client, () => {
      // The lists must already be invalidated by the time the modal settles.
      expect(states(client)).toEqual({ records: true, equipment: true, unrelated: false })
      closed = true
    })

    await handlers.saveSuccess()

    expect(closed).toBe(true)
    expect(states(client)).toEqual({ records: true, equipment: true, unrelated: false })
  })

  it('covers both query families on the delete success path', async () => {
    const client = seededClient()
    const handlers = integratingSphereMutationHandlers(client, () => {
      throw new Error('deleting a record must not close the editor')
    })

    await handlers.deleteSuccess()

    expect(states(client)).toEqual({ records: true, equipment: true, unrelated: false })
  })

  it('routes every mutation through the shared helper instead of ad-hoc calls', () => {
    // The page owns no invalidation of its own: the only two invalidateQueries
    // calls live in the shared helper module, and the mutations reach them there.
    expect(pageSource).not.toContain('invalidateQueries')
    expect(queriesSource.match(/invalidateQueries\(/g) ?? []).toHaveLength(2)
    expect(pageSource.match(/onSuccess: mutationHandlers\./g) ?? []).toHaveLength(2)
    expect(pageSource).toContain('onSuccess: mutationHandlers.saveSuccess')
    expect(pageSource).toContain('onSuccess: mutationHandlers.deleteSuccess')
  })
})
