import axios from 'axios'

const authTokenKey = 'new_lims_auth_token'

const apiBaseURL = import.meta.env.VITE_API_BASE_URL || (import.meta.env.DEV ? 'http://localhost:8000' : undefined)

export const api = axios.create({
  baseURL: apiBaseURL,
  withCredentials: true,
  headers: {
    Accept: 'application/json',
  },
})

api.interceptors.request.use((config) => {
  const token = localStorage.getItem(authTokenKey)

  if (token) {
    config.headers.Authorization = `Bearer ${token}`
  }

  return config
})

export function getAuthToken() {
  return localStorage.getItem(authTokenKey)
}

export function setAuthToken(token: string) {
  localStorage.setItem(authTokenKey, token)
}

export function clearAuthToken() {
  localStorage.removeItem(authTokenKey)
}

export function isUnauthorizedError(error: unknown) {
  return typeof error === 'object' && error !== null && 'response' in error && (error as { response?: { status?: number } }).response?.status === 401
}
