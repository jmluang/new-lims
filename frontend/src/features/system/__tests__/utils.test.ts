import { describe, expect, it } from 'vitest'
import { blobErrorMessage, errorMessage } from '../utils'

describe('system utils', () => {
  it('uses plain Error messages before falling back to generic copy', () => {
    expect(errorMessage(new Error('请选择委托单'), 'Unable to receive samples')).toBe('请选择委托单')
  })

  it('shows the missing permission from 403 API responses', () => {
    expect(
      errorMessage(
        {
          response: {
            status: 403,
            data: {
              message: 'Forbidden',
              permission: 'test_orders.read',
            },
          },
        },
        'Unable to load test orders',
      ),
    ).toBe('没有权限执行该操作，请联系管理员开通相应权限。')
  })

  // Axios rejections are Error instances carrying a response, so the response has
  // to win over Error.message — otherwise every backend code is swallowed by
  // axios' own "Request failed with status code NNN".
  const axiosError = (status: number, data: Record<string, unknown>) =>
    Object.assign(new Error(`Request failed with status code ${status}`), {
      response: { status, data },
    })

  it('translates the backend error code carried by an axios rejection', () => {
    expect(errorMessage(axiosError(409, { message: 'PDF_SOURCE_ENCRYPTED' }), 'PDF 结构检查失败')).toBe(
      'PDF 已加密，请上传未加密的文件',
    )
  })

  // api/pdf/* wraps its stable codes in {error: {code, message}} rather than a
  // top-level message, so reading only data.message missed every PDF code.
  it('reads the PDF error envelope, not just a top-level message', () => {
    expect(
      errorMessage(
        axiosError(409, { error: { code: 'PDF_REPORT_NUMBER_ALREADY_REGISTERED', message: 'PDF_REPORT_NUMBER_ALREADY_REGISTERED' } }),
        'PDF 定稿失败',
      ),
    ).toBe('该报告编号已存在')
  })

  it('prefers the envelope code over a generic top-level message', () => {
    expect(
      errorMessage(
        axiosError(409, { message: 'Conflict', error: { code: 'PDF_DOCUMENT_ALREADY_HAS_ACTIVE_WORK' } }),
        '失败',
      ),
    ).toBe('该文档已有进行中的任务，请先完成或取消')
  })

  it('uses actionable copy instead of untranslated backend codes', () => {
    expect(errorMessage(axiosError(409, { message: 'SOME_UNMAPPED_CODE' }), 'PDF 结构检查失败')).toBe('PDF 结构检查失败')
  })

  it('never exposes SQL, model names, stack traces or private paths from any error channel', () => {
    const messages = ['读取文件失败：Unable to parse 测试.pdf', '读取失败：Vendor\\Reader\\Parser internal failure', 'SQLSTATE[HY000]: connection failed (SQL: select * from users)', 'No query results for model [App\\Models\\Equipment].', 'Unable to read /Users/operator/private/secret.pdf', '读取失败：SQLSTATE[HY000] database failure', 'TypeError: Cannot read properties of undefined', 'at render (/var/www/app/Page.tsx:12:3)']
    for (const message of messages) {
      expect(errorMessage(axiosError(500, { message }), '操作失败')).toBe('操作失败')
      expect(errorMessage(axiosError(422, { errors: { file: [message] } }), '操作失败')).toBe('操作失败')
      expect(errorMessage(new Error(message), '操作失败')).toBe('操作失败')
      expect(errorMessage(message, '操作失败')).toBe('操作失败')
    }
  })

  it('gives recovery guidance for missing records, sessions, connection failures and rate limits', () => {
    expect(errorMessage(axiosError(404, { message: 'No query results for model [App\\Models\\Sample].' }))).toBe('未找到所需记录，请刷新后重试。')
    expect(errorMessage(axiosError(401, { message: 'Unauthenticated.' }))).toBe('登录已失效，请重新登录。')
    expect(errorMessage(Object.assign(new Error('Network Error'), { code: 'ERR_NETWORK' }))).toBe('无法连接服务器，请检查网络并确认操作结果。')
    expect(errorMessage(axiosError(429, {}))).toBe('操作过于频繁，请稍后重试。')
  })

  it('retains readable business messages, including plain strings', () => {
    const message = '未找到设备「EQ-001」。请核对设备编号。'
    expect(errorMessage(axiosError(404, { message }))).toBe(message)
    expect(errorMessage(message)).toBe(message)
  })

  it('sanitizes JSON and HTML errors returned as PDF download blobs', async () => {
    const wrap = (body: string) => ({ response: { status: 502, data: new Blob([body]) } })
    expect(await blobErrorMessage(wrap(JSON.stringify({ message: 'SQLSTATE[HY000] database failure' })), 'PDF 生成失败')).toBe('PDF 生成失败')
    expect(await blobErrorMessage(wrap('<html>Proxy stack trace</html>'), 'PDF 生成失败')).toBe('PDF 生成失败')
    expect(await blobErrorMessage(wrap(JSON.stringify({ error: { code: 'PDF_SOURCE_ENCRYPTED' } })), 'PDF 生成失败')).toBe('PDF 已加密，请上传未加密的文件')
  })

  it('shows the missing permission when the 403 arrives as an axios rejection', () => {
    expect(
      errorMessage(axiosError(403, { message: 'Forbidden', permission: 'pdf.workflow.create' }), '加载失败'),
    ).toBe('没有权限执行该操作，请联系管理员开通相应权限。')
  })

  it('prefers the first validation error over the response message', () => {
    expect(
      errorMessage(
        axiosError(422, { message: 'The given data was invalid.', errors: { report_number: ['报告编号已存在'] } }),
        '提交失败',
      ),
    ).toBe('报告编号已存在')
  })

  it('falls back to the caller copy when the response carries no message', () => {
    expect(errorMessage(axiosError(500, {}), 'PDF 定稿失败')).toBe('PDF 定稿失败')
  })

  it('does not throw on null or undefined errors', () => {
    expect(errorMessage(null, 'PDF 定稿失败')).toBe('PDF 定稿失败')
    expect(errorMessage(undefined, 'PDF 定稿失败')).toBe('PDF 定稿失败')
  })
})
