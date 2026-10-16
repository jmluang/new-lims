import { Check, ChevronDown } from 'lucide-react'
import { useEffect, useId, useRef, useState, type KeyboardEvent } from 'react'
import { inputClass } from '../../features/system/utils'

/** Select-only counterpart to the app's sample/company search listboxes. */
export function ListboxSelect({ label, value, options, onChange }: {
  label: string
  value: string
  options: Array<{ value: string; label: string }>
  onChange: (value: string) => void
}) {
  const listId = `select-${useId().replaceAll(':', '')}`
  const trigger = useRef<HTMLButtonElement>(null)
  const [open, setOpen] = useState(false)
  const [activeIndex, setActiveIndex] = useState(0)
  const selected = options.find(option => option.value === value)
  const active = options[activeIndex]
  useEffect(() => {
    if (open) document.getElementById(`${listId}-${activeIndex}`)?.scrollIntoView?.({ block: 'nearest' })
  }, [open, activeIndex, listId])

  function expand() {
    setActiveIndex(Math.max(0, options.findIndex(option => option.value === value)))
    setOpen(true)
  }
  function choose(next: string) {
    onChange(next)
    setOpen(false)
    trigger.current?.focus()
  }
  function keyDown(event: KeyboardEvent<HTMLButtonElement>) {
    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
      event.preventDefault()
      if (!open) { expand(); return }
      setActiveIndex(index => Math.max(0, Math.min(options.length - 1, index + (event.key === 'ArrowDown' ? 1 : -1))))
    } else if (event.key === 'Home' || event.key === 'End') {
      event.preventDefault()
      setOpen(true)
      setActiveIndex(event.key === 'Home' ? 0 : options.length - 1)
    } else if (event.key === 'Enter' || event.key === ' ') {
      event.preventDefault()
      if (!open) expand()
      else if (active) choose(active.value)
    } else if (event.key === 'Escape') {
      event.preventDefault()
      setOpen(false)
    } else if (event.key === 'Tab') setOpen(false)
  }

  return <div className="relative min-w-0 w-full" onBlur={event => {
    if (!event.currentTarget.contains(event.relatedTarget)) setOpen(false)
  }}>
    <button ref={trigger} type="button" role="combobox" aria-label={label} aria-haspopup="listbox"
      aria-expanded={open} aria-controls={listId} aria-activedescendant={open && active ? `${listId}-${activeIndex}` : undefined}
      className={`${inputClass} flex min-h-11 items-center justify-between gap-2 py-2 text-left`}
      onClick={() => open ? setOpen(false) : expand()} onKeyDown={keyDown}>
      <span className="min-w-0 truncate">{selected?.label ?? `请选择${label}`}</span>
      <ChevronDown aria-hidden="true" className="size-4 shrink-0 text-slate-500" />
    </button>
    {open ? <div id={listId} role="listbox" aria-label={label}
      className="absolute inset-x-0 top-full z-40 mt-1 max-h-[min(18rem,50vh)] overflow-y-auto rounded-md border border-emerald-900/15 bg-white p-1 text-sm text-slate-900 shadow-lg shadow-slate-900/10">
      {options.map((option, index) => <button key={option.value} id={`${listId}-${index}`} type="button"
        role="option" aria-selected={option.value === value} tabIndex={-1}
        className={`flex min-h-11 w-full items-center justify-between gap-2 rounded px-3 py-2 text-left text-sm ${index === activeIndex ? 'bg-emerald-50 text-emerald-950' : 'text-slate-800 hover:bg-slate-50'}`}
        onMouseEnter={() => setActiveIndex(index)} onMouseDown={event => event.preventDefault()} onClick={() => choose(option.value)}>
        <span className="min-w-0 break-words">{option.label}</span>
        {option.value === value ? <Check aria-hidden="true" className="size-4 shrink-0 text-emerald-700" /> : null}
      </button>)}
    </div> : null}
  </div>
}
