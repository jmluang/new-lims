import { z } from 'zod'

export const standardSchema = z.object({
  std_no: z.string().min(1, '请填写标准编号'),
  chinese_name: z.string().min(1, '请填写中文名称'),
  publish_date: z.string().optional(),
  implement_date: z.string().optional(),
  status: z.enum(['active', 'pending', 'abolished', 'replaced', 'disabled']),
  abolish_date: z.string().optional(),
  replaced_by: z.string().optional(),
  corresponding_std: z.string().optional(),
  category: z.string().optional(),
  language: z.string().optional(),
})

export type StandardFormValues = z.infer<typeof standardSchema>

export const standardCatalogSchema = z.object({
  parent_id: z.number().nullable().optional(),
  code: z.string().min(1, '请填写目录编码'),
  name: z.string().min(1, '请填写目录名称'),
  content: z.string().optional(),
  sort_order: z.coerce.number().int().min(0).optional(),
})

export type StandardCatalogFormValues = z.infer<typeof standardCatalogSchema>

export const standardItemSchema = z.object({
  item_no: z.string().min(1, '请填写项目编号'),
  item_name: z.string().min(1, '请填写项目名称'),
  requirement: z.string().optional(),
  unit: z.string().optional(),
  method: z.string().optional(),
  remark: z.string().optional(),
})

export type StandardItemFormValues = z.infer<typeof standardItemSchema>
