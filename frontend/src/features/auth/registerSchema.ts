import { z } from 'zod'

export const registerSchema = z.object({
  name: z.string().min(1, '请填写名称'),
  email: z.email('请输入有效邮箱'),
  password: z.string().min(8, '密码至少 8 位'),
  phone: z.string().optional(),
  department_id: z.string().optional(),
})

export type RegisterForm = z.infer<typeof registerSchema>

export function registerPayload(values: RegisterForm) {
  return {
    name: values.name,
    email: values.email,
    password: values.password,
    phone: values.phone || null,
    department_id: values.department_id ? Number(values.department_id) : null,
  }
}
