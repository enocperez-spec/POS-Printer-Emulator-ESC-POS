import type { ActivationRequest, ConfigurationBackupCreateRequest, ConfigurationBackupPreview, ConfigurationRestoreResult, ConnectionDiagnosticsResponse, DiagnosticPdfPreview, DiagnosticPdfRequest, JobSummary, LicenseStatus, MaintenanceEntitlementRequest, MaintenanceRefreshResult, PrinterListener, PrinterListenerCollection, PrinterListenerInput, PrinterPortSelection, PrinterProfile, PrinterProfileInput, PrinterProfileStatus, PrinterSetupStatus, PrinterStateStatus, PrinterStateUpdate, PromotionEntitlementRequest, PromotionOfferStatus, PromotionStartResult, ReceiptJob, ServiceStatus, StoredGraphic, SupportRequestDraftSummary, SupportRequestInput, SupportRequestPreview, SupportRequestResult, UpdateStatus } from './types'

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

async function download(url: string, init?: RequestInit): Promise<Blob> {
  const response = await fetch(url, init)
  if (!response.ok) {
    const problem = await response.json().catch(() => null)
    throw new Error(problem?.detail ?? `Download failed (${response.status})`)
  }
  return response.blob()
}

type RuntimeListenerResponse = {
  configuration: PrinterListenerInput & { id: string; protocol?: 'RawTcp' }
  state: string
  listening: boolean
  lastConnection?: string
  lastError?: string
  counters?: Record<string, number>
}

type ListenerCollectionResponse = PrinterListenerCollection | {
  listeners: RuntimeListenerResponse[]
  maximumListeners?: number
}

function normalizeListeners(response: ListenerCollectionResponse): PrinterListenerCollection {
  const entries = response.listeners
  if (entries.length === 0 || !('configuration' in entries[0])) return response as PrinterListenerCollection
  return {
    maximumListeners: response.maximumListeners ?? 15,
    listeners: (entries as RuntimeListenerResponse[]).map(runtime => {
      const counters = runtime.counters ?? {}
      const state = runtime.state === 'Listening' ? 'Listening'
        : runtime.state === 'Starting' || runtime.state === 'Stopping' || runtime.state === 'Stopped' || runtime.state === 'Faulted'
          ? runtime.state
          : 'Faulted'
      return {
        ...runtime.configuration,
        protocol: 'RawTcp',
        isDefault: runtime.configuration.id === 'default',
        profileName: undefined,
        status: state,
        listening: runtime.listening,
        lastConnection: runtime.lastConnection,
        lastError: runtime.lastError,
        counters: {
          activeConnections: counters.activeConnections ?? 0,
          totalConnections: counters.totalConnections ?? counters.acceptedConnections ?? 0,
          bytesReceived: counters.bytesReceived ?? counters.receivedBytes ?? 0,
          jobsReceived: counters.jobsReceived ?? counters.receivedJobs ?? 0,
          jobsCompleted: counters.jobsCompleted ?? counters.completedJobs ?? 0,
          jobsRejected: counters.jobsRejected ?? counters.rejectedJobs ?? 0,
          jobsFailed: counters.jobsFailed ?? counters.failedJobs ?? 0,
          queued: counters.queued ?? counters.queuedJobs ?? 0,
          processing: counters.processing ?? counters.activeJobs ?? 0,
        },
      }
    }),
  }
}

export const api = {
  status: () => json<ServiceStatus>('/api/status'),
  jobs: (listenerId?: string) => json<JobSummary[]>(listenerId ? `/api/jobs?listenerId=${encodeURIComponent(listenerId)}` : '/api/jobs'),
  job: (id: string) => json<ReceiptJob>(`/api/jobs/${id}`),
  deleteJob: (id: string) => request(`/api/jobs/${id}`, { method: 'DELETE' }),
  clearJobs: (listenerId?: string) => request(listenerId ? `/api/jobs?listenerId=${encodeURIComponent(listenerId)}` : '/api/jobs', { method: 'DELETE' }),
  sample: () => json<ReceiptJob>('/api/sample', { method: 'POST' }),
  printerListeners: () => json<ListenerCollectionResponse>('/api/listeners').then(normalizeListeners),
  createPrinterListener: (listener: PrinterListenerInput) => json<PrinterListener>('/api/listeners', {
    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(listener),
  }),
  updatePrinterListener: (id: string, listener: PrinterListenerInput) => json<PrinterListener>(`/api/listeners/${encodeURIComponent(id)}`, {
    method: 'PUT', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(listener),
  }),
  deletePrinterListener: (id: string) => request(`/api/listeners/${encodeURIComponent(id)}`, { method: 'DELETE' }),
  startPrinterListener: (id: string) => request(`/api/listeners/${encodeURIComponent(id)}/start`, { method: 'POST' }),
  stopPrinterListener: (id: string) => request(`/api/listeners/${encodeURIComponent(id)}/stop`, { method: 'POST' }),
  restartPrinterListener: (id: string) => request(`/api/listeners/${encodeURIComponent(id)}/restart`, { method: 'POST' }),
  importCapture: (file: File) => {
    const form = new FormData()
    form.set('file', file)
    return json<{ id: string; origin: string }>('/api/captures/import', { method: 'POST', body: form })
  },
  replayJob: (id: string) => json<{ id: string; origin: string }>(`/api/jobs/${id}/replay`, { method: 'POST' }),
  downloadJob: (id: string, format: 'text' | 'raw' | 'capture') => download(`/api/jobs/${id}/${format}`),
  authorizeReceiptImage: (id: string, action: 'copy' | 'save') => json<{ authorized: boolean }>(
    `/api/jobs/${id}/receipt-image/authorize`,
    { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action }) },
  ),
  activate: (request: ActivationRequest) => json<LicenseStatus>('/api/license/activate', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(request),
  }),
  applyMaintenance: (request: MaintenanceEntitlementRequest) => json<LicenseStatus>('/api/license/maintenance/apply', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(request),
  }),
  applyPromotion: (request: PromotionEntitlementRequest) => json<LicenseStatus>('/api/license/promotion/apply', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(request),
  }),
  promotionOffer: () => json<PromotionOfferStatus>('/api/license/promotion/offer'),
  startPromotion: (grantedTier: 'Lite' | 'Pro' | 'Enterprise') => json<PromotionStartResult>('/api/license/promotion/start', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ grantedTier }),
  }),
  refreshMaintenance: () => json<MaintenanceRefreshResult>('/api/license/maintenance/refresh', { method: 'POST' }),
  runConnectionDiagnostics: () => json<ConnectionDiagnosticsResponse>('/api/support/connection-diagnostics', { method: 'POST' }),
  previewAdvancedDiagnosticPdf: (input: DiagnosticPdfRequest) => json<DiagnosticPdfPreview>('/api/support/diagnostic-pdf/advanced/preview', {
    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(input),
  }),
  createAdvancedDiagnosticPdf: (input: DiagnosticPdfRequest) => download('/api/support/diagnostic-pdf/advanced', {
    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(input),
  }),
  previewStandardDiagnosticPdf: (input: DiagnosticPdfRequest) => json<DiagnosticPdfPreview>('/api/support/diagnostic-pdf/standard/preview', {
    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(input),
  }),
  createStandardDiagnosticPdf: (input: DiagnosticPdfRequest) => download('/api/support/diagnostic-pdf/standard', {
    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(input),
  }),
  restartDiagnosticListener: (id: string) => json<PrinterListener>(`/api/support/connection-diagnostics/listeners/${encodeURIComponent(id)}/restart`, { method: 'POST' }),
  previewSupportRequest: (input: SupportRequestInput) => json<SupportRequestPreview>('/api/support/requests/preview', {
    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(input),
  }),
  submitSupportRequest: (input: SupportRequestInput) => json<SupportRequestResult>('/api/support/requests', {
    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(input),
  }),
  supportRequestDrafts: () => json<SupportRequestDraftSummary[]>('/api/support/requests/drafts'),
  retrySupportRequest: (reference: string) => json<SupportRequestResult>(`/api/support/requests/drafts/${encodeURIComponent(reference)}/retry`, { method: 'POST' }),
  deleteSupportRequestDraft: (reference: string) => request(`/api/support/requests/drafts/${encodeURIComponent(reference)}`, { method: 'DELETE' }),
  checkUpdates: (force = false) => json<UpdateStatus>(`/api/updates/check?force=${force}`),
  printerSetupStatus: () => json<PrinterSetupStatus>('/api/printer-setup/status'),
  availablePrinterPort: (printerName: string, ipAddress: string, startingPort = 9100) =>
    json<PrinterPortSelection>(`/api/printer-setup/available-port?printerName=${encodeURIComponent(printerName)}&ipAddress=${encodeURIComponent(ipAddress)}&startingPort=${startingPort}`),
  printerState: () => json<PrinterStateStatus>('/api/printer-state'),
  updatePrinterState: (state: PrinterStateUpdate) => json<PrinterStateStatus>('/api/printer-state', {
    method: 'PUT',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(state),
  }),
  resetPrinterState: () => json<PrinterStateStatus>('/api/printer-state/reset', { method: 'POST' }),
  listenerPrinterState: (id: string) => json<PrinterStateStatus>(`/api/listeners/${encodeURIComponent(id)}/printer-state`),
  updateListenerPrinterState: (id: string, state: PrinterStateUpdate) => json<PrinterStateStatus>(`/api/listeners/${encodeURIComponent(id)}/printer-state`, {
    method: 'PUT', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(state),
  }),
  resetListenerPrinterState: (id: string) => json<PrinterStateStatus>(`/api/listeners/${encodeURIComponent(id)}/printer-state/reset`, { method: 'POST' }),
  printerProfiles: () => json<PrinterProfileStatus>('/api/printer-profiles'),
  createPrinterProfile: (profile: PrinterProfileInput) => json<PrinterProfile>('/api/printer-profiles', {
    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(profile),
  }),
  updatePrinterProfile: (id: string, profile: PrinterProfileInput) => json<PrinterProfile>(`/api/printer-profiles/${encodeURIComponent(id)}`, {
    method: 'PUT', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(profile),
  }),
  duplicatePrinterProfile: (id: string) => json<PrinterProfile>(`/api/printer-profiles/${encodeURIComponent(id)}/duplicate`, { method: 'POST' }),
  selectPrinterProfile: (profileId: string) => json<PrinterProfile>('/api/printer-profiles/select', {
    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ profileId }),
  }),
  deletePrinterProfile: (id: string) => request(`/api/printer-profiles/${encodeURIComponent(id)}`, { method: 'DELETE' }),
  exportPrinterProfile: (id: string) => download(`/api/printer-profiles/${encodeURIComponent(id)}/export`),
  importPrinterProfile: (file: File) => {
    const form = new FormData(); form.set('file', file)
    return json<PrinterProfile>('/api/printer-profiles/import', { method: 'POST', body: form })
  },
  storedGraphics: () => json<StoredGraphic[]>('/api/stored-graphics'),
  importStoredGraphic: (keyCode: string, name: string, file: File) => {
    const form = new FormData()
    form.set('name', name)
    form.set('file', file)
    return json<StoredGraphic>(`/api/stored-graphics/${encodeURIComponent(keyCode)}`, { method: 'POST', body: form })
  },
  deleteStoredGraphic: (keyCode: string) => request(`/api/stored-graphics/${encodeURIComponent(keyCode)}`, { method: 'DELETE' }),
  createConfigurationBackup: (input: ConfigurationBackupCreateRequest) => download('/api/backups/create', {
    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(input),
  }),
  inspectConfigurationBackup: (file: File, password: string) => {
    const form = new FormData(); form.set('file', file); form.set('password', password)
    return json<ConfigurationBackupPreview>('/api/backups/inspect', { method: 'POST', body: form })
  },
  restoreConfigurationBackup: (file: File, password: string) => {
    const form = new FormData(); form.set('file', file); form.set('password', password)
    return json<ConfigurationRestoreResult>('/api/backups/restore', { method: 'POST', body: form })
  },
}
