/**
 * What a completed signature should say to the person who just signed.
 *
 * `issuer` is always the third and final step — PdfWorkflowService pins the
 * sequence and requires all three roles — and its signature publishes the
 * report. Telling that signer to hand it to the next one named a person who
 * does not exist.
 */
export function signedTitle(lastSigner: boolean): string {
  return lastSigner ? '全部签署已完成' : '签名已完成'
}

export function signedText(lastSigner: boolean): string {
  return lastSigner
    ? '本次签名已写入报告，三位签署人全部完成，报告已发布。'
    : '本次签名已写入报告，可以交给下一位签署人。'
}
