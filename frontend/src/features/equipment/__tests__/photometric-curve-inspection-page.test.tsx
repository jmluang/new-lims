import { QueryClient } from '@tanstack/react-query'
import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { renderToStaticMarkup } from 'react-dom/server'
import { describe, expect, it, vi } from 'vitest'
import { allowsRoute } from '../../../app/routePermissions'
import { navGroups } from '../../../components/app/navigation'
import {
  InspectionEquipmentCards,
  InspectionEquipmentTable,
  InspectionRecordCards,
  InspectionRecordDetail,
  InspectionRecordFormFields,
  InspectionRecordTable,
} from '../PhotometricCurveInspectionPage'
import {
  invalidatePhotometricCurveLists,
  photometricCurveEquipmentQueryKey,
  photometricCurveMutationHandlers,
  photometricCurveRecordsQueryKey,
} from '../photometricCurveQueries'
import {
  emptyPhotometricCurveInspectionForm,
  inspectionFormFromRecord,
  type PhotometricCurveEquipmentLedgerRow,
  type PhotometricCurveInspectionRecord,
} from '../photometricCurveInspectionSchema'

const pageSource = readFileSync(fileURLToPath(new URL('../PhotometricCurveInspectionPage.tsx', import.meta.url)), 'utf8')
const routesSource = readFileSync(fileURLToPath(new URL('../../../app/routes.tsx', import.meta.url)), 'utf8')
const queriesSource = readFileSync(fileURLToPath(new URL('../photometricCurveQueries.ts', import.meta.url)), 'utf8')

vi.mock('../../auth/useCurrentUser', () => ({
  useEffectivePermissions: () => ({
    data: {
      resources: {
        photometric_curve_inspection_records: {
          actions: { read: true, create: true, update: true, delete: false },
          fields: {},
        },
      },
    },
  }),
}))

const record: PhotometricCurveInspectionRecord = {
  id: 1,
  sample_id: 3,
  sample_no: '26010058874-1-1/1',
  equipment_system_id: 5,
  system_code: 'sys-01',
  system_name: '系统1',
  c0_180: '60.2',
  c30_210: '60.3',
  c60_240: '64.5',
  c90_270: '60.8',
  average_angle: '61.5',
  probe: 'far_field',
  test_distance: '26.0000',
  peak_luminous_intensity: '221.0',
  luminous_flux: '1674.0',
  voltage: '220.8',
  current: '0.1189',
  power: '14.2400',
  power_factor: '0.5422',
  frequency: 50,
  remark: '首件点检',
  recorded_at: '2026-08-21 10:29:00',
  operator_id: 9,
  operator_name: '点检员',
  equipment: [
    {
      id: 11,
      equipment_id: 7,
      equipment_no: 'XPD-S-001',
      equipment_name: '智能交流测试专用电源',
      manufacturer: '杭州远方',
      model: 'DPS1060-V200',
      serial_no: 'G117422CJ1361114',
      next_calibration_date: '2027-03-01',
    },
    {
      id: 12,
      equipment_id: 8,
      equipment_no: 'XPD-S-004',
      equipment_name: '数字功率计',
      manufacturer: '杭州远方',
      model: 'PF310',
      serial_no: 'G122097CA8361137',
      next_calibration_date: '2027-05-20',
    },
  ],
  photos: [{ id: 21, collection: 'photos', file_name: 'curve.jpg', mime_type: 'image/jpeg', size: 2048, sha256: 'a'.repeat(64) }],
  files: [{ id: 22, collection: 'files', file_name: 'report.pdf', mime_type: 'application/pdf', size: 4096, sha256: 'b'.repeat(64) }],
}

const ledgerRows: PhotometricCurveEquipmentLedgerRow[] = [
  {
    id: 11,
    inspection_record_id: 4,
    equipment_id: 7,
    equipment_no: 'XPD-S-001',
    equipment_name: '智能交流测试专用电源',
    manufacturer: '杭州远方',
    model: 'DPS1060-V200',
    serial_no: 'G117422CJ1361114',
    next_calibration_date: '2027-03-01',
    recorded_at: '2026-08-21 10:29:00',
    operator_name: '点检员',
  },
  {
    id: 12,
    inspection_record_id: 4,
    equipment_id: null,
    equipment_no: 'XPD-S-004',
    equipment_name: '数字功率计',
    manufacturer: '杭州远方',
    model: 'PF310',
    serial_no: 'G122097CA8361137',
    next_calibration_date: '2027-05-20',
    recorded_at: '2026-08-21 10:29:00',
    operator_name: '点检员',
  },
]

const actionProps = { canDelete: false, onDetail: () => {}, onEdit: () => {}, onDelete: () => {} }
const formProps = {
  recordId: null,
  fieldErrors: {},
  equipmentLookupFailed: false,
  sampleLookupFailed: false,
  systemLookupFailed: false,
  onEquipmentCode: () => {},
  onSampleCode: () => {},
  onSystemCode: () => {},
  onRemoveEquipment: () => {},
  onChange: () => {},
}

describe('photometric curve record list', () => {
  it('keeps the list scannable instead of squeezing the whole workbook into one row', () => {
    const html = renderToStaticMarkup(<InspectionRecordTable records={[record]} {...actionProps} />)
    const headers = [...html.matchAll(/<th[^>]*>([^<]*)<\/th>/g)].map((match) => match[1])

    expect(headers).toEqual([
      'ID',
      '样品编号',
      '系统编码',
      '平均角度',
      '探头',
      '测试距离(m)',
      '峰值光强(cd)',
      '光通量(lm)',
      '记录日期',
      '操作人',
      '操作',
    ])
    // The electrical measurements, the attachments and the devices belong to detail.
    expect(headers).not.toContain('电压')
    expect(headers).not.toContain('功率因数')
    expect(headers).not.toContain('照片')
    expect(html).not.toContain('XPD-S-001')
  })

  it('shows the derived average, the probe in Chinese and the exact stored precision', () => {
    const html = renderToStaticMarkup(<InspectionRecordTable records={[record]} {...actionProps} />)

    expect(html).toContain('>61.5<')
    expect(html).toContain('>远场<')
    expect(html).toContain('>26.0000<')
    expect(html).toContain('>221.0<')
    expect(html).toContain('>1674.0<')
    expect(html).toContain('2026-08-21 10:29:00')
    expect(html).toContain('点检员')
    expect(html).not.toContain('far_field')
  })

  it('falls back to a placeholder when the system snapshot is missing', () => {
    const html = renderToStaticMarkup(
      <InspectionRecordTable records={[{ ...record, equipment_system_id: null, system_code: null }]} {...actionProps} />,
    )

    expect(html).toContain('>系统编码</th>')
    expect(html).toContain('>-</td>')
  })

  it('renders the granted actions and leaves out the ungranted delete action', () => {
    const withoutDelete = renderToStaticMarkup(<InspectionRecordTable records={[record]} {...actionProps} />)
    const withDelete = renderToStaticMarkup(<InspectionRecordTable records={[record]} {...actionProps} canDelete />)

    expect(withoutDelete).toContain('详情')
    expect(withoutDelete).toContain('编辑')
    expect(withoutDelete).not.toContain('删除')
    expect(withDelete).toContain('删除')
  })

  it('uses cards on mobile carrying the ids and the audit fields', () => {
    const html = renderToStaticMarkup(<InspectionRecordCards records={[record]} {...actionProps} />)

    expect(html).toContain('data-mobile-photometric-curve-records')
    expect(html).toContain('md:hidden')
    expect(html).toContain('>ID</dt>')
    expect(html).toContain('>1</dd>')
    expect(html).toContain('26010058874-1-1/1')
    expect(html).toContain('data-mobile-system-code')
    expect(html).toContain('sys-01')
    expect(html).toContain('远场')
    expect(html).toContain('61.5')
    expect(html).toContain('26.0000 m')
    expect(html).toContain('221.0 cd')
    expect(html).toContain('2026-08-21 10:29:00')
    expect(html).toContain('点检员')
    expect(html).toContain('详情')
    expect(html).toContain('编辑')
    // Cards must not fall back to the wide table on a phone.
    expect(html).not.toContain('<table')
  })
})

describe('photometric curve record detail', () => {
  it('carries every measurement with its unit, plus the derived average', () => {
    const html = renderToStaticMarkup(<InspectionRecordDetail record={record} />)

    for (const value of ['60.2', '60.3', '64.5', '60.8', '26.0000', '221.0', '1674.0', '220.8', '0.1189', '14.2400', '0.5422', '50']) {
      expect(html).toContain(value)
    }

    expect(html).toContain('测试距离（m）')
    expect(html).toContain('电流（A）')
    expect(html).toContain('频率（Hz）')
    expect(html).toContain('光通量（lm）')
    expect(html).toContain('平均角度（自动计算）')
    expect(html).toContain('>61.5</dd>')
    expect(html).toContain('系统名称')
    expect(html).toContain('系统1')
    expect(html).toContain('首件点检')
    expect(html).toContain('远场')
  })

  it('lists every used device with its full snapshot', () => {
    const html = renderToStaticMarkup(<InspectionRecordDetail record={record} />)

    expect(html).toContain('data-inspection-equipment-snapshots')
    expect(html).toContain('XPD-S-001')
    expect(html).toContain('DPS1060-V200')
    expect(html).toContain('G117422CJ1361114')
    expect(html).toContain('XPD-S-004')
    expect(html).toContain('PF310')
    expect(html).toContain('2027-05-20')
    // The detail table is per-record, so it must not carry the global ledger ids.
    expect(html).not.toContain('点检记录ID')
  })

  it('names each attachment with its size and offers a download, never a public URL', () => {
    const html = renderToStaticMarkup(<InspectionRecordDetail record={record} />)

    expect(html).toContain('data-record-attachments')
    expect(html).toContain('照片（1）')
    expect(html).toContain('文件（1）')
    expect(html).toContain('report.pdf')
    expect(html).toContain('4.00 KB')
    expect(html).toContain('下载')
    expect(html).not.toContain('inspection-media')
    expect(html).not.toContain('/storage/')
  })

  it('says so plainly when a record carries no attachments', () => {
    const html = renderToStaticMarkup(<InspectionRecordDetail record={{ ...record, photos: [], files: [] }} />)

    expect(html).toContain('暂无照片')
    expect(html).toContain('暂无文件')
  })

  it('loads photo bytes through the authenticated endpoint and revokes the object URL', () => {
    expect(pageSource).toContain("`${BASE}/${recordId}/media/${media.id}/view`, { responseType: 'blob' }")
    expect(pageSource).toContain('URL.createObjectURL')
    expect(pageSource).toContain('URL.revokeObjectURL')
    expect(pageSource).toContain('link.download = media.file_name')
  })
})

describe('photometric curve editor', () => {
  it('puts equipment entry first, then the sample and the system, all on the shared QR scanner', () => {
    const html = renderToStaticMarkup(
      <InspectionRecordFormFields form={emptyPhotometricCurveInspectionForm()} {...formProps} />,
    )

    expect(html.indexOf('使用设备（先录入）')).toBeLessThan(html.indexOf('样品编号'))
    expect(html.indexOf('样品编号')).toBeLessThan(html.indexOf('系统编码'))
    expect(html.indexOf('系统编码')).toBeLessThan(html.indexOf('测量值'))
    expect(html.match(/data-scanner-selection/g)).toHaveLength(3)
    expect(html).toContain('扫码/手输设备编号')
    expect(html).toContain('扫码/手输样品编号')
    expect(html).toContain('扫码/手输系统编码')
    expect(html).toContain('尚未录入设备')
    expect(html).toContain('尚未录入样品')
    expect(html).toContain('尚未录入系统编码')
  })

  it('shows the average angle as a read-only field derived from the four angles', () => {
    const form = {
      ...emptyPhotometricCurveInspectionForm(),
      c0_180: '60.2',
      c30_210: '60.3',
      c60_240: '64.5',
      c90_270: '60.8',
    }
    const html = renderToStaticMarkup(<InspectionRecordFormFields form={form} {...formProps} />)
    const changed = renderToStaticMarkup(<InspectionRecordFormFields form={{ ...form, c90_270: '70.8' }} {...formProps} />)

    expect(html).toContain('data-average-angle')
    expect(html).toContain('平均角度（自动计算）')
    const averageInput = /<input[^>]*data-average-angle[^>]*>/.exec(html)?.[0] ?? ''
    expect(averageInput).toMatch(/readonly/i)
    expect(averageInput).not.toMatch(/onchange/i)
    expect(html).toContain('value="61.5"')
    expect(changed).toContain('value="64.0"')
    // Derived, never sent, never independently editable.
    expect(pageSource).toContain('const averageAngle = deriveAverageAngle(form)')
    expect(pageSource).not.toContain("onChange({ average_angle")
  })

  it('leaves the average blank until all four angles are valid', () => {
    const html = renderToStaticMarkup(
      <InspectionRecordFormFields
        form={{ ...emptyPhotometricCurveInspectionForm(), c0_180: '60.2', c30_210: '60.3' }}
        {...formProps}
      />,
    )

    expect(html).toContain('data-average-angle')
    expect(html).not.toContain('value="30.1"')
  })

  it('offers no control at all for the server-owned recorded time', () => {
    const html = renderToStaticMarkup(
      <InspectionRecordFormFields form={inspectionFormFromRecord(record)} {...formProps} recordId={record.id} />,
    )

    // The timestamp is audit evidence: the editor shows it nowhere it could be typed,
    // and carries no datetime input that a save could pick up.
    expect(html).not.toContain('记录时间')
    expect(html).not.toContain('type="datetime-local"')
    expect(html).not.toContain('2026-08-21')
    expect(pageSource).not.toContain('recorded_at: event.target.value')
    expect(pageSource).not.toContain('localDateTimeInputValue')
  })

  it('offers the probe as a select of the two named options, not free text', () => {
    const html = renderToStaticMarkup(
      <InspectionRecordFormFields form={emptyPhotometricCurveInspectionForm()} {...formProps} />,
    )

    expect(html).toContain('<select')
    expect(html).toContain('value="near_field"')
    expect(html).toContain('value="far_field"')
    expect(html).toContain('>近场</option>')
    expect(html).toContain('>远场</option>')
  })

  it('labels every numeric input with its unit and shows the decimal hint', () => {
    const html = renderToStaticMarkup(
      <InspectionRecordFormFields form={emptyPhotometricCurveInspectionForm()} {...formProps} />,
    )

    expect(html).toContain('测试距离（m）')
    expect(html).toContain('峰值光强（cd）')
    expect(html).toContain('光通量（lm）')
    expect(html).toContain('电流（A）')
    expect(html).toContain('频率（Hz）')
    expect(html).toContain('placeholder="0.0000"')
    expect(html).toContain('placeholder="0.0"')
    expect(html).toContain('inputMode="decimal"')
    expect(html).toContain('inputMode="numeric"')
  })

  it('marks the exact field that failed instead of one opaque banner', () => {
    const html = renderToStaticMarkup(
      <InspectionRecordFormFields
        form={emptyPhotometricCurveInspectionForm()}
        {...formProps}
        fieldErrors={{ c0_180: '请填写测量值', voltage: '最多保留 1 位小数', photos: '最多保留 10 个附件' }}
      />,
    )

    expect(html).toContain('请填写测量值')
    expect(html).toContain('最多保留 1 位小数')
    expect(html).toContain('最多保留 10 个附件')
  })

  it('keeps the stored attachments visible and removable while editing', () => {
    const html = renderToStaticMarkup(
      <InspectionRecordFormFields form={inspectionFormFromRecord(record)} {...formProps} recordId={record.id} />,
    )

    expect(html).toContain('data-inspection-attachments')
    expect(html).toContain('data-retained-media')
    expect(html).toContain('curve.jpg')
    expect(html).toContain('report.pdf')
    expect(html).toContain('照片（1/10）')
    expect(html).toContain('文件（1/10）')
    expect(html).toContain('移除 curve.jpg')
    expect(html).toContain('移除 report.pdf')
  })

  it('restricts what each attachment picker will accept', () => {
    const html = renderToStaticMarkup(
      <InspectionRecordFormFields form={emptyPhotometricCurveInspectionForm()} {...formProps} />,
    )

    expect(html).toContain('accept="image/jpeg,image/png,image/webp"')
    expect(html).toContain('accept=".pdf,.xls,.xlsx,.csv,.doc,.docx,.zip"')
    expect(html).toContain('尚未选择照片')
    expect(html).toContain('尚未选择文件')
  })

  it('keeps a retained orphan device snapshot visible with its warning', () => {
    const orphaned = inspectionFormFromRecord({
      ...record,
      equipment: [{ ...record.equipment[0], equipment_id: null }],
    })
    const html = renderToStaticMarkup(<InspectionRecordFormFields form={orphaned} {...formProps} />)

    expect(html).toContain('data-orphan-equipment-notice')
    expect(html).toContain('XPD-S-001')
    expect(html).toContain('台账已删除 · 保留快照')
  })

  it('distinguishes a retained subject from one the operator just re-scanned', () => {
    const retained = renderToStaticMarkup(<InspectionRecordFormFields form={inspectionFormFromRecord(record)} {...formProps} />)
    const replaced = renderToStaticMarkup(
      <InspectionRecordFormFields
        form={{
          ...inspectionFormFromRecord(record),
          sample: { source: 'selected', id: 3, sample_no: '26010058874-1-1/1' },
          system: { source: 'selected', id: 5, code: 'sys-01', name: '系统1' },
        }}
        {...formProps}
      />,
    )

    expect(retained).toContain('data-retained-sample-notice')
    expect(retained).toContain('data-retained-system-notice')
    expect(replaced).toContain('data-selected-sample-notice')
    expect(replaced).toContain('data-selected-system-notice')
    expect(replaced).not.toContain('data-retained-sample-notice')
  })

  it('reports a failed lookup for each of the three codes independently', () => {
    const html = renderToStaticMarkup(
      <InspectionRecordFormFields
        form={emptyPhotometricCurveInspectionForm()}
        {...formProps}
        equipmentLookupFailed
        sampleLookupFailed
        systemLookupFailed
      />,
    )

    expect(html).toContain('未找到设备')
    expect(html).toContain('未找到样品')
    expect(html).toContain('未找到系统编码')
  })
})

describe('photometric curve used-equipment ledger', () => {
  it('flattens one row per record-to-device association with the three ids', () => {
    const html = renderToStaticMarkup(<InspectionEquipmentTable rows={ledgerRows} />)
    const headers = [...html.matchAll(/<th[^>]*>([^<]*)<\/th>/g)].map((match) => match[1])

    expect(headers).toEqual(['ID', '点检记录ID', '设备台账ID', '设备编号', '名称', '制造商', '型号', '序列号', '下次校准', '日期', '操作人'])
    expect(html).toContain('>11<')
    expect(html).toContain('>12<')
    expect(html).toContain('XPD-S-001')
    expect(html).toContain('XPD-S-004')
    expect(html).toContain('2026-08-21 10:29:00')
    expect(html).toContain('点检员')
    // A snapshot whose ledger row is gone still shows, marked as deleted.
    expect(html).toContain('已删除')
  })

  it('uses cards on mobile carrying the same ids', () => {
    const html = renderToStaticMarkup(<InspectionEquipmentCards rows={ledgerRows} />)

    expect(html).toContain('data-mobile-photometric-curve-equipment')
    expect(html).toContain('md:hidden')
    expect(html).toContain('点检记录ID')
    expect(html).toContain('>4</dd>')
    expect(html).toContain('设备台账ID')
    expect(html).toContain('>7</dd>')
    expect(html).toContain('已删除')
    expect(html).not.toContain('<table')
  })
})

describe('photometric curve page wiring', () => {
  it('offers exactly the two page level views on one route', () => {
    expect(pageSource).toContain('data-photometric-curve-views')
    expect(pageSource).toContain('点检记录总表')
    expect(pageSource).toContain('使用设备总表')
    expect(pageSource).toContain("useState<PhotometricCurveView>('records')")
    expect(routesSource.match(/path: '[^']*photometric-curve[^']*'/g) ?? []).toEqual([
      "path: '/equipment/photometric-curve-inspections'",
    ])
    expect(routesSource.match(/component: PhotometricCurveInspectionPage/g) ?? []).toHaveLength(1)
  })

  it('requests the ledger endpoint only while its view is active', () => {
    expect(pageSource).toContain('`${BASE}/equipment`')
    expect(pageSource).toContain("enabled: view === 'equipment'")
    expect(pageSource).toContain('buildPhotometricCurveEquipmentListParams(equipmentFilters, equipmentPage, equipmentPerPage)')
  })

  it('filters on free text, probe and a date range, and resets the page on every change', () => {
    expect(pageSource).toContain('<Field label="样品/系统/设备">')
    expect(pageSource).toContain('placeholder="样品编号/系统编码/设备编号"')
    expect(pageSource).toContain('<Field label="探头">')
    expect(pageSource).toContain('<option value="">全部</option>')
    expect(pageSource).toContain('buildPhotometricCurveInspectionListParams(filters, page, perPage)')
    expect(pageSource).toContain('setFilters(emptyPhotometricCurveInspectionFilters)')
    expect((pageSource.match(/setPage\(1\)/g) ?? []).length).toBeGreaterThanOrEqual(5)
  })

  it('posts multipart for both create and edit, spoofing the method on the edit', () => {
    expect(pageSource).toContain('await api.post(BASE, buildPhotometricCurveInspectionPayload(form, \'create\'))')
    expect(pageSource).toContain('await api.post(`${BASE}/${editingId}`, buildPhotometricCurveInspectionPayload(form, \'update\'))')
    expect(pageSource).not.toContain('api.put(')
  })

  it('invalidates both list families after every mutation', async () => {
    const queryClient = new QueryClient()
    const invalidated: unknown[][] = []
    vi.spyOn(queryClient, 'invalidateQueries').mockImplementation(async (filters) => {
      invalidated.push((filters as { queryKey: unknown[] }).queryKey)
    })

    await invalidatePhotometricCurveLists(queryClient)

    expect(invalidated).toEqual([[...photometricCurveRecordsQueryKey], [...photometricCurveEquipmentQueryKey]])
    expect(queriesSource).toContain('photometric-curve-inspection-records')
    expect(queriesSource).toContain('photometric-curve-inspection-equipment')
  })

  it('only closes the editor once the lists have been refreshed', async () => {
    const queryClient = new QueryClient()
    const order: string[] = []
    vi.spyOn(queryClient, 'invalidateQueries').mockImplementation(async () => {
      order.push('invalidate')
    })
    const handlers = photometricCurveMutationHandlers(queryClient, () => order.push('close'))

    await handlers.saveSuccess()

    expect(order).toEqual(['invalidate', 'invalidate', 'close'])
  })

  it('does not ship a second scanner implementation', () => {
    expect(pageSource).not.toContain('html5-qrcode')
    expect(pageSource).not.toContain('getUserMedia')
    expect(pageSource).toContain("from './InspectionSharedFields'")
  })
})

describe('photometric curve navigation and route permission', () => {
  it('adds exactly one equipment navigation entry guarded by the read permission', () => {
    const items = navGroups.flatMap((group) => group.items).filter((item) => item.to.includes('photometric-curve'))

    expect(items).toHaveLength(1)
    expect(items[0]).toMatchObject({
      label: '配光曲线点检记录',
      to: '/equipment/photometric-curve-inspections',
      resource: 'photometric_curve_inspection_records',
      action: 'read',
    })
    expect(navGroups.find((group) => group.items.includes(items[0]))?.label).toBe('设备管理')
  })

  it('hides the route from a user without the read permission', () => {
    const granted = { resources: { photometric_curve_inspection_records: { actions: { read: true }, fields: {} } } }
    const denied = { resources: { photometric_curve_inspection_records: { actions: { read: false }, fields: {} } } }

    expect(allowsRoute(granted, 'photometric_curve_inspection_records', 'read')).toBe(true)
    expect(allowsRoute(denied, 'photometric_curve_inspection_records', 'read')).toBe(false)
    expect(routesSource).toContain("requireRoutePermission('photometric_curve_inspection_records')")
  })

  it('keeps the integrating sphere workflow on its own route and navigation entry', () => {
    const spheres = navGroups.flatMap((group) => group.items).filter((item) => item.to.includes('integrating-sphere'))

    expect(spheres).toHaveLength(1)
    expect(spheres[0].resource).toBe('integrating_sphere_inspection_records')
  })
})
