import { useRef, useState, type FormEvent } from 'react'
import { ImageIcon, Trash2 } from 'lucide-react'
import { api } from './api'
import type { StoredGraphic } from './types'

export function StoredGraphicsSettings({ graphics, onChanged }: {
  graphics: StoredGraphic[]
  onChanged: () => Promise<void>
}) {
  const [keyCode, setKeyCode] = useState('00')
  const [name, setName] = useState('')
  const [file, setFile] = useState<File>()
  const [busy, setBusy] = useState(false)
  const [deletingKey, setDeletingKey] = useState<string>()
  const [confirmDeleteKey, setConfirmDeleteKey] = useState<string>()
  const [message, setMessage] = useState<string>()
  const fileInput = useRef<HTMLInputElement>(null)

  async function importGraphic(event: FormEvent) {
    event.preventDefault()
    const normalizedKey = keyCode.trim().toUpperCase()
    if (!/^[A-Z0-9]{2}$/.test(normalizedKey)) {
      setMessage('Enter the two-character Epson storage key, such as 00.')
      return
    }
    if (!file) {
      setMessage('Choose a PNG, JPEG, or WebP logo file.')
      return
    }

    setBusy(true)
    setMessage(undefined)
    try {
      await api.importStoredGraphic(normalizedKey, name.trim(), file)
      await onChanged()
      setKeyCode(normalizedKey)
      setName('')
      setFile(undefined)
      if (fileInput.current) fileInput.current.value = ''
      setMessage(`Stored logo ${normalizedKey} was imported successfully.`)
    } catch (cause) {
      setMessage(cause instanceof Error ? cause.message : 'The logo could not be imported.')
    } finally {
      setBusy(false)
    }
  }

  async function deleteGraphic(key: string) {
    if (confirmDeleteKey !== key) {
      setConfirmDeleteKey(key)
      return
    }
    setDeletingKey(key)
    setMessage(undefined)
    try {
      await api.deleteStoredGraphic(key)
      await onChanged()
      setConfirmDeleteKey(undefined)
      setMessage(`Stored logo ${key} was removed.`)
    } catch (cause) {
      setMessage(cause instanceof Error ? cause.message : 'The logo could not be removed.')
    } finally {
      setDeletingKey(undefined)
    }
  }

  return (
    <div className="settings-panel stored-graphics-settings">
      <div className="settings-status-card">
        <div className="settings-status-icon"><ImageIcon size={25} /></div>
        <div>
          <h2>Stored printer logos</h2>
          <p>Match an imported image to the two-character key sent by an Epson NV graphic command.</p>
        </div>
      </div>

      <form className="stored-graphic-form" onSubmit={importGraphic}>
        <div className="stored-graphic-fields">
          <label>
            Epson storage key
            <input value={keyCode} maxLength={2} onChange={event => setKeyCode(event.target.value.toUpperCase())} placeholder="00" />
            <small>The receipt in your example uses key 00.</small>
          </label>
          <label>
            Logo name
            <input value={name} maxLength={80} onChange={event => setName(event.target.value)} placeholder="Store logo (optional)" />
          </label>
        </div>
        <label className="stored-graphic-file">
          Image file
          <input ref={fileInput} type="file" accept="image/png,image/jpeg,image/webp" onChange={event => setFile(event.target.files?.[0])} />
          <small>PNG, JPEG, or WebP · maximum 2 MB. High-contrast monochrome artwork works best.</small>
        </label>
        <div className="settings-actions">
          <button className="primary-action" type="submit" disabled={busy}>{busy ? 'Importing…' : 'Import or replace logo'}</button>
        </div>
      </form>

      {message && <div className="stored-graphic-message" role="status">{message}</div>}

      <div className="stored-graphic-library">
        <div className="stored-graphic-library-heading">
          <h3>Imported logos</h3>
          <span>{graphics.length} saved</span>
        </div>
        {graphics.length === 0 ? (
          <div className="stored-graphic-empty">
            <ImageIcon size={25} />
            <p>No stored logos have been imported yet.</p>
          </div>
        ) : graphics.map(graphic => (
          <article className="stored-graphic-card" key={graphic.keyCode}>
            <div className="stored-graphic-thumbnail"><img src={graphic.contentUrl} alt={graphic.name} /></div>
            <div>
              <strong>{graphic.name}</strong>
              <span>Key {graphic.keyCode} · {formatBytes(graphic.size)}</span>
              <span>{graphic.fileName}</span>
            </div>
            <button
              className={confirmDeleteKey === graphic.keyCode ? 'confirm-delete' : ''}
              type="button"
              disabled={deletingKey === graphic.keyCode}
              onClick={() => void deleteGraphic(graphic.keyCode)}
              onBlur={() => setConfirmDeleteKey(current => current === graphic.keyCode ? undefined : current)}
            >
              <Trash2 size={14} /> {deletingKey === graphic.keyCode ? 'Removing…' : confirmDeleteKey === graphic.keyCode ? 'Confirm delete' : 'Remove'}
            </button>
          </article>
        ))}
      </div>
    </div>
  )
}

function formatBytes(value: number) {
  return value < 1024 ? `${value} B` : `${(value / 1024).toFixed(1)} KB`
}
