import type { ActivationRequest, JobSummary, LicenseStatus, ReceiptJob, ServiceStatus } from './types'

async function json<T>(url: string, init?: RequestInit): Promise<T> {
  const response = await fetch(url, init)
  if (!response.ok) {
    const problem = await response.json().catch(() => null)
    throw new Error(problem?.detail ?? `Request failed (${response.status})`)
  }
  return response.json() as Promise<T>
}

export const api = {
  status: () => json<ServiceStatus>('/api/status'),
  jobs: () => json<JobSummary[]>('/api/jobs'),
  job: (id: string) => json<ReceiptJob>(`/api/jobs/${id}`),
  sample: () => json<{ id: string }>('/api/sample', { method: 'POST' }),
  activate: (request: ActivationRequest) => json<LicenseStatus>('/api/license/activate', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(request),
  }),
}
