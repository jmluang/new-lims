import { renderToStaticMarkup } from 'react-dom/server'
import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'
import type { Standard } from '../../standards/StandardListPage'
import { StandardSearchInput } from '../StandardSearchInput'
import { filterStandardOptions, isSelectedStandardValue } from '../standardSearch'

const standards: Standard[] = [
  { id: 1, std_no: 'GB 7000.1-2015', chinese_name: '灯具 第1部分：一般要求与试验', status: 'active' },
  { id: 2, std_no: 'GB/T 9468-2008', chinese_name: '灯具分布光度测量的一般要求', status: 'active' },
]
const source = readFileSync(fileURLToPath(new URL('../StandardSearchInput.tsx', import.meta.url)), 'utf8')

describe('standard search', () => {
  it('filters by standard number and Chinese name', () => {
    expect(filterStandardOptions(standards, '7000.1')).toEqual([standards[0]])
    expect(filterStandardOptions(standards, '分布光度')).toEqual([standards[1]])
  })

  it('marks only an exact standard value as selected when standard numbers share a prefix', () => {
    const shorterStandard: Standard = { id: 3, std_no: 'GB 7000.1', chinese_name: '旧版灯具要求', status: 'active' }
    const selectedValue = 'GB 7000.1-2015 灯具 第1部分：一般要求与试验'

    expect(isSelectedStandardValue(selectedValue, standards[0])).toBe(true)
    expect(isSelectedStandardValue(selectedValue, shorterStandard)).toBe(false)
  })

  it('renders an accessible searchable combobox', () => {
    const markup = renderToStaticMarkup(
      <StandardSearchInput value="" standards={standards} className="input" ariaLabel="搜索选择标准 1" onSelect={() => undefined} />,
    )

    expect(markup).toContain('role="combobox"')
    expect(markup).toContain('aria-label="搜索选择标准 1"')
    expect(markup).toContain('输入标准号或名称搜索')
  })

  it('renders the options in a page-level layer so table overflow cannot clip them', () => {
    expect(source).toContain('createPortal(')
    expect(source).toContain('className="fixed z-[100] overflow-y-auto')
    expect(source).toContain('document.body')
  })

  it('matches the compact A4 table typography without shrinking mobile touch targets', () => {
    expect(source).toContain('min-h-11')
    expect(source).toContain('md:w-7')
    expect(source).toContain('md:text-[9pt]')
    expect(source).toContain('md:text-[8pt]')
  })

  it('uses a restrained focus treatment and marks the selected standard explicitly', () => {
    expect(source).toContain('focus:bg-white focus:ring-0')
    expect(source).toContain("index === activeIndex && 'bg-slate-100/80")
    expect(source).toContain('aria-selected={selected}')
    expect(source).toContain('<Check')
  })
})
