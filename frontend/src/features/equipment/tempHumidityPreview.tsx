import type { TempHumidityEquipmentLookup } from './tempHumidityTypes'

export function TempHumidityRecordFormPreview({
  defaultPerson,
  currentEquipNo,
  lookupEquipment,
}: {
  defaultPerson: string
  currentEquipNo?: string
  lookupEquipment: TempHumidityEquipmentLookup | null
}) {
  return (
    <section>
      <h1>添加记录</h1>
      <div>{defaultPerson}</div>
      {currentEquipNo ? <div>{currentEquipNo}</div> : null}
      {lookupEquipment ? (
        <dl>
          <dt>设备编号</dt>
          <dd>{lookupEquipment.equipment_no}</dd>
          <dt>设备名称</dt>
          <dd>{lookupEquipment.name}</dd>
          <dt>放置场所</dt>
          <dd>{lookupEquipment.location_site ?? '-'}</dd>
          <dt>放置房间</dt>
          <dd>{lookupEquipment.location_room ?? '-'}</dd>
          <dt>下次校准</dt>
          <dd>{lookupEquipment.next_calibration_date ?? '-'}</dd>
        </dl>
      ) : null}
    </section>
  )
}
