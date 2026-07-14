import { useCallback, useEffect, useMemo, useState } from 'react'
import {
  AlertTriangle,
  Braces,
  ChevronDown,
  CircleStop,
  Download,
  FileText,
  Filter,
  FlaskConical,
  Minus,
  Moon,
  Plus,
  Printer,
  RotateCw,
  Search,
  Settings,
  SlidersHorizontal,
  Sun,
} from 'lucide-react'
import { api } from './api'
import type { JobSummary, ReceiptJob, ReceiptLine, ServiceStatus } from './types'

const emptyStatus: ServiceStatus = {
  listening: false,
  listener: '0.0.0.0:9100',
  version: '0.1.0',
  trial: { mode: 'Trial', dailyLimit: 5, usedToday: 0, remaining: 5, localDate: '' },
}

function formatBytes(value: number) {
  return value < 1024 ? `${value} B` : `${(value / 1024).toFixed(1)} KB`
}

function formatTime(value: string) {
  return new Intl.DateTimeFormat(undefined, { hour: '2-digit', minute: '2-digit', second: '2-digit' }).format(new Date(value))
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

  useEffect(() => {
    document.documentElement.dataset.theme = theme
    localStorage.setItem('receipt-lab-theme', theme)
    document.querySelector('meta[name="theme-color"]')?.setAttribute('content', theme === 'dark' ? '#0d1522' : '#f7f9fc')
  }, [theme])

  const refresh = useCallback(async () => {
    try {
      const [nextStatus, nextJobs] = await Promise.all([api.status(), api.jobs()])
      setStatus(nextStatus)
      setJobs(nextJobs)
      setSelectedId(current => current ?? nextJobs[0]?.id)
    } catch (cause) {
      setError(cause instanceof Error ? cause.message : 'Unable to reach the local service.')
    }
  }, [])

  useEffect(() => {
    void refresh()
    const timer = window.setInterval(() => void refresh(), 1500)
    return () => window.clearInterval(timer)
  }, [refresh])

  useEffect(() => {
    if (!selectedId) {
      setJob(undefined)
      return
    }
    void api.job(selectedId).then(setJob).catch(cause => setError(cause.message))
  }, [selectedId])

  const filteredJobs = useMemo(() => {
    const needle = query.trim().toLowerCase()
    if (!needle) return jobs
    return jobs.filter(item => `${item.sourceIp} ${item.preview} ${item.status}`.toLowerCase().includes(needle))
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

  return (
    <div className="app-shell">
      <Header status={status} onSample={renderSample} busy={busy} theme={theme} onTheme={() => setTheme(current => current === 'light' ? 'dark' : 'light')} />
      {error && (
        <div className="error-banner" role="alert">
          <AlertTriangle size={16} /> {error}
          <button onClick={() => setError(undefined)}>Dismiss</button>
        </div>
      )}
      <main className="workspace">
        <ActivityRail jobs={filteredJobs} selectedId={selectedId} query={query} onQuery={setQuery} onSelect={setSelectedId} />
        <PreviewPane job={job} zoom={zoom} onZoom={setZoom} onSample={renderSample} />
        <Inspector job={job} tab={tab} onTab={setTab} />
      </main>
      <footer className="status-bar">
        <span>Receipt Lab v{status.version}</span>
        <span>Local only. Receipt data stays on this device.</span>
        <span>Windows 10/11 · x64</span>
      </footer>
    </div>
  )
}

function Header({ status, onSample, busy, theme, onTheme }: {
  status: ServiceStatus
  onSample: () => void
  busy: boolean
  theme: 'light' | 'dark'
  onTheme: () => void
}) {
  return (
    <header className="app-header">
      <div className="brand-mark" aria-hidden="true"><Printer size={18} /></div>
      <strong className="brand-name">Receipt Lab</strong>
      <div className={`service-state ${status.listening ? 'is-live' : 'is-down'}`}>
        <span className="state-dot" /> {status.listening ? 'Running (listening)' : 'Listener stopped'}
      </div>
      <div className="header-fact"><span>Listener</span> {status.listener}</div>
      <div className="header-fact"><span>Trial jobs remaining</span> <strong>{status.trial.remaining}</strong></div>
      <div className="header-actions">
        <button className="sample-button" onClick={onSample} disabled={busy || status.trial.remaining === 0}>
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
        <button className="icon-text-button"><Settings size={17} /> Settings</button>
      </div>
    </header>
  )
}

function ActivityRail({ jobs, selectedId, query, onQuery, onSelect }: {
  jobs: JobSummary[]
  selectedId?: string
  query: string
  onQuery: (value: string) => void
  onSelect: (id: string) => void
}) {
  return (
    <aside className="activity-rail">
      <div className="pane-heading">
        <strong>Activity</strong>
        <SlidersHorizontal size={17} />
      </div>
      <label className="search-control">
        <Search size={16} />
        <input value={query} onChange={event => onQuery(event.target.value)} placeholder="Search jobs…" />
      </label>
      <button className="filter-control"><Filter size={15} /> All sources <ChevronDown size={15} /></button>
      <div className="job-list">
        {jobs.map(item => (
          <button key={item.id} className={`job-row ${selectedId === item.id ? 'is-selected' : ''}`} onClick={() => onSelect(item.id)}>
            <span className={`job-dot ${item.status.toLowerCase()}`} />
            <span className="job-time">{formatTime(item.receivedAt)}</span>
            <span className="job-source">{item.sourceIp}</span>
            <strong className="job-preview">{item.preview}</strong>
            <span className="job-size">{formatBytes(item.payloadSize)}</span>
            <span className="job-result">{item.status}</span>
            {item.unsupportedCount > 0 && <span className="job-warning">{item.unsupportedCount} warning</span>}
          </button>
        ))}
        {jobs.length === 0 && (
          <div className="rail-empty">
            <CircleStop size={22} />
            <strong>No receipt jobs yet</strong>
            <span>Send ESC/POS data to port 9100 or render the test receipt.</span>
          </div>
        )}
      </div>
      <div className="rail-footer">Showing {jobs.length} session job{jobs.length === 1 ? '' : 's'}</div>
    </aside>
  )
}

function PreviewPane({ job, zoom, onZoom, onSample }: { job?: ReceiptJob; zoom: number; onZoom: (value: number) => void; onSample: () => void }) {
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
        {job && <a className="toolbar-link" href={`/api/jobs/${job.id}/text`}><FileText size={16} /> Text</a>}
        {job && <a className="toolbar-link" href={`/api/jobs/${job.id}/raw`}><Download size={16} /> Raw</a>}
        <button onClick={() => window.print()}><Printer size={16} /> Print / PDF</button>
      </div>
      <div className="preview-canvas">
        {job ? (
          <div className="paper-wrap" style={{ transform: `scale(${zoom / 100})` }}>
            <ReceiptPaper lines={job.lines} />
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

function ReceiptPaper({ lines }: { lines: ReceiptLine[] }) {
  return (
    <article className="receipt-paper">
      <div className="trial-watermark" aria-hidden="true">
        {Array.from({ length: 8 }, (_, index) => <span key={index}>TRIAL · NOT FOR PRODUCTION USE</span>)}
      </div>
      <div className="receipt-content">
        {lines.map((line, lineIndex) => {
          if (line.kind === 'barcode') return <Barcode key={lineIndex} label={line.data ?? ''} />
          if (line.kind === 'qr') return <QrPlaceholder key={lineIndex} label={line.data ?? ''} />
          return (
            <div key={lineIndex} className={`receipt-line align-${line.alignment}`}>
              {line.spans.map((span, spanIndex) => (
                <span key={spanIndex} style={{
                  fontWeight: span.bold ? 800 : 500,
                  textDecoration: span.underline ? 'underline' : undefined,
                  fontSize: `${12 * Math.min(span.height, 3)}px`,
                  letterSpacing: span.width > 1 ? `${span.width - 1}px` : undefined,
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

function Barcode({ label }: { label: string }) {
  const bars = Array.from(label || 'RECEIPT', (char, index) => ((char.charCodeAt(0) + index * 7) % 4) + 1)
  return (
    <div className="barcode-block">
      <div className="barcode-bars">{bars.flatMap((width, index) => [<i key={`${index}-a`} style={{ width }} />, <b key={`${index}-b`} style={{ width: (index % 3) + 1 }} />])}</div>
      <span>{label}</span>
    </div>
  )
}

function QrPlaceholder({ label }: { label: string }) {
  return <div className="qr-block" title={label}>{Array.from({ length: 81 }, (_, index) => <i key={index} className={(index * 17 + label.length * 7) % 5 < 2 ? 'on' : ''} />)}</div>
}

function Inspector({ job, tab, onTab }: { job?: ReceiptJob; tab: 'commands' | 'raw' | 'details'; onTab: (tab: 'commands' | 'raw' | 'details') => void }) {
  const unsupported = job?.commands.filter(command => !command.supported).length ?? 0
  return (
    <aside className="inspector">
      <div className="inspector-tabs">
        <button className={tab === 'commands' ? 'active' : ''} onClick={() => onTab('commands')}>Commands</button>
        <button className={tab === 'raw' ? 'active' : ''} onClick={() => onTab('raw')}>Raw data</button>
        <button className={tab === 'details' ? 'active' : ''} onClick={() => onTab('details')}>Job details</button>
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
          <div><dt>Payload</dt><dd>{formatBytes(job.payloadSize)}</dd></div>
          <div><dt>Processing result</dt><dd className="success-text">{job.status}</dd></div>
          <div><dt>Unsupported commands</dt><dd>{job.unsupportedCount}</dd></div>
        </dl>
      )}
    </aside>
  )
}

export default App
