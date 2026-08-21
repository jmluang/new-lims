import type { QueryClient } from '@tanstack/react-query'

export const photometricCurveCalibrationRecordsQueryKey = ['photometric-curve-calibration-records'] as const
export const photometricCurveCalibrationEquipmentQueryKey = ['photometric-curve-calibration-equipment'] as const

/**
 * A write to a record changes both views of this page: the record list and the
 * flattened used-equipment ledger derived from it, so both keys are invalidated
 * together no matter which view the operator is looking at.
 */
export function photometricCurveCalibrationMutationHandlers(queryClient: QueryClient, onSuccessAction: () => void) {
  function invalidateQueries() {
    void queryClient.invalidateQueries({ queryKey: photometricCurveCalibrationRecordsQueryKey })
    void queryClient.invalidateQueries({ queryKey: photometricCurveCalibrationEquipmentQueryKey })
  }

  return {
    saveSuccess: () => {
      invalidateQueries()
      onSuccessAction()
    },
    deleteSuccess: () => {
      invalidateQueries()
    },
  }
}
