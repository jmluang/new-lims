import { createContext, type Dispatch, type SetStateAction } from 'react'

export type PageHeaderContent = {
  title: string
  description: string
}

export const PageHeaderContext = createContext<Dispatch<SetStateAction<PageHeaderContent | null>> | null>(null)
