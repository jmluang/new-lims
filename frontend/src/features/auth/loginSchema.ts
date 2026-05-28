import { z } from 'zod'

export const loginSchema = z.object({
  email: z.email('请输入有效邮箱'),
  password: z.string().min(1, '请填写密码'),
})

export type LoginForm = z.infer<typeof loginSchema>
