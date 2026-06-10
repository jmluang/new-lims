import { Outlet, useNavigate } from '@tanstack/react-router'
import { useEffect } from 'react'
import { AppLayout } from '../components/app/AppLayout'
import { clearAuthToken, isUnauthorizedError } from '../lib/api'
import { useCurrentUser, useEffectivePermissions } from '../features/auth/useCurrentUser'
import { ErrorNotice, LoadingState } from '../features/system/shared'

export function ProtectedLayout() {
  const navigate = useNavigate()
  const currentUser = useCurrentUser()
  const permissions = useEffectivePermissions()
  const authError = currentUser.error ?? permissions.error

  useEffect(() => {
    if (!isUnauthorizedError(authError)) {
      return
    }

    clearAuthToken()
    void navigate({ to: '/login', replace: true })
  }, [authError, navigate])

  if (currentUser.isPending) {
    return <LoadingState label="正在验证登录状态" />
  }

  if (permissions.isPending) {
    return <LoadingState label="正在加载权限" />
  }

  if (currentUser.isError || permissions.isError) {
    if (!isUnauthorizedError(authError)) {
      return <ErrorNotice error={authError} fallback="无法加载登录状态" />
    }

    return <LoadingState label="正在跳转登录" />
  }

  return (
    <AppLayout>
      <Outlet />
    </AppLayout>
  )
}
