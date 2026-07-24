import { lazy, Suspense, useCallback, useEffect, useMemo, useRef, useState, type ChangeEvent, type FormEvent } from 'react'
import {
  AlertTriangle,
  Braces,
  CheckCircle2,
  ChevronDown,
  ChevronRight,
  CircleHelp,
  CircleStop,
  Copy,
  DatabaseBackup,
  Download,
  ExternalLink,
  FileText,
  Filter,
  FlaskConical,
  Gauge,
  KeyRound,
  LifeBuoy,
  ImageIcon,
  LockKeyhole,
  Minus,
  Moon,
  Network,
  PanelLeftClose,
  PanelLeftOpen,
  PanelRightClose,
  PanelRightOpen,
  Package,
  Play,
  Plus,
  Printer,
  RefreshCw,
  RotateCw,
  Search,
  Settings,
  SlidersHorizontal,
  Sun,
  Trash2,
  Upload,
  X,
} from 'lucide-react'
import { api } from './api'
import { QRCodeSVG } from 'qrcode.react'
import BarcodeRenderer from 'react-barcode'
import { PrinterSetupWizard } from './PrinterSetupWizard'
import { PrinterStateSettings } from './PrinterStateSettings'
import { PrinterProfilesSettings } from './PrinterProfilesSettings'
import { StoredGraphicsSettings } from './StoredGraphicsSettings'
import { BackupRestoreSettings } from './BackupRestoreSettings'
import { TrialOnboarding } from './TrialOnboarding'
import type { BackupPreferences, ConnectionDiagnosticCheck, ConnectionDiagnosticReport, JobSummary, PrinterListener, PromotionOfferStatus, ReceiptJob, ReceiptLine, ServiceStatus, StoredGraphic, SupportPackagePreview, SupportRequestDraftSummary, SupportRequestInput, SupportRequestPreview, SupportRequestResult, UpdateStatus } from './types'

const PrinterListenersSettings = lazy(() => import('./PrinterListenersSettings').then(module => ({ default: module.PrinterListenersSettings })))
const trialOnboardingStorageKey = 'pos-printer-emulator-trial-onboarding-v2'
const viewModeStorageKey = 'pos-printer-emulator-view-mode'

const emptyStatus: ServiceStatus = {
  listening: false,
  listener: '0.0.0.0:9100',
  version: '0.3.47',
  license: {
    mode: 'Trial', isPaid: false, hasProAccess: false, isEnterprise: false, maximumListeners: 1, dailyLimit: 5, usedToday: 0, remaining: 5, localDate: '',
    customerName: '', emailAddress: '',
    maintenance: { isApplicable: false, isActive: false, isGrandfathered: false, state: 'NotApplicable', message: 'Annual maintenance is included for one year with a paid license purchase.' },
    promotion: { isApplicable: true, isActive: false, state: 'None', message: 'No promotional access is installed.' },
    features: { history: false, exports: false, premiumFeatures: false, watermark: true, storedLogos: false, printerState: false, printerProfiles: false, updates: false, support: false, multipleListeners: false },
  },
}

type ClearRequest =
  | { kind: 'one'; id: string; label: string }
  | { kind: 'all'; count: number; listenerId?: string; listenerName?: string }

type SettingsSection = 'license' | 'printer' | 'listeners' | 'profiles' | 'logos' | 'state' | 'backup' | 'updates' | 'support'
type ViewMode = 'simple' | 'expert'

function formatBytes(value: number) {
  return value < 1024 ? `${value} B` : `${(value / 1024).toFixed(1)} KB`
}

function formatTime(value: string) {
  return new Intl.DateTimeFormat(undefined, { hour: '2-digit', minute: '2-digit', second: '2-digit' }).format(new Date(value))
}

function formatMaintenanceDate(value?: string) {
  return value ? new Intl.DateTimeFormat(undefined, { year: 'numeric', month: 'long', day: 'numeric', timeZone: 'UTC' }).format(new Date(value)) : 'Not applicable'
}

function formatTrialDateTime(value?: string) {
  return value ? new Intl.DateTimeFormat(undefined, {
    year: 'numeric', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit',
  }).format(new Date(value)) : 'Not available'
}

function formatTrialRemaining(expiresAt: string | undefined, now: number) {
  if (!expiresAt) return 'Not available'
  const remaining = Math.max(0, new Date(expiresAt).getTime() - now)
  const days = Math.floor(remaining / 86_400_000)
  const hours = Math.floor((remaining % 86_400_000) / 3_600_000)
  const minutes = Math.floor((remaining % 3_600_000) / 60_000)
  return remaining === 0 ? 'Expired' : `${days} day${days === 1 ? '' : 's'}, ${hours} hour${hours === 1 ? '' : 's'}, ${minutes} min remaining`
}

function saveDownload(blob: Blob, fileName: string) {
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = fileName
  link.style.display = 'none'
  document.body.appendChild(link)
  link.click()
  link.remove()
  window.setTimeout(() => URL.revokeObjectURL(url), 1_000)
}

function App() {
  const [theme, setTheme] = useState<'light' | 'dark'>(() =>
    document.documentElement.dataset.theme === 'dark' ? 'dark' : 'light',
  )
  const [status, setStatus] = useState<ServiceStatus>(emptyStatus)
  const [viewMode, setViewMode] = useState<ViewMode>(() => localStorage.getItem(viewModeStorageKey) === 'expert' ? 'expert' : 'simple')
  const [statusReady, setStatusReady] = useState(false)
  const [trialOnboardingComplete, setTrialOnboardingComplete] = useState(() =>
    localStorage.getItem(trialOnboardingStorageKey) === 'complete',
  )
  const [jobs, setJobs] = useState<JobSummary[]>([])
  const [selectedId, setSelectedId] = useState<string | undefined>(() => localStorage.getItem('pos-printer-emulator-selected-job') ?? undefined)
  const [job, setJob] = useState<ReceiptJob>()
  const [query, setQuery] = useState(() => localStorage.getItem('pos-printer-emulator-job-search') ?? '')
  const [listenerFilter, setListenerFilter] = useState(() => localStorage.getItem('pos-printer-emulator-listener-filter') ?? 'all')
  const [listeners, setListeners] = useState<PrinterListener[]>([])
  const [tab, setTab] = useState<'commands' | 'raw' | 'details'>(() => {
    const saved = localStorage.getItem('pos-printer-emulator-inspector-tab')
    return saved === 'raw' || saved === 'details' ? saved : 'commands'
  })
  const [zoom, setZoom] = useState(() => Math.min(160, Math.max(50, Number(localStorage.getItem('pos-printer-emulator-zoom')) || 100)))
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string>()
  const [settingsSection, setSettingsSection] = useState<SettingsSection>()
  const [updateStatus, setUpdateStatus] = useState<UpdateStatus>()
  const [updateNoticeDismissed, setUpdateNoticeDismissed] = useState(false)
  const [activityCollapsed, setActivityCollapsed] = useState(() => localStorage.getItem('pos-printer-emulator-activity-collapsed') === 'true')
  const [inspectorCollapsed, setInspectorCollapsed] = useState(() => localStorage.getItem('pos-printer-emulator-inspector-collapsed') === 'true')
  const [clearRequest, setClearRequest] = useState<ClearRequest>()
  const [clearing, setClearing] = useState(false)
  const [storedGraphics, setStoredGraphics] = useState<StoredGraphic[]>([])
  const [captureBusy, setCaptureBusy] = useState(false)
  const [exporting, setExporting] = useState<'text' | 'raw' | 'capture'>()
  const captureFileRef = useRef<HTMLInputElement>(null)
  const multipleListenersEnabled = status.license.features.multipleListeners === true && status.license.maximumListeners > 1

  useEffect(() => {
    document.documentElement.dataset.theme = theme
    localStorage.setItem('pos-printer-emulator-theme', theme)
    document.querySelector('meta[name="theme-color"]')?.setAttribute('content', theme === 'dark' ? '#0d1522' : '#f7f9fc')
  }, [theme])

  useEffect(() => {
    localStorage.setItem(viewModeStorageKey, viewMode)
  }, [viewMode])

  useEffect(() => {
    localStorage.setItem('pos-printer-emulator-activity-collapsed', String(activityCollapsed))
  }, [activityCollapsed])

  useEffect(() => {
    localStorage.setItem('pos-printer-emulator-inspector-collapsed', String(inspectorCollapsed))
  }, [inspectorCollapsed])

  useEffect(() => {
    if (selectedId) localStorage.setItem('pos-printer-emulator-selected-job', selectedId)
    else localStorage.removeItem('pos-printer-emulator-selected-job')
    localStorage.setItem('pos-printer-emulator-job-search', query)
    localStorage.setItem('pos-printer-emulator-listener-filter', listenerFilter)
    localStorage.setItem('pos-printer-emulator-inspector-tab', tab)
    localStorage.setItem('pos-printer-emulator-zoom', String(zoom))
  }, [selectedId, query, listenerFilter, tab, zoom])

  const refresh = useCallback(async () => {
    try {
      const [nextStatus, nextJobs] = await Promise.all([api.status(), api.jobs(listenerFilter === 'all' ? undefined : listenerFilter)])
      setStatus(nextStatus)
      setStatusReady(true)
      setJobs(nextJobs)
      setSelectedId(current => current && nextJobs.some(item => item.id === current) ? current : nextJobs[0]?.id)
    } catch (cause) {
      setError(cause instanceof Error ? cause.message : 'Unable to reach the local service.')
    }
  }, [listenerFilter])

  useEffect(() => {
    if (!statusReady) return
    let cancelled = false
    const load = async () => {
      try {
        const next = await api.printerListeners()
        if (!cancelled) {
          setListeners(next.listeners)
          setListenerFilter(current => current === 'all' || next.listeners.some(listener => listener.id === current) ? current : 'all')
        }
      } catch { /* Listener management errors are shown inside Settings. */ }
    }
    void load()
    const timer = window.setInterval(() => void load(), 4_000)
    return () => {
      cancelled = true
      window.clearInterval(timer)
    }
  }, [statusReady])

  const checkForUpdates = useCallback(async (force = false) => {
    const result = await api.checkUpdates(force)
    setUpdateStatus(result)
    if (result.updateAvailable) setUpdateNoticeDismissed(false)
    return result
  }, [])

  const refreshStoredGraphics = useCallback(async () => {
    setStoredGraphics(await api.storedGraphics())
  }, [])

  const applyRestoredPreferences = useCallback(async (preferences: BackupPreferences) => {
    setTheme(preferences.theme)
    setActivityCollapsed(preferences.activityCollapsed)
    setInspectorCollapsed(preferences.inspectorCollapsed)
    await refresh()
    if (status.license.features.storedLogos) await refreshStoredGraphics()
    if (status.license.features.multipleListeners) {
      const restoredListeners = await api.printerListeners()
      setListeners(restoredListeners.listeners)
    }
  }, [refresh, refreshStoredGraphics, status.license.features.multipleListeners, status.license.features.storedLogos])

  useEffect(() => {
    void refresh()
    const timer = window.setInterval(() => void refresh(), 1500)
    return () => window.clearInterval(timer)
  }, [refresh])

  useEffect(() => {
    if (status.license.features.storedLogos) {
      void refreshStoredGraphics().catch(cause => setError(cause instanceof Error ? cause.message : 'Unable to load stored printer logos.'))
    } else {
      setStoredGraphics([])
    }
  }, [refreshStoredGraphics, status.license.features.storedLogos])

  useEffect(() => {
    if (!status.license.features.updates) return
    const initial = window.setTimeout(() => void checkForUpdates(false).catch(() => undefined), 5000)
    const periodic = window.setInterval(() => void checkForUpdates(false).catch(() => undefined), 4 * 60 * 60 * 1000)
    return () => {
      window.clearTimeout(initial)
      window.clearInterval(periodic)
    }
  }, [checkForUpdates, status.license.features.updates])

  useEffect(() => {
    if (!selectedId) {
      setJob(undefined)
      return
    }
    if (job?.id === selectedId) return
    let cancelled = false
    void api.job(selectedId)
      .then(nextJob => { if (!cancelled) setJob(nextJob) })
      .catch(cause => { if (!cancelled) setError(cause.message) })
    return () => { cancelled = true }
  }, [job?.id, selectedId])

  const filteredJobs = useMemo(() => {
    const needle = query.trim().toLowerCase()
    if (!needle) return jobs
    return jobs.filter(item => `${item.sourceIp} ${item.preview} ${item.status} ${item.origin} ${item.listenerName ?? ''} ${item.listenerPort ?? ''} ${item.importedFileName ?? ''}`.toLowerCase().includes(needle))
  }, [jobs, query])

  const simpleListener = useMemo(() => {
    if (listenerFilter !== 'all') {
      const filtered = listeners.find(listener => listener.id === listenerFilter)
      if (filtered) return filtered
    }
    return listeners.find(listener => listener.isDefault) ?? listeners.find(listener => listener.listening) ?? listeners[0]
  }, [listenerFilter, listeners])

  async function renderSample() {
    setBusy(true)
    setError(undefined)
    try {
      const created = await api.sample()
      setSelectedId(created.id)
      setJob(created)
      void refresh()
      return true
    } catch (cause) {
      setError(cause instanceof Error ? cause.message : 'Could not render the sample receipt.')
      return false
    } finally {
      setBusy(false)
    }
  }

  function completeTrialOnboarding() {
    localStorage.setItem(trialOnboardingStorageKey, 'complete')
    setTrialOnboardingComplete(true)
  }

  function reviewTrialOnboarding() {
    localStorage.removeItem(trialOnboardingStorageKey)
    setSettingsSection(undefined)
    setTrialOnboardingComplete(false)
  }

  function openOnboardingSection(section: SettingsSection) {
    completeTrialOnboarding()
    setSettingsSection(section)
  }

  async function importCapture(event: ChangeEvent<HTMLInputElement>) {
    const file = event.target.files?.[0]
    event.target.value = ''
    if (!file) return
    setCaptureBusy(true)
    setError(undefined)
    try {
      const created = await api.importCapture(file)
      setSelectedId(created.id)
      await refresh()
    } catch (cause) {
      setError(cause instanceof Error ? cause.message : 'The receipt capture could not be imported.')
    } finally {
      setCaptureBusy(false)
    }
  }

  async function replayJob() {
    if (!selectedId) return
    setCaptureBusy(true)
    setError(undefined)
    try {
      const created = await api.replayJob(selectedId)
      setSelectedId(created.id)
      await refresh()
    } catch (cause) {
      setError(cause instanceof Error ? cause.message : 'The receipt capture could not be replayed.')
    } finally {
      setCaptureBusy(false)
    }
  }

  async function downloadJob(format: 'text' | 'raw' | 'capture') {
    if (!job || exporting) return
    setError(undefined)
    setExporting(format)
    try {
      const blob = await api.downloadJob(job.id, format)
      const extension = format === 'text' ? 'txt' : format === 'raw' ? 'bin' : 'ppecapture'
      saveDownload(blob, `receipt-${job.id.replaceAll('-', '')}.${extension}`)
    } catch (cause) {
      setError(cause instanceof Error ? cause.message : 'The receipt could not be downloaded.')
    } finally {
      setExporting(undefined)
    }
  }

  function clearJob(id: string) {
    const selected = jobs.find(item => item.id === id)
    setClearRequest({ kind: 'one', id, label: selected?.preview ?? 'receipt job' })
  }

  function clearAllJobs() {
    if (jobs.length === 0) return
    const filteredListener = listenerFilter === 'all' ? undefined : listeners.find(listener => listener.id === listenerFilter)
    setClearRequest({
      kind: 'all',
      count: jobs.length,
      listenerId: listenerFilter === 'all' ? undefined : listenerFilter,
      listenerName: filteredListener?.name ?? (listenerFilter === 'all' ? undefined : 'the selected printer'),
    })
  }

  async function confirmClear() {
    if (!clearRequest) return
    setClearing(true)
    try {
      if (clearRequest.kind === 'all') {
        await api.clearJobs(clearRequest.listenerId)
        setJobs([])
        setSelectedId(undefined)
        setJob(undefined)
      } else {
        await api.deleteJob(clearRequest.id)
        const remaining = jobs.filter(item => item.id !== clearRequest.id)
        setJobs(remaining)
        if (selectedId === clearRequest.id) {
          setJob(undefined)
          setSelectedId(remaining[0]?.id)
        }
      }
      setClearRequest(undefined)
    } catch (cause) {
      setError(cause instanceof Error ? cause.message : 'Could not clear the receipt jobs.')
    } finally {
      setClearing(false)
    }
  }

  return (
    <div className="app-shell">
      <Header status={status} onSample={renderSample} busy={busy} theme={theme} viewMode={viewMode}
        onViewMode={setViewMode}
        onTheme={() => setTheme(current => current === 'light' ? 'dark' : 'light')}
        onTrialSetup={reviewTrialOnboarding}
        onSettings={setSettingsSection} />
      {error && (
        <div className="error-banner" role="alert">
          <AlertTriangle size={16} /> {error}
          <button onClick={() => setError(undefined)}>Dismiss</button>
        </div>
      )}
      {status.license.features.updates && updateStatus?.updateAvailable && !updateNoticeDismissed && (
        <div className="update-banner" role="status">
          <RefreshCw size={16} />
          <span><strong>Update available:</strong> POS Printer Emulator {updateStatus.latestVersion}</span>
          <button onClick={() => setSettingsSection('updates')}>View update</button>
          <button className="banner-dismiss" onClick={() => setUpdateNoticeDismissed(true)} aria-label="Dismiss update notification"><X size={15} /></button>
        </div>
      )}
      <input ref={captureFileRef} className="capture-file-input" type="file" accept=".bin,.ppecapture,application/octet-stream,application/vnd.pos-printer-emulator.capture+zip" onChange={importCapture} />
      {viewMode === 'simple' ? (
        <SimpleWorkspace
          status={status}
          listener={simpleListener}
          job={job}
          storedGraphics={storedGraphics}
          busy={busy}
          importing={captureBusy}
          importEnabled={status.license.features.premiumFeatures}
          onSample={renderSample}
          onImport={() => captureFileRef.current?.click()}
          onOpenSettings={setSettingsSection}
          onOpenExpert={() => setViewMode('expert')}
          onError={setError}
        />
      ) : (
        <main className={`workspace ${activityCollapsed ? 'activity-is-collapsed' : ''} ${inspectorCollapsed ? 'inspector-is-collapsed' : ''}`}>
          {activityCollapsed ? (
            <CollapsedSide side="left" label="Activity" onExpand={() => setActivityCollapsed(false)} />
          ) : (
            <ActivityRail jobs={filteredJobs} totalJobs={jobs.length} selectedId={selectedId} query={query} onQuery={setQuery}
              listenerEndpoint={status.listener}
              listeners={listeners} listenerFilter={listenerFilter} onListenerFilter={setListenerFilter}
              onSelect={setSelectedId} onDelete={clearJob} onClearAll={clearAllJobs}
              onCollapse={() => setActivityCollapsed(true)} historyEnabled={status.license.features.history}
              onImport={() => captureFileRef.current?.click()} importEnabled={status.license.features.premiumFeatures} importing={captureBusy} />
          )}
          <PreviewPane job={job} zoom={zoom} onZoom={setZoom} onSample={renderSample} license={status.license} storedGraphics={storedGraphics}
            listenerEndpoint={status.listener}
            onReplay={replayJob} replaying={captureBusy} onDownload={downloadJob} exporting={exporting} />
          {inspectorCollapsed ? (
            <CollapsedSide side="right" label="Inspector" onExpand={() => setInspectorCollapsed(false)} />
          ) : (
            <Inspector job={job} tab={tab} onTab={setTab} onCollapse={() => setInspectorCollapsed(true)} />
          )}
        </main>
      )}
      <footer className="status-bar">
        <span>POS Printer Emulator v{status.version} · {status.license.mode} License</span>
        <span>Local only. Receipt data stays on this device.</span>
        <span>Windows 11 Pro · x64</span>
      </footer>
      {settingsSection && (
        <SettingsDialog
          status={status}
          initialSection={settingsSection}
          updateStatus={updateStatus}
          onCheckUpdates={checkForUpdates}
          storedGraphics={storedGraphics}
          listeners={listeners}
          onStoredGraphicsChanged={refreshStoredGraphics}
          onListenersChanged={setListeners}
          preferences={{ theme, activityCollapsed, inspectorCollapsed }}
          onPreferencesRestored={applyRestoredPreferences}
          onClose={() => setSettingsSection(undefined)}
          onActivated={license => {
            setStatus(current => ({ ...current, license }))
            void refresh()
          }}
        />
      )}
      {statusReady && status.license.mode === 'Trial' && !trialOnboardingComplete && !settingsSection && (
        <TrialOnboarding
          status={status}
          listener={listeners.find(listener => listener.isDefault) ?? listeners[0]}
          onSetup={() => openOnboardingSection('printer')}
          onTroubleshoot={() => openOnboardingSection('support')}
          onDismiss={completeTrialOnboarding}
          onTestReceipt={async () => {
            if (await renderSample()) completeTrialOnboarding()
          }}
        />
      )}
      {clearRequest && (
        <ClearJobsDialog request={clearRequest} busy={clearing}
          onCancel={() => setClearRequest(undefined)} onConfirm={confirmClear} />
      )}
    </div>
  )
}

function SimpleWorkspace({ status, listener, job, storedGraphics, busy, importing, importEnabled, onSample, onImport, onOpenSettings, onOpenExpert, onError }: {
  status: ServiceStatus
  listener?: PrinterListener
  job?: ReceiptJob
  storedGraphics: StoredGraphic[]
  busy: boolean
  importing: boolean
  importEnabled: boolean
  onSample: () => void
  onImport: () => void
  onOpenSettings: (section: SettingsSection) => void
  onOpenExpert: () => void
  onError: (message?: string) => void
}) {
  const [copied, setCopied] = useState<string>()
  const storedGraphicMap = useMemo(() => new Map(storedGraphics.map(graphic => [graphic.keyCode, graphic])), [storedGraphics])
  const fallbackPort = Number(status.listener.match(/:(\d+)$/)?.[1]) || 9100
  const rawAddress = listener?.connectionAddress?.trim() || listener?.bindAddress?.trim() || status.listener.replace(/:\d+$/, '')
  const address = !rawAddress || rawAddress === '0.0.0.0' || rawAddress === '::' ? '127.0.0.1' : rawAddress
  const port = listener?.port ?? fallbackPort
  const listenerName = listener?.name || 'POS Printer Emulator'
  const listenerHealthy = listener ? listener.listening && listener.status !== 'Faulted' : status.listening
  const summaryHealthy = status.listenerSummary
    ? status.listenerSummary.running > 0 && status.listenerSummary.faulted === 0
    : listenerHealthy
  const healthTitle = summaryHealthy ? 'Everything is ready' : 'Printer listener needs attention'
  const healthDescription = summaryHealthy
    ? 'Your printer listener is online and waiting for a receipt.'
    : 'The listener is not currently accepting receipts.'
  const nextAction = summaryHealthy
    ? 'Connect your POS in Step 2 or send a Test Receipt in Step 3.'
    : 'Run diagnostics or open Settings to review the listener.'

  async function copyConnectionValue(label: string, value: string) {
    try {
      if (navigator.clipboard?.writeText) {
        await navigator.clipboard.writeText(value)
      } else {
        const input = document.createElement('textarea')
        input.value = value
        input.style.position = 'fixed'
        input.style.opacity = '0'
        document.body.appendChild(input)
        input.select()
        const copySucceeded = document.execCommand('copy')
        input.remove()
        if (!copySucceeded) throw new Error('Copy was not supported.')
      }
      setCopied(label)
      window.setTimeout(() => setCopied(current => current === label ? undefined : current), 1_800)
    } catch {
      onError(`Could not copy the ${label.toLowerCase()}. Select the value and copy it manually.`)
    }
  }

  return (
    <main className="simple-workspace">
      <section className="simple-primary" aria-labelledby="simple-health-title">
        <div className={`simple-health ${summaryHealthy ? 'is-ready' : 'needs-attention'}`} role="status">
          <div className="simple-health-icon" aria-hidden="true">{summaryHealthy ? <CheckCircle2 size={39} /> : <AlertTriangle size={36} />}</div>
          <div>
            <h1 id="simple-health-title">{healthTitle}</h1>
            <p>{healthDescription}</p>
            <strong>Next: <span>{nextAction}</span></strong>
          </div>
        </div>

        <div className="simple-steps" aria-label="Printer setup steps">
          <article className="simple-step">
            <span className="simple-step-number" aria-hidden="true">1</span>
            <div>
              <h2>Set up your Windows printer</h2>
              <p>Create a standard TCP/IP printer that points to this listener.</p>
              <button className="simple-primary-action" type="button" onClick={() => onOpenSettings('printer')}><Printer size={17} /> Open setup wizard</button>
            </div>
          </article>

          <article className="simple-step">
            <span className="simple-step-number" aria-hidden="true">2</span>
            <div>
              <h2>Connect your POS</h2>
              <p>Configure your POS to send receipts to this listener.</p>
              <div className="simple-connection-grid">
                <ConnectionValue label="Listener name" value={listenerName} copied={copied === 'Listener name'} onCopy={() => void copyConnectionValue('Listener name', listenerName)} />
                <ConnectionValue label="Address" value={address} copied={copied === 'Address'} onCopy={() => void copyConnectionValue('Address', address)} />
                <ConnectionValue label="Port" value={String(port)} copied={copied === 'Port'} onCopy={() => void copyConnectionValue('Port', String(port))} />
                <ConnectionValue label="Protocol" value="RAW TCP" copied={copied === 'Protocol'} onCopy={() => void copyConnectionValue('Protocol', 'RAW TCP')} />
              </div>
              <small>Use these details in your POS printer or network settings.</small>
            </div>
          </article>

          <article className="simple-step">
            <span className="simple-step-number" aria-hidden="true">3</span>
            <div>
              <h2>Verify your first receipt</h2>
              <p>Send a test receipt to confirm everything is working.</p>
              <button className="simple-primary-action" type="button" disabled={busy} onClick={onSample}><FlaskConical size={17} /> {busy ? 'Sending test receipt…' : 'Send test receipt'}</button>
            </div>
          </article>
        </div>
      </section>

      <section className="simple-latest" aria-labelledby="simple-latest-title">
        <header><h2 id="simple-latest-title">Latest receipt</h2>{job && <span>{formatTime(job.receivedAt)}</span>}</header>
        <div className={`simple-receipt-stage ${job ? 'has-receipt' : ''}`}>
          {job ? (
            <div className="simple-receipt-scale" style={{ width: `${Math.round(364 * job.profilePaperWidthMm / 80)}px` }}>
              <ReceiptPaper lines={job.lines} watermark={status.license.features.watermark} storedGraphics={storedGraphicMap} paperWidthMm={job.profilePaperWidthMm} />
            </div>
          ) : (
            <div className="simple-receipt-empty">
              <FileText size={48} />
              <strong>No receipt received yet</strong>
              <span>Receipts from your POS will appear here.</span>
            </div>
          )}
        </div>
        <p className="simple-listener-wait"><span className={`state-dot ${listenerHealthy ? '' : 'is-down'}`} /> {listenerHealthy ? `Waiting for a receipt on ${address}:${port} (RAW TCP)` : `Listener ${listenerName} is not currently running`}</p>
      </section>

      <section className="simple-actions" aria-labelledby="simple-actions-title">
        <div>
          <h2 id="simple-actions-title">Quick actions</h2>
          <div className="simple-action-list">
            <button type="button" onClick={onImport} disabled={!importEnabled || importing} title={importEnabled ? 'Open a saved receipt capture' : 'Capture import requires a Lite, Pro, or Enterprise License'}>
              {importEnabled ? <Upload size={28} /> : <LockKeyhole size={25} />}
              <span><strong>Import capture</strong><small>{importEnabled ? 'Open a saved ESC/POS capture file and view the receipt.' : 'Available with a Lite, Pro, or Enterprise License.'}</small></span>
              <ChevronRight size={18} />
            </button>
            <button type="button" onClick={() => onOpenSettings('support')}>
              <Gauge size={28} /><span><strong>Run diagnostics</strong><small>Check listener health and Windows configuration.</small></span><ChevronRight size={18} />
            </button>
            <button type="button" onClick={() => onOpenSettings('support')}>
              <LifeBuoy size={28} /><span><strong>Submit support request</strong><small>Get help with privacy-reviewed logs and system information.</small></span><ChevronRight size={18} />
            </button>
          </div>
        </div>
        <aside className="simple-expert-handoff">
          <h2>Need more control?</h2>
          <p>Open Expert Mode for detailed receipt commands, raw data, and job controls.</p>
          <button type="button" onClick={onOpenExpert}>Open Expert Mode <ChevronRight size={17} /></button>
        </aside>
      </section>
    </main>
  )
}

function ConnectionValue({ label, value, copied, onCopy }: { label: string; value: string; copied: boolean; onCopy: () => void }) {
  return (
    <div className="simple-connection-value">
      <span>{label}</span>
      <div><code>{value}</code><button type="button" onClick={onCopy} aria-label={`Copy ${label.toLowerCase()}`} title={`Copy ${label.toLowerCase()}`}><Copy size={15} />{copied && <b role="status">Copied</b>}</button></div>
    </div>
  )
}

function ClearJobsDialog({ request, busy, onCancel, onConfirm }: {
  request: ClearRequest
  busy: boolean
  onCancel: () => void
  onConfirm: () => void
}) {
  const isAll = request.kind === 'all'
  const listenerName = isAll ? request.listenerName : undefined
  return (
    <div className="modal-backdrop" role="presentation">
      <section className="confirm-dialog" role="dialog" aria-modal="true" aria-labelledby="clear-jobs-title">
        <div className="confirm-icon"><Trash2 size={24} /></div>
        <div>
          <h2 id="clear-jobs-title">{isAll ? listenerName ? `Clear ${request.count} jobs for ${listenerName}?` : `Clear all ${request.count} jobs?` : 'Clear this job?'}</h2>
          <p>{isAll
            ? listenerName
              ? `Every job for ${listenerName} will be permanently removed. Jobs belonging to other printers will be kept.`
              : 'Every job in Activity will be permanently removed, including saved paid-license history.'
            : `“${request.label}” will be permanently removed from Activity and saved history.`}</p>
        </div>
        <div className="confirm-actions">
          <button onClick={onCancel} disabled={busy}>Cancel</button>
          <button className="danger-button" onClick={onConfirm} disabled={busy}>{busy ? 'Clearing…' : isAll ? 'Clear all jobs' : 'Clear job'}</button>
        </div>
      </section>
    </div>
  )
}

function Header({ status, onSample, busy, theme, viewMode, onViewMode, onTheme, onTrialSetup, onSettings }: {
  status: ServiceStatus
  onSample: () => void
  busy: boolean
  theme: 'light' | 'dark'
  viewMode: ViewMode
  onViewMode: (mode: ViewMode) => void
  onTheme: () => void
  onTrialSetup: () => void
  onSettings: (section: SettingsSection) => void
}) {
  const listenerSummary = status.listenerSummary
  const multipleListenersEnabled = status.license.features.multipleListeners === true && status.license.maximumListeners > 1
  const listenerStateLabel = multipleListenersEnabled && listenerSummary
    ? `${listenerSummary.running} of ${listenerSummary.total} listeners running`
    : status.listening ? 'Running (listening)' : 'Listener stopped'
  const listenerFact = multipleListenersEnabled && listenerSummary
    ? `${listenerSummary.total} printers · ${listenerSummary.faulted} faulted`
    : status.listener
  const serviceLive = listenerSummary ? listenerSummary.running > 0 : status.listening
  return (
    <header className="app-header">
      <div className="brand-mark" aria-hidden="true">
        <img src="/pos-printer-emulator-icon.png" alt="" />
      </div>
      <strong className="brand-name">POS Printer Emulator</strong>
      <div className={`service-state ${serviceLive ? 'is-live' : 'is-down'}`}>
        <span className="state-dot" /> {listenerStateLabel}
      </div>
      <div className="header-fact"><span>{multipleListenersEnabled && listenerSummary ? 'Listeners' : 'Listener'}</span> {listenerFact}</div>
      {!status.license.isPaid && <div className="trial-allowance"><strong>{status.license.remaining} of {status.license.dailyLimit}</strong> complete Trial POS jobs left today</div>}
      <div className="view-mode-switch" role="group" aria-label="Application view">
        <button className={viewMode === 'simple' ? 'active' : ''} type="button" aria-pressed={viewMode === 'simple'} onClick={() => onViewMode('simple')}>
          <span className="mode-full-label">Simple mode</span><span className="mode-short-label">Simple</span>
        </button>
        <button className={viewMode === 'expert' ? 'active' : ''} type="button" aria-pressed={viewMode === 'expert'} onClick={() => onViewMode('expert')}>
          <span className="mode-full-label">Expert mode</span><span className="mode-short-label">Expert</span>
        </button>
      </div>
      <div className="header-actions">
        {status.license.mode === 'Trial' && <button className="trial-setup-button" onClick={onTrialSetup} title="Reopen the two-step Trial setup guide"><CircleHelp size={16} /> Trial setup</button>}
        <button className="sample-button" onClick={onSample} disabled={busy} title="Built-in Test Receipts are unlimited and do not use Trial POS jobs">
          <FlaskConical size={16} /> {busy ? 'Rendering…' : 'Test receipt'}
        </button>
        <button
          className="theme-button"
          onClick={onTheme}
          aria-label={`Switch to ${theme === 'light' ? 'dark' : 'light'} mode`}
          title={`Switch to ${theme === 'light' ? 'dark' : 'light'} mode`}
        >
          {theme === 'light' ? <Moon size={17} /> : <Sun size={17} />}
          {theme === 'light' ? 'Dark mode' : 'Light mode'}
        </button>
        <button className="settings-button" onClick={() => onSettings('license')} aria-haspopup="dialog">
          <Settings size={17} /> Settings
        </button>
      </div>
    </header>
  )
}

function ActivityRail({ jobs, totalJobs, selectedId, query, onQuery, listenerEndpoint, listeners, listenerFilter, onListenerFilter, onSelect, onDelete, onClearAll, onCollapse, historyEnabled, onImport, importEnabled, importing }: {
  jobs: JobSummary[]
  totalJobs: number
  selectedId?: string
  query: string
  onQuery: (value: string) => void
  listenerEndpoint: string
  listeners: PrinterListener[]
  listenerFilter: string
  onListenerFilter: (listenerId: string) => void
  onSelect: (id: string) => void
  onDelete: (id: string) => void
  onClearAll: () => void
  onCollapse: () => void
  historyEnabled: boolean
  onImport: () => void
  importEnabled: boolean
  importing: boolean
}) {
  return (
    <aside className="activity-rail">
      <div className="pane-heading">
        <strong>Activity</strong>
        <div className="pane-actions">
          <button onClick={onImport} disabled={!importEnabled || importing} aria-label="Import receipt capture" title={importEnabled ? 'Import .bin or .ppecapture receipt' : 'Capture import requires a Lite, Pro, or Enterprise License'}><Upload size={15} /></button>
          <button onClick={onClearAll} disabled={totalJobs === 0} aria-label="Clear all jobs" title="Clear all jobs"><Trash2 size={15} /></button>
          <button onClick={onCollapse} aria-label="Collapse Activity panel" title="Collapse Activity panel"><PanelLeftClose size={17} /></button>
        </div>
      </div>
      <label className="search-control">
        <Search size={16} />
        <input value={query} onChange={event => onQuery(event.target.value)} placeholder="Search jobs…" />
      </label>
      <label className="filter-control">
        <Filter size={15} />
        <select value={listenerFilter} onChange={event => onListenerFilter(event.target.value)} aria-label="Filter Activity by printer">
          <option value="all">All printers</option>
          {listeners.map(listener => <option key={listener.id} value={listener.id}>{listener.name} · port {listener.port}</option>)}
        </select>
        <ChevronDown size={15} />
      </label>
      <div className="job-list">
        {jobs.map(item => (
          <div key={item.id} className={`job-row ${selectedId === item.id ? 'is-selected' : ''}`}>
            <button className="job-row-select" onClick={() => onSelect(item.id)} aria-label={`Open ${item.preview}`}>
              <span className={`job-dot ${item.status.toLowerCase().replaceAll(' ', '-')}`} />
              <span className="job-time">{formatTime(item.receivedAt)}</span>
              <span className="job-source">{item.sourceIp}</span>
              <strong className="job-preview">{item.preview}</strong>
              <span className="job-size">{formatBytes(item.payloadSize)}</span>
              {item.listenerName ? <span className="job-listener"><Network size={11} />{item.listenerName}{item.listenerPort ? ` · ${item.listenerPort}` : ''}</span> : null}
              <span className="job-result"><b className={`job-origin ${item.origin.toLowerCase().replaceAll(' ', '-')}`}>{item.origin}</b>{item.status}</span>
              {item.unsupportedCount > 0 && <span className="job-warning">{item.unsupportedCount} warning</span>}
            </button>
            <button className="job-delete" onClick={() => onDelete(item.id)} aria-label={`Clear ${item.preview}`} title="Clear this job"><Trash2 size={14} /></button>
          </div>
        ))}
        {jobs.length === 0 && (
          <div className="rail-empty">
            <CircleStop size={22} />
            <strong>No receipt jobs yet</strong>
            <span>Send ESC/POS data to {listenerEndpoint} or render the test receipt.</span>
          </div>
        )}
      </div>
      <div className="rail-footer">
        {historyEnabled ? `Showing ${jobs.length} saved job${jobs.length === 1 ? '' : 's'}` : `Showing ${jobs.length} session job${jobs.length === 1 ? '' : 's'} · History requires Lite`}
      </div>
    </aside>
  )
}

function PreviewPane({ job, zoom, onZoom, onSample, license, storedGraphics, listenerEndpoint, onReplay, replaying, onDownload, exporting }: {
  job?: ReceiptJob
  zoom: number
  onZoom: (value: number) => void
  onSample: () => void
  license: ServiceStatus['license']
  storedGraphics: StoredGraphic[]
  listenerEndpoint: string
  onReplay: () => void
  replaying: boolean
  onDownload: (format: 'text' | 'raw' | 'capture') => void
  exporting?: 'text' | 'raw' | 'capture'
}) {
  const storedGraphicMap = useMemo(() => new Map(storedGraphics.map(graphic => [graphic.keyCode, graphic])), [storedGraphics])
  return (
    <section className="preview-pane">
      <div className="pane-heading"><strong>Receipt preview</strong></div>
      <div className="preview-toolbar">
        <div className="zoom-group">
          <button aria-label="Zoom out" onClick={() => onZoom(Math.max(50, zoom - 10))}><Minus size={16} /></button>
          <span>{zoom}%</span>
          <button aria-label="Zoom in" onClick={() => onZoom(Math.min(160, zoom + 10))}><Plus size={16} /></button>
        </div>
        <button onClick={() => onZoom(100)}><RotateCw size={16} /> Actual size</button>
        <span className="toolbar-spacer" />
        {job && (license.features.exports
          ? <button className="toolbar-link" onClick={() => onDownload('text')} disabled={exporting !== undefined}><FileText size={16} /> {exporting === 'text' ? 'Saving…' : 'Text'}</button>
          : <button className="premium-disabled" disabled title="Available with a Lite, Pro, or Enterprise License"><LockKeyhole size={15} /> Text</button>)}
        {job && (license.features.exports
          ? <button className="toolbar-link" onClick={() => onDownload('raw')} disabled={exporting !== undefined}><Download size={16} /> {exporting === 'raw' ? 'Saving…' : 'Raw'}</button>
          : <button className="premium-disabled" disabled title="Available with a Lite, Pro, or Enterprise License"><LockKeyhole size={15} /> Raw</button>)}
        {job && (license.features.exports
          ? <button className="toolbar-link" onClick={() => onDownload('capture')} disabled={exporting !== undefined}><Package size={16} /> {exporting === 'capture' ? 'Saving…' : 'Capture'}</button>
          : <button className="premium-disabled" disabled title="Available with a Lite, Pro, or Enterprise License"><LockKeyhole size={15} /> Capture</button>)}
        {job && <button onClick={onReplay} disabled={!license.features.premiumFeatures || replaying}
          className={!license.features.premiumFeatures ? 'premium-disabled' : ''}
          title={!license.features.premiumFeatures ? 'Available with a Lite, Pro, or Enterprise License' : 'Replay this receipt without sending it from the POS again'}>
          {!license.features.premiumFeatures && <LockKeyhole size={15} />}<Play size={15} /> {replaying ? 'Replaying…' : 'Replay'}
        </button>}
        <button onClick={() => window.print()} disabled={!license.features.premiumFeatures}
          className={!license.features.premiumFeatures ? 'premium-disabled' : ''}
          title={!license.features.premiumFeatures ? 'Available with a Lite, Pro, or Enterprise License' : undefined}>
          {!license.features.premiumFeatures && <LockKeyhole size={15} />}<Printer size={16} /> Print / PDF
        </button>
      </div>
      <div className="preview-canvas">
        {job ? (
          <>
            {job.origin === 'Trial Limit' && <div className="trial-limit-banner" role="status"><AlertTriangle size={17} /><div><strong>Trial Limit Reached</strong><span>The original bytes and receipt content after line 10 were permanently discarded. Upgrade for unlimited complete jobs.</span></div><a href="https://buy.posprinteremulator.com/" target="_blank" rel="noreferrer">View licenses</a></div>}
            <div className="paper-wrap" style={{ transform: `scale(${zoom / 100})`, width: `${Math.round(364 * job.profilePaperWidthMm / 80)}px` }}>
              <ReceiptPaper lines={job.lines} watermark={license.features.watermark} storedGraphics={storedGraphicMap} paperWidthMm={job.profilePaperWidthMm} />
            </div>
          </>
        ) : (
          <div className="preview-empty">
            <div className="empty-receipt"><Printer size={34} /></div>
            <h2>Ready for a receipt</h2>
            <p>The listener is waiting on {listenerEndpoint}. Use a POS terminal or render the built-in test job.</p>
            <button className="primary-button" onClick={onSample}><FlaskConical size={16} /> Render test receipt</button>
          </div>
        )}
      </div>
    </section>
  )
}

function ReceiptPaper({ lines, watermark, storedGraphics, paperWidthMm }: { lines: ReceiptLine[]; watermark: boolean; storedGraphics: Map<string, StoredGraphic>; paperWidthMm: number }) {
  return (
    <article className="receipt-paper" style={{ width: `${Math.round(364 * paperWidthMm / 80)}px` }}>
      {watermark && (
        <div className="trial-watermark" aria-hidden="true">
          {Array.from({ length: 8 }, (_, index) => <span key={index}>TRIAL · NOT FOR PRODUCTION USE</span>)}
        </div>
      )}
      <div className="receipt-content">
        {lines.map((line, lineIndex) => {
          if (line.kind === 'barcode') return <BarcodePreview key={lineIndex} data={line.data ?? ''} />
          if (line.kind === 'qr') return <QrCode key={lineIndex} data={line.data ?? ''} />
          if (line.kind === 'image') {
            const imageData = line.data ?? ''
            if (imageData.startsWith('raster-v1:')) {
              return <RasterGraphic key={lineIndex} data={imageData} alignment={line.alignment} />
            }
            if (imageData.startsWith('stored-v1:') || imageData.startsWith('NV graphic ')) {
              const keyCode = storedGraphicKey(imageData)
              const graphic = keyCode ? storedGraphics.get(keyCode) : undefined
              return graphic
                ? <StoredGraphicPreview key={lineIndex} graphic={graphic} alignment={line.alignment} />
                : <StoredGraphicNotice key={lineIndex} data={imageData} />
            }
            return <GraphicPlaceholder key={lineIndex} label={imageData || 'Printer graphic'} />
          }
          return (
            <div key={lineIndex} className={`receipt-line align-${line.alignment}`}>
              {line.spans.map((span, spanIndex) => (
                <span key={spanIndex} style={{
                  fontWeight: span.bold ? 800 : 500,
                  textDecoration: span.underline ? 'underline' : undefined,
                  fontSize: `${12 * Math.min(span.height, 3)}px`,
                  letterSpacing: span.width > 1 ? `${span.width - 1}px` : undefined,
                  color: span.color === 'red' ? '#b3261e' : undefined,
                  background: span.inverted ? '#111' : undefined,
                  padding: span.inverted ? '0 2px' : undefined,
                  display: span.rotated || span.upsideDown ? 'inline-block' : undefined,
                  transform: span.rotated ? 'rotate(90deg)' : span.upsideDown ? 'rotate(180deg)' : undefined,
                  fontFamily: span.font === 'B' ? '"Arial Narrow", "Cascadia Mono", monospace' : undefined,
                  fontSizeAdjust: span.font === 'B' ? '0.85' : undefined,
                }}>{span.text}</span>
              ))}
              {line.spans.length === 0 && <span>&nbsp;</span>}
            </div>
          )
        })}
      </div>
    </article>
  )
}

function SettingsDialog({ status, initialSection, updateStatus, onCheckUpdates, storedGraphics, listeners, onStoredGraphicsChanged, onListenersChanged, preferences, onPreferencesRestored, onClose, onActivated }: {
  status: ServiceStatus
  initialSection: SettingsSection
  updateStatus?: UpdateStatus
  onCheckUpdates: (force?: boolean) => Promise<UpdateStatus>
  storedGraphics: StoredGraphic[]
  listeners: PrinterListener[]
  onStoredGraphicsChanged: () => Promise<void>
  onListenersChanged: (listeners: PrinterListener[]) => void
  preferences: BackupPreferences
  onPreferencesRestored: (preferences: BackupPreferences) => Promise<void>
  onClose: () => void
  onActivated: (license: ServiceStatus['license']) => void
}) {
  const features = status.license.features
  const multipleListenersEnabled = features.multipleListeners === true && status.license.maximumListeners > 1
  const canAccess = (candidate: SettingsSection) => candidate === 'logos' ? features.storedLogos
    : candidate === 'state' ? features.printerState
      : candidate === 'profiles' ? features.printerProfiles
        : candidate === 'updates' ? features.updates
          : true
  const [section, setSection] = useState<SettingsSection>(canAccess(initialSection) ? initialSection : 'license')
  useEffect(() => {
    if (!canAccess(section)) setSection('license')
  }, [features.printerProfiles, features.printerState, features.storedLogos, features.updates, section])
  const printerWizardLabel = status.license.mode === 'Trial' ? 'Trial Configuration Wizard' : 'Printer Setup Wizard'
  const labels: Record<SettingsSection, string> = { license: 'License', printer: printerWizardLabel, listeners: 'Printer Listeners', profiles: 'Printer Profiles', logos: 'Stored Logos', state: 'Printer State', backup: 'Backup & Restore', updates: 'Check for Updates', support: 'Support' }
  const lockedTitle = 'Requires a Lite, Pro, or Enterprise License'
  const updatesLockedTitle = status.license.isPaid
    ? 'Renew Application Maintenance and Support to check for updates'
    : lockedTitle
  const applicationVersion = status.version.startsWith('v') ? status.version : `v${status.version}`

  return (
    <div className="modal-backdrop" role="presentation">
      <section className="settings-dialog" role="dialog" aria-modal="true" aria-labelledby="settings-title">
        <header className="settings-dialog-header">
          <div><Settings size={20} /><div><h2 id="settings-title">Settings</h2><p>{labels[section]}</p></div></div>
          <button className="dialog-close" onClick={onClose} aria-label="Close settings"><X size={19} /></button>
        </header>
        <div className="settings-layout">
          <nav className="settings-nav" aria-label="Settings sections">
            <div className="settings-nav-items">
              <button className={section === 'license' ? 'active' : ''} onClick={() => setSection('license')}><KeyRound size={18} /><span>License</span><ChevronRight size={15} /></button>
              <button className={section === 'printer' ? 'active' : ''} onClick={() => setSection('printer')}><Printer size={18} /><span>{printerWizardLabel}</span><ChevronRight size={15} /></button>
              <button className={section === 'listeners' ? 'active' : ''} onClick={() => setSection('listeners')}><Network size={18} /><span>Printer Listeners</span>{multipleListenersEnabled ? <ChevronRight size={15} /> : <span className="pro-lock">1 included</span>}</button>
              <button className={section === 'profiles' ? 'active' : ''} onClick={() => setSection('profiles')} disabled={!features.printerProfiles} title={!features.printerProfiles ? lockedTitle : undefined}><SlidersHorizontal size={18} /><span>Printer Profiles</span>{features.printerProfiles ? <ChevronRight size={15} /> : <span className="pro-lock"><LockKeyhole size={12} />Lite</span>}</button>
              <button className={section === 'logos' ? 'active' : ''} onClick={() => setSection('logos')} disabled={!features.storedLogos} title={!features.storedLogos ? lockedTitle : undefined}><ImageIcon size={18} /><span>Stored Logos</span>{features.storedLogos ? <ChevronRight size={15} /> : <span className="pro-lock"><LockKeyhole size={12} />Lite</span>}</button>
              <button className={section === 'state' ? 'active' : ''} onClick={() => setSection('state')} disabled={!features.printerState} title={!features.printerState ? lockedTitle : undefined}><Gauge size={18} /><span>Printer State</span>{features.printerState ? <ChevronRight size={15} /> : <span className="pro-lock"><LockKeyhole size={12} />Lite</span>}</button>
              <button className={section === 'backup' ? 'active' : ''} onClick={() => setSection('backup')}><DatabaseBackup size={18} /><span>Backup &amp; Restore</span><ChevronRight size={15} /></button>
              <button className={section === 'updates' ? 'active' : ''} onClick={() => setSection('updates')} disabled={!features.updates} title={!features.updates ? updatesLockedTitle : undefined}><RefreshCw size={18} /><span>Check for Updates</span>{features.updates ? <ChevronRight size={15} /> : <span className="pro-lock"><LockKeyhole size={12} />{status.license.isPaid ? 'Renew' : 'Lite'}</span>}</button>
              <button className={section === 'support' ? 'active' : ''} onClick={() => setSection('support')}><LifeBuoy size={18} /><span>Support</span><ChevronRight size={15} /></button>
            </div>
            <div className="settings-version" aria-label={`Application version ${applicationVersion}`} title={`POS Printer Emulator ${applicationVersion}`}>
              <span>Version</span>
              <strong>{applicationVersion}</strong>
            </div>
          </nav>
          <div className="settings-content">
            {section === 'license' && <LicenseSettings status={status} onActivated={onActivated} />}
            {section === 'printer' && <PrinterSetupWizard onCancel={onClose} trialMode={status.license.mode === 'Trial'} />}
            {section === 'listeners' && <Suspense fallback={<div className="listener-loading"><RefreshCw className="spin" size={17} /> Loading Printer Listeners…</div>}><PrinterListenersSettings canManage={multipleListenersEnabled} licenseMode={status.license.mode} maximumListeners={status.license.maximumListeners} onOpenSetup={() => setSection('printer')} onChanged={onListenersChanged} /></Suspense>}
            {section === 'profiles' && features.printerProfiles && <PrinterProfilesSettings />}
            {section === 'logos' && features.storedLogos && <StoredGraphicsSettings graphics={storedGraphics} onChanged={onStoredGraphicsChanged} />}
            {section === 'state' && features.printerState && <PrinterStateSettings listeners={listeners} multipleListeners={multipleListenersEnabled} />}
            {section === 'backup' && <BackupRestoreSettings license={status.license} preferences={preferences} onRestored={onPreferencesRestored} />}
            {section === 'updates' && features.updates && <UpdatesSettings status={status} updateStatus={updateStatus} onCheckUpdates={onCheckUpdates} />}
            {section === 'support' && <SupportSettings status={status} onOpenPrinterWizard={() => setSection('printer')} />}
          </div>
        </div>
      </section>
    </div>
  )
}

function LicenseSettings({ status, onActivated }: {
  status: ServiceStatus
  onActivated: (license: ServiceStatus['license']) => void
}) {
  const [customerName, setCustomerName] = useState(status.license.customerName)
  const [emailAddress, setEmailAddress] = useState(status.license.emailAddress)
  const [activationKey, setActivationKey] = useState('')
  const [busy, setBusy] = useState(false)
  const [message, setMessage] = useState<string>()
  const [changingLicense, setChangingLicense] = useState(!status.license.isPaid)
  const [maintenanceKey, setMaintenanceKey] = useState('')
  const [maintenanceBusy, setMaintenanceBusy] = useState(false)
  const [maintenanceRefreshBusy, setMaintenanceRefreshBusy] = useState(false)
  const [maintenanceMessage, setMaintenanceMessage] = useState<string>()
  const [maintenanceMessageKind, setMaintenanceMessageKind] = useState<'success' | 'info' | 'error'>('info')
  const [promotionOffer, setPromotionOffer] = useState<PromotionOfferStatus>()
  const [promotionOfferLoading, setPromotionOfferLoading] = useState(false)
  const [selectedPromotionTier, setSelectedPromotionTier] = useState<'Lite' | 'Pro' | 'Enterprise'>()
  const [promotionBusy, setPromotionBusy] = useState(false)
  const [promotionMessage, setPromotionMessage] = useState<string>()
  const [countdownNow, setCountdownNow] = useState(Date.now())
  const showActivationForm = !status.license.isPaid || changingLicense
  const upgradeGuidance = status.license.mode === 'Lite'
    ? 'Upgrade to Pro for up to 2 printer listeners, or Enterprise for up to 15.'
    : status.license.mode === 'Pro'
      ? 'Upgrade to Enterprise for up to 15 printer listeners.'
      : status.license.mode === 'Enterprise'
        ? 'Enter a replacement Enterprise key whenever this installation is reissued.'
        : 'Lite unlocks all paid features with one listener; Pro supports 2 and Enterprise supports up to 15.'

  useEffect(() => {
    if (status.license.promotion.isActive || !status.license.promotion.isApplicable) return
    let cancelled = false
    setPromotionOfferLoading(true)
    api.promotionOffer()
      .then(offer => {
        if (cancelled) return
        setPromotionOffer(offer)
        setSelectedPromotionTier(current =>
          current && offer.eligibleTiers.includes(current) ? current : offer.eligibleTiers[0])
      })
      .catch(cause => {
        if (!cancelled) setPromotionMessage(cause instanceof Error ? cause.message : 'Promotional-trial eligibility could not be checked.')
      })
      .finally(() => {
        if (!cancelled) setPromotionOfferLoading(false)
      })
    return () => { cancelled = true }
  }, [status.license.promotion.isActive, status.license.promotion.isApplicable, status.license.promotion.state])

  useEffect(() => {
    if (!status.license.promotion.isActive) return
    const timer = window.setInterval(() => setCountdownNow(Date.now()), 60_000)
    return () => window.clearInterval(timer)
  }, [status.license.promotion.isActive])

  async function activate(event: FormEvent) {
    event.preventDefault()
    setBusy(true)
    setMessage(undefined)
    try {
      const license = await api.activate({ customerName, emailAddress, activationKey })
      setActivationKey('')
      setChangingLicense(false)
      onActivated(license)
    } catch (cause) {
      setMessage(cause instanceof Error ? cause.message : 'The activation key could not be validated.')
    } finally {
      setBusy(false)
    }
  }

  async function applyMaintenance(event: FormEvent) {
    event.preventDefault()
    setMaintenanceBusy(true)
    setMaintenanceMessage(undefined)
    try {
      const license = await api.applyMaintenance({ entitlementToken: maintenanceKey })
      setMaintenanceKey('')
      setMaintenanceMessage('Maintenance coverage was updated successfully.')
      setMaintenanceMessageKind('success')
      onActivated(license)
    } catch (cause) {
      setMaintenanceMessage(cause instanceof Error ? cause.message : 'The maintenance renewal key could not be validated.')
      setMaintenanceMessageKind('error')
    } finally {
      setMaintenanceBusy(false)
    }
  }

  async function refreshMaintenance() {
    setMaintenanceRefreshBusy(true)
    setMaintenanceMessage(undefined)
    try {
      const result = await api.refreshMaintenance()
      setMaintenanceMessage(result.message)
      setMaintenanceMessageKind(result.license.maintenance.isActive ? 'success' : 'info')
      onActivated(result.license)
    } catch (cause) {
      setMaintenanceMessage(cause instanceof Error ? cause.message : 'Maintenance status could not be refreshed.')
      setMaintenanceMessageKind('error')
    } finally {
      setMaintenanceRefreshBusy(false)
    }
  }

  async function startPromotion() {
    if (!selectedPromotionTier) return
    setPromotionBusy(true)
    setPromotionMessage(undefined)
    try {
      const result = await api.startPromotion(selectedPromotionTier)
      setPromotionMessage(result.message)
      onActivated(result.license)
    } catch (cause) {
      setPromotionMessage(cause instanceof Error ? cause.message : 'The Five-Day Promotional Trial could not be started.')
    } finally {
      setPromotionBusy(false)
    }
  }

  return (
    <div className="settings-panel license-settings">
      <div className={`license-hero ${status.license.isPaid ? 'is-paid' : ''} ${status.license.isEnterprise ? 'is-enterprise' : ''}`}>
        <div className="license-hero-icon">{status.license.isPaid ? <CheckCircle2 size={27} /> : <KeyRound size={27} />}</div>
        <div>
          <h2>{status.license.promotion.isActive
            ? `${status.license.promotion.grantedTier} Five-Day Trial`
            : status.license.isPaid ? `${status.license.mode} License activated` : 'Trial License'}</h2>
          <p>{status.license.promotion.isActive
            ? `Temporary ${status.license.promotion.grantedTier} access is active. ${status.license.promotion.previousTier === 'Trial' ? 'Your normal Trial access' : `Your permanent ${status.license.promotion.previousTier} License`} will return automatically at expiration.`
            : status.license.isPaid
            ? `${status.license.mode} features are unlocked, including unlimited receipt jobs, saved history, and exports.`
            : `${status.license.remaining} of ${status.license.dailyLimit} complete Trial POS print jobs remain today. Built-in Test Receipts are unlimited.`}</p>
        </div>
      </div>

      <div className="license-summary">
        <div><span>Status</span><strong>{status.license.promotion.isActive ? `Five-Day Trial Active · ${status.license.promotion.grantedTier}` : status.license.isPaid ? `Activated · ${status.license.mode} License` : 'Trial License'}</strong></div>
        <div><span>Permanent activation key</span><strong>{status.license.promotion.isActive && status.license.promotion.previousTier === 'Trial' ? 'No permanent key installed' : status.license.isPaid ? 'Validated and stored securely' : 'No activation key installed'}</strong></div>
        <div><span>Printer listeners</span><strong>Up to {status.license.maximumListeners}</strong></div>
        {status.license.licenseId && <div><span>License ID</span><strong>{status.license.licenseId}</strong></div>}
      </div>

      {(status.license.promotion.isApplicable || status.license.promotion.state !== 'None') && (
        <section className={`maintenance-card promotion-card ${status.license.promotion.isActive ? 'is-active' : status.license.promotion.state === 'Expired' ? 'is-expired' : ''}`}>
          <div className="maintenance-heading">
            <div><FlaskConical size={18} /><strong>Five-Day Promotional Trial</strong></div>
            <span>{status.license.promotion.isActive ? 'Five-Day Trial Active' : promotionOffer?.state ?? status.license.promotion.state}</span>
          </div>
          <p>{status.license.promotion.isActive
            ? `You are evaluating the ${status.license.promotion.grantedTier} edition. Every included feature is available until the time shown below.`
            : promotionOffer?.message ?? status.license.promotion.message}</p>
          {status.license.promotion.expiresAt && (
            <dl>
              <div><dt>Edition being evaluated</dt><dd>{status.license.promotion.grantedTier}</dd></div>
              <div><dt>Started</dt><dd>{formatTrialDateTime(status.license.promotion.startsAt)}</dd></div>
              <div><dt>Expires</dt><dd>{formatTrialDateTime(status.license.promotion.expiresAt)}</dd></div>
              <div><dt>Time remaining</dt><dd>{formatTrialRemaining(status.license.promotion.expiresAt, countdownNow)}</dd></div>
            </dl>
          )}
          {!status.license.promotion.isActive && promotionOffer?.state === 'Eligible' && (
            <>
              <div className="promotion-tier-grid" role="radiogroup" aria-label="Edition to evaluate">
                {promotionOffer.eligibleTiers.map(tier => (
                  <button key={tier} type="button" role="radio" aria-checked={selectedPromotionTier === tier}
                    className={selectedPromotionTier === tier ? 'selected' : ''} onClick={() => setSelectedPromotionTier(tier)}>
                    <strong>{tier}</strong>
                    <span>{tier === 'Lite' ? 'All paid features · 1 listener' : tier === 'Pro' ? 'All paid features · 2 listeners' : 'All features · up to 15 listeners'}</span>
                  </button>
                ))}
              </div>
              <div className="promotion-action-row">
                <button className="promotion-primary" type="button" disabled={promotionBusy || !selectedPromotionTier} onClick={startPromotion}>
                  <FlaskConical size={16} /> {promotionBusy ? 'Starting securely…' : `Start Five-Day ${selectedPromotionTier ?? ''} Trial`}
                </button>
                <small>No key entry is required. Eligibility and expiration are verified securely by the licensing server.</small>
              </div>
            </>
          )}
          {promotionOfferLoading && <div className="maintenance-message"><RefreshCw className="spin" size={14} /> Checking trial eligibility…</div>}
          {promotionMessage && <div className="maintenance-message" role="status">{promotionMessage}</div>}
          {(status.license.promotion.isActive || promotionOffer?.state === 'Used') && (
            <div className="maintenance-actions">
              <a href={promotionOffer?.purchaseUrl ?? `https://buy.posprinteremulator.com/?tier=${status.license.promotion.grantedTier ?? ''}`} target="_blank" rel="noreferrer">
                <ExternalLink size={15} /> Purchase {status.license.promotion.grantedTier ?? 'a license edition'}
              </a>
            </div>
          )}
          {promotionOffer?.state === 'VerificationRequired' && promotionOffer.verificationUrl && (
            <div className="maintenance-actions">
              <a href={promotionOffer.verificationUrl} target="_blank" rel="noreferrer"><ExternalLink size={15} /> Verify customer email</a>
            </div>
          )}
        </section>
      )}

      {status.license.isPaid && (
        <section className={`maintenance-card ${status.license.maintenance.isActive ? 'is-active' : 'is-expired'}`}>
          <div className="maintenance-heading">
            <div><RefreshCw size={18} /><strong>Application Maintenance and Support</strong></div>
            <span>{status.license.maintenance.isActive ? 'Active' : status.license.maintenance.state}</span>
          </div>
          <p>{status.license.maintenance.message}</p>
          <dl>
            <div><dt>Coverage ends</dt><dd>{formatMaintenanceDate(status.license.maintenance.expiresAt)}</dd></div>
            <div><dt>Permanent license</dt><dd>{status.license.mode} features remain available</dd></div>
          </dl>
          {status.license.maintenance.isGrandfathered && <small>Grandfathered coverage for licenses issued before v0.3.26.</small>}
          <div className="maintenance-actions">
            <button type="button" onClick={refreshMaintenance} disabled={maintenanceRefreshBusy}><RefreshCw className={maintenanceRefreshBusy ? 'spin' : ''} size={15} /> {maintenanceRefreshBusy ? 'Refreshing…' : 'Refresh maintenance status'}</button>
            {status.license.maintenance.renewalUrl && <a href={status.license.maintenance.renewalUrl} target="_blank" rel="noreferrer"><ExternalLink size={15} /> Renew optional annual maintenance</a>}
          </div>
          {maintenanceMessage && <div className={maintenanceMessageKind === 'success' ? 'maintenance-success' : maintenanceMessageKind === 'error' ? 'maintenance-error' : 'maintenance-message'} role={maintenanceMessageKind === 'error' ? 'alert' : 'status'}>{maintenanceMessage}</div>}
          <details className="maintenance-key-panel">
            <summary>Apply a maintenance renewal key</summary>
            <form onSubmit={applyMaintenance}>
              <label>Renewal key<textarea required rows={3} value={maintenanceKey} onChange={event => setMaintenanceKey(event.target.value)} placeholder="PPEM1-…" spellCheck={false} /></label>
              <button className="secondary-action" type="submit" disabled={maintenanceBusy}><KeyRound size={16} /> {maintenanceBusy ? 'Applying…' : 'Apply renewal key'}</button>
            </form>
          </details>
        </section>
      )}

      {status.license.isPaid ? (
        <div className="registered-details">
          <div><span>Registered to</span><strong>{status.license.customerName}</strong></div>
          <div><span>Email</span><strong>{status.license.emailAddress}</strong></div>
          <p className="settings-note">{upgradeGuidance}</p>
          {!showActivationForm && <button className="secondary-action" type="button" onClick={() => setChangingLicense(true)}><KeyRound size={16} /> Change or upgrade license</button>}
          <p className="settings-note">Your activation key is never included in support diagnostics.</p>
        </div>
      ) : null}

      {showActivationForm ? (
        <details className="permanent-activation-disclosure" open={status.license.isPaid}>
          <summary>{status.license.isPaid ? 'Change or upgrade the permanent license' : 'Already purchased? Activate a permanent license'}</summary>
          <p>The permanent-license form is separate from the Five-Day Promotional Trial. Starting an evaluation never requires an activation key.</p>
          <form className="activation-form" onSubmit={activate}>
            <label>Customer or company name<input required value={customerName} onChange={event => setCustomerName(event.target.value)} autoComplete="organization" /></label>
            <label>Email address<input required type="email" value={emailAddress} onChange={event => setEmailAddress(event.target.value)} autoComplete="email" /></label>
            <label className="key-field">Purchased activation key<textarea required rows={4} value={activationKey} onChange={event => setActivationKey(event.target.value)} placeholder="PPE1-…" spellCheck={false} /></label>
            {message && <div className="activation-error" role="alert"><AlertTriangle size={16} />{message}</div>}
            {message && <a className="download-diagnostics activation-diagnostics" href="/api/support/activation-diagnostics" download><Download size={17} /> Download Activation Diagnostics</a>}
            <div className="settings-actions">
              <button className="activate-button" type="submit" disabled={busy}><KeyRound size={17} /> {busy ? 'Validating…' : status.license.isPaid ? 'Validate replacement key' : 'Validate and activate purchased license'}</button>
              {status.license.isPaid && <button type="button" disabled={busy} onClick={() => { setChangingLicense(false); setActivationKey(''); setMessage(undefined) }}>Cancel</button>}
            </div>
            <p className="activation-note">A purchased Lite, Pro, or Enterprise activation key unlocks its permanent license level immediately without reinstalling. Any replacement key must match the customer information entered above.</p>
          </form>
        </details>
      ) : null}
    </div>
  )
}

function UpdatesSettings({ status, updateStatus, onCheckUpdates }: {
  status: ServiceStatus
  updateStatus?: UpdateStatus
  onCheckUpdates: (force?: boolean) => Promise<UpdateStatus>
}) {
  const [checking, setChecking] = useState(false)
  const [result, setResult] = useState(updateStatus)
  const [installState, setInstallState] = useState<{ state: string; message: string; percent?: number }>()

  useEffect(() => {
    const webview = (window as Window & { chrome?: { webview?: { addEventListener: (name: string, handler: (event: { data: unknown }) => void) => void; removeEventListener: (name: string, handler: (event: { data: unknown }) => void) => void } } }).chrome?.webview
    if (!webview) return
    const handler = (event: { data: unknown }) => {
      const message = event.data as { type?: string; state?: string; message?: string; percent?: number }
      if (message?.type === 'update-state' && message.state && message.message) setInstallState({ state: message.state, message: message.message, percent: message.percent })
    }
    webview.addEventListener('message', handler)
    return () => webview.removeEventListener('message', handler)
  }, [])

  async function checkNow() {
    setChecking(true)
    try {
      setResult(await onCheckUpdates(true))
    } finally {
      setChecking(false)
    }
  }

  const available = result?.updateAvailable === true
  return (
    <div className="settings-panel update-settings">
      <div className={`settings-status-card ${available ? 'update-available' : result?.checkSucceeded ? 'is-current' : ''}`}>
        <div className="settings-status-icon">{available ? <Download size={25} /> : <RefreshCw size={25} />}</div>
        <div>
          <h2>{available ? `Version ${result.latestVersion} is available` : 'Application updates'}</h2>
          <p>{result?.message ?? 'Check for the latest POS Printer Emulator release.'}</p>
        </div>
      </div>
      <dl className="update-details">
        <div><dt>Installed version</dt><dd>{status.version}</dd></div>
        <div><dt>Latest version</dt><dd>{result?.latestVersion ?? 'Not checked'}</dd></div>
        <div><dt>Last checked</dt><dd>{result ? new Date(result.checkedAt).toLocaleString() : 'Never'}</dd></div>
        <div><dt>Automatic checks</dt><dd>Enabled · every 4 hours</dd></div>
      </dl>
      <div className="settings-actions">
        <button className="secondary-action" onClick={checkNow} disabled={checking}><RefreshCw size={16} className={checking ? 'spin' : ''} /> {checking ? 'Checking…' : 'Check now'}</button>
        {available && result?.downloadUrl && result?.checksumUrl && (
          <button className="primary-action" disabled={installState?.state === 'downloading' || installState?.state === 'preparing'} onClick={() => launchUpdate(result.downloadUrl!, result.checksumUrl!, result.latestVersion!)}><Download size={16} /> {installState?.state === 'downloading' ? `Downloading ${installState.percent ?? 0}%…` : installState?.state === 'preparing' ? 'Preparing…' : 'Download and install'}</button>
        )}
        {available && result?.releaseUrl && <a href={result.releaseUrl} target="_blank" rel="noreferrer"><ExternalLink size={15} /> Release details</a>}
      </div>
      {installState && <div className={`diagnostic-message update-${installState.state}`} role="status">{installState.message}</div>}
      <p className="settings-note">Automatic checks use the official POS Printer Emulator GitHub Releases feed. The installer and its security checksum are downloaded first. The application closes only after you confirm, then restarts automatically when setup finishes.</p>
    </div>
  )
}

function SupportSettings({ status, onOpenPrinterWizard }: { status: ServiceStatus; onOpenPrinterWizard: () => void }) {
  const maintenance = status.license.maintenance
  const assistedSupport = maintenance.isActive
  const [showForm, setShowForm] = useState(false)
  const [request, setRequest] = useState<SupportRequestInput>({
    requestType: 'Bug Report', subject: '', description: '', stepsToReproduce: '', expectedBehavior: '', actualBehavior: '',
    contactName: status.license.customerName ?? '', contactEmail: status.license.emailAddress ?? '', includeDiagnostics: false, consentToSubmit: false, attachments: [],
  })
  const [preview, setPreview] = useState<SupportRequestPreview>()
  const [result, setResult] = useState<SupportRequestResult>()
  const [drafts, setDrafts] = useState<SupportRequestDraftSummary[]>([])
  const [message, setMessage] = useState<string>()
  const [busy, setBusy] = useState(false)
  const [diagnostics, setDiagnostics] = useState<ConnectionDiagnosticReport>()
  const [packagePreview, setPackagePreview] = useState<SupportPackagePreview>()
  const [diagnosticsBusy, setDiagnosticsBusy] = useState(false)
  const [diagnosticMessage, setDiagnosticMessage] = useState<string>()

  useEffect(() => { api.supportRequestDrafts().then(setDrafts).catch(() => undefined) }, [])

  async function reviewRequest(event: FormEvent) {
    event.preventDefault(); setBusy(true); setMessage(undefined)
    try { setPreview(await api.previewSupportRequest({ ...request, consentToSubmit: false })) }
    catch (error) { setMessage(error instanceof Error ? error.message : 'The support request could not be reviewed.') }
    finally { setBusy(false) }
  }

  async function submitRequest() {
    setBusy(true); setMessage(undefined)
    try {
      const submitted = await api.submitSupportRequest({ ...request, consentToSubmit: true })
      setResult(submitted); setPreview(undefined); setShowForm(false)
      setDrafts(await api.supportRequestDrafts())
    } catch (error) { setMessage(error instanceof Error ? error.message : 'The support request could not be submitted.') }
    finally { setBusy(false) }
  }

  function update<K extends keyof SupportRequestInput>(key: K, value: SupportRequestInput[K]) {
    setRequest(current => ({ ...current, [key]: value, consentToSubmit: false })); setPreview(undefined); setResult(undefined)
  }

  async function retryDraft(reference: string) {
    setBusy(true); setMessage(undefined)
    try { setResult(await api.retrySupportRequest(reference)); setDrafts(await api.supportRequestDrafts()) }
    catch (error) { setMessage(error instanceof Error ? error.message : 'The saved request could not be retried.') }
    finally { setBusy(false) }
  }

  async function deleteDraft(reference: string) {
    await api.deleteSupportRequestDraft(reference); setDrafts(await api.supportRequestDrafts())
  }

  async function runDiagnostics() {
    setDiagnosticsBusy(true); setDiagnosticMessage(undefined)
    try {
      const response = await api.runConnectionDiagnostics()
      setDiagnostics(response.report); setPackagePreview(response.packagePreview)
    } catch (error) { setDiagnosticMessage(error instanceof Error ? error.message : 'Connection diagnostics could not run.') }
    finally { setDiagnosticsBusy(false) }
  }

  async function runDiagnosticAction(check: ConnectionDiagnosticCheck) {
    if (check.action === 'OpenPrinterSetupWizard') { onOpenPrinterWizard(); return }
    if (check.action === 'RepairInstallation') { setDiagnosticMessage('Run the latest POS Printer Emulator installer and choose repair. Your registration and license are preserved.'); return }
    if (check.action === 'RepairFirewall') {
      const desktop = (window as Window & { chrome?: { webview?: { postMessage: (message: unknown) => void } } }).chrome?.webview
      if (!desktop) { setDiagnosticMessage('Firewall repair is available in the installed Windows desktop application.'); return }
      if (!window.confirm('Windows will ask for administrator approval to recreate the private/domain POS Printer Emulator firewall rule. Continue?')) return
      desktop.postMessage({ type: 'repair-firewall' }); setDiagnosticMessage('Waiting for Windows firewall repair approval…'); return
    }
    if (check.action === 'RestartListener' && check.listenerId) {
      setDiagnosticsBusy(true); setDiagnosticMessage(undefined)
      try { await api.restartDiagnosticListener(check.listenerId); await runDiagnostics() }
      catch (error) { setDiagnosticMessage(error instanceof Error ? error.message : 'The printer listener could not be restarted.') }
      finally { setDiagnosticsBusy(false) }
    }
  }

  async function copySupportSummary() {
    if (!diagnostics) return
    const summary = [`POS Printer Emulator ${diagnostics.applicationVersion}`, `Package: ${diagnostics.packageId}`, `Results: ${diagnostics.passed} passed, ${diagnostics.attentionNeeded} attention, ${diagnostics.failed} failed, ${diagnostics.skipped} skipped`, '', ...diagnostics.checks.map(check => `[${diagnosticStatusLabel(check.status)}] ${check.title}: ${check.summary}`)].join('\n')
    await navigator.clipboard.writeText(summary); setDiagnosticMessage('The support summary was copied.')
  }

  async function addAttachments(event: ChangeEvent<HTMLInputElement>) {
    const selected = Array.from(event.target.files ?? [])
    event.target.value = ''
    if (request.attachments.length + selected.length > 3) { setMessage('Attach no more than three files.'); return }
    if (selected.some(file => !['png', 'jpg', 'jpeg', 'txt', 'log', 'zip'].includes(file.name.split('.').pop()?.toLowerCase() ?? ''))) { setMessage('Attachments must be PNG, JPEG, TXT, LOG, or ZIP files.'); return }
    if (selected.some(file => file.size <= 0 || file.size > 5 * 1024 * 1024)) { setMessage('Each attachment must be between 1 byte and 5 MB.'); return }
    if (request.attachments.reduce((total, file) => total + Math.ceil(file.contentBase64.length * .75), 0) + selected.reduce((total, file) => total + file.size, 0) > 10 * 1024 * 1024) { setMessage('Attachments may total no more than 10 MB.'); return }
    setBusy(true); setMessage(undefined)
    try {
      const attachments = await Promise.all(selected.map(async file => ({
        fileName: file.name,
        contentType: supportAttachmentContentType(file),
        contentBase64: await fileToBase64(file),
      })))
      update('attachments', [...request.attachments, ...attachments])
    } catch { setMessage('One or more attachments could not be read.') }
    finally { setBusy(false) }
  }

  return (
    <div className="settings-panel support-settings">
      <div className={`settings-status-card ${assistedSupport ? 'is-current' : ''}`}>
        <div className="settings-status-icon"><LifeBuoy size={25} /></div>
        <div><h2>{assistedSupport ? 'Technical support is available' : 'Local support tools'}</h2><p>{assistedSupport ? `Assisted support is included through ${formatMaintenanceDate(maintenance.expiresAt)}.` : status.license.isPaid ? 'Assisted support requires renewed maintenance. Diagnostic export remains available.' : 'Diagnostic export is available locally. Assisted support is included with a paid license and active maintenance.'}</p></div>
      </div>
      <div className="support-detail-grid">
        <div><span>Application</span><strong>POS Printer Emulator {status.version}</strong></div>
        <div><span>Listener</span><strong>{status.listener}</strong></div>
        <div><span>Service</span><strong>{status.listening ? 'Running' : 'Stopped'}</strong></div>
        <div><span>License</span><strong>{status.license.mode} License</strong></div>
        <div><span>Maintenance</span><strong>{maintenance.state}{maintenance.expiresAt ? ` · ${formatMaintenanceDate(maintenance.expiresAt)}` : ''}</strong></div>
      </div>
      <section className="connection-diagnostics">
        <header><div><span>Local, privacy-safe checks</span><h3>Connection Diagnostics</h3><p>Checks this emulator, Windows printing, listeners, ports, drivers, and firewall configuration. It does not test or infer your POS software.</p></div><button className="primary-action" type="button" disabled={diagnosticsBusy} onClick={runDiagnostics}><RefreshCw size={16} className={diagnosticsBusy ? 'spin' : ''} /> {diagnosticsBusy ? 'Running checks…' : diagnostics ? 'Run again' : 'Run diagnostics'}</button></header>
        {diagnosticMessage && <div className="diagnostic-message" role="status">{diagnosticMessage}</div>}
        {diagnostics && <>
          <div className="diagnostic-metrics"><div className="passed"><strong>{diagnostics.passed}</strong><span>Passed</span></div><div className="attention"><strong>{diagnostics.attentionNeeded}</strong><span>Attention needed</span></div><div className="failed"><strong>{diagnostics.failed}</strong><span>Failed</span></div><div className="skipped"><strong>{diagnostics.skipped}</strong><span>Skipped</span></div></div>
          <div className="connection-guidance"><h4>Connection details for your POS software</h4>{diagnostics.connectionDetails.map(connection => <article key={connection.listenerId}><div><strong>{connection.printerName}</strong><span>{connection.localOnly ? 'POS software on this computer only' : 'Private/domain network connection'}</span></div><code>{connection.ipAddress}:{connection.port}</code><button type="button" onClick={() => navigator.clipboard.writeText(`${connection.ipAddress}:${connection.port}`)}>Copy</button></article>)}</div>
          <div className="diagnostic-checks">{diagnostics.checks.map(check => <article className={`diagnostic-check ${check.status.toLowerCase()}`} key={check.id}><span className="diagnostic-state">{diagnosticStatusLabel(check.status)}</span><div><strong>{check.title}</strong><p>{check.summary}</p>{check.technicalDetails && <details><summary>Technical details</summary><pre>{check.technicalDetails}</pre></details>}</div>{check.action && <button type="button" disabled={diagnosticsBusy} onClick={() => runDiagnosticAction(check)}>{diagnosticActionLabel(check.action)}</button>}</article>)}</div>
          <div className="support-package-actions"><button type="button" onClick={copySupportSummary}>Copy Support Summary</button><a className="primary-action" href={`/api/support/connection-diagnostics/package/${encodeURIComponent(diagnostics.packageId)}`} download><Download size={16} /> Save Support Package</a><a href="/api/support/diagnostics" download>Download legacy text log</a></div>
          {packagePreview && <details className="support-package-preview"><summary>Preview Support Package contents</summary><p><strong>Package ID:</strong> {packagePreview.packageId}</p><h4>Files</h4><ul>{packagePreview.files.map(file => <li key={file.fileName}><code>{file.fileName}</code><span>{file.description}</span></li>)}</ul><h4>Excluded by default</h4><p>{packagePreview.excludedCategories.join(', ')}.</p></details>}
        </>}
      </section>
      <div className="settings-actions support-actions">
        <button className="primary-action" type="button" disabled={!assistedSupport} title={!assistedSupport ? 'Requires active Application Maintenance and Support' : undefined} onClick={() => setShowForm(value => !value)}><LifeBuoy size={16} /> Submit a Support Request</button>
        {!assistedSupport && maintenance.renewalUrl && <a className="primary-action" href={maintenance.renewalUrl} target="_blank" rel="noreferrer"><ExternalLink size={15} /> Renew maintenance</a>}
      </div>
      <div className="privacy-callout"><LockKeyhole size={17} /><p>The report includes application events, version, service status, and basic system details. It does not include receipt contents or activation keys.</p></div>
      {showForm && <form className="support-request-form" onSubmit={reviewRequest}>
        <div className="support-request-heading"><div><span>Private submission</span><h3>Support request details</h3></div><button type="button" onClick={() => setShowForm(false)} aria-label="Close support request"><X size={16} /></button></div>
        <label>Request type<select value={request.requestType} onChange={event => update('requestType', event.target.value as SupportRequestInput['requestType'])}><option>Bug Report</option><option>Feature Request</option><option>License Issue</option><option>Other Issue</option></select></label>
        <label className="wide">Subject<input required maxLength={160} value={request.subject} onChange={event => update('subject', event.target.value)} /></label>
        <label className="wide">Detailed description<textarea required rows={4} maxLength={8000} value={request.description} onChange={event => update('description', event.target.value)} /></label>
        {request.requestType === 'Bug Report' && <>
          <label className="wide">Steps to reproduce<textarea rows={3} value={request.stepsToReproduce} onChange={event => update('stepsToReproduce', event.target.value)} /></label>
          <label>Expected behavior<textarea rows={3} value={request.expectedBehavior} onChange={event => update('expectedBehavior', event.target.value)} /></label>
          <label>Actual behavior<textarea rows={3} value={request.actualBehavior} onChange={event => update('actualBehavior', event.target.value)} /></label>
        </>}
        <label>Contact name<input required maxLength={160} value={request.contactName} onChange={event => update('contactName', event.target.value)} /></label>
        <label>Email address<input required type="email" maxLength={254} value={request.contactEmail} onChange={event => update('contactEmail', event.target.value)} /></label>
        <label className="support-attachments wide">Optional screenshots or attachments<input type="file" multiple accept=".png,.jpg,.jpeg,.txt,.log,.zip,image/png,image/jpeg,text/plain,application/zip" onChange={addAttachments} disabled={busy || request.attachments.length >= 3} /><small>PNG, JPEG, TXT, LOG, or ZIP. Up to 3 files, 5 MB each, 10 MB total.</small></label>
        {request.attachments.length > 0 && <div className="support-attachment-list wide">{request.attachments.map((attachment, index) => <div key={`${attachment.fileName}-${index}`}><FileText size={14} /><span>{attachment.fileName}</span><button type="button" onClick={() => update('attachments', request.attachments.filter((_, candidate) => candidate !== index))}><X size={13} /> Remove</button></div>)}</div>}
        <label className="support-consent wide"><input type="checkbox" checked={request.includeDiagnostics} onChange={event => update('includeDiagnostics', event.target.checked)} /><span>Include redacted diagnostic logs for this request. You will see the included categories before submission.</span></label>
        {message && <div className="support-request-message" role="alert">{message}</div>}
        <div className="support-form-actions wide"><button type="button" onClick={() => setShowForm(false)}>Cancel</button><button className="primary-action" type="submit" disabled={busy}>{busy ? 'Preparing…' : 'Review before submitting'}</button></div>
      </form>}
      {preview && <section className="support-request-preview">
        <h3>Review what will be sent</h3>
        <dl><div><dt>Application</dt><dd>{preview.applicationVersion}</dd></div><div><dt>Windows</dt><dd>{preview.windowsVersion}</dd></div><div><dt>Listener summary</dt><dd>{preview.listenerSummary}</dd></div><div><dt>Diagnostic logs</dt><dd>{preview.diagnosticsIncluded ? 'Included after redaction' : 'Not included'}</dd></div></dl>
        {preview.attachments.length > 0 && <div className="support-preview-attachments"><strong>Private attachments</strong>{preview.attachments.map(file => <span key={file.fileName}>{file.fileName} · {formatBytes(file.size)}</span>)}<small>Attachments are retained privately for support staff and are not posted to the public GitHub issue. Remove any sensitive content you do not want to send.</small></div>}
        <p><strong>Removed or masked:</strong> {preview.removedByRedaction.join(', ')}.</p>
        <label className="support-consent"><input type="checkbox" checked={request.consentToSubmit} onChange={event => setRequest(current => ({ ...current, consentToSubmit: event.target.checked }))} /><span>I reviewed this information and consent to send it securely to POS Printer Emulator support.</span></label>
        <div className="support-form-actions"><button type="button" onClick={() => setPreview(undefined)}>Back</button><button className="primary-action" type="button" disabled={busy || !request.consentToSubmit} onClick={submitRequest}>{busy ? 'Submitting…' : 'Submit Support Request'}</button></div>
      </section>}
      {result && <div className={`support-request-result ${result.state === 'Submitted' ? 'success' : 'queued'}`}><CheckCircle2 size={18} /><div><strong>{result.state === 'Submitted' ? 'Support request submitted' : 'Support request saved for retry'}</strong><p>{result.message}</p><code>{result.issueNumber ? `GitHub issue #${result.issueNumber}` : result.reference}</code><div>{result.issueUrl && <a href={result.issueUrl} target="_blank" rel="noreferrer">View issue <ExternalLink size={13} /></a>}<button type="button" onClick={() => navigator.clipboard.writeText(result.reference)}>Copy reference</button></div></div></div>}
      {drafts.length > 0 && <section className="support-drafts"><h3>Saved support requests</h3>{drafts.map(draft => <article key={draft.reference}><div><strong>{draft.subject}</strong><span>{draft.requestType} · {draft.reference}</span></div><button type="button" disabled={busy} onClick={() => retryDraft(draft.reference)}><RefreshCw size={14} /> Retry</button><button type="button" onClick={() => deleteDraft(draft.reference)}><Trash2 size={14} /> Delete</button></article>)}</section>}
    </div>
  )
}

function diagnosticStatusLabel(status: ConnectionDiagnosticCheck['status']) {
  return status === 'AttentionNeeded' ? 'Attention needed' : status
}

function diagnosticActionLabel(action: NonNullable<ConnectionDiagnosticCheck['action']>) {
  if (action === 'RestartListener') return 'Restart listener'
  if (action === 'RepairFirewall') return 'Repair firewall'
  if (action === 'OpenPrinterSetupWizard') return 'Open Printer Setup Wizard'
  return 'Repair installation'
}

function supportAttachmentContentType(file: File) {
  const extension = file.name.split('.').pop()?.toLowerCase()
  if (extension === 'png') return 'image/png'
  if (extension === 'jpg' || extension === 'jpeg') return 'image/jpeg'
  if (extension === 'zip') return 'application/zip'
  return 'text/plain'
}

function fileToBase64(file: File): Promise<string> {
  return new Promise((resolve, reject) => {
    const reader = new FileReader()
    reader.onerror = () => reject(reader.error)
    reader.onload = () => {
      const value = String(reader.result ?? '')
      resolve(value.slice(value.indexOf(',') + 1))
    }
    reader.readAsDataURL(file)
  })
}

function launchUpdate(url: string, checksumUrl: string, version: string) {
  const desktop = (window as Window & { chrome?: { webview?: { postMessage: (message: unknown) => void } } }).chrome?.webview
  if (desktop) {
    desktop.postMessage({ type: 'install-update', url, checksumUrl, version })
  } else {
    window.open(url, '_blank', 'noopener,noreferrer')
  }
}

type BarcodeFormat = 'UPC' | 'EAN13' | 'EAN8' | 'CODE39' | 'ITF' | 'codabar' | 'CODE128'
type BarcodeData = { label: string; format: BarcodeFormat; width: number; height: number; hri: number }

function barcodeFormat(mode: number): BarcodeFormat {
  if (mode === 0 || mode === 1 || mode === 65 || mode === 66) return 'UPC'
  if (mode === 2 || mode === 67) return 'EAN13'
  if (mode === 3 || mode === 68) return 'EAN8'
  if (mode === 4 || mode === 69) return 'CODE39'
  if (mode === 5 || mode === 70) return 'ITF'
  if (mode === 6 || mode === 71) return 'codabar'
  return 'CODE128'
}

function decodeBarcode(data: string): BarcodeData {
  try {
    const parts = data.split(':')
    if (parts.length === 6 && parts[0] === 'barcode-v1') {
      const mode = Number(parts[1])
      const format = barcodeFormat(mode)
      const decoded = atob(parts[5])
      return {
        label: format === 'CODE39' ? decoded.replace(/^\*|\*$/g, '') : decoded,
        format,
        width: Math.min(6, Math.max(2, Number(parts[2]) || 2)),
        height: Math.min(110, Math.max(24, Math.round((Number(parts[3]) || 162) * 0.5))),
        hri: Math.min(3, Math.max(0, Number(parts[4]) || 0)),
      }
    }
  } catch { /* Preserve compatibility with older saved receipts. */ }
  return { label: data.replace(/^\*|\*$/g, ''), format: 'CODE39', width: 2, height: 55, hri: 2 }
}

function BarcodePreview({ data }: { data: string }) {
  const barcode = decodeBarcode(data)
  const showText = barcode.hri !== 0
  return (
    <div className={`barcode-block hri-${barcode.hri}`}>
      <BarcodeRenderer value={barcode.label || 'RECEIPT'} format={barcode.format} width={barcode.width} height={barcode.height} displayValue={showText} textPosition={barcode.hri === 1 ? 'top' : 'bottom'} margin={0} background="#fff" lineColor="#111" />
    </div>
  )
}

function QrCode({ data }: { data: string }) {
  let label = data
  let moduleSize = 3
  let level: 'L' | 'M' | 'Q' | 'H' = 'L'
  try {
    const parts = data.split(':')
    if (parts.length === 5 && parts[0] === 'qr-v1') {
      label = atob(parts[4])
      moduleSize = Math.min(8, Math.max(1, Number(parts[2]) || 3))
      level = ({ 48: 'L', 49: 'M', 50: 'Q', 51: 'H' } as const)[Number(parts[3]) as 48 | 49 | 50 | 51] ?? 'L'
    }
  } catch { /* Preserve compatibility with older saved receipts. */ }
  const size = Math.min(220, Math.max(84, moduleSize * 29))
  return <div className="qr-block" title={label}><QRCodeSVG value={label || 'POS Printer Emulator'} size={size} level={level} marginSize={2} /></div>
}

type RasterGraphicData = {
  width: number
  height: number
  scaleX: number
  scaleY: number
  bytes: Uint8Array
}

function decodeRasterGraphic(data: string): RasterGraphicData | undefined {
  try {
    const parts = data.split(':')
    if (parts.length !== 6 || parts[0] !== 'raster-v1') return undefined

    const width = Number(parts[1])
    const height = Number(parts[2])
    const scaleX = Number(parts[3])
    const scaleY = Number(parts[4])
    if (![width, height, scaleX, scaleY].every(Number.isInteger)
      || width < 1 || width > 4096 || height < 1 || height > 4096
      || scaleX < 1 || scaleX > 2 || scaleY < 1 || scaleY > 2) return undefined

    const binary = atob(parts[5])
    const bytes = Uint8Array.from(binary, character => character.charCodeAt(0))
    if (bytes.length !== Math.ceil(width / 8) * height) return undefined
    return { width, height, scaleX, scaleY, bytes }
  } catch {
    return undefined
  }
}

function RasterGraphic({ data, alignment }: { data: string; alignment: ReceiptLine['alignment'] }) {
  const canvasRef = useRef<HTMLCanvasElement>(null)
  const raster = useMemo(() => decodeRasterGraphic(data), [data])

  useEffect(() => {
    const canvas = canvasRef.current
    if (!canvas || !raster) return
    const context = canvas.getContext('2d')
    if (!context) return

    const image = context.createImageData(raster.width, raster.height)
    const rowBytes = Math.ceil(raster.width / 8)
    for (let y = 0; y < raster.height; y += 1) {
      for (let x = 0; x < raster.width; x += 1) {
        if ((raster.bytes[y * rowBytes + Math.floor(x / 8)] & (0x80 >> (x % 8))) === 0) continue
        const pixel = (y * raster.width + x) * 4
        image.data[pixel] = 16
        image.data[pixel + 1] = 16
        image.data[pixel + 2] = 16
        image.data[pixel + 3] = 255
      }
    }
    context.clearRect(0, 0, raster.width, raster.height)
    context.putImageData(image, 0, 0)
  }, [raster])

  if (!raster) return <GraphicPlaceholder label="Invalid raster image" />
  return (
    <div className={`raster-graphic align-${alignment}`} role="img" aria-label="Printed receipt logo">
      <canvas
        ref={canvasRef}
        width={raster.width}
        height={raster.height}
        style={{ width: raster.width * raster.scaleX, height: raster.height * raster.scaleY }}
      />
    </div>
  )
}

function GraphicPlaceholder({ label }: { label: string }) {
  return (
    <div className="graphic-placeholder" title="The POS requested an image stored inside the physical printer.">
      <ImageIcon size={22} />
      <strong>{label}</strong>
      <span>Stored printer image unavailable</span>
    </div>
  )
}

function StoredGraphicNotice({ data }: { data: string }) {
  const keyCode = storedGraphicKey(data) ?? 'unknown'
  return (
    <div
      className="stored-graphic-notice"
      title="This command prints a logo stored in the physical printer. The print job contains only its storage key, not the logo pixels."
    >
      <ImageIcon size={20} />
      <strong>Stored printer logo {keyCode}</strong>
      <span>Logo data is not included in this print job</span>
    </div>
  )
}

function StoredGraphicPreview({ graphic, alignment }: { graphic: StoredGraphic; alignment: ReceiptLine['alignment'] }) {
  return (
    <div className={`stored-graphic-preview align-${alignment}`} role="img" aria-label={`Stored printer logo ${graphic.keyCode}: ${graphic.name}`}>
      <img src={graphic.contentUrl} alt={graphic.name} />
    </div>
  )
}

function storedGraphicKey(data: string) {
  const parts = data.split(':')
  const value = parts[0] === 'stored-v1' ? parts[1] : data.replace(/^NV graphic\s*/i, '')
  return /^[A-Z0-9]{2}$/i.test(value) ? value.toUpperCase() : undefined
}

function CollapsedSide({ side, label, onExpand }: { side: 'left' | 'right'; label: string; onExpand: () => void }) {
  return (
    <aside className={`collapsed-side collapsed-${side}`}>
      <button onClick={onExpand} aria-label={`Expand ${label} panel`} title={`Expand ${label} panel`}>
        {side === 'left' ? <PanelLeftOpen size={18} /> : <PanelRightOpen size={18} />}
        <span>{label}</span>
      </button>
    </aside>
  )
}

function Inspector({ job, tab, onTab, onCollapse }: { job?: ReceiptJob; tab: 'commands' | 'raw' | 'details'; onTab: (tab: 'commands' | 'raw' | 'details') => void; onCollapse: () => void }) {
  const unsupported = job?.commands.filter(command => !command.supported).length ?? 0
  return (
    <aside className="inspector">
      <div className="inspector-tabs">
        <button className={`tab-button ${tab === 'commands' ? 'active' : ''}`} onClick={() => onTab('commands')}>Commands</button>
        <button className={`tab-button ${tab === 'raw' ? 'active' : ''}`} onClick={() => onTab('raw')}>Raw data</button>
        <button className={`tab-button ${tab === 'details' ? 'active' : ''}`} onClick={() => onTab('details')}>Job details</button>
        <button className="inspector-collapse" onClick={onCollapse} aria-label="Collapse inspector panel" title="Collapse inspector panel"><PanelRightClose size={17} /></button>
      </div>
      {!job ? (
        <div className="inspector-empty"><Braces size={28} /><span>Select a receipt to inspect parsed commands and raw bytes.</span></div>
      ) : tab === 'commands' ? (
        <>
          <div className="inspector-summary">
            <strong>Parsed ESC/POS commands</strong>
            {unsupported > 0 && <span className="warning-label"><AlertTriangle size={14} /> {unsupported} unsupported</span>}
          </div>
          <div className="command-table-wrap">
            <table className="command-table">
              <thead><tr><th>Offset</th><th>Bytes (hex)</th><th>Command</th><th>Details</th></tr></thead>
              <tbody>
                {job.commands.map((command, index) => (
                  <tr key={`${command.offset}-${index}`} className={!command.supported ? 'unsupported' : ''}>
                    <td>{command.offset.toString(16).toUpperCase().padStart(4, '0')}</td>
                    <td>{command.hex}</td>
                    <td>{command.name}</td>
                    <td>{command.details}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <div className="inspector-footer"><span>{formatBytes(job.payloadSize)} ({job.payloadSize.toLocaleString()} bytes)</span><span>{job.commands.length} commands</span></div>
        </>
      ) : tab === 'raw' ? (
        <div className="raw-view">{job.hex.map((line, index) => <div key={index}><span>{(index * 16).toString(16).toUpperCase().padStart(6, '0')}</span>{line}</div>)}</div>
      ) : (
        <dl className="job-details">
          <div><dt>Receipt ID</dt><dd>{job.id}</dd></div>
          <div><dt>Received</dt><dd>{new Date(job.receivedAt).toLocaleString()}</dd></div>
          <div><dt>Source address</dt><dd>{job.sourceIp}</dd></div>
          {job.listenerName && <div><dt>Printer listener</dt><dd>{job.listenerName}</dd></div>}
          {job.listenerPort && <div><dt>Listener endpoint</dt><dd>TCP port {job.listenerPort}</dd></div>}
          {job.listenerId && <div><dt>Listener ID</dt><dd>{job.listenerId}</dd></div>}
          <div><dt>Job origin</dt><dd><span className={`detail-origin ${job.origin.toLowerCase().replaceAll(' ', '-')}`}>{job.origin}</span></dd></div>
          <div><dt>Renderer version</dt><dd>{job.rendererVersion}</dd></div>
          <div><dt>Printer profile</dt><dd>{job.profileName}</dd></div>
          <div><dt>Profile paper</dt><dd>{job.profilePaperWidthMm} mm · {job.profilePrintableDots} dots</dd></div>
          {job.capturedProfileId && <div><dt>Captured profile</dt><dd>{job.capturedProfileId}</dd></div>}
          {job.originalReceivedAt && <div><dt>Original received</dt><dd>{new Date(job.originalReceivedAt).toLocaleString()}</dd></div>}
          {job.originalSourceIp && <div><dt>Original source</dt><dd>{job.originalSourceIp}</dd></div>}
          {job.importedFileName && <div><dt>Imported file</dt><dd>{job.importedFileName}</dd></div>}
          {job.parentJobId && <div><dt>Parent job</dt><dd>{job.parentJobId}</dd></div>}
          <div><dt>Payload</dt><dd>{formatBytes(job.payloadSize)}</dd></div>
          <div><dt>Processing result</dt><dd className="success-text">{job.status}</dd></div>
          <div><dt>Unsupported commands</dt><dd>{job.unsupportedCount}</dd></div>
        </dl>
      )}
    </aside>
  )
}

export default App
