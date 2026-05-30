import { z } from 'zod'

export const standardSchema = z.object({
  std_no: z.string().min(1, 'Standard number is required'),
  chinese_name: z.string().min(1, 'Chinese name is required'),
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
  code: z.string().min(1, 'Catalog code is required'),
  name: z.string().min(1, 'Catalog name is required'),
  content: z.string().optional(),
  sort_order: z.coerce.number().int().min(0).optional(),
})

export type StandardCatalogFormValues = z.infer<typeof standardCatalogSchema>

export const standardItemSchema = z.object({
  item_no: z.string().min(1, 'Item number is required'),
  item_name: z.string().min(1, 'Item name is required'),
  requirement: z.string().optional(),
  unit: z.string().optional(),
  method: z.string().optional(),
  remark: z.string().optional(),
})

export type StandardItemFormValues = z.infer<typeof standardItemSchema>
