import { useRef, useState, type ChangeEvent } from 'react'
import { AlertTriangle, CheckCircle2, CircleHelp, DatabaseBackup, Eye, FileArchive, LockKeyhole, RotateCcw, ShieldCheck, Upload } from 'lucide-react'
import { api } from './api'
import type { BackupPreferences, ConfigurationBackupPreview, ConfigurationRestoreResult, LicenseStatus } from './types'

type Props = {
  license: LicenseStatus
  preferences: BackupPreferences
  onRestored: (preferences: BackupPreferences) => Promise<void>
}

function saveBackup(blob: Blob) {
  const timestamp = new Date().toISOString().replaceAll(':', '').replaceAll('-', '').slice(0, 15)
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = `pos-printer-emulator-${timestamp}.ppebackup`
  document.body.appendChild(link)
  link.click()
  link.remove()
  window.setTimeout(() => URL.revokeObjectURL(url), 1_000)
}

function displayDate(value: string) {
  return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value))
}

export function BackupRestoreSettings({ license, preferences, onRestored }: Props) {
  const [backupPassword, setBackupPassword] = useState('')
  const [confirmPassword, setConfirmPassword] = useState('')
  const [includeHistory, setIncludeHistory] = useState(false)
  const [creating, setCreating] = useState(false)
  const [createMessage, setCreateMessage] = useState<string>()
  const [file, setFile] = useState<File>()
  const [restorePassword, setRestorePassword] = useState('')
  const [preview, setPreview] = useState<ConfigurationBackupPreview>()
  const [result, setResult] = useState<ConfigurationRestoreResult>()
  const [inspecting, setInspecting] = useState(false)
  const [restoring, setRestoring] = useState(false)
  const [confirmed, setConfirmed] = useState(false)
  const [restoreMessage, setRestoreMessage] = useState<string>()
  const fileInput = useRef<HTMLInputElement>(null)

  const backupPasswordValid = backupPassword.length >= 10 && backupPassword === confirmPassword
  const canInspect = file !== undefined && restorePassword.length >= 10 && !inspecting && !restoring

  async function createBackup() {
    if (!backupPasswordValid) return
    setCreating(true)
    setCreateMessage(undefined)
    try {
      const blob = await api.createConfigurationBackup({
        password: backupPassword,
        includeHistory: includeHistory && license.isPaid,
        preferences,
      })
      saveBackup(blob)
      setCreateMessage('Encrypted backup created. Keep the file and password in a safe place.')
      setBackupPassword('')
      setConfirmPassword('')
    } catch (cause) {
      setCreateMessage(cause instanceof Error ? cause.message : 'The backup could not be created.')
    } finally {
      setCreating(false)
    }
  }

  function chooseFile(event: ChangeEvent<HTMLInputElement>) {
    const next = event.target.files?.[0]
    setFile(next)
    setPreview(undefined)
    setResult(undefined)
    setConfirmed(false)
    setRestoreMessage(undefined)
  }

  async function inspectBackup() {
    if (!file || !canInspect) return
    setInspecting(true)
    setRestoreMessage(undefined)
    setPreview(undefined)
    setResult(undefined)
    setConfirmed(false)
    try {
      setPreview(await api.inspectConfigurationBackup(file, restorePassword))
    } catch (cause) {
      setRestoreMessage(cause instanceof Error ? cause.message : 'The backup could not be inspected.')
    } finally {
      setInspecting(false)
    }
  }

  async function restoreBackup() {
    if (!file || !preview || !confirmed) return
    setRestoring(true)
    setRestoreMessage(undefined)
    try {
      const restored = await api.restoreConfigurationBackup(file, restorePassword)
      setResult(restored)
      setPreview(undefined)
      setConfirmed(false)
      setRestorePassword('')
      await onRestored(restored.preferences)
    } catch (cause) {
      setRestoreMessage(cause instanceof Error ? cause.message : 'The backup could not be restored.')
    } finally {
      setRestoring(false)
    }
  }

  return (
    <div className="backup-settings">
      <section className="settings-status-card backup-hero">
        <div className="settings-status-icon"><DatabaseBackup size={25} /></div>
        <div><h3>Backup &amp; Restore</h3><p>Create one encrypted, portable file containing your POS Printer Emulator configuration.</p></div>
      </section>

      <div className="backup-security-note">
        <ShieldCheck size={18} />
        <p><strong>Private by design.</strong> Activation keys, maintenance keys, registration details, credentials, logs, Windows drivers, and printer queues are never exported.</p>
      </div>

      <section className="backup-card">
        <header><div><FileArchive size={19} /><div><h4>Create encrypted backup</h4><p>Configuration is included by default. Receipt history is optional.</p></div></div></header>
        <div className="backup-fields">
          <label>Backup password<input type="password" value={backupPassword} onChange={event => setBackupPassword(event.target.value)} autoComplete="new-password" placeholder="At least 10 characters" /></label>
          <label>Confirm password<input type="password" value={confirmPassword} onChange={event => setConfirmPassword(event.target.value)} autoComplete="new-password" placeholder="Enter the password again" /></label>
        </div>
        {confirmPassword && backupPassword !== confirmPassword && <p className="backup-inline-error">The passwords do not match.</p>}
        <label className={`backup-check ${!license.isPaid ? 'disabled' : ''}`}>
          <input type="checkbox" checked={includeHistory && license.isPaid} disabled={!license.isPaid} onChange={event => setIncludeHistory(event.target.checked)} />
          <span><strong>Include local receipt history</strong><small>{license.isPaid ? 'May contain customer receipt data and make the backup much larger.' : 'Receipt history backup requires a Lite, Pro, or Enterprise License.'}</small></span>
        </label>
        <p className="backup-password-warning"><LockKeyhole size={15} /> The password cannot be recovered. Without it, the backup cannot be opened.</p>
        <div className="backup-actions"><button className="primary-action" onClick={createBackup} disabled={!backupPasswordValid || creating}>{creating ? 'Creating backup…' : 'Create Backup'}</button></div>
        {createMessage && <p className={createMessage.startsWith('Encrypted') ? 'backup-success' : 'backup-error'}>{createMessage}</p>}
      </section>

      <section className="backup-card">
        <header><div><RotateCcw size={19} /><div><div className="backup-title-with-help"><h4>Restore from backup</h4><span className="backup-help" tabIndex={0} aria-label="How to restore a backup"><CircleHelp size={16} /><span className="backup-help-tooltip" role="tooltip"><strong>How to restore</strong><span>1. Do not extract the backup in Windows.</span><span>2. Choose the .ppebackup file and enter its password.</span><span>3. Select Review Backup, confirm the contents, then restore.</span><small>Files ending in .ppebackup.zip from version 0.3.34 are also accepted.</small></span></span></div><p>Review every category and warning before anything changes.</p></div></div></header>
        <input ref={fileInput} className="visually-hidden" type="file" accept=".ppebackup,.ppebackup.zip,application/vnd.pos-printer-emulator.backup" onChange={chooseFile} />
        <button className="backup-file-button" onClick={() => fileInput.current?.click()}><Upload size={16} /> {file ? file.name : 'Choose .ppebackup file'}</button>
        <label className="backup-restore-password">Backup password<input type="password" value={restorePassword} onChange={event => { setRestorePassword(event.target.value); setPreview(undefined); setConfirmed(false) }} autoComplete="current-password" placeholder="Password used when the backup was created" /></label>
        <div className="backup-actions"><button onClick={inspectBackup} disabled={!canInspect}><Eye size={16} /> {inspecting ? 'Inspecting…' : 'Review Backup'}</button></div>
        {restoreMessage && <p className="backup-error">{restoreMessage}</p>}

        {preview && (
          <div className="backup-preview">
            <div className="backup-preview-heading"><CheckCircle2 size={20} /><div><strong>Backup verified</strong><span>Created {displayDate(preview.createdAt)} with version {preview.applicationVersion}</span></div></div>
            <dl>
              <div><dt>Printer listeners</dt><dd>{preview.printerListenerCount}</dd></div>
              <div><dt>Custom profiles</dt><dd>{preview.printerProfileCount}</dd></div>
              <div><dt>Stored logos</dt><dd>{preview.storedLogoCount}</dd></div>
              <div><dt>Receipt jobs</dt><dd>{preview.receiptJobCount}</dd></div>
            </dl>
            <div className="backup-category-columns">
              <div><strong>Will restore</strong><ul>{preview.includedData.map(item => <li key={item}>{item}</li>)}</ul></div>
              <div><strong>Never included</strong><ul>{preview.excludedData.map(item => <li key={item}>{item}</li>)}</ul></div>
            </div>
            {preview.warnings.length > 0 && <div className="backup-warnings"><AlertTriangle size={17} /><ul>{preview.warnings.map(warning => <li key={warning}>{warning}</li>)}</ul></div>}
            <label className="backup-check confirmation"><input type="checkbox" checked={confirmed} onChange={event => setConfirmed(event.target.checked)} /><span><strong>Replace the current configuration</strong><small>A safety snapshot will be created first. If restore fails, the current configuration will be restored automatically.</small></span></label>
            <div className="backup-actions"><button className="danger-action" onClick={restoreBackup} disabled={!confirmed || restoring}>{restoring ? 'Restoring…' : 'Restore Configuration'}</button></div>
          </div>
        )}

        {result && (
          <div className="backup-result">
            <CheckCircle2 size={22} />
            <div><h4>Configuration restored successfully</h4><p>{result.restoredListeners} listener configurations, {result.restoredProfiles} custom profiles, {result.restoredLogos} stored logos, and {result.restoredReceiptJobs} receipt jobs were restored.</p><small>Safety snapshot: {result.safetySnapshotId}</small></div>
          </div>
        )}
      </section>
    </div>
  )
}
