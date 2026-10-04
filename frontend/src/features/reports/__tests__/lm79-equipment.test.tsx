import { renderToStaticMarkup } from 'react-dom/server'
import { describe, expect, it } from 'vitest'
import { ReportEquipmentFields } from '../ReportEquipmentFields'

const props = {
  devices: [{ equipment_id: 5, equipment_no: 'EQ-005', name: 'Photometer', model: 'PM-30', serial: 'SERIAL-005', manufacturer: 'Instrument Factory', next_calibration_date: '2027-10-04', cal_cert: 'CAL-005', cal_org: 'Calibration Lab', cal_due: '2027-10-04' }],
  active: true, editable: true, pending: false, error: null, notice: '',
  onCode: () => {}, onRemove: () => {}, onCalibrationChange: () => {},
}

describe('Report equipment ledger selection', () => {
  it('uses the shared scanner and displays device identity as ledger data', () => {
    const html = renderToStaticMarkup(<ReportEquipmentFields {...props} />)
    for (const value of ['打开扫码', '扫码或输入设备编号', 'EQ-005', 'Photometer', 'PM-30', 'SERIAL-005', '2027-10-04', '校准证书编号', '校准机构']) expect(html).toContain(value)
    expect(html.match(/<input/g)).toHaveLength(4)
    expect(html).not.toContain('添加手填设备')
  })

  it('unmounts the camera-bearing scanner when hidden or read-only', () => {
    for (const extra of [{ active: false }, { editable: false }]) {
      const html = renderToStaticMarkup(<ReportEquipmentFields {...props} {...extra} />)
      expect(html).not.toContain('打开扫码')
      expect(html).toContain('EQ-005')
    }
  })
})
