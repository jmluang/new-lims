import type { QueryClient } from '@tanstack/react-query'

export const integratingSphereCalibrationRecordsQueryKey = ['integrating-sphere-calibration-records'] as const
export const integratingSphereCalibrationEquipmentQueryKey = ['integrating-sphere-calibration-equipment'] as const
export const integratingSphereCalibrationFormOptionsQueryKey = ['integrating-sphere-calibration-form-options'] as const

export function integratingSphereCalibrationMutationHandlers(queryClient: QueryClient, onSuccessAction: () => void) {
  function invalidateQueries() {
    void queryClient.invalidateQueries({ queryKey: integratingSphereCalibrationRecordsQueryKey })
    void queryClient.invalidateQueries({ queryKey: integratingSphereCalibrationEquipmentQueryKey })
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
