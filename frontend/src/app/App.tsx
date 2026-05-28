import { QueryClientProvider } from '@tanstack/react-query'
import { RouterProvider } from '@tanstack/react-router'
import { queryClient } from '../lib/query-client'
import { router } from './router'
import { installChineseUiTranslations } from '../lib/zh'

export function App() {
  installChineseUiTranslations()
  return (
    <QueryClientProvider client={queryClient}>
      <RouterProvider router={router} />
    </QueryClientProvider>
  )
}
