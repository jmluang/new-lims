export function normalizeScanValue(value: string): string | null {
  const text = value.trim()

  return text === '' ? null : text
}

export function completeDetectedScan(
  value: string,
  onDetected: (text: string) => void,
  closeScanner: () => void,
) {
  const text = normalizeScanValue(value)

  if (text === null) {
    return false
  }

  onDetected(text)
  closeScanner()

  return true
}

export type QrScannerInstance = {
  stop: () => Promise<void>
  clear: () => void
}

export async function stopQrScannerIfRunning(scanner: QrScannerInstance, isRunning: boolean) {
  if (isRunning) {
    await scanner.stop()
  }

  scanner.clear()
}
