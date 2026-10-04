import { createRouter } from '@tanstack/react-router'
import { routeTree } from './routes'
import { Button, Panel } from '../features/system/shared'

export const router = createRouter({
  routeTree,
  defaultErrorComponent: ({ reset }) => <div role="alert" className="mx-auto max-w-xl p-6">
    <Panel title="页面暂时无法显示" description="请重试；持续失败请联系管理员。">
      <div className="flex items-center gap-3"><Button onClick={reset}>重试</Button><a href="/" className="text-sm text-emerald-800 underline">返回首页</a></div>
    </Panel>
  </div>,
})

declare module '@tanstack/react-router' {
  interface Register {
    router: typeof router
  }
}
