import { useMutation } from '@tanstack/react-query'
import { Link } from '@tanstack/react-router'
import { ArrowLeft, Printer } from 'lucide-react'
import { useEffect, useRef } from 'react'
import { api } from '../../lib/api'
import { Button, EmptyState, ErrorNotice, LoadingState, PageShell } from '../system/shared'
import { SampleLabelPrintArea, SampleLabelPrintStyles, type SampleLabelPreview } from './SampleLabelPrintArea'
import { loadSampleLabelIds } from './sampleLabelPrintState'
import { sampleLabelSpec } from './sampleLabelSpec'

export function SampleLabelPrintPage() {
  const sampleIds = useRef(loadSampleLabelIds()).current
  const printStarted = useRef(false)
  const preview = useMutation({
    mutationFn: async (ids: number[]) => {
      const response = await api.post<{ data: SampleLabelPreview[] }>('/api/sample-labels/preview', {
        sample_ids: ids,
        label_width_mm: sampleLabelSpec.widthMm,
        label_height_mm: sampleLabelSpec.heightMm,
      })

      return response.data.data
    },
  })

  useEffect(() => {
    if (sampleIds.length > 0) {
      preview.mutate(sampleIds)
    }
    // The IDs are captured once from the successful receive action.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  useEffect(() => {
    if (printStarted.current || preview.data === undefined || preview.data.length === 0) {
      return
    }

    printStarted.current = true
    const timeout = window.setTimeout(() => window.print())

    return () => window.clearTimeout(timeout)
  }, [preview.data])

  return (
    <PageShell
      title="样品标签打印"
      description="本次接收的样品标签已生成，并会自动打开打印对话框。"
      actions={
        <>
          <Link className="inline-flex h-9 items-center justify-center gap-2 rounded-md px-3 text-sm font-medium text-slate-600 hover:bg-slate-100" to="/samples">
            <ArrowLeft className="size-4" aria-hidden="true" />
            返回样品信息
          </Link>
          <Button variant="primary" disabled={!preview.data?.length} onClick={() => window.print()}>
            <Printer className="size-4" aria-hidden="true" />
            打印
          </Button>
        </>
      }
    >
      <SampleLabelPrintStyles />
      {preview.isPending ? <LoadingState label="正在生成样品标签" /> : null}
      {preview.error ? <ErrorNotice error={preview.error} fallback="无法生成样品标签" /> : null}
      {!preview.isPending && !preview.error && preview.data?.length === 0 ? <EmptyState title="暂无待打印样品" description="请从样品接收页面完成接收后再打印标签。" /> : null}
      <SampleLabelPrintArea labels={preview.data ?? []} />
    </PageShell>
  )
}
