import { describe, expect, it } from 'vitest'
import { signingControlsUnavailable, signingFailureText } from '../signingFailure'

describe('signingFailureText', () => {
  // The bug this exists to stop: an unmapped code was rendered verbatim, so a
  // signer read "JAVA_EXECUTION_REGISTRATION_DEADLINE" off their screen.
  it('never renders a raw internal code', () => {
    for (const code of ['JAVA_EXECUTION_REGISTRATION_DEADLINE', 'SOMETHING_NOBODY_TRANSLATED']) {
      expect(signingFailureText(code)).not.toContain(code)
    }
  })

  it('tells the signer they may retry when no signature can exist', () => {
    expect(signingFailureText('JAVA_FAILED_BEFORE_PRIVATE_KEY')).toContain('没有生成签名')
  })

  // The distinction that matters: after the private key ran, a signature may
  // already be in the report, so retrying risks signing it twice.
  it('tells the signer to stop when a signature might already exist', () => {
    for (const code of ['JAVA_FAILED_AFTER_PRIVATE_KEY', 'JAVA_OUTCOME_UNKNOWN', 'JAVA_POST_OUTCOME_UNCERTAIN']) {
      expect(signingFailureText(code)).toContain('不要重复提交')
    }
  })

  it('falls back when there is no code at all', () => {
    expect(signingFailureText(null)).toContain('联系管理员')
    expect(signingFailureText(undefined)).toContain('联系管理员')
  })

  it('never recommends retrying an unknown manual-review outcome', () => {
    expect(signingFailureText('PROMOTED_FINAL_INTEGRITY_FAILURE', 'manual_review')).toContain('不要重复提交')
    expect(signingFailureText('DOWNSTREAM_CANDIDATE_AMBIGUOUS', 'manual_review')).not.toContain('请重新提交')
    expect(signingFailureText('GENERATED_REVISION_VERIFICATION_FAILED', 'manual_review')).toContain('联系管理员')
    expect(signingFailureText('UNKNOWN_PRE_KEY_FAILURE', 'failed')).toContain('重新提交')
  })

  it('only keeps the form for retryable failures', () => {
    expect(signingControlsUnavailable('failed')).toBe(false)
    expect(signingControlsUnavailable('completed')).toBe(true)
    expect(signingControlsUnavailable('manual_review')).toBe(true)
    expect(signingControlsUnavailable('irreversible_failed')).toBe(true)
    expect(signingControlsUnavailable('cancelled')).toBe(true)
  })
})
