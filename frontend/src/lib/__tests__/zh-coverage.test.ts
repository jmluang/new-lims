import { readFileSync, readdirSync, statSync } from 'node:fs'
import { join } from 'node:path'
import { describe, expect, it } from 'vitest'
import { textMap } from '../zh'

/**
 * The UI is written with English source strings that `zhText` maps to Chinese. A key that is missing
 * from `textMap` falls through and renders English, and raw JSX text never reaches `zhText` at all
 * (only `<thead>` cells inside `DataTable` and `Button` children are translated automatically).
 * These checks catch both leaks before they reach a page.
 */

/** Brand names, unit hints and example values that are meant to stay as they are. */
const allowedLiterals = new Set(['New LIMS', 'CMA, CNAS'])

const translatedProps = /\b(title|description|label|placeholder|fallback)="([^"]*)"/g
const zhTextKeys = /zhText\(\s*'((?:[^'\\]|\\.)*)'/g
const rawTextNodes = /<(div|span|dt|dd|td|p|h1|h2|h3|h4|label|option|li|strong|small)\b[^>]*>\s*([A-Za-z][A-Za-z0-9 ,.'()/&-]{1,60}?)\s*<\//g

describe('chinese text coverage', () => {
  const files = sourceFiles('src')

  it('resolves every English zhText key through textMap', () => {
    const missing = collect(files, (line) =>
      [...line.matchAll(zhTextKeys)]
        .map((match) => unescape(match[1]))
        .filter((key) => isEnglish(key) && !(key in textMap) && !allowedLiterals.has(key)),
    )

    expect(missing).toEqual([])
  })

  it('resolves every English string passed to a translated prop', () => {
    const missing = collect(files, (line) =>
      [...line.matchAll(translatedProps)]
        .map((match) => match[2])
        .filter((value) => isEnglish(value) && !(value in textMap) && !allowedLiterals.has(value)),
    )

    expect(missing).toEqual([])
  })

  it('keeps raw JSX text out of English, since only table heads and buttons are translated', () => {
    const untranslated = collect(files, (line) =>
      [...line.matchAll(rawTextNodes)].map((match) => match[2].trim()).filter((text) => isEnglish(text) && !allowedLiterals.has(text)),
    )

    expect(untranslated).toEqual([])
  })
})

function collect(files: string[], match: (line: string) => string[]) {
  const found: string[] = []

  files.forEach((file) => {
    readFileSync(file, 'utf8')
      .split('\n')
      .forEach((line, index) => {
        match(line).forEach((value) => found.push(`${file}:${index + 1} ${value}`))
      })
  })

  return found
}

function sourceFiles(dir: string): string[] {
  return readdirSync(dir).flatMap((entry) => {
    const path = join(dir, entry)

    if (statSync(path).isDirectory()) {
      return sourceFiles(path)
    }

    return /\.tsx?$/.test(path) && !path.includes('__tests__') ? [path] : []
  })
}

function isEnglish(value: string) {
  return /[A-Za-z]{3}/.test(value) && !/[一-鿿]/.test(value)
}

function unescape(value: string) {
  return value.replace(/\\'/g, "'")
}
