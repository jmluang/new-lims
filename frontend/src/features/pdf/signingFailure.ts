import { zhErrorText } from '../../lib/zh'

/** Shown when the code carries nothing the signer can act on. */
const GENERIC = '本次签名没有完成，请重新提交；若仍然失败请联系管理员。'

/**
 * What a failed signature should say to the person who tried to sign it.
 *
 * `zhErrorText` hands back whatever it was given when a code is unknown, which
 * put raw internal codes like JAVA_EXECUTION_REGISTRATION_DEADLINE on screen.
 * Internal state names and ledger phases are for the operator's logs; the
 * signer gets a sentence they can act on.
 */
export function signingFailureText(code: string | null | undefined): string {
  if (!code) return GENERIC

  const translated = zhErrorText(code)

  return translated && translated !== code ? translated : GENERIC
}
