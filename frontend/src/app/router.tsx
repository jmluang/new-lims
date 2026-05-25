import { createRootRoute, createRoute, createRouter } from '@tanstack/react-router'
import { AppLayout } from '../components/app/AppLayout'

function DashboardPage() {
  return (
    <AppLayout>
      <section className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        {[
          ['Users', 'Group-based access control'],
          ['Customers', 'Sensitive field permissions'],
          ['Equipment', 'Locations, files, and labels'],
          ['Audit', 'Append-only operation records'],
        ].map(([title, description]) => (
          <article className="rounded-lg border border-slate-200 bg-white p-4 shadow-sm" key={title}>
            <h2 className="text-sm font-semibold text-slate-900">{title}</h2>
            <p className="mt-2 text-sm text-slate-500">{description}</p>
          </article>
        ))}
      </section>
    </AppLayout>
  )
}

const rootRoute = createRootRoute()

const indexRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/',
  component: DashboardPage,
})

export const router = createRouter({
  routeTree: rootRoute.addChildren([indexRoute]),
})

declare module '@tanstack/react-router' {
  interface Register {
    router: typeof router
  }
}
