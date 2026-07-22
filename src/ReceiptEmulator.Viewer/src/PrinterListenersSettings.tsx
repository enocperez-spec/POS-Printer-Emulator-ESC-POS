import { useCallback, useEffect, useMemo, useState, type FormEvent } from 'react'
import {
  Activity,
  AlertTriangle,
  Check,
  Copy,
  Crown,
  Edit3,
  LockKeyhole,
  Network,
  Pause,
  Play,
  Plus,
  Printer,
  RefreshCw,
  Save,
  Server,
  Trash2,
  X,
} from 'lucide-react'
import { api } from './api'
import type { LicenseStatus, PrinterListener, PrinterListenerCollection, PrinterListenerInput, PrinterProfile } from './types'

type Props = {
  canManage: boolean
  licenseMode: LicenseStatus['mode']
  maximumListeners: number
  onOpenSetup?: () => void
  onChanged?: (listeners: PrinterListener[]) => void
}

const newListenerInput = (): PrinterListenerInput => ({
  name: 'New POS Printer',
  bindAddress: '0.0.0.0',
  port: 9101,
  profileId: 'epson-tm-t88v',
  enabled: true,
  idleJobTimeoutMilliseconds: 1500,
  maximumJobBytes: 4_194_304,
  buffer: {
    enabled: false,
    capacity: 100,
    processingDelayMilliseconds: 0,
    overflowBehavior: 'RejectNewest',
  },
})

function listenerInput(listener: PrinterListener): PrinterListenerInput {
  return {
    name: listener.name,
    bindAddress: listener.bindAddress,
    port: listener.port,
    profileId: listener.profileId,
    enabled: listener.enabled,
    idleJobTimeoutMilliseconds: listener.idleJobTimeoutMilliseconds,
    maximumJobBytes: listener.maximumJobBytes,
    buffer: { ...listener.buffer },
  }
}

function listenerEndpoint(listener: PrinterListener) {
  if (listener.endpoint) return listener.endpoint
  const address = listener.connectionAddress || (listener.bindAddress === '0.0.0.0' ? 'This computer' : listener.bindAddress)
  return `${address}:${listener.port}`
}

function numberLabel(value: number | undefined) {
  return (value ?? 0).toLocaleString()
}

export function PrinterListenersSettings({ canManage, licenseMode, maximumListeners, onOpenSetup, onChanged }: Props) {
  const [collection, setCollection] = useState<PrinterListenerCollection>()
  const [profiles, setProfiles] = useState<PrinterProfile[]>([])
  const [editingId, setEditingId] = useState<string | 'new'>()
  const [form, setForm] = useState<PrinterListenerInput>(newListenerInput)
  const [busyId, setBusyId] = useState<string>()
  const [message, setMessage] = useState<string>()
  const [error, setError] = useState<string>()
  const [copiedId, setCopiedId] = useState<string>()

  const refreshListeners = useCallback(async () => {
    const next = await api.printerListeners()
    setCollection(next)
    onChanged?.(next.listeners)
    return next
  }, [onChanged])

  useEffect(() => {
    let cancelled = false
    void api.printerListeners()
      .then(listeners => {
        if (cancelled) return
        setCollection(listeners)
        onChanged?.(listeners.listeners)
      })
      .catch(cause => { if (!cancelled) setError(cause instanceof Error ? cause.message : 'Unable to load printer listeners.') })
    if (canManage) {
      void api.printerProfiles()
        .then(profileStatus => { if (!cancelled) setProfiles(profileStatus.profiles) })
        .catch(cause => { if (!cancelled) setError(cause instanceof Error ? cause.message : 'Unable to load printer profiles.') })
    } else {
      setProfiles([])
    }

    const timer = window.setInterval(() => {
      void refreshListeners().catch(() => undefined)
    }, 4_000)
    return () => {
      cancelled = true
      window.clearInterval(timer)
    }
  }, [canManage, onChanged, refreshListeners])

  const runningCount = useMemo(
    () => collection?.listeners.filter(listener => listener.listening || listener.status === 'Running' || listener.status === 'Listening').length ?? 0,
    [collection],
  )

  function beginCreate() {
    const usedPorts = new Set(collection?.listeners.map(listener => listener.port) ?? [])
    let port = 9100
    while (usedPorts.has(port) && port < 65_535) port += 1
    const selectedProfileId = profiles[0]?.id ?? 'epson-tm-t88v'
    setForm({ ...newListenerInput(), port, profileId: selectedProfileId })
    setEditingId('new')
    setError(undefined)
    setMessage(undefined)
  }

  function beginEdit(listener: PrinterListener) {
    setForm(listenerInput(listener))
    setEditingId(listener.id)
    setError(undefined)
    setMessage(undefined)
  }

  async function submit(event: FormEvent) {
    event.preventDefault()
    const target = editingId
    if (!target) return
    setBusyId(target)
    setError(undefined)
    setMessage(undefined)
    try {
      if (target === 'new') {
        await api.createPrinterListener(form)
      } else {
        await api.updatePrinterListener(target, form)
      }
      setEditingId(undefined)
      await refreshListeners()
      setMessage(`${form.name} was ${target === 'new' ? 'created' : 'updated'} successfully.`)
    } catch (cause) {
      setError(cause instanceof Error ? cause.message : 'The printer listener could not be saved.')
    } finally {
      setBusyId(undefined)
    }
  }

  async function changeRuntime(listener: PrinterListener, action: 'start' | 'stop' | 'restart') {
    setBusyId(listener.id)
    setError(undefined)
    setMessage(undefined)
    try {
      if (action === 'start') await api.startPrinterListener(listener.id)
      else if (action === 'stop') await api.stopPrinterListener(listener.id)
      else await api.restartPrinterListener(listener.id)
      await refreshListeners()
      setMessage(`${listener.name} ${action === 'stop' ? 'stopped' : action === 'start' ? 'started' : 'restarted'}.`)
    } catch (cause) {
      setError(cause instanceof Error ? cause.message : `The listener ${action} action could not be completed.`)
    } finally {
      setBusyId(undefined)
    }
  }

  async function remove(listener: PrinterListener) {
    if (listener.isDefault || !window.confirm(`Delete ${listener.name}? Existing receipt history will be kept.`)) return
    setBusyId(listener.id)
    setError(undefined)
    setMessage(undefined)
    try {
      await api.deletePrinterListener(listener.id)
      await refreshListeners()
      setMessage(`${listener.name} was deleted.`)
    } catch (cause) {
      setError(cause instanceof Error ? cause.message : 'The printer listener could not be deleted.')
    } finally {
      setBusyId(undefined)
    }
  }

  async function copyDetails(listener: PrinterListener) {
    const text = [
      `Printer: ${listener.name}`,
      `Protocol: RAW TCP`,
      `IP address: ${listener.connectionAddress || (listener.bindAddress === '0.0.0.0' ? '127.0.0.1' : listener.bindAddress)}`,
      `Port: ${listener.port}`,
      `Profile: ${listener.profileName || profiles.find(profile => profile.id === listener.profileId)?.name || listener.profileId}`,
    ].join('\n')
    try {
      await navigator.clipboard.writeText(text)
      setCopiedId(listener.id)
      window.setTimeout(() => setCopiedId(current => current === listener.id ? undefined : current), 1_800)
    } catch {
      setError('Windows could not copy the connection details. Please copy the address and port manually.')
    }
  }

  const listeners = collection?.listeners ?? []
  const atLimit = collection?.maximumListeners !== undefined && listeners.length >= collection.maximumListeners

  if (!canManage) {
    return (
      <SingleListenerReadOnlyPanel
        licenseMode={licenseMode}
        maximumListeners={maximumListeners}
        listener={listeners.find(listener => listener.isDefault) ?? listeners[0]}
        loading={!collection}
        error={error}
        copied={copiedId !== undefined}
        onCopy={listener => void copyDetails(listener)}
        onOpenSetup={onOpenSetup}
      />
    )
  }

  return (
    <div className="settings-panel printer-listeners-settings">
      <div className="listener-heading">
        <div>
          <span className="enterprise-eyebrow"><Crown size={13} /> {licenseMode} · up to {collection?.maximumListeners ?? maximumListeners}</span>
          <h2>Printer Listeners</h2>
          <p>Run independent virtual receipt printers on this computer. Each printer has its own port, profile, state, queue, and counters.</p>
        </div>
        <button className="primary-action" onClick={beginCreate} disabled={atLimit || busyId !== undefined} title={atLimit ? 'The listener limit has been reached.' : undefined}>
          <Plus size={16} /> Add printer
        </button>
      </div>

      <div className="listener-summary" aria-label="Listener status summary">
        <div><Server size={17} /><span>Configured</span><strong>{listeners.length}</strong></div>
        <div><Activity size={17} /><span>Running</span><strong>{runningCount}</strong></div>
        <div><Network size={17} /><span>Available slots</span><strong>{collection?.maximumListeners === undefined ? '—' : Math.max(0, collection.maximumListeners - listeners.length)}</strong></div>
      </div>

      {message ? <div className="listener-message is-success" role="status"><Check size={15} />{message}</div> : null}
      {error ? <div className="listener-message is-error" role="alert"><AlertTriangle size={15} />{error}</div> : null}

      {editingId ? (
        <ListenerForm
          editingId={editingId}
          form={form}
          profiles={profiles}
          busy={busyId === editingId}
          onForm={setForm}
          onCancel={() => setEditingId(undefined)}
          onSubmit={submit}
        />
      ) : null}

      {!collection ? (
        <div className="listener-loading"><RefreshCw className="spin" size={17} /> Loading printer listeners…</div>
      ) : (
        <div className="listener-list">
          {listeners.map(listener => (
            <ListenerCard
              key={listener.id}
              listener={listener}
              profileName={listener.profileName || profiles.find(profile => profile.id === listener.profileId)?.name}
              busy={busyId === listener.id}
              copied={copiedId === listener.id}
              onCopy={() => void copyDetails(listener)}
              onEdit={() => beginEdit(listener)}
              onStart={() => void changeRuntime(listener, 'start')}
              onStop={() => void changeRuntime(listener, 'stop')}
              onRestart={() => void changeRuntime(listener, 'restart')}
              onDelete={() => void remove(listener)}
            />
          ))}
          {listeners.length === 0 ? (
            <div className="listener-empty"><Server size={28} /><strong>No printer listeners configured</strong><span>Add the first virtual printer to begin listening for POS print jobs.</span></div>
          ) : null}
        </div>
      )}
      <p className="settings-note">Use a unique TCP port for every printer. Port 9100 is the standard starting point; additional printers normally use 9101, 9102, and so on.</p>
    </div>
  )
}

function SingleListenerReadOnlyPanel({ licenseMode, maximumListeners, listener, loading, error, copied, onCopy, onOpenSetup }: {
  licenseMode: LicenseStatus['mode']
  maximumListeners: number
  listener?: PrinterListener
  loading: boolean
  error?: string
  copied: boolean
  onCopy: (listener: PrinterListener) => void
  onOpenSetup?: () => void
}) {
  const localEndpoint = listener ? `127.0.0.1:${listener.port}` : '127.0.0.1:9100'
  const networkEndpoint = listener?.connectionAddress && listener.connectionAddress !== '127.0.0.1'
    ? `${listener.connectionAddress}:${listener.port}`
    : 'Network address unavailable'
  const running = listener?.listening || listener?.status === 'Running' || listener?.status === 'Listening'

  return (
    <div className="settings-panel single-listener-settings">
      <div className="listener-heading">
        <div>
          <span className="enterprise-eyebrow"><LockKeyhole size={13} /> {licenseMode} · {maximumListeners} included listener</span>
          <h2>Your Printer Listener</h2>
          <p>Follow these two steps: install the Windows printer, then send your POS print jobs to the included listener.</p>
        </div>
      </div>

      <div className="single-listener-steps">
        <section>
          <span className="single-listener-step-number">1</span>
          <div><small>INSTALL THE PRINTER</small><h3>Run the Printer Setup Wizard</h3><p>The wizard installs and connects the Windows receipt printer for you.</p></div>
          <button className="primary-action" onClick={onOpenSetup}><Printer size={15} /> Open setup wizard</button>
        </section>
        <section>
          <span className="single-listener-step-number">2</span>
          <div><small>CONFIGURE YOUR POS</small><h3>Use the listener address below</h3><p>Set the POS printer protocol to RAW TCP/ESC-POS and enter the matching address and port.</p></div>
        </section>
      </div>

      {error ? <div className="listener-message is-error" role="alert"><AlertTriangle size={15} />{error}</div> : null}
      {loading ? (
        <div className="listener-loading"><RefreshCw className="spin" size={17} /> Loading your included listener…</div>
      ) : listener ? (
        <article className="readonly-listener-card">
          <header>
            <div className="listener-card-icon"><Server size={20} /></div>
            <div><h3>{listener.name}</h3><p>{listener.profileName || listener.profileId}</p></div>
            <span className={`listener-status ${running ? 'status-running' : 'status-stopped'}`}><i />{running ? 'Running' : listener.status}</span>
          </header>
          <div className="readonly-listener-lock"><LockKeyhole size={14} /><span><strong>Included listener — read-only</strong>Its name, port, and profile cannot be edited with the {licenseMode} License.</span></div>
          <div className="readonly-connection-grid">
            <div><Server size={17} /><span><small>POS on this computer</small><strong>{localEndpoint}</strong></span></div>
            <div><Network size={17} /><span><small>POS on another computer</small><strong>{networkEndpoint}</strong></span></div>
          </div>
          <div className="readonly-listener-instruction"><strong>Important:</strong> Your POS system must send print jobs to one of these exact addresses and port {listener.port}.</div>
          <footer><button onClick={() => onCopy(listener)}><Copy size={15} />{copied ? 'Copied connection details' : 'Copy connection details'}</button></footer>
        </article>
      ) : (
        <div className="listener-empty"><Server size={28} /><strong>The included listener could not be found</strong><span>Open the Printer Setup Wizard to restore the default Trial printer configuration.</span></div>
      )}

      <MultipleListenerUpgradePanel licenseMode={licenseMode} maximumListeners={maximumListeners} compact />
    </div>
  )
}

function MultipleListenerUpgradePanel({ licenseMode, maximumListeners, compact = false }: { licenseMode: LicenseStatus['mode']; maximumListeners: number; compact?: boolean }) {
  return (
    <div className={`settings-panel enterprise-upgrade-panel ${compact ? 'is-compact' : ''}`}>
      <div className="enterprise-upgrade-icon"><Crown size={30} /></div>
      <span>Pro and Enterprise feature</span>
      <h2>Run multiple virtual receipt printers</h2>
      <p>Your {licenseMode} License includes {maximumListeners} local listener. Upgrade to Pro for up to 2 independently configured printers, or Enterprise for up to 15.</p>
      <ul>
        <li><Check size={15} /> Separate TCP port and printer profile for each listener</li>
        <li><Check size={15} /> Independent state, queue, counters, and failure handling</li>
        <li><Check size={15} /> Filter Activity and diagnostics by printer</li>
      </ul>
      <a className="enterprise-upgrade-action" href="https://www.posprinteremulator.com/pricing" target="_blank" rel="noreferrer"><Crown size={16} /> Compare license options</a>
      <small>Enter a replacement key under License to upgrade this installation without reinstalling.</small>
    </div>
  )
}

function ListenerForm({ editingId, form, profiles, busy, onForm, onCancel, onSubmit }: {
  editingId: string | 'new'
  form: PrinterListenerInput
  profiles: PrinterProfile[]
  busy: boolean
  onForm: (form: PrinterListenerInput) => void
  onCancel: () => void
  onSubmit: (event: FormEvent) => void
}) {
  return (
    <form className="listener-form" onSubmit={onSubmit}>
      <div className="listener-form-heading">
        <div><span>{editingId === 'new' ? 'New virtual printer' : 'Edit virtual printer'}</span><h3>{editingId === 'new' ? 'Configure listener' : form.name}</h3></div>
        <button type="button" onClick={onCancel} aria-label="Close listener form"><X size={17} /></button>
      </div>
      <label>Printer name<input required maxLength={80} value={form.name} onChange={event => onForm({ ...form, name: event.target.value })} /></label>
      <label>TCP port<input required type="number" min={1} max={65535} value={form.port} onChange={event => onForm({ ...form, port: Number(event.target.value) })} /><small>Must be unique on this computer.</small></label>
      <label>Listen on address<select value={form.bindAddress} onChange={event => onForm({ ...form, bindAddress: event.target.value })}><option value="0.0.0.0">All network connections</option><option value="127.0.0.1">This computer only</option></select><small>Use “this computer only” when the POS runs locally.</small></label>
      <label>Printer profile<select value={form.profileId} onChange={event => onForm({ ...form, profileId: event.target.value })}>{profiles.map(profile => <option key={profile.id} value={profile.id}>{profile.name}</option>)}</select></label>
      <label className="listener-check wide"><input type="checkbox" checked={form.enabled} onChange={event => onForm({ ...form, enabled: event.target.checked })} /><span><strong>Start automatically</strong><small>Start this listener whenever the POS Printer Emulator service starts.</small></span></label>
      <fieldset className="listener-buffer wide">
        <legend>Connection limits</legend>
        <label>End-of-job timeout (ms)<input type="number" min={100} max={60000} value={form.idleJobTimeoutMilliseconds} onChange={event => onForm({ ...form, idleJobTimeoutMilliseconds: Number(event.target.value) })} /><small>Finishes a job after this much idle time.</small></label>
        <label>Maximum job size (bytes)<input type="number" min={1024} max={67108864} value={form.maximumJobBytes} onChange={event => onForm({ ...form, maximumJobBytes: Number(event.target.value) })} /><small>Protects this listener from oversized payloads.</small></label>
      </fieldset>
      <fieldset className="listener-buffer wide">
        <legend>Print-job buffer</legend>
        <label className="listener-check wide"><input type="checkbox" checked={form.buffer.enabled} onChange={event => onForm({ ...form, buffer: { ...form.buffer, enabled: event.target.checked } })} /><span><strong>Enable buffering</strong><small>Queue bursts of print jobs and process them in order.</small></span></label>
        <label>Queue capacity<input disabled={!form.buffer.enabled} type="number" min={1} max={10000} value={form.buffer.capacity} onChange={event => onForm({ ...form, buffer: { ...form.buffer, capacity: Number(event.target.value) } })} /></label>
        <label>Processing delay (ms)<input disabled={!form.buffer.enabled} type="number" min={0} max={60000} value={form.buffer.processingDelayMilliseconds} onChange={event => onForm({ ...form, buffer: { ...form.buffer, processingDelayMilliseconds: Number(event.target.value) } })} /></label>
        <label className="wide">When the queue is full<select disabled={!form.buffer.enabled} value={form.buffer.overflowBehavior} onChange={event => onForm({ ...form, buffer: { ...form.buffer, overflowBehavior: event.target.value as PrinterListenerInput['buffer']['overflowBehavior'] } })}><option value="RejectNewest">Reject the newest job</option><option value="DropOldest">Drop the oldest queued job</option></select></label>
      </fieldset>
      <div className="listener-form-actions wide"><button type="button" onClick={onCancel}>Cancel</button><button className="primary-action" type="submit" disabled={busy}><Save size={15} /> {busy ? 'Saving…' : editingId === 'new' ? 'Create printer' : 'Save changes'}</button></div>
    </form>
  )
}

function ListenerCard({ listener, profileName, busy, copied, onCopy, onEdit, onStart, onStop, onRestart, onDelete }: {
  listener: PrinterListener
  profileName?: string
  busy: boolean
  copied: boolean
  onCopy: () => void
  onEdit: () => void
  onStart: () => void
  onStop: () => void
  onRestart: () => void
  onDelete: () => void
}) {
  const isRunning = listener.listening || listener.status === 'Running' || listener.status === 'Listening'
  const statusClass = listener.status.toLowerCase()
  const counters = listener.counters ?? {
    activeConnections: 0, totalConnections: 0, bytesReceived: 0, jobsReceived: 0,
    jobsCompleted: 0, jobsRejected: 0, jobsFailed: 0, queued: 0, processing: 0,
  }
  return (
    <article className={`listener-card status-${statusClass}`}>
      <header>
        <div className="listener-card-icon"><Server size={20} /></div>
        <div className="listener-card-title"><div><h3>{listener.name}</h3>{listener.isDefault ? <span>Default</span> : null}</div><p>{profileName || listener.profileId}</p></div>
        <span className={`listener-status status-${statusClass}`}><i />{listener.status}</span>
      </header>
      <div className="listener-connection">
        <div><span>POS connection</span><strong>{listenerEndpoint(listener)}</strong></div>
        <button onClick={onCopy} disabled={busy} title="Copy POS connection details">{copied ? <Check size={15} /> : <Copy size={15} />}{copied ? 'Copied' : 'Copy details'}</button>
      </div>
      <dl className="listener-counters">
        <div><dt>Jobs</dt><dd>{numberLabel(counters.jobsReceived)}</dd></div>
        <div><dt>Completed</dt><dd>{numberLabel(counters.jobsCompleted)}</dd></div>
        <div><dt>Queued</dt><dd>{numberLabel(counters.queued)}</dd></div>
        <div><dt>Connections</dt><dd>{numberLabel(counters.activeConnections)} active</dd></div>
        <div><dt>Rejected</dt><dd className={(counters.jobsRejected ?? 0) > 0 ? 'counter-warning' : ''}>{numberLabel(counters.jobsRejected)}</dd></div>
        <div><dt>Failed</dt><dd className={(counters.jobsFailed ?? 0) > 0 ? 'counter-danger' : ''}>{numberLabel(counters.jobsFailed)}</dd></div>
      </dl>
      {listener.lastConnection ? <p className="listener-last-event">Last connection {new Date(listener.lastConnection).toLocaleString()}</p> : null}
      {listener.lastError ? <div className="listener-error"><AlertTriangle size={14} /><span><strong>Listener error</strong>{listener.lastError}</span></div> : null}
      <footer>
        <button onClick={onEdit} disabled={busy}><Edit3 size={14} /> Edit</button>
        {isRunning ? <button onClick={onStop} disabled={busy}><Pause size={14} /> Stop</button> : <button onClick={onStart} disabled={busy}><Play size={14} /> Start</button>}
        <button onClick={onRestart} disabled={busy || !isRunning}><RefreshCw className={busy ? 'spin' : ''} size={14} /> Restart</button>
        <button className="danger-action" onClick={onDelete} disabled={busy || listener.isDefault} title={listener.isDefault ? 'The default listener cannot be deleted.' : 'Delete this listener'}><Trash2 size={14} /> Delete</button>
      </footer>
    </article>
  )
}
