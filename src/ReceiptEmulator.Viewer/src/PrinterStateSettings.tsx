import { useEffect, useState } from 'react'
import { AlertTriangle, CheckCircle2, CircleOff, Gauge, RotateCcw, Save } from 'lucide-react'
import { api } from './api'
import type { PaperStatus, PrinterStateStatus, PrinterStateUpdate } from './types'

const readyState: PrinterStateUpdate = {
  online: true,
  paperStatus: 'Ready',
  coverOpen: false,
  cutterError: false,
  recoverableError: false,
  unrecoverableError: false,
  autoRecoverableError: false,
  drawerOpen: false,
}

const presets: Array<{ label: string; description: string; state: PrinterStateUpdate }> = [
  { label: 'Ready', description: 'Online and ready to print', state: readyState },
  { label: 'Paper low', description: 'Near-end sensor warning', state: { ...readyState, paperStatus: 'Low' } },
  { label: 'Paper out', description: 'Stop because paper is empty', state: { ...readyState, paperStatus: 'Out' } },
  { label: 'Cover open', description: 'Receipt cover is open', state: { ...readyState, coverOpen: true } },
  { label: 'Cutter error', description: 'Automatic cutter is blocked', state: { ...readyState, cutterError: true } },
  { label: 'Offline', description: 'Printer is unavailable', state: { ...readyState, online: false } },
]

export function PrinterStateSettings() {
  const [status, setStatus] = useState<PrinterStateStatus>()
  const [draft, setDraft] = useState<PrinterStateUpdate>(readyState)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string>()

  useEffect(() => {
    let active = true
    api.printerState()
      .then(value => {
        if (!active) return
        setStatus(value)
        setDraft(toUpdate(value))
      })
      .catch(cause => active && setError(messageFrom(cause)))
    return () => { active = false }
  }, [])

  async function apply(next = draft) {
    setBusy(true)
    setError(undefined)
    try {
      const value = await api.updatePrinterState(next)
      setStatus(value)
      setDraft(toUpdate(value))
    } catch (cause) {
      setError(messageFrom(cause))
    } finally {
      setBusy(false)
    }
  }

  async function reset() {
    setBusy(true)
    setError(undefined)
    try {
      const value = await api.resetPrinterState()
      setStatus(value)
      setDraft(toUpdate(value))
    } catch (cause) {
      setError(messageFrom(cause))
    } finally {
      setBusy(false)
    }
  }

  if (!status && !error) return <div className="printer-state-loading"><RotateCcw className="spin" size={20} /> Reading printer state…</div>

  return (
    <div className="settings-panel printer-state-settings">
      <div className={`settings-status-card printer-state-hero ${status?.effectiveOnline ? 'is-current' : 'has-fault'}`}>
        <div className="settings-status-icon">{status?.effectiveOnline ? <CheckCircle2 size={26} /> : <AlertTriangle size={26} />}</div>
        <div>
          <h2>{status?.summary ?? 'Printer state unavailable'}</h2>
          <p>The emulator answers Epson real-time status requests with this simulated printer condition.</p>
        </div>
      </div>

      <section className="state-section">
        <div className="state-section-heading"><div><h3>Quick scenarios</h3><p>Choose a common condition to test how the POS responds.</p></div></div>
        <div className="state-presets">
          {presets.map(preset => (
            <button key={preset.label} disabled={busy} onClick={() => apply(preset.state)}>
              <strong>{preset.label}</strong><span>{preset.description}</span>
            </button>
          ))}
        </div>
      </section>

      <section className="state-section">
        <div className="state-section-heading"><div><h3>Custom printer state</h3><p>Combine conditions for a specific support or testing scenario.</p></div></div>
        <div className="state-control-grid">
          <label className="state-select"><span>Paper supply</span><select value={draft.paperStatus} onChange={event => setDraft(current => ({ ...current, paperStatus: event.target.value as PaperStatus }))}><option>Ready</option><option>Low</option><option>Out</option></select></label>
          <StateToggle label="Printer online" detail="Manual online/offline control" checked={draft.online} onChange={online => setDraft(current => ({ ...current, online }))} />
          <StateToggle label="Cover open" detail="Report the receipt cover open" checked={draft.coverOpen} onChange={coverOpen => setDraft(current => ({ ...current, coverOpen }))} />
          <StateToggle label="Cutter error" detail="Report an automatic cutter fault" checked={draft.cutterError} onChange={cutterError => setDraft(current => ({ ...current, cutterError }))} />
          <StateToggle label="Recoverable error" detail="POS may request printer recovery" checked={draft.recoverableError} onChange={recoverableError => setDraft(current => ({ ...current, recoverableError }))} />
          <StateToggle label="Unrecoverable error" detail="Report a service-required failure" checked={draft.unrecoverableError} onChange={unrecoverableError => setDraft(current => ({ ...current, unrecoverableError }))} />
          <StateToggle label="Auto-recoverable error" detail="Report a temporary printer fault" checked={draft.autoRecoverableError} onChange={autoRecoverableError => setDraft(current => ({ ...current, autoRecoverableError }))} />
          <StateToggle label="Cash drawer open" detail="Change drawer connector status" checked={draft.drawerOpen} onChange={drawerOpen => setDraft(current => ({ ...current, drawerOpen }))} />
        </div>
        {error && <div className="state-error"><AlertTriangle size={16} />{error}</div>}
        <div className="settings-actions state-actions"><button className="primary-action" disabled={busy} onClick={() => apply()}><Save size={16} />{busy ? 'Saving…' : 'Apply printer state'}</button><button disabled={busy} onClick={reset}><RotateCcw size={16} />Reset to ready</button></div>
      </section>

      <section className="state-protocol">
        <Gauge size={20} />
        <div><strong>Epson status protocol active</strong><span>DLE EOT real-time status and GS a Automatic Status Back</span></div>
        <dl><div><dt>Responses</dt><dd>{status?.responsesSent ?? 0}</dd></div><div><dt>ASB clients</dt><dd>{status?.asbConnections ?? 0}</dd></div></dl>
      </section>
    </div>
  )
}

function StateToggle({ label, detail, checked, onChange }: { label: string; detail: string; checked: boolean; onChange: (checked: boolean) => void }) {
  return <button type="button" className={`state-toggle ${checked ? 'active' : ''}`} aria-pressed={checked} onClick={() => onChange(!checked)}><span className="state-toggle-icon">{checked ? <CheckCircle2 size={18} /> : <CircleOff size={18} />}</span><span><strong>{label}</strong><small>{detail}</small></span><i aria-hidden="true" /></button>
}

function toUpdate(status: PrinterStateStatus): PrinterStateUpdate {
  return {
    online: status.online,
    paperStatus: status.paperStatus,
    coverOpen: status.coverOpen,
    cutterError: status.cutterError,
    recoverableError: status.recoverableError,
    unrecoverableError: status.unrecoverableError,
    autoRecoverableError: status.autoRecoverableError,
    drawerOpen: status.drawerOpen,
  }
}

function messageFrom(cause: unknown) { return cause instanceof Error ? cause.message : 'The printer state could not be updated.' }
