import { getDocument, type PDFDocumentLoadingTask, type PDFDocumentProxy } from 'pdfjs-dist'

export function loadPdfDocument(file: Blob, onLoaded: (pdf: PDFDocumentProxy) => void, onError: () => void) {
  let cancelled = false
  let task: PDFDocumentLoadingTask | undefined
  void file.arrayBuffer()
    .then((bytes) => {
      if (cancelled) return
      task = getDocument({ data: bytes })
      return task.promise
    })
    .then((pdf) => {
      if (pdf && !cancelled) onLoaded(pdf)
    })
    .catch(() => { if (!cancelled) onError() })

  return () => {
    cancelled = true
    void task?.destroy()
  }
}
