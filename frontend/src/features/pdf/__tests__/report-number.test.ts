import { describe, expect, it } from 'vitest'
import { reportNumberFromFileName } from '../api'

/**
 * The signing desk pre-fills the report number from the file name and lets the
 * operator correct it. Cover-page extraction is not trusted for this on its
 * own: in production it returned a whole labelled line ("产品名称:LED 面板灯") as
 * the number, which then reached the ledger search and the report recipient.
 */
describe('report number pre-fill', () => {
  it('reads the number out of the lab naming convention', () => {
    expect(reportNumberFromFileName('XDP2025120133 民爆 面板灯  委托检测报告.pdf')).toBe('XDP2025120133')
  })

  it('finds the number when the name leads with something else', () => {
    expect(reportNumberFromFileName('副本-XDP2025120133.pdf')).toBe('XDP2025120133')
  })

  it('normalises the prefix so the ledger holds one spelling', () => {
    expect(reportNumberFromFileName('xdp2025120133 报告.pdf')).toBe('XDP2025120133')
  })

  it('returns nothing rather than a guess for names that do not carry one', () => {
    // Empty leaves the field blank with a warning: the operator types it, and
    // a missing number is better than an invented one.
    expect(reportNumberFromFileName('检测报告.pdf')).toBe('')
    expect(reportNumberFromFileName('XDP 2025120133.pdf')).toBe('')
  })
})
