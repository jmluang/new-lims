import { Check, ChevronDown } from 'lucide-react'
import { useId, useLayoutEffect, useMemo, useRef, useState, type CSSProperties, type FocusEvent, type KeyboardEvent } from 'react'
import { createPortal } from 'react-dom'
import type { Standard } from '../standards/StandardListPage'
import { cn } from '../../lib/utils'
import { filterStandardOptions, isSelectedStandardValue } from './standardSearch'

export function StandardSearchInput({
  value,
  standards,
  className,
  ariaLabel,
  onSelect,
}: {
  value: string
  standards: Standard[]
  className: string
  ariaLabel: string
  onSelect: (standard: Standard) => void
}) {
  const listboxId = `standard-options-${useId().replaceAll(':', '')}`
  const [open, setOpen] = useState(false)
  const [searching, setSearching] = useState(false)
  const [query, setQuery] = useState('')
  const [activeIndex, setActiveIndex] = useState(0)
  const [listboxStyle, setListboxStyle] = useState<CSSProperties>({})
  const anchorRef = useRef<HTMLDivElement>(null)
  const options = useMemo(() => filterStandardOptions(standards, searching ? query : ''), [query, searching, standards])

  useLayoutEffect(() => {
    if (!open) {
      return
    }

    function updateListboxPosition() {
      const anchor = anchorRef.current

      if (!anchor) {
        return
      }

      const rect = anchor.getBoundingClientRect()
      const compact = window.innerWidth >= 768
      const gap = compact ? 2 : 4
      const viewportPadding = 8
      const roomBelow = window.innerHeight - rect.bottom - gap - viewportPadding
      const roomAbove = rect.top - gap - viewportPadding
      const openAbove = roomBelow < 160 && roomAbove > roomBelow
      const preferredHeight = compact ? 192 : 240
      const minimumHeight = compact ? 72 : 96
      const availableHeight = Math.max(minimumHeight, Math.min(preferredHeight, openAbove ? roomAbove : roomBelow))
      const left = Math.max(viewportPadding, Math.min(rect.left, window.innerWidth - rect.width - viewportPadding))

      setListboxStyle({
        left,
        width: rect.width,
        maxHeight: availableHeight,
        ...(openAbove
          ? { bottom: window.innerHeight - rect.top + gap, top: undefined }
          : { top: rect.bottom + gap, bottom: undefined }),
      })
    }

    updateListboxPosition()
    window.addEventListener('resize', updateListboxPosition)
    window.addEventListener('scroll', updateListboxPosition, true)

    return () => {
      window.removeEventListener('resize', updateListboxPosition)
      window.removeEventListener('scroll', updateListboxPosition, true)
    }
  }, [open])

  function close() {
    setOpen(false)
    setSearching(false)
    setQuery('')
  }

  function selectStandard(standard: Standard) {
    onSelect(standard)
    close()
  }

  function handleKeyDown(event: KeyboardEvent<HTMLInputElement>) {
    if (event.key === 'ArrowDown') {
      event.preventDefault()
      if (!open) {
        setOpen(true)
        setSearching(false)
        setActiveIndex(0)
        return
      }
      setActiveIndex((current) => Math.min(current + 1, Math.max(options.length - 1, 0)))
      return
    }

    if (event.key === 'ArrowUp') {
      event.preventDefault()
      if (!open) {
        setOpen(true)
        setSearching(false)
        setActiveIndex(0)
        return
      }
      setActiveIndex((current) => Math.max(current - 1, 0))
      return
    }

    if (event.key === 'Enter' && open && options[activeIndex]) {
      event.preventDefault()
      selectStandard(options[activeIndex])
      return
    }

    if (event.key === 'Escape') {
      close()
    }
  }

  function handleBlur(event: FocusEvent<HTMLElement>) {
    const nextTarget = event.relatedTarget
    const listbox = document.getElementById(listboxId)
    const remainsInCombobox = nextTarget instanceof Node
      && (anchorRef.current?.contains(nextTarget) || listbox?.contains(nextTarget))

    if (!remainsInCombobox) {
      close()
    }
  }

  return (
    <div
      ref={anchorRef}
      className="relative min-w-0 w-full transition-shadow focus-within:ring-1 focus-within:ring-inset focus-within:ring-emerald-600/45"
      onBlur={handleBlur}
    >
      <input
        className={cn(className, 'bg-white pr-10 focus:bg-white focus:ring-0 md:pr-7')}
        value={searching ? query : value}
        placeholder="输入标准号或名称搜索"
        role="combobox"
        aria-label={ariaLabel}
        aria-autocomplete="list"
        aria-controls={listboxId}
        aria-expanded={open}
        aria-activedescendant={open && options[activeIndex] ? `${listboxId}-${options[activeIndex].id}` : undefined}
        onChange={(event) => {
          setQuery(event.target.value)
          setSearching(true)
          setActiveIndex(0)
          setOpen(true)
        }}
        onFocus={() => {
          setSearching(false)
          setActiveIndex(0)
          setOpen(true)
        }}
        onKeyDown={handleKeyDown}
      />
      <button
        className={cn(
          'absolute inset-y-0 right-0 flex w-10 items-center justify-center text-slate-400 transition-colors hover:bg-slate-50 hover:text-emerald-700 focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-inset focus-visible:ring-emerald-600/40 md:w-7',
          open && 'text-emerald-700',
        )}
        type="button"
        aria-label={`${open ? '收起' : '展开'}标准选项`}
        aria-controls={listboxId}
        aria-expanded={open}
        onClick={() => {
          if (open) {
            close()
            return
          }
          setSearching(false)
          setActiveIndex(0)
          setOpen(true)
        }}
      >
        <ChevronDown className={cn('size-4 transition-transform md:size-3', open && 'rotate-180')} aria-hidden="true" />
      </button>

      {open && typeof document !== 'undefined' ? createPortal(
        <div
          className="fixed z-[100] overflow-y-auto rounded-md border border-slate-200 bg-white p-1 shadow-[0_8px_24px_rgb(15_23_42/0.14)] md:rounded-sm md:p-0.5 md:shadow-[0_6px_18px_rgb(15_23_42/0.14)]"
          id={listboxId}
          role="listbox"
          aria-label="标准搜索结果"
          style={listboxStyle}
          onBlur={handleBlur}
        >
          {options.length > 0 ? (
            options.map((standard, index) => {
              const selected = isSelectedStandardValue(value, standard)

              return (
                <button
                  className={cn(
                    'flex min-h-11 w-full items-center gap-2 rounded px-3 py-2 text-left text-slate-800 transition-colors hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-inset focus-visible:ring-emerald-600/35 md:min-h-0 md:rounded-sm md:px-2 md:py-1',
                    index === activeIndex && 'bg-slate-100/80 text-slate-950',
                  )}
                  id={`${listboxId}-${standard.id}`}
                  type="button"
                  role="option"
                  aria-selected={selected}
                  key={standard.id}
                  onMouseEnter={() => setActiveIndex(index)}
                  onClick={() => selectStandard(standard)}
                >
                  <span className="min-w-0 flex-1">
                    <span className="block truncate text-sm font-medium leading-5 md:text-[9pt] md:leading-[12pt]">{standard.std_no}</span>
                    <span className="mt-0.5 block truncate text-xs leading-4 text-slate-500 md:mt-0 md:text-[8pt] md:leading-[10pt]">{standard.chinese_name}</span>
                  </span>
                  {selected ? <Check className="size-4 shrink-0 text-emerald-700 md:size-3.5" aria-hidden="true" /> : null}
                </button>
              )
            })
          ) : (
            <div className="flex min-h-11 items-center px-3 py-2 text-sm text-slate-500 md:min-h-0 md:px-2 md:py-1.5 md:text-[9pt]">未找到匹配标准</div>
          )}
        </div>,
        document.body,
      ) : null}
    </div>
  )
}
