import { zhErrorText } from '../../lib/zh'

/** Shown when the code carries nothing the signer can act on. */
const RETRYABLE_GENERIC = '本次签名没有完成，请重新提交；若仍然失败请联系管理员。'
const NON_RETRYABLE_GENERIC = '本次签名结果需要人工核对，请不要重复提交并联系管理员。'

/**
 * What a failed signature should say to the person who tried to sign it.
 *
 * `zhErrorText` hands back whatever it was given when a code is unknown, which
 * put raw internal codes like JAVA_EXECUTION_REGISTRATION_DEADLINE on screen.
 * Internal state names and ledger phases are for the operator's logs; the
 * signer gets a sentence they can act on.
 */
export function signingFailureText(code: string | null | undefined, state?: string | null): string {
  const fallback = signingControlsUnavailable(state) ? NON_RETRYABLE_GENERIC : RETRYABLE_GENERIC
  if (!code) return fallback

  const translated = zhErrorText(code)

  return translated && translated !== code ? translated : fallback
}

/** Terminal outcomes that must not offer another signing attempt. */
export function signingControlsUnavailable(state: string | null | undefined): boolean {
  return ['completed', 'irreversible_failed', 'manual_review', 'cancelled'].includes(state ?? '')
}
