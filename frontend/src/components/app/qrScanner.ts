export function normalizeScanValue(value: string): string | null {
  const text = value.trim()

  return text === '' ? null : text
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
