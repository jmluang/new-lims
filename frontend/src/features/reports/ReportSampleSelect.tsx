import { Check, ChevronDown } from 'lucide-react'
import { useId, useState, type KeyboardEvent } from 'react'
import { inputClass } from '../system/utils'
import type { SampleOption } from './lm79Api'

function sampleLabel(sample: SampleOption) {
  return [sample.snapshot.sample_no, sample.snapshot.sample_name, sample.snapshot.model].filter(Boolean).join(' · ')
}

export function ReportSampleSelect({ selected, options, pending, disabled, onSearch, onSelect }: {
  selected: SampleOption | null
  options: SampleOption[]
  pending: boolean
  disabled: boolean
  onSearch: (query: string) => void
  onSelect: (sample: SampleOption | null) => void
}) {
  const listId = `report-samples-${useId().replaceAll(':', '')}`
  const [open, setOpen] = useState(false)
  const [query, setQuery] = useState('')
  const [activeIndex, setActiveIndex] = useState(0)
  const activeOption = !pending ? options[Math.min(activeIndex, options.length - 1)] : undefined

  function expand() {
    setQuery('')
    onSearch('')
    setActiveIndex(0)
    setOpen(true)
  }

  function choose(sample: SampleOption) {
    onSelect(sample)
    setQuery('')
    setOpen(false)
  }

  function handleKeyDown(event: KeyboardEvent<HTMLInputElement>) {
    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
      event.preventDefault()
      if (!open) { expand(); return }
      setActiveIndex(index => Math.max(0, Math.min(index + (event.key === 'ArrowDown' ? 1 : -1), options.length - 1)))
    } else if (event.key === 'Enter' && open) {
      event.preventDefault()
      if (activeOption) choose(activeOption)
    } else if (event.key === 'Escape') {
      setOpen(false)
    }
  }

  return <div className="relative min-w-0" onBlur={event => { if (!event.currentTarget.contains(event.relatedTarget)) setOpen(false) }}>
    <div className="relative">
      <input className={`${inputClass} pr-10`} role="combobox" aria-label="搜索选择已接收样品" aria-autocomplete="list" aria-controls={listId} aria-expanded={open}
        aria-activedescendant={open && activeOption ? `${listId}-${activeOption.id}` : undefined}
        placeholder="输入样品编号 / 名称 / 型号搜索" disabled={disabled} autoComplete="off"
        value={open ? query : selected ? sampleLabel(selected) : query}
        onFocus={expand} onKeyDown={handleKeyDown}
        onChange={event => { const text = event.target.value; setQuery(text); setActiveIndex(0); setOpen(true); onSelect(null); onSearch(text) }} />
      <button className="absolute inset-y-0 right-0 flex w-10 items-center justify-center text-slate-500 hover:text-emerald-800 disabled:opacity-50" type="button" disabled={disabled}
        aria-label={`${open ? '收起' : '展开'}样品选项`} aria-expanded={open} aria-controls={listId} onClick={() => open ? setOpen(false) : expand()}>
        <ChevronDown aria-hidden="true" className="size-4" />
      </button>
    </div>
    {open && !disabled ? <div id={listId} role="listbox" aria-label="已接收样品搜索结果" aria-busy={pending} className="absolute inset-x-0 top-full z-40 mt-1 max-h-60 overflow-y-auto rounded-md border border-emerald-900/15 bg-white p-1 shadow-lg shadow-slate-900/10">
      {pending ? <p role="status" className="px-3 py-3 text-sm text-slate-500">正在搜索样品…</p> : options.length ? options.map((sample, index) => <button key={sample.id} id={`${listId}-${sample.id}`} role="option" aria-selected={sample.id === selected?.id} type="button"
        className={`flex min-h-11 w-full items-center justify-between gap-2 rounded px-3 py-2 text-left text-sm ${index === Math.min(activeIndex, options.length - 1) ? 'bg-emerald-50 text-emerald-950' : 'text-slate-800 hover:bg-slate-50'}`}
        onMouseEnter={() => setActiveIndex(index)} onClick={() => choose(sample)}>
        <span className="min-w-0 break-words">{sampleLabel(sample)}{sample.snapshot.order_no ? <span className="mt-0.5 block text-xs text-slate-500">委托单：{sample.snapshot.order_no}</span> : null}</span>
        {sample.id === selected?.id ? <Check aria-hidden="true" className="size-4 shrink-0 text-emerald-700" /> : null}
      </button>) : <p className="px-3 py-3 text-sm text-slate-500">未找到匹配的已接收样品，请更换关键词。</p>}
    </div> : null}
  </div>
}
