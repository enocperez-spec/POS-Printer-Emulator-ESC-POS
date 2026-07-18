import { useCallback, useEffect, useMemo, useRef, useState, type ChangeEvent, type FormEvent } from 'react'
import {
  AlertTriangle,
  Braces,
  CheckCircle2,
  ChevronDown,
  ChevronRight,
  CircleStop,
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
import type { JobSummary, ReceiptJob, ReceiptLine, ServiceStatus, StoredGraphic, UpdateStatus } from './types'

const emptyStatus: ServiceStatus = {
  listening: false,
  listener: '0.0.0.0:9100',
  version: '0.3.20',
  license: {
    mode: 'Trial', hasProAccess: false, isEnterprise: false, dailyLimit: 5, usedToday: 0, remaining: 5, localDate: '',
    customerName: '', emailAddress: '',
    features: { history: false, exports: false, premiumFeatures: false, watermark: true, storedLogos: false, printerState: false, printerProfiles: false, updates: false, support: false },
  },
}

type ClearRequest =
  | { kind: 'one'; id: string; label: string }
  | { kind: 'all'; count: number }

type SettingsSection = 'license' | 'printer' | 'profiles' | 'logos' | 'state' | 'updates' | 'support'

function formatBytes(value: number) {
  return value < 1024 ? `${value} B` : `${(value / 1024).toFixed(1)} KB`
}

function formatTime(value: string) {
  return new Intl.DateTimeFormat(undefined, { hour: '2-digit', minute: '2-digit', second: '2-digit' }).format(new Date(value))
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
  const [jobs, setJobs] = useState<JobSummary[]>([])
  const [selectedId, setSelectedId] = useState<string>()
  const [job, setJob] = useState<ReceiptJob>()
  const [query, setQuery] = useState('')
  const [tab, setTab] = useState<'commands' | 'raw' | 'details'>('commands')
  const [zoom, setZoom] = useState(100)
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

  useEffect(() => {
    document.documentElement.dataset.theme = theme
    localStorage.setItem('pos-printer-emulator-theme', theme)
    document.querySelector('meta[name="theme-color"]')?.setAttribute('content', theme === 'dark' ? '#0d1522' : '#f7f9fc')
  }, [theme])

  useEffect(() => {
    localStorage.setItem('pos-printer-emulator-activity-collapsed', String(activityCollapsed))
  }, [activityCollapsed])

  useEffect(() => {
    localStorage.setItem('pos-printer-emulator-inspector-collapsed', String(inspectorCollapsed))
  }, [inspectorCollapsed])

  const refresh = useCallback(async () => {
    try {
      const [nextStatus, nextJobs] = await Promise.all([api.status(), api.jobs()])
      setStatus(nextStatus)
      setJobs(nextJobs)
      setSelectedId(current => current && nextJobs.some(item => item.id === current) ? current : nextJobs[0]?.id)
    } catch (cause) {
      setError(cause instanceof Error ? cause.message : 'Unable to reach the local service.')
    }
  }, [])

  const checkForUpdates = useCallback(async (force = false) => {
    const result = await api.checkUpdates(force)
    setUpdateStatus(result)
    if (result.updateAvailable) setUpdateNoticeDismissed(false)
    return result
  }, [])

  const refreshStoredGraphics = useCallback(async () => {
    setStoredGraphics(await api.storedGraphics())
  }, [])

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
    let cancelled = false
    void api.job(selectedId)
      .then(nextJob => { if (!cancelled) setJob(nextJob) })
      .catch(cause => { if (!cancelled) setError(cause.message) })
    return () => { cancelled = true }
  }, [selectedId])

  const filteredJobs = useMemo(() => {
    const needle = query.trim().toLowerCase()
    if (!needle) return jobs
    return jobs.filter(item => `${item.sourceIp} ${item.preview} ${item.status} ${item.origin} ${item.importedFileName ?? ''}`.toLowerCase().includes(needle))
  }, [jobs, query])

  async function renderSample() {
    setBusy(true)
    setError(undefined)
    try {
      const created = await api.sample()
      setSelectedId(created.id)
      await refresh()
    } catch (cause) {
      setError(cause instanceof Error ? cause.message : 'Could not render the sample receipt.')
    } finally {
      setBusy(false)
    }
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
    if (jobs.length > 0) setClearRequest({ kind: 'all', count: jobs.length })
  }

  async function confirmClear() {
    if (!clearRequest) return
    setClearing(true)
    try {
      if (clearRequest.kind === 'all') {
        await api.clearJobs()
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
      <Header status={status} onSample={renderSample} busy={busy} theme={theme}
        onTheme={() => setTheme(current => current === 'light' ? 'dark' : 'light')}
        onSettings={setSettingsSection} />
      {error && (
        <div className="error-banner" role="alert">
          <AlertTriangle size={16} /> {error}
          <button onClick={() => setError(undefined)}>Dismiss</button>
        </div>
      )}
      {updateStatus?.updateAvailable && !updateNoticeDismissed && (
        <div className="update-banner" role="status">
          <RefreshCw size={16} />
          <span><strong>Update available:</strong> POS Printer Emulator {updateStatus.latestVersion}</span>
          <button onClick={() => setSettingsSection('updates')}>View update</button>
          <button className="banner-dismiss" onClick={() => setUpdateNoticeDismissed(true)} aria-label="Dismiss update notification"><X size={15} /></button>
        </div>
      )}
      <input ref={captureFileRef} className="capture-file-input" type="file" accept=".bin,.ppecapture,application/octet-stream,application/vnd.pos-printer-emulator.capture+zip" onChange={importCapture} />
      <main className={`workspace ${activityCollapsed ? 'activity-is-collapsed' : ''} ${inspectorCollapsed ? 'inspector-is-collapsed' : ''}`}>
        {activityCollapsed ? (
          <CollapsedSide side="left" label="Activity" onExpand={() => setActivityCollapsed(false)} />
        ) : (
          <ActivityRail jobs={filteredJobs} totalJobs={jobs.length} selectedId={selectedId} query={query} onQuery={setQuery}
            onSelect={setSelectedId} onDelete={clearJob} onClearAll={clearAllJobs}
            onCollapse={() => setActivityCollapsed(true)} historyEnabled={status.license.features.history}
            onImport={() => captureFileRef.current?.click()} importEnabled={status.license.features.premiumFeatures} importing={captureBusy} />
        )}
        <PreviewPane job={job} zoom={zoom} onZoom={setZoom} onSample={renderSample} license={status.license} storedGraphics={storedGraphics}
          onReplay={replayJob} replaying={captureBusy} onDownload={downloadJob} exporting={exporting} />
        {inspectorCollapsed ? (
          <CollapsedSide side="right" label="Inspector" onExpand={() => setInspectorCollapsed(false)} />
        ) : (
          <Inspector job={job} tab={tab} onTab={setTab} onCollapse={() => setInspectorCollapsed(true)} />
        )}
      </main>
      <footer className="status-bar">
        <span>POS Printer Emulator v{status.version} · {status.license.mode} License</span>
        <span>Local only. Receipt data stays on this device.</span>
        <span>Windows 10/11 · x64</span>
      </footer>
      {settingsSection && (
        <SettingsDialog
          status={status}
          initialSection={settingsSection}
          updateStatus={updateStatus}
          onCheckUpdates={checkForUpdates}
          storedGraphics={storedGraphics}
          onStoredGraphicsChanged={refreshStoredGraphics}
          onClose={() => setSettingsSection(undefined)}
          onActivated={license => {
            setStatus(current => ({ ...current, license }))
            void refresh()
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

function ClearJobsDialog({ request, busy, onCancel, onConfirm }: {
  request: ClearRequest
  busy: boolean
  onCancel: () => void
  onConfirm: () => void
}) {
  const isAll = request.kind === 'all'
  return (
    <div className="modal-backdrop" role="presentation">
      <section className="confirm-dialog" role="dialog" aria-modal="true" aria-labelledby="clear-jobs-title">
        <div className="confirm-icon"><Trash2 size={24} /></div>
        <div>
          <h2 id="clear-jobs-title">{isAll ? `Clear all ${request.count} jobs?` : 'Clear this job?'}</h2>
          <p>{isAll
            ? 'Every job in Activity will be permanently removed, including saved Pro or Enterprise history.'
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

function Header({ status, onSample, busy, theme, onTheme, onSettings }: {
  status: ServiceStatus
  onSample: () => void
  busy: boolean
  theme: 'light' | 'dark'
  onTheme: () => void
  onSettings: (section: SettingsSection) => void
}) {
  return (
    <header className="app-header">
      <div className="brand-mark" aria-hidden="true">
        <img src="/pos-printer-emulator-icon.png" alt="" />
      </div>
      <strong className="brand-name">POS Printer Emulator</strong>
      <div className={`service-state ${status.listening ? 'is-live' : 'is-down'}`}>
        <span className="state-dot" /> {status.listening ? 'Running (listening)' : 'Listener stopped'}
      </div>
      <div className="header-fact"><span>Listener</span> {status.listener}</div>
      <div className="header-actions">
        <button className="sample-button" onClick={onSample} disabled={busy || (!status.license.hasProAccess && status.license.remaining === 0)}>
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

function ActivityRail({ jobs, totalJobs, selectedId, query, onQuery, onSelect, onDelete, onClearAll, onCollapse, historyEnabled, onImport, importEnabled, importing }: {
  jobs: JobSummary[]
  totalJobs: number
  selectedId?: string
  query: string
  onQuery: (value: string) => void
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
          <button onClick={onImport} disabled={!importEnabled || importing} aria-label="Import receipt capture" title={importEnabled ? 'Import .bin or .ppecapture receipt' : 'Capture import requires a Pro or Enterprise License'}><Upload size={15} /></button>
          <button onClick={onClearAll} disabled={totalJobs === 0} aria-label="Clear all jobs" title="Clear all jobs"><Trash2 size={15} /></button>
          <button onClick={onCollapse} aria-label="Collapse Activity panel" title="Collapse Activity panel"><PanelLeftClose size={17} /></button>
        </div>
      </div>
      <label className="search-control">
        <Search size={16} />
        <input value={query} onChange={event => onQuery(event.target.value)} placeholder="Search jobs…" />
      </label>
      <button className="filter-control"><Filter size={15} /> All sources <ChevronDown size={15} /></button>
      <div className="job-list">
        {jobs.map(item => (
          <div key={item.id} className={`job-row ${selectedId === item.id ? 'is-selected' : ''}`}>
            <button className="job-row-select" onClick={() => onSelect(item.id)} aria-label={`Open ${item.preview}`}>
              <span className={`job-dot ${item.status.toLowerCase()}`} />
              <span className="job-time">{formatTime(item.receivedAt)}</span>
              <span className="job-source">{item.sourceIp}</span>
              <strong className="job-preview">{item.preview}</strong>
              <span className="job-size">{formatBytes(item.payloadSize)}</span>
              <span className="job-result"><b className={`job-origin ${item.origin.toLowerCase()}`}>{item.origin}</b>{item.status}</span>
              {item.unsupportedCount > 0 && <span className="job-warning">{item.unsupportedCount} warning</span>}
            </button>
            <button className="job-delete" onClick={() => onDelete(item.id)} aria-label={`Clear ${item.preview}`} title="Clear this job"><Trash2 size={14} /></button>
          </div>
        ))}
        {jobs.length === 0 && (
          <div className="rail-empty">
            <CircleStop size={22} />
            <strong>No receipt jobs yet</strong>
            <span>Send ESC/POS data to port 9100 or render the test receipt.</span>
          </div>
        )}
      </div>
      <div className="rail-footer">
        {historyEnabled ? `Showing ${jobs.length} saved job${jobs.length === 1 ? '' : 's'}` : `Showing ${jobs.length} session job${jobs.length === 1 ? '' : 's'} · History requires Pro`}
      </div>
    </aside>
  )
}

function PreviewPane({ job, zoom, onZoom, onSample, license, storedGraphics, onReplay, replaying, onDownload, exporting }: {
  job?: ReceiptJob
  zoom: number
  onZoom: (value: number) => void
  onSample: () => void
  license: ServiceStatus['license']
  storedGraphics: StoredGraphic[]
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
          : <button className="premium-disabled" disabled title="Available with a Pro or Enterprise License"><LockKeyhole size={15} /> Text</button>)}
        {job && (license.features.exports
          ? <button className="toolbar-link" onClick={() => onDownload('raw')} disabled={exporting !== undefined}><Download size={16} /> {exporting === 'raw' ? 'Saving…' : 'Raw'}</button>
          : <button className="premium-disabled" disabled title="Available with a Pro or Enterprise License"><LockKeyhole size={15} /> Raw</button>)}
        {job && (license.features.exports
          ? <button className="toolbar-link" onClick={() => onDownload('capture')} disabled={exporting !== undefined}><Package size={16} /> {exporting === 'capture' ? 'Saving…' : 'Capture'}</button>
          : <button className="premium-disabled" disabled title="Available with a Pro or Enterprise License"><LockKeyhole size={15} /> Capture</button>)}
        {job && <button onClick={onReplay} disabled={!license.features.premiumFeatures || replaying}
          className={!license.features.premiumFeatures ? 'premium-disabled' : ''}
          title={!license.features.premiumFeatures ? 'Available with a Pro or Enterprise License' : 'Replay this receipt without sending it from the POS again'}>
          {!license.features.premiumFeatures && <LockKeyhole size={15} />}<Play size={15} /> {replaying ? 'Replaying…' : 'Replay'}
        </button>}
        <button onClick={() => window.print()} disabled={!license.features.premiumFeatures}
          className={!license.features.premiumFeatures ? 'premium-disabled' : ''}
          title={!license.features.premiumFeatures ? 'Available with a Pro or Enterprise License' : undefined}>
          {!license.features.premiumFeatures && <LockKeyhole size={15} />}<Printer size={16} /> Print / PDF
        </button>
      </div>
      <div className="preview-canvas">
        {job ? (
          <div className="paper-wrap" style={{ transform: `scale(${zoom / 100})`, width: `${Math.round(364 * job.profilePaperWidthMm / 80)}px` }}>
            <ReceiptPaper lines={job.lines} watermark={license.features.watermark} storedGraphics={storedGraphicMap} paperWidthMm={job.profilePaperWidthMm} />
          </div>
        ) : (
          <div className="preview-empty">
            <div className="empty-receipt"><Printer size={34} /></div>
            <h2>Ready for a receipt</h2>
            <p>The listener is waiting on TCP port 9100. Use a POS terminal or render the built-in test job.</p>
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

function SettingsDialog({ status, initialSection, updateStatus, onCheckUpdates, storedGraphics, onStoredGraphicsChanged, onClose, onActivated }: {
  status: ServiceStatus
  initialSection: SettingsSection
  updateStatus?: UpdateStatus
  onCheckUpdates: (force?: boolean) => Promise<UpdateStatus>
  storedGraphics: StoredGraphic[]
  onStoredGraphicsChanged: () => Promise<void>
  onClose: () => void
  onActivated: (license: ServiceStatus['license']) => void
}) {
  const features = status.license.features
  const canAccess = (candidate: SettingsSection) => candidate === 'logos' ? features.storedLogos
    : candidate === 'state' ? features.printerState
      : candidate === 'profiles' ? features.printerProfiles
      : candidate === 'updates' ? features.updates
        : candidate === 'support' ? features.support
          : true
  const [section, setSection] = useState<SettingsSection>(canAccess(initialSection) ? initialSection : 'license')
  const labels: Record<SettingsSection, string> = { license: 'License', printer: 'Printer Setup Wizard', profiles: 'Printer Profiles', logos: 'Stored Logos', state: 'Printer State', updates: 'Check for Updates', support: 'Support' }
  const lockedTitle = 'Requires a Pro or Enterprise License'

  return (
    <div className="modal-backdrop" role="presentation">
      <section className="settings-dialog" role="dialog" aria-modal="true" aria-labelledby="settings-title">
        <header className="settings-dialog-header">
          <div><Settings size={20} /><div><h2 id="settings-title">Settings</h2><p>{labels[section]}</p></div></div>
          <button className="dialog-close" onClick={onClose} aria-label="Close settings"><X size={19} /></button>
        </header>
        <div className="settings-layout">
          <nav className="settings-nav" aria-label="Settings sections">
            <button className={section === 'license' ? 'active' : ''} onClick={() => setSection('license')}><KeyRound size={18} /><span>License</span><ChevronRight size={15} /></button>
            <button className={section === 'printer' ? 'active' : ''} onClick={() => setSection('printer')}><Printer size={18} /><span>Printer Setup Wizard</span><ChevronRight size={15} /></button>
            <button className={section === 'profiles' ? 'active' : ''} onClick={() => setSection('profiles')} disabled={!features.printerProfiles} title={!features.printerProfiles ? lockedTitle : undefined}><SlidersHorizontal size={18} /><span>Printer Profiles</span>{features.printerProfiles ? <ChevronRight size={15} /> : <span className="pro-lock"><LockKeyhole size={12} />Pro</span>}</button>
            <button className={section === 'logos' ? 'active' : ''} onClick={() => setSection('logos')} disabled={!features.storedLogos} title={!features.storedLogos ? lockedTitle : undefined}><ImageIcon size={18} /><span>Stored Logos</span>{features.storedLogos ? <ChevronRight size={15} /> : <span className="pro-lock"><LockKeyhole size={12} />Pro</span>}</button>
            <button className={section === 'state' ? 'active' : ''} onClick={() => setSection('state')} disabled={!features.printerState} title={!features.printerState ? lockedTitle : undefined}><Gauge size={18} /><span>Printer State</span>{features.printerState ? <ChevronRight size={15} /> : <span className="pro-lock"><LockKeyhole size={12} />Pro</span>}</button>
            <button className={section === 'updates' ? 'active' : ''} onClick={() => setSection('updates')} disabled={!features.updates} title={!features.updates ? lockedTitle : undefined}><RefreshCw size={18} /><span>Check for Updates</span>{features.updates ? <ChevronRight size={15} /> : <span className="pro-lock"><LockKeyhole size={12} />Pro</span>}</button>
            <button className={section === 'support' ? 'active' : ''} onClick={() => setSection('support')} disabled={!features.support} title={!features.support ? lockedTitle : undefined}><LifeBuoy size={18} /><span>Support</span>{features.support ? <ChevronRight size={15} /> : <span className="pro-lock"><LockKeyhole size={12} />Pro</span>}</button>
          </nav>
          <div className="settings-content">
            {section === 'license' && <LicenseSettings status={status} onActivated={onActivated} />}
            {section === 'printer' && <PrinterSetupWizard onCancel={onClose} />}
            {section === 'profiles' && features.printerProfiles && <PrinterProfilesSettings />}
            {section === 'logos' && features.storedLogos && <StoredGraphicsSettings graphics={storedGraphics} onChanged={onStoredGraphicsChanged} />}
            {section === 'state' && features.printerState && <PrinterStateSettings />}
            {section === 'updates' && features.updates && <UpdatesSettings status={status} updateStatus={updateStatus} onCheckUpdates={onCheckUpdates} />}
            {section === 'support' && features.support && <SupportSettings status={status} />}
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

  async function activate(event: FormEvent) {
    event.preventDefault()
    setBusy(true)
    setMessage(undefined)
    try {
      const license = await api.activate({ customerName, emailAddress, activationKey })
      setActivationKey('')
      onActivated(license)
    } catch (cause) {
      setMessage(cause instanceof Error ? cause.message : 'The activation key could not be validated.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="settings-panel license-settings">
      <div className={`license-hero ${status.license.hasProAccess ? 'is-paid' : ''} ${status.license.isEnterprise ? 'is-enterprise' : ''}`}>
        <div className="license-hero-icon">{status.license.hasProAccess ? <CheckCircle2 size={27} /> : <KeyRound size={27} />}</div>
        <div>
          <h2>{status.license.hasProAccess ? `${status.license.mode} License activated` : 'Trial License'}</h2>
          <p>{status.license.hasProAccess
            ? `${status.license.mode} features are unlocked, including unlimited receipt jobs, saved history, and exports.`
            : `${status.license.remaining} of ${status.license.dailyLimit} emulated print jobs remain today.`}</p>
        </div>
      </div>

      <div className="license-summary">
        <div><span>Status</span><strong>{status.license.hasProAccess ? `Activated · ${status.license.mode} License` : 'Trial License'}</strong></div>
        <div><span>Activation key</span><strong>{status.license.hasProAccess ? 'Validated and stored securely' : 'No activation key installed'}</strong></div>
        {status.license.licenseId && <div><span>License ID</span><strong>{status.license.licenseId}</strong></div>}
      </div>

      {status.license.hasProAccess ? (
        <div className="registered-details">
          <div><span>Registered to</span><strong>{status.license.customerName}</strong></div>
          <div><span>Email</span><strong>{status.license.emailAddress}</strong></div>
          <p className="settings-note">Your activation key is never included in support diagnostics.</p>
        </div>
      ) : (
        <form className="activation-form" onSubmit={activate}>
          <label>Customer or company name<input required value={customerName} onChange={event => setCustomerName(event.target.value)} autoComplete="organization" /></label>
          <label>Email address<input required type="email" value={emailAddress} onChange={event => setEmailAddress(event.target.value)} autoComplete="email" /></label>
          <label className="key-field">Activation key<textarea required rows={4} value={activationKey} onChange={event => setActivationKey(event.target.value)} placeholder="PPE1-…" spellCheck={false} /></label>
          {message && <div className="activation-error" role="alert"><AlertTriangle size={16} />{message}</div>}
          <button className="activate-button" type="submit" disabled={busy}><KeyRound size={17} /> {busy ? 'Validating…' : 'Validate and activate'}</button>
          <p className="activation-note">A Pro or Enterprise activation key unlocks its license level immediately without reinstalling.</p>
        </form>
      )}
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
        {available && result?.downloadUrl && (
          <button className="primary-action" onClick={() => launchUpdate(result.downloadUrl!)}><Download size={16} /> Download and install</button>
        )}
        {available && result?.releaseUrl && <a href={result.releaseUrl} target="_blank" rel="noreferrer"><ExternalLink size={15} /> Release details</a>}
      </div>
      <p className="settings-note">Automatic checks use the official POS Printer Emulator GitHub Releases feed. Installation always asks for Windows confirmation.</p>
    </div>
  )
}

function SupportSettings({ status }: { status: ServiceStatus }) {
  return (
    <div className="settings-panel support-settings">
      <div className="settings-status-card">
        <div className="settings-status-icon"><LifeBuoy size={25} /></div>
        <div><h2>Support diagnostics</h2><p>Save a text report and send it to support when you need help.</p></div>
      </div>
      <div className="support-detail-grid">
        <div><span>Application</span><strong>POS Printer Emulator {status.version}</strong></div>
        <div><span>Listener</span><strong>{status.listener}</strong></div>
        <div><span>Service</span><strong>{status.listening ? 'Running' : 'Stopped'}</strong></div>
        <div><span>License</span><strong>{status.license.mode} License</strong></div>
      </div>
      <a className="download-diagnostics" href="/api/support/diagnostics" download><Download size={17} /> Download diagnostic log</a>
      <div className="privacy-callout"><LockKeyhole size={17} /><p>The report includes application events, version, service status, and basic system details. It does not include receipt contents or activation keys.</p></div>
    </div>
  )
}

function launchUpdate(url: string) {
  const desktop = (window as Window & { chrome?: { webview?: { postMessage: (message: unknown) => void } } }).chrome?.webview
  if (desktop) {
    desktop.postMessage({ type: 'install-update', url })
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
          <div><dt>Job origin</dt><dd><span className={`detail-origin ${job.origin.toLowerCase()}`}>{job.origin}</span></dd></div>
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
