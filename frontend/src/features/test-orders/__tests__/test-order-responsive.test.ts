import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'

const source = readFileSync(fileURLToPath(new URL('../TestOrderEntrustForm.tsx', import.meta.url)), 'utf8')

describe('test order detail responsive layout', () => {
  it('stacks paired fields on mobile and restores the formal grid on desktop', () => {
    expect(source).toContain('grid-cols-[7rem_minmax(0,1fr)]')
    expect(source).toContain('md:grid-cols-[7rem_minmax(0,1fr)_7rem_minmax(0,1fr)]')
    expect(source).toContain('md:grid-cols-[6rem_minmax(0,1fr)_6rem_minmax(0,1fr)_6rem_minmax(0,1fr)]')
    expect(source).not.toContain('grid-cols-[6rem_1fr_6rem_1fr_6rem_1fr]')
  })

  it('keeps the party name column wider than contact and phone columns', () => {
    expect(source).toContain('md:grid-cols-[minmax(0,15fr)_minmax(0,40fr)_minmax(0,7fr)_minmax(0,15fr)_minmax(0,7fr)_minmax(0,16fr)]')
    expect(source).toContain('name={`${prefix}_company`} readOnlySingleLine')
    expect(source).toContain('name={`${prefix}_contact`} readOnlySingleLine')
    expect(source).toContain('name={`${prefix}_phone`} readOnlySingleLine')
    expect(source).toContain('name={`${prefix}_email`} type="email" readOnlySingleLine')
  })

  it('matches the printed standards and report requirement layout', () => {
    expect(source).toContain("filter(Boolean).join(' ')")
    expect(source).toContain('readOnlyClassName="w-full text-center"')
    expect(source).toContain('md:grid-cols-[minmax(0,15fr)_minmax(0,50fr)_minmax(0,15fr)_minmax(0,20fr)]')
    expect(source).toContain('md:grid-cols-[minmax(0,15fr)_minmax(0,35fr)_minmax(0,15fr)_minmax(0,35fr)]')
    expect(source).toContain('<ReadonlyChoiceList')
  })

  it('uses an A4 sheet ratio and the same 15mm content margin as the PDF renderer', () => {
    expect(source).toContain("style={{ containerType: 'inline-size' }}")
    expect(source).toContain("style={{ minHeight: 'calc(100cqw * 297 / 210)' }}")
    expect(source).toContain('className="min-w-0 overflow-hidden border border-emerald-900/15 md:m-[15mm]"')
  })

  it('matches the PDF renderer typography in read-only A4 mode', () => {
    expect(source).toContain("text-[14pt]")
    expect(source).toContain("text-[16pt]")
    expect(source).toContain("text-[10pt]")
    expect(source).toContain("text-[9pt]")
  })

  it('uses the shared read-only typography for the order number', () => {
    expect(source).toContain('<CellLabel>委托编号</CellLabel><Cell><ReadOnly value={order.order_no} /></Cell>')
  })

  it('uses customer search and a locked customer-linked address in detail edit mode', () => {
    expect(source).toContain('<CustomerCompanySearchInput')
    expect(source).toContain('aria-label="委托单位地址（由所选公司同步）"')
    expect(source).toContain('customerSnapshotValues(\'client\'')
  })

  it('labels every editable native select for assistive technology', () => {
    expect(source).toContain('ariaLabel="紧急程度"')
    expect(source).toContain('ariaLabel="样品状态"')
    expect(source).toContain('ariaLabel="样品是否返还"')
    expect(source).toContain('ariaLabel="报告提交"')
    expect(source).toContain('ariaLabel="准许检测分包"')
    expect(source).toContain('aria-label={ariaLabel}')
  })

  it('keeps confirmation rows readable instead of squeezing ten cells together', () => {
    expect(source).toContain('function ConfirmationRow')
    expect(source).toContain('grid-cols-[7rem_minmax(0,1fr)] md:grid-cols-5')
    expect(source).toContain('grid-cols-[4rem_minmax(0,1fr)] md:contents')
  })

  it('uses phone-sized touch targets for editable controls', () => {
    expect(source).toContain("const cellInput = 'h-11")
    expect(source).toContain('min-h-11 items-center')
    expect(source).toContain('md:h-6')
  })

  it('keeps the same A4 margin and print density while editing on desktop', () => {
    expect(source).toContain('md:m-[15mm]')
    expect(source).toContain('min-h-10 md:min-h-6')
    expect(source).toContain('text-sm md:text-[9pt]')
    expect(source).toContain('action={editable ? <button')
  })
})
