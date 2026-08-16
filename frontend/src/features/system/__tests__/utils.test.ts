import { describe, expect, it } from 'vitest'
import { errorMessage } from '../utils'

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
    ).toBe('没有权限执行该操作：缺少 test_orders.read')
  })

  // Axios rejections are Error instances carrying a response, so the response has
  // to win over Error.message — otherwise every backend code is swallowed by
  // axios' own "Request failed with status code NNN".
  const axiosError = (status: number, data: Record<string, unknown>) =>
    Object.assign(new Error(`Request failed with status code ${status}`), {
      response: { status, data },
    })

  it('translates the backend error code carried by an axios rejection', () => {
    expect(errorMessage(axiosError(409, { message: 'PDF_SOURCE_SHA_ALREADY_REGISTERED' }), 'PDF 结构检查失败')).toBe(
      '该 PDF 文件已上传过，请勿重复上传或更换文件',
    )
  })

  it('keeps an untranslated backend code visible instead of the axios message', () => {
    expect(errorMessage(axiosError(409, { message: 'SOME_UNMAPPED_CODE' }), 'PDF 结构检查失败')).toBe('SOME_UNMAPPED_CODE')
  })

  it('shows the missing permission when the 403 arrives as an axios rejection', () => {
    expect(
      errorMessage(axiosError(403, { message: 'Forbidden', permission: 'pdf.workflow.create' }), '加载失败'),
    ).toBe('没有权限执行该操作：缺少 pdf.workflow.create')
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
