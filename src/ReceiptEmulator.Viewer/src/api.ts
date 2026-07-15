import type { ActivationRequest, JobSummary, LicenseStatus, PrinterSetupStatus, PrinterStateStatus, PrinterStateUpdate, ReceiptJob, ServiceStatus, StoredGraphic, UpdateStatus } from './types'

async function json<T>(url: string, init?: RequestInit): Promise<T> {
  const response = await fetch(url, init)
  if (!response.ok) {
    const problem = await response.json().catch(() => null)
    throw new Error(problem?.detail ?? `Request failed (${response.status})`)
  }
  return response.json() as Promise<T>
}

async function request(url: string, init?: RequestInit): Promise<void> {
  const response = await fetch(url, init)
  if (!response.ok) {
    const problem = await response.json().catch(() => null)
    throw new Error(problem?.detail ?? `Request failed (${response.status})`)
  }
}

export const api = {
  status: () => json<ServiceStatus>('/api/status'),
  jobs: () => json<JobSummary[]>('/api/jobs'),
  job: (id: string) => json<ReceiptJob>(`/api/jobs/${id}`),
  deleteJob: (id: string) => request(`/api/jobs/${id}`, { method: 'DELETE' }),
  clearJobs: () => request('/api/jobs', { method: 'DELETE' }),
  sample: () => json<{ id: string }>('/api/sample', { method: 'POST' }),
  activate: (request: ActivationRequest) => json<LicenseStatus>('/api/license/activate', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(request),
  }),
  checkUpdates: (force = false) => json<UpdateStatus>(`/api/updates/check?force=${force}`),
  printerSetupStatus: () => json<PrinterSetupStatus>('/api/printer-setup/status'),
  printerState: () => json<PrinterStateStatus>('/api/printer-state'),
  updatePrinterState: (state: PrinterStateUpdate) => json<PrinterStateStatus>('/api/printer-state', {
    method: 'PUT',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(state),
  }),
  resetPrinterState: () => json<PrinterStateStatus>('/api/printer-state/reset', { method: 'POST' }),
  storedGraphics: () => json<StoredGraphic[]>('/api/stored-graphics'),
  importStoredGraphic: (keyCode: string, name: string, file: File) => {
    const form = new FormData()
    form.set('name', name)
    form.set('file', file)
    return json<StoredGraphic>(`/api/stored-graphics/${encodeURIComponent(keyCode)}`, { method: 'POST', body: form })
  },
  deleteStoredGraphic: (keyCode: string) => request(`/api/stored-graphics/${encodeURIComponent(keyCode)}`, { method: 'DELETE' }),
}
