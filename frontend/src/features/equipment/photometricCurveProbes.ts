/**
 * The probe a goniophotometer measures with, shared by the two photometric-curve
 * workflows.
 *
 * Inspection and calibration are separate records with separate measurement
 * contracts, but they name the same physical probe and the API stores the same two
 * stable codes for both. Keeping the pair here means the Chinese labels can never
 * drift apart between the two pages.
 */
export const photometricCurveProbes = [
  { value: 'near_field', label: '近场' },
  { value: 'far_field', label: '远场' },
] as const

export type PhotometricCurveProbe = (typeof photometricCurveProbes)[number]['value']

export function probeLabel(probe: string | null | undefined) {
  return photometricCurveProbes.find((option) => option.value === probe)?.label ?? '-'
}
