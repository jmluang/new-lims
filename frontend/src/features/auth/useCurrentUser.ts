import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, clearAuthToken, getAuthToken, setAuthToken } from '../../lib/api'

export type CurrentUser = {
  id: number
  name: string
  email: string
  must_change_password?: boolean
}

type LoginPayload = {
  email: string
  password: string
}

type ChangePasswordPayload = {
  current_password: string
  password: string
  password_confirmation: string
}

export type EffectivePermissions = {
  resources: Record<
    string,
    {
      actions: Record<string, boolean>
      fields: Record<string, Record<string, boolean>>
    }
  >
}

export const currentUserQueryKey = ['current-user'] as const
export const effectivePermissionsQueryKey = ['effective-permissions'] as const

export async function fetchCurrentUser() {
  const response = await api.get<{ data: CurrentUser }>('/api/me')

  return response.data.data
}

export async function fetchEffectivePermissions() {
  const response = await api.get<{ data: EffectivePermissions }>('/api/permissions/effective')

  return response.data.data
}

export function useCurrentUser() {
  return useQuery({
    queryKey: currentUserQueryKey,
    queryFn: fetchCurrentUser,
    enabled: Boolean(getAuthToken()),
    retry: false,
  })
}

export function useEffectivePermissions() {
  return useQuery({
    queryKey: effectivePermissionsQueryKey,
    queryFn: fetchEffectivePermissions,
    enabled: Boolean(getAuthToken()),
    retry: false,
  })
}

export function useLogin() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (payload: LoginPayload) => {
      await api.get('/sanctum/csrf-cookie')
      const response = await api.post<{ data: { token: string; user: CurrentUser } }>('/api/login', payload)

      return response.data.data
    },
    onSuccess: ({ token, user }) => {
      setAuthToken(token)
      queryClient.setQueryData(currentUserQueryKey, user)
      void queryClient.invalidateQueries({ queryKey: effectivePermissionsQueryKey })
    },
  })
}

export function useChangePassword() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (payload: ChangePasswordPayload) => {
      const response = await api.post<{ data: { must_change_password: boolean } }>('/api/auth/password', payload)

      return response.data.data
    },
    onSuccess: () => {
      queryClient.setQueryData<CurrentUser | undefined>(currentUserQueryKey, (current) =>
        current ? { ...current, must_change_password: false } : current,
      )
      void queryClient.invalidateQueries({ queryKey: currentUserQueryKey })
    },
  })
}

export function useLogout() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async () => {
      await api.post('/api/logout')
    },
    onSettled: () => {
      clearAuthToken()
      queryClient.clear()
    },
  })
}
