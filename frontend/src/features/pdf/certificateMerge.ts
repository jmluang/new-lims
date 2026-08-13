import { api } from '../../lib/api'

/**
 * Appends a declaration page (证书模板) to the end of a report.
 *
 * The merge happens in the browser so the bytes that get signed are exactly the
 * bytes the operator ends up with — the server never rewrites the document
 * after the digests are taken.
 *
 * pdf-lib is imported on demand: it is several hundred kilobytes and only the
 * signing desk needs it, and only when a declaration page was selected.
 */
export async function mergeCertificateTemplate(source: Blob, certificateTemplateId: number): Promise<Blob> {
  const [{ PDFDocument }, response] = await Promise.all([
    import('pdf-lib'),
    api.get<ArrayBuffer>(`/api/pdf/signing/certificate-templates/${certificateTemplateId}/file`, {
      responseType: 'arraybuffer',
    }),
  ])

  const [reportDoc, certificateDoc] = await Promise.all([
    PDFDocument.load(await source.arrayBuffer()),
    PDFDocument.load(response.data),
  ])

  const merged = await PDFDocument.create()

  for (const doc of [reportDoc, certificateDoc]) {
    const pages = await merged.copyPages(doc, doc.getPageIndices())
    pages.forEach((page) => merged.addPage(page))
  }

  // Object streams off keeps the output parseable by the PDF libraries the
  // signing service and any downstream tooling use.
  const bytes = await merged.save({
    useObjectStreams: false,
    addDefaultPage: false,
    objectsPerTick: 20,
    updateFieldAppearances: false,
  })

  return new Blob([bytes as unknown as BlobPart], { type: 'application/pdf' })
}
