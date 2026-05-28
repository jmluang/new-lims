import { zodResolver } from '@hookform/resolvers/zod'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Edit3, Plus, Trash2 } from 'lucide-react'
import { useEffect, useState } from 'react'
import { useForm } from 'react-hook-form'
import { PermissionGate } from '../../components/app/PermissionGate'
import { api } from '../../lib/api'
import { Button, DataTable, EmptyState, ErrorNotice, Field, LoadingState, Modal, StatusBadge } from '../system/shared'
import { type ApiCollection, inputClass } from '../system/utils'
import { type Customer, type FieldPermissionMeta } from './CustomerListPage'
import { contactSchema, type ContactFormValues } from './customerSchema'

export type CustomerContact = {
  id: number
  customer_id: number
  name: string
  phone?: string | null
  email?: string | null
  is_default: boolean
  status: 'active' | 'disabled'
  _field_permissions?: FieldPermissionMeta
}

export function CustomerContactList({ customer }: { customer: Customer | null }) {
  const queryClient = useQueryClient()
  const [editing, setEditing] = useState<CustomerContact | null>(null)
  const [formOpen, setFormOpen] = useState(false)
  const contactsQuery = useQuery({
    queryKey: ['customer-contacts', customer?.id],
    enabled: Boolean(customer),
    queryFn: async () => {
      const response = await api.get<ApiCollection<CustomerContact>>(`/api/customers/${customer?.id}/contacts`)

      return response.data
    },
  })
  const saveContact = useMutation({
    mutationFn: async (values: ContactFormValues) => {
      if (!customer) {
        return
      }

      const payload = filterContactPayload(values, contactsQuery.data?.meta?.fields as FieldPermissionMeta | undefined)

      if (editing) {
        await api.put(`/api/customers/${customer.id}/contacts/${editing.id}`, payload)
        return
      }

      await api.post(`/api/customers/${customer.id}/contacts`, payload)
    },
    onSuccess: async () => {
      setFormOpen(false)
      setEditing(null)
      await queryClient.invalidateQueries({ queryKey: ['customer-contacts', customer?.id] })
      await queryClient.invalidateQueries({ queryKey: ['customers'] })
    },
  })
  const deleteContact = useMutation({
    mutationFn: async (contact: CustomerContact) => {
      if (!customer) {
        return
      }

      await api.delete(`/api/customers/${customer.id}/contacts/${contact.id}`)
    },
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['customer-contacts', customer?.id] })
      await queryClient.invalidateQueries({ queryKey: ['customers'] })
    },
  })
  const contacts = contactsQuery.data?.data ?? []
  const fieldPermissions = contactsQuery.data?.meta?.fields as FieldPermissionMeta | undefined

  if (!customer) {
    return <EmptyState title="No customer selected" description="Select a customer before managing contacts." />
  }

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between gap-3">
        <div className="text-sm font-medium text-slate-900">Contacts for {customer.name}</div>
        <PermissionGate resource="customer_contacts" action="create">
          <Button
            variant="secondary"
            onClick={() => {
              setEditing(null)
              setFormOpen(true)
              saveContact.reset()
            }}
          >
            <Plus className="size-4" aria-hidden="true" />
            Add contact
          </Button>
        </PermissionGate>
      </div>

      {contactsQuery.isPending ? <LoadingState label="Loading contacts" /> : null}
      {contactsQuery.isError ? <ErrorNotice error={contactsQuery.error} fallback="Unable to load contacts" /> : null}
      {saveContact.error || deleteContact.error ? <ErrorNotice error={saveContact.error ?? deleteContact.error} fallback="Contact operation failed" /> : null}

      <Modal
        title={editing ? 'Edit contact' : 'Create contact'}
        open={formOpen}
        onClose={() => {
          setFormOpen(false)
          setEditing(null)
        }}
      >
        <ContactForm
          contact={editing}
          fieldPermissions={fieldPermissions}
          submitting={saveContact.isPending}
          onSubmit={(values) => saveContact.mutateAsync(values)}
          onCancel={() => {
            setFormOpen(false)
            setEditing(null)
          }}
        />
      </Modal>

      {contacts.length === 0 && !contactsQuery.isPending ? (
        <EmptyState title="No contacts" description="Add a contact and mark one as the default contact." />
      ) : null}

      {contacts.length > 0 ? (
        <>
          <DataTable>
            <thead className="bg-slate-50 text-left text-xs uppercase text-slate-500">
              <tr>
                <th className="px-3 py-2 font-medium">Name</th>
                <th className="px-3 py-2 font-medium">Phone</th>
                <th className="px-3 py-2 font-medium">Email</th>
                <th className="px-3 py-2 font-medium">Status</th>
                <th className="px-3 py-2 font-medium">Actions</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-200">
              {contacts.map((contact) => (
                <tr key={contact.id}>
                  <td className="px-3 py-2">
                    <div className="font-medium text-slate-900">{contact.name}</div>
                    {contact.is_default ? <div className="text-xs text-emerald-700">default</div> : null}
                  </td>
                  <td className="px-3 py-2 text-slate-600">{contact.phone ?? '-'}</td>
                  <td className="px-3 py-2 text-slate-600">{contact.email ?? '-'}</td>
                  <td className="px-3 py-2">
                    <StatusBadge status={contact.status} />
                  </td>
                  <td className="px-3 py-2">
                    <ContactActions
                      contact={contact}
                      onEdit={() => {
                        setEditing(contact)
                        setFormOpen(true)
                      }}
                      onDelete={() => deleteContact.mutate(contact)}
                    />
                  </td>
                </tr>
              ))}
            </tbody>
          </DataTable>

          <div className="space-y-2 md:hidden">
            {contacts.map((contact) => (
              <article className="rounded-md border border-slate-200 bg-white p-3" key={contact.id}>
                <div className="flex items-start justify-between gap-2">
                  <div>
                    <div className="text-sm font-semibold text-slate-900">{contact.name}</div>
                    <div className="text-xs text-slate-500">{contact.phone ?? 'phone hidden'}</div>
                    <div className="text-xs text-slate-500">{contact.email ?? 'email hidden'}</div>
                  </div>
                  <StatusBadge status={contact.is_default ? 'active' : contact.status} />
                </div>
                <div className="mt-3">
                  <ContactActions
                    contact={contact}
                    onEdit={() => {
                      setEditing(contact)
                      setFormOpen(true)
                    }}
                    onDelete={() => deleteContact.mutate(contact)}
                  />
                </div>
              </article>
            ))}
          </div>
        </>
      ) : null}
    </div>
  )
}

function ContactForm({
  contact,
  fieldPermissions,
  submitting,
  onSubmit,
  onCancel,
}: {
  contact: CustomerContact | null
  fieldPermissions?: FieldPermissionMeta
  submitting: boolean
  onSubmit: (values: ContactFormValues) => Promise<void>
  onCancel: () => void
}) {
  const form = useForm<ContactFormValues>({
    resolver: zodResolver(contactSchema),
    defaultValues: contactDefaults(contact),
  })

  useEffect(() => {
    form.reset(contactDefaults(contact))
  }, [contact, form])

  return (
    <form className="space-y-3" onSubmit={form.handleSubmit(onSubmit)}>
      <div className="grid gap-3 sm:grid-cols-2">
        <Field label="Name">
          <input className={inputClass} {...form.register('name')} />
        </Field>
        {!fieldPermissions?.phone?.hidden ? (
          <Field label="Phone">
            <input className={inputClass} disabled={fieldPermissions?.phone?.update === false} {...form.register('phone')} />
          </Field>
        ) : null}
        {!fieldPermissions?.email?.hidden ? (
          <Field label="Email">
            <input className={inputClass} type="email" disabled={fieldPermissions?.email?.update === false} {...form.register('email')} />
          </Field>
        ) : null}
        <Field label="Status">
          <select className={inputClass} {...form.register('status')}>
            <option value="active">active</option>
            <option value="disabled">disabled</option>
          </select>
        </Field>
        <label className="flex items-center gap-2 pt-6 text-sm text-slate-700">
          <input className="size-4 rounded border-slate-300 text-emerald-600" type="checkbox" {...form.register('is_default')} />
          Default contact
        </label>
      </div>
      <div className="mt-3 flex justify-end gap-2">
        <Button type="button" variant="ghost" onClick={onCancel}>
          Cancel
        </Button>
        <Button type="submit" variant="primary" disabled={submitting}>
          Save contact
        </Button>
      </div>
    </form>
  )
}

function ContactActions({ contact, onEdit, onDelete }: { contact: CustomerContact; onEdit: () => void; onDelete: () => void }) {
  return (
    <div className="flex flex-wrap gap-2">
      <PermissionGate resource="customer_contacts" action="update">
        <Button variant="secondary" onClick={onEdit}>
          <Edit3 className="size-4" aria-hidden="true" />
          Edit
        </Button>
      </PermissionGate>
      <PermissionGate resource="customer_contacts" action="delete">
        <Button variant="danger" disabled={contact.is_default} onClick={onDelete}>
          <Trash2 className="size-4" aria-hidden="true" />
          Delete
        </Button>
      </PermissionGate>
    </div>
  )
}

function contactDefaults(contact?: CustomerContact | null): ContactFormValues {
  return {
    name: contact?.name ?? '',
    phone: contact?.phone ?? '',
    email: contact?.email ?? '',
    is_default: contact?.is_default ?? false,
    status: contact?.status ?? 'active',
  }
}

function filterContactPayload(values: ContactFormValues, permissions?: FieldPermissionMeta): ContactFormValues {
  return {
    ...values,
    phone: permissions?.phone?.update === false ? undefined : values.phone,
    email: permissions?.email?.update === false ? undefined : values.email,
  }
}
