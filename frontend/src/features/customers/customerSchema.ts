import { z } from 'zod'

export const customerSchema = z.object({
  name: z.string().min(1, '请填写名称'),
  credit_code: z.string().optional(),
  type: z.string().optional(),
  level: z.string().optional(),
  source: z.string().optional(),
  industry: z.string().optional(),
  phone: z.string().optional(),
  email: z.union([z.email('请输入有效邮箱'), z.literal('')]).optional(),
  address: z.string().optional(),
  remark: z.string().optional(),
  status: z.enum(['active', 'disabled']),
})

export type CustomerFormValues = z.infer<typeof customerSchema>

export const contactSchema = z.object({
  name: z.string().min(1, '请填写名称'),
  phone: z.string().optional(),
  email: z.union([z.email('请输入有效邮箱'), z.literal('')]).optional(),
  is_default: z.boolean(),
  status: z.enum(['active', 'disabled']),
})

export type ContactFormValues = z.infer<typeof contactSchema>
