import { api } from '../../lib/api'

/**
 * Shares one declaration-page download across a batch of report merges.
 *
 * The merge happens in the browser so the bytes that get signed are exactly the
 * bytes the operator ends up with — the server never rewrites the document
 * after the digests are taken.
 *
 * pdf-lib is imported on demand: it is several hundred kilobytes and only the
 * signing desk needs it, and only when a declaration page was selected.
 */
export function createCertificateMerger(certificateTemplateId: number) {
  const template = api.get<ArrayBuffer>(`/api/pdf/signing/certificate-templates/${certificateTemplateId}/file`, {
    responseType: 'arraybuffer',
  }).then(response => response.data)

  return async (source: Blob): Promise<Blob> => {
    const [{ PDFDocument }, templateBytes] = await Promise.all([import('pdf-lib'), template])

    const [reportDoc, certificateDoc] = await Promise.all([
      PDFDocument.load(await source.arrayBuffer()),
      PDFDocument.load(templateBytes),
    ])

    const merged = await PDFDocument.create()

    for (const doc of [reportDoc, certificateDoc]) {
      const pages = await merged.copyPages(doc, doc.getPageIndices())
      pages.forEach((page) => merged.addPage(page))
    }

    // Object streams off keeps the output parseable by downstream PDF tools.
    const bytes = await merged.save({
      useObjectStreams: false,
      addDefaultPage: false,
      objectsPerTick: 20,
      updateFieldAppearances: false,
    })

    return new Blob([bytes as unknown as BlobPart], { type: 'application/pdf' })
  }
}
