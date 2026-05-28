import { Outlet } from '@tanstack/react-router'
import { AppLayout } from '../components/app/AppLayout'

export function ProtectedLayout() {
  return (
    <AppLayout>
      <Outlet />
    </AppLayout>
  )
}
