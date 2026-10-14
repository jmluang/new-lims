import type { RenderTask } from 'pdfjs-dist'

export function releasePdfCanvas(task: Pick<RenderTask, 'cancel'>, canvas: HTMLCanvasElement) {
  return () => {
    try {
      task.cancel()
    } finally {
      canvas.width = 0
      canvas.height = 0
    }
  }
}
