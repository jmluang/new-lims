export function DashboardPage() {
  return (
    <>
      <section className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        {[
          ['用户', '基于角色组的权限控制'],
          ['客户', '敏感字段权限管理'],
          ['设备', '位置、附件与标签管理'],
          ['审计', '不可篡改的操作记录'],
        ].map(([title, description]) => (
          <article className="rounded-lg border border-slate-200 bg-white p-4 shadow-sm" key={title}>
            <h2 className="text-sm font-semibold text-slate-900">{title}</h2>
            <p className="mt-2 text-sm text-slate-500">{description}</p>
          </article>
        ))}
      </section>
    </>
  )
}
