export function normalizeScanValue(value: string): string | null {
  const text = value.trim()

  return text === '' ? null : text
}
