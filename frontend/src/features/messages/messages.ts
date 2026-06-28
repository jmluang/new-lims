import { api } from '../../lib/api'

export type MessageUser = {
  id: number
  name: string
  email: string
}

export type MessageTestOrder = {
  id: number
  order_no: string
  client_company: string
}

export type UserMessage = {
  id: number
  title: string
  content: string
  read: boolean
  read_at?: string | null
  created_at?: string | null
  sender?: MessageUser | null
  test_order?: MessageTestOrder | null
}

export type MessageRecipient = MessageUser

export type MessageCollection = {
  data: UserMessage[]
  meta: {
    unread_count: number
  }
}

export async function fetchMessages() {
  const response = await api.get<MessageCollection>('/api/messages')

  return response.data
}

export async function markMessageRead(messageId: number) {
  const response = await api.post<{ data: UserMessage }>(`/api/messages/${messageId}/read`)

  return response.data.data
}

export async function fetchMessageRecipients() {
  const response = await api.get<{ data: MessageRecipient[] }>('/api/test-orders/message-recipients')

  return response.data.data
}

export async function pushTestOrderMessage(testOrderId: number, recipientUserId: number) {
  const response = await api.post<{ data: UserMessage }>(`/api/test-orders/${testOrderId}/messages`, {
    recipient_user_id: recipientUserId,
  })

  return response.data.data
}
