import { useRef } from 'react'
import { reportSections, type ReportSectionId } from './reportSections'

export function ReportSectionTabs({ selected, onChange }: { selected: ReportSectionId; onChange: (id: ReportSectionId) => void }) {
  const buttons = useRef<Array<HTMLButtonElement | null>>([])
  const current = reportSections.find(section => section.id === selected)!

  function move(index: number, key: string) {
    let next: number
    if (key === 'ArrowRight') next = (index + 1) % reportSections.length
    else if (key === 'ArrowLeft') next = (index + reportSections.length - 1) % reportSections.length
    else if (key === 'Home') next = 0
    else if (key === 'End') next = reportSections.length - 1
    else return false
    onChange(reportSections[next].id)
    buttons.current[next]?.focus()
    return true
  }

  return <div className="sticky top-20 z-20 min-w-0 space-y-3 bg-slate-50 py-2">
    <div role="tablist" aria-label="报告编制章节" className="flex max-w-full gap-2 overflow-x-auto rounded-lg border border-emerald-900/10 bg-white p-2 shadow-[0_1px_2px_rgb(15_23_42/0.05)]">
      {reportSections.map((section, index) => {
        const active = section.id === selected
        const Icon = section.icon
        return <button
          key={section.id}
          ref={element => { buttons.current[index] = element }}
          type="button"
          role="tab"
          id={`report-tab-${section.id}`}
          aria-selected={active}
          aria-controls={`report-panel-${section.id}`}
          tabIndex={active ? 0 : -1}
          onClick={() => onChange(section.id)}
          onKeyDown={event => { if (move(index, event.key)) event.preventDefault() }}
          className={`group relative inline-flex min-h-12 shrink-0 flex-1 items-center justify-center gap-2.5 whitespace-nowrap rounded-md px-4 py-3 text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 focus-visible:ring-offset-2 ${active ? 'bg-emerald-700 text-white shadow-sm' : 'text-slate-600 hover:bg-emerald-50 hover:text-emerald-900'}`}
        >
          <span aria-hidden="true" className={`inline-flex size-6 items-center justify-center rounded-md text-xs ${active ? 'bg-white/15 text-white' : 'bg-slate-100 text-slate-500 group-hover:bg-emerald-100'}`}>{String(index + 1).padStart(2, '0')}</span>
          <Icon aria-hidden="true" className="hidden size-4 shrink-0 lg:block" />
          {section.title}
        </button>
      })}
    </div>
    <p className="px-1 text-sm leading-6 text-slate-500">{current.description}</p>
  </div>
}
