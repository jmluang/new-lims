import { ChevronDown } from 'lucide-react'
import { useId, useMemo, useState, type FocusEvent, type KeyboardEvent } from 'react'
import type { Customer } from '../customers/CustomerListPage'
import { cn } from '../../lib/utils'
import { zhText } from '../../lib/zh'
import { filterCustomerOptions } from './customerCompanySearch'

const prefixLabels = {
  client: '委托单位',
  manufacturer: '制造商',
  maker: '生产厂',
} as const

export function CustomerCompanySearchInput({
  prefix,
  value,
  customers,
  className,
  disabled = false,
  onChange,
}: {
  prefix: keyof typeof prefixLabels
  value: string
  customers: Customer[]
  className: string
  disabled?: boolean
  onChange: (value: string) => void
}) {
  const listboxId = `customer-options-${useId().replaceAll(':', '')}`
  const [open, setOpen] = useState(false)
  const [activeIndex, setActiveIndex] = useState(0)
  const [query, setQuery] = useState('')
  const options = useMemo(() => filterCustomerOptions(customers, query), [customers, query])

  function selectCustomer(customer: Customer) {
    onChange(customer.name)
    setQuery('')
    setOpen(false)
  }

  function handleKeyDown(event: KeyboardEvent<HTMLInputElement>) {
    if (disabled) {
      return
    }

    if (event.key === 'ArrowDown') {
      event.preventDefault()
      if (!open) {
        setQuery('')
        setActiveIndex(0)
        setOpen(true)
        return
      }
      setOpen(true)
      setActiveIndex((current) => Math.min(current + 1, Math.max(options.length - 1, 0)))
      return
    }

    if (event.key === 'ArrowUp') {
      event.preventDefault()
      if (!open) {
        setQuery('')
        setActiveIndex(0)
        setOpen(true)
        return
      }
      setOpen(true)
      setActiveIndex((current) => Math.max(current - 1, 0))
      return
    }

    if (event.key === 'Enter' && open && options[activeIndex]) {
      event.preventDefault()
      selectCustomer(options[activeIndex])
      return
    }

    if (event.key === 'Escape') {
      setOpen(false)
    }
  }

  function handleBlur(event: FocusEvent<HTMLDivElement>) {
    if (!event.currentTarget.contains(event.relatedTarget)) {
      setOpen(false)
    }
  }

  return (
    <div className="relative min-w-0 w-full" onBlur={handleBlur}>
      <input
        className={cn(className, 'pr-10')}
        value={value}
        readOnly={disabled}
        placeholder={zhText('Search') ?? undefined}
        role="combobox"
        aria-label={`搜索选择${prefixLabels[prefix]}`}
        aria-autocomplete="list"
        aria-controls={listboxId}
        aria-expanded={open}
        aria-activedescendant={open && options[activeIndex] ? `${listboxId}-${options[activeIndex].id}` : undefined}
        onChange={(event) => {
          onChange(event.target.value)
          setQuery(event.target.value)
          setActiveIndex(0)
          setOpen(true)
        }}
        onFocus={() => {
          if (!disabled) {
            setQuery('')
            setActiveIndex(0)
            setOpen(true)
          }
        }}
        onKeyDown={handleKeyDown}
      />
      <button
        className="absolute inset-y-0 right-0 flex w-10 items-center justify-center text-slate-500 transition-colors hover:text-emerald-800 disabled:cursor-not-allowed disabled:opacity-50"
        type="button"
        aria-label={`${open ? '收起' : '展开'}${prefixLabels[prefix]}选项`}
        aria-controls={listboxId}
        aria-expanded={open}
        disabled={disabled}
        onClick={() => setOpen((current) => {
          const next = !current
          if (next) {
            setQuery('')
            setActiveIndex(0)
          }
          return next
        })}
      >
        <ChevronDown className={cn('size-4 transition-transform', open && 'rotate-180')} aria-hidden="true" />
      </button>

      {open ? (
        <div
          className="absolute inset-x-0 top-full z-40 mt-1 max-h-60 overflow-y-auto rounded-md border border-emerald-900/15 bg-white p-1 shadow-lg shadow-slate-900/10"
          id={listboxId}
          role="listbox"
          aria-label={`${prefixLabels[prefix]}搜索结果`}
        >
          {options.length > 0 ? (
            options.map((customer, index) => {
              const metadata = [customer.credit_code, customer.phone].filter(Boolean).join(' · ')

              return (
                <button
                  className={cn(
                    'block w-full rounded px-3 py-2 text-left transition-colors',
                    index === activeIndex ? 'bg-emerald-50 text-emerald-950' : 'text-slate-800 hover:bg-slate-50',
                  )}
                  id={`${listboxId}-${customer.id}`}
                  type="button"
                  role="option"
                  aria-selected={index === activeIndex}
                  key={customer.id}
                  onMouseEnter={() => setActiveIndex(index)}
                  onClick={() => selectCustomer(customer)}
                >
                  <span className="block truncate text-sm font-medium">{customer.name}</span>
                  {metadata ? <span className="mt-0.5 block truncate text-xs text-slate-500">{metadata}</span> : null}
                </button>
              )
            })
          ) : (
            <div className="px-3 py-3 text-sm text-slate-500">未找到匹配公司</div>
          )}
        </div>
      ) : null}
    </div>
  )
}
