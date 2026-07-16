import { useEffect, useMemo, useRef, useState, type ChangeEvent, type FormEvent } from 'react'
import { Check, Copy, Download, FileUp, Pencil, Plus, Printer, Save, Trash2, X } from 'lucide-react'
import { api } from './api'
import type { PrinterCapabilities, PrinterProfile, PrinterProfileInput, PrinterProfileStatus } from './types'

const defaultCapabilities: PrinterCapabilities = {
  cutter: true, cashDrawer: true, rasterImages: true, nvGraphics: true,
  barcodes: true, qrCodes: true, twoColor: false, dleEotStatus: true, automaticStatusBack: true,
}

const newProfile: PrinterProfileInput = {
  name: 'Custom ESC/POS 80 mm',
  description: '',
  paperWidthMm: 80,
  printableDots: 576,
  maximumRasterWidthDots: 576,
  maximumRasterHeightDots: 2304,
  defaultCodePage: 437,
  supportedCodePages: [437, 850, 858, 1252],
  fontAColumns: 48,
  fontBColumns: 64,
  capabilities: defaultCapabilities,
}

const capabilityLabels: Record<keyof PrinterCapabilities, string> = {
  cutter: 'Paper cutter', cashDrawer: 'Cash drawer', rasterImages: 'Raster images',
  nvGraphics: 'Stored NV graphics', barcodes: 'Barcodes', qrCodes: 'QR codes', twoColor: 'Two-color printing',
  dleEotStatus: 'DLE EOT status', automaticStatusBack: 'Automatic Status Back',
}

function profileInput(profile: PrinterProfile): PrinterProfileInput {
  const { id: _id, builtIn: _builtIn, ...input } = profile
  return input
}

function saveBlob(blob: Blob, fileName: string) {
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url; link.download = fileName; document.body.appendChild(link); link.click(); link.remove()
  window.setTimeout(() => URL.revokeObjectURL(url), 1_000)
}

export function PrinterProfilesSettings() {
  const [status, setStatus] = useState<PrinterProfileStatus>()
  const [focusedId, setFocusedId] = useState<string>()
  const [editingId, setEditingId] = useState<string | 'new'>()
  const [form, setForm] = useState<PrinterProfileInput>(newProfile)
  const [codePages, setCodePages] = useState('437, 850, 858, 1252')
  const [busy, setBusy] = useState(false)
  const [message, setMessage] = useState<string>()
  const fileRef = useRef<HTMLInputElement>(null)

  async function refresh(preferredId?: string) {
    const next = await api.printerProfiles()
    setStatus(next)
    setFocusedId(current => preferredId ?? (current && next.profiles.some(profile => profile.id === current) ? current : next.selectedProfileId))
  }

  useEffect(() => { void refresh().catch(cause => setMessage(cause instanceof Error ? cause.message : 'Unable to load printer profiles.')) }, [])

  const focused = useMemo(() => status?.profiles.find(profile => profile.id === focusedId), [status, focusedId])

  function beginCreate() {
    setEditingId('new'); setForm(newProfile); setCodePages(newProfile.supportedCodePages.join(', ')); setMessage(undefined)
  }

  function beginEdit(profile: PrinterProfile) {
    setEditingId(profile.id); setForm(profileInput(profile)); setCodePages(profile.supportedCodePages.join(', ')); setMessage(undefined)
  }

  async function submit(event: FormEvent) {
    event.preventDefault(); setBusy(true); setMessage(undefined)
    try {
      const supportedCodePages = codePages.split(/[\s,;]+/).filter(Boolean).map(Number)
      const input = { ...form, supportedCodePages }
      const saved = editingId === 'new'
        ? await api.createPrinterProfile(input)
        : await api.updatePrinterProfile(editingId!, input)
      setEditingId(undefined); await refresh(saved.id); setMessage(`${saved.name} saved.`)
    } catch (cause) { setMessage(cause instanceof Error ? cause.message : 'The profile could not be saved.') }
    finally { setBusy(false) }
  }

  async function select(profile: PrinterProfile) {
    setBusy(true); setMessage(undefined)
    try { await api.selectPrinterProfile(profile.id); await refresh(profile.id); setMessage(`${profile.name} is now active for new and replayed jobs.`) }
    catch (cause) { setMessage(cause instanceof Error ? cause.message : 'The profile could not be selected.') }
    finally { setBusy(false) }
  }

  async function duplicate(profile: PrinterProfile) {
    setBusy(true); setMessage(undefined)
    try { const copy = await api.duplicatePrinterProfile(profile.id); await refresh(copy.id); beginEdit(copy) }
    catch (cause) { setMessage(cause instanceof Error ? cause.message : 'The profile could not be duplicated.') }
    finally { setBusy(false) }
  }

  async function remove(profile: PrinterProfile) {
    if (!window.confirm(`Delete ${profile.name}? Saved jobs keep their profile snapshot.`)) return
    setBusy(true); setMessage(undefined)
    try { await api.deletePrinterProfile(profile.id); await refresh(); setMessage(`${profile.name} deleted.`) }
    catch (cause) { setMessage(cause instanceof Error ? cause.message : 'The profile could not be deleted.') }
    finally { setBusy(false) }
  }

  async function exportProfile(profile: PrinterProfile) {
    setBusy(true); setMessage(undefined)
    try { saveBlob(await api.exportPrinterProfile(profile.id), `${profile.id}.ppeprofile`) }
    catch (cause) { setMessage(cause instanceof Error ? cause.message : 'The profile could not be exported.') }
    finally { setBusy(false) }
  }

  async function importProfile(event: ChangeEvent<HTMLInputElement>) {
    const file = event.target.files?.[0]; event.target.value = ''; if (!file) return
    setBusy(true); setMessage(undefined)
    try { const imported = await api.importPrinterProfile(file); await refresh(imported.id); setMessage(`${imported.name} imported.`) }
    catch (cause) { setMessage(cause instanceof Error ? cause.message : 'The profile could not be imported.') }
    finally { setBusy(false) }
  }

  if (!status) return <div className="printer-profile-loading"><span className="spin" /> Loading printer profiles…</div>

  return (
    <div className="settings-panel printer-profiles-settings">
      <div className="profile-heading">
        <div><h2>Printer profiles</h2><p>Model the paper, character, and command capabilities used for new and replayed receipts.</p></div>
        <div className="profile-heading-actions">
          <input ref={fileRef} type="file" accept=".ppeprofile,application/json" onChange={importProfile} hidden />
          <button className="secondary-action" onClick={() => fileRef.current?.click()} disabled={busy}><FileUp size={15} /> Import</button>
          <button className="primary-action" onClick={beginCreate} disabled={busy}><Plus size={15} /> New profile</button>
        </div>
      </div>

      {message && <div className="profile-message" role="status">{message}</div>}

      <div className="profile-workspace">
        <div className="profile-list" aria-label="Available printer profiles">
          {status.profiles.map(profile => {
            const active = status.selectedProfileId === profile.id
            return <button key={profile.id} className={`${focusedId === profile.id ? 'focused' : ''} ${active ? 'active' : ''}`} onClick={() => { setFocusedId(profile.id); setEditingId(undefined) }}>
              <span className="profile-list-icon"><Printer size={18} /></span>
              <span><strong>{profile.name}</strong><small>{profile.paperWidthMm} mm · {profile.printableDots} dots</small></span>
              {active ? <b><Check size={12} /> Active</b> : profile.builtIn ? <em>Built-in</em> : <em>Custom</em>}
            </button>
          })}
        </div>

        <div className="profile-detail">
          {editingId ? (
            <ProfileForm form={form} setForm={setForm} codePages={codePages} setCodePages={setCodePages} busy={busy} onSubmit={submit} onCancel={() => setEditingId(undefined)} />
          ) : focused ? (
            <>
              <div className="profile-detail-header"><div><span>{focused.builtIn ? 'Protected built-in profile' : 'Custom printer profile'}</span><h3>{focused.name}</h3><p>{focused.description || 'No description provided.'}</p></div></div>
              <dl className="profile-specs">
                <div><dt>Paper</dt><dd>{focused.paperWidthMm} mm</dd></div>
                <div><dt>Printable width</dt><dd>{focused.printableDots} dots</dd></div>
                <div><dt>Maximum image</dt><dd>{focused.maximumRasterWidthDots} × {focused.maximumRasterHeightDots} dots</dd></div>
                <div><dt>Font A</dt><dd>{focused.fontAColumns} columns</dd></div>
                <div><dt>Font B</dt><dd>{focused.fontBColumns} columns</dd></div>
                <div className="wide"><dt>Code pages</dt><dd>Default CP{focused.defaultCodePage} · {focused.supportedCodePages.map(page => `CP${page}`).join(', ')}</dd></div>
              </dl>
              <div className="profile-capability-list">{Object.entries(capabilityLabels).map(([key, label]) => <span className={focused.capabilities[key as keyof PrinterCapabilities] ? 'supported' : 'unsupported'} key={key}>{focused.capabilities[key as keyof PrinterCapabilities] ? <Check size={12} /> : <X size={12} />}{label}</span>)}</div>
              <div className="profile-actions">
                {status.selectedProfileId !== focused.id && <button className="primary-action" onClick={() => select(focused)} disabled={busy}><Check size={15} /> Use this profile</button>}
                <button className="secondary-action" onClick={() => duplicate(focused)} disabled={busy}><Copy size={15} /> Duplicate</button>
                <button className="secondary-action" onClick={() => exportProfile(focused)} disabled={busy}><Download size={15} /> Export</button>
                {!focused.builtIn && <button className="secondary-action" onClick={() => beginEdit(focused)} disabled={busy}><Pencil size={15} /> Edit</button>}
                {!focused.builtIn && <button className="danger-action" onClick={() => remove(focused)} disabled={busy}><Trash2 size={15} /> Delete</button>}
              </div>
            </>
          ) : null}
        </div>
      </div>
      <p className="settings-note">Built-in profiles remain protected. Duplicate one to create a customized version. Every saved job keeps the profile name and dimensions used when it was processed.</p>
    </div>
  )
}

function ProfileForm({ form, setForm, codePages, setCodePages, busy, onSubmit, onCancel }: {
  form: PrinterProfileInput; setForm: (value: PrinterProfileInput) => void
  codePages: string; setCodePages: (value: string) => void; busy: boolean
  onSubmit: (event: FormEvent) => void; onCancel: () => void
}) {
  const number = (key: 'paperWidthMm' | 'printableDots' | 'maximumRasterWidthDots' | 'maximumRasterHeightDots' | 'defaultCodePage' | 'fontAColumns' | 'fontBColumns', value: string) => setForm({ ...form, [key]: Number(value) })
  return <form className="profile-form" onSubmit={onSubmit}>
    <div className="profile-form-heading"><div><span>Custom profile</span><h3>{form.name || 'New printer profile'}</h3></div><button type="button" onClick={onCancel} aria-label="Cancel editing"><X size={17} /></button></div>
    <label className="wide">Profile name<input required minLength={2} maxLength={80} value={form.name} onChange={event => setForm({ ...form, name: event.target.value })} /></label>
    <label className="wide">Description<textarea rows={2} maxLength={300} value={form.description} onChange={event => setForm({ ...form, description: event.target.value })} /></label>
    <label>Paper width (mm)<input required type="number" min="40" max="120" value={form.paperWidthMm} onChange={event => number('paperWidthMm', event.target.value)} /></label>
    <label>Printable width (dots)<input required type="number" min="200" max="1024" value={form.printableDots} onChange={event => number('printableDots', event.target.value)} /></label>
    <label>Maximum image width<input required type="number" min="200" max={form.printableDots} value={form.maximumRasterWidthDots} onChange={event => number('maximumRasterWidthDots', event.target.value)} /></label>
    <label>Maximum image height<input required type="number" min="8" max="8192" value={form.maximumRasterHeightDots} onChange={event => number('maximumRasterHeightDots', event.target.value)} /></label>
    <label>Font A columns<input required type="number" min="20" max="96" value={form.fontAColumns} onChange={event => number('fontAColumns', event.target.value)} /></label>
    <label>Font B columns<input required type="number" min="20" max="128" value={form.fontBColumns} onChange={event => number('fontBColumns', event.target.value)} /></label>
    <label>Default code page<input required type="number" min="1" max="65535" value={form.defaultCodePage} onChange={event => number('defaultCodePage', event.target.value)} /></label>
    <label className="wide">Supported code pages<input required value={codePages} onChange={event => setCodePages(event.target.value)} placeholder="437, 850, 858, 1252" /><small>Comma-separated numeric Windows code-page identifiers.</small></label>
    <fieldset className="profile-capabilities"><legend>Command capabilities</legend>{Object.entries(capabilityLabels).map(([key, label]) => <label key={key}><input type="checkbox" checked={form.capabilities[key as keyof PrinterCapabilities]} onChange={event => setForm({ ...form, capabilities: { ...form.capabilities, [key]: event.target.checked } })} />{label}</label>)}</fieldset>
    <div className="profile-form-actions"><button type="button" className="secondary-action" onClick={onCancel}>Cancel</button><button type="submit" className="primary-action" disabled={busy}><Save size={15} /> {busy ? 'Saving…' : 'Save profile'}</button></div>
  </form>
}
