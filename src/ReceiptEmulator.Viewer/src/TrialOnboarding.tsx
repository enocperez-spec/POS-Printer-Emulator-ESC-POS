import { CheckCircle2, Copy, FlaskConical, LifeBuoy, LockKeyhole, Network, Printer, Server, X } from 'lucide-react'
import { useState } from 'react'
import type { PrinterListener, ServiceStatus } from './types'

function portFromStatus(status: ServiceStatus) {
  const parsed = Number(status.listener.split(':').at(-1))
  return Number.isInteger(parsed) && parsed > 0 ? parsed : 9100
}

export function TrialOnboarding({ status, listener, onSetup, onTestReceipt, onTroubleshoot, onDismiss }: {
  status: ServiceStatus
  listener?: PrinterListener
  onSetup: () => void
  onTestReceipt: () => Promise<void>
  onTroubleshoot: () => void
  onDismiss: () => void
}) {
  const [copied, setCopied] = useState(false)
  const port = listener?.port ?? portFromStatus(status)
  const localEndpoint = `127.0.0.1:${port}`
  const networkAddress = listener?.connectionAddress
  const networkEndpoint = networkAddress && networkAddress !== '127.0.0.1'
    ? `${networkAddress}:${port}`
    : undefined
  const listenerReady = listener?.listening ?? status.listening
  const listenerName = listener?.name ?? 'POS Printer Emulator'

  async function copyConnection() {
    const details = [
      `Printer listener: ${listenerName}`,
      `POS on this computer: ${localEndpoint}`,
      networkEndpoint ? `POS on another computer: ${networkEndpoint}` : undefined,
      'Protocol: RAW TCP / ESC-POS',
    ].filter(Boolean).join('\n')
    try {
      await navigator.clipboard.writeText(details)
      setCopied(true)
      window.setTimeout(() => setCopied(false), 1_800)
    } catch {
      setCopied(false)
    }
  }

  return (
    <div className="modal-backdrop onboarding-backdrop" role="presentation">
      <section className="onboarding-dialog" role="dialog" aria-modal="true" aria-labelledby="trial-welcome-title">
        <button className="dialog-close" onClick={onDismiss} aria-label="Close welcome guide"><X size={19} /></button>
        <div className="onboarding-kicker">First-launch Trial setup</div>
        <div className="onboarding-hero">
          <div className="onboarding-icon"><Printer size={34} /></div>
          <div>
            <h1 id="trial-welcome-title">Connect your POS in two steps</h1>
            <p>Your Trial already includes one printer listener. First install the Windows printer, then point your POS to the listener shown below.</p>
          </div>
        </div>

        <div className="onboarding-flow">
          <section className="onboarding-step-card is-action">
            <div className="onboarding-step-number">1</div>
            <div className="onboarding-step-content">
              <span className="onboarding-step-label">Install the Windows printer</span>
              <h2>Run the Printer Setup Wizard</h2>
              <p>The wizard installs the Epson driver, creates the Windows TCP/IP printer, and connects it to the included listener.</p>
              <button className="primary-action" onClick={onSetup}><Printer size={17} /> Set up your printer</button>
            </div>
          </section>

          <section className="onboarding-step-card">
            <div className="onboarding-step-number">2</div>
            <div className="onboarding-step-content">
              <span className="onboarding-step-label">Configure your POS software</span>
              <div className="onboarding-listener-heading">
                <div><h2>{listenerName}</h2><span><LockKeyhole size={12} /> Included Trial listener · read-only</span></div>
                <span className={`listener-status ${listenerReady ? 'status-running' : 'status-stopped'}`}><i />{listenerReady ? 'Running' : 'Stopped'}</span>
              </div>
              <p>In your POS printer settings, select RAW TCP/ESC-POS and enter the address that matches where the POS is running.</p>
              <div className="onboarding-endpoints">
                <div><Server size={16} /><span><small>POS on this computer</small><strong>{localEndpoint}</strong></span></div>
                <div><Network size={16} /><span><small>POS on another computer</small><strong>{networkEndpoint ?? 'Network address unavailable'}</strong></span></div>
              </div>
              <div className="onboarding-pos-note"><strong>Important:</strong> Your POS must send print jobs to one of these exact addresses and port {port}.</div>
              <button className="onboarding-copy" onClick={() => void copyConnection()}><Copy size={15} />{copied ? 'Copied connection details' : 'Copy connection details'}</button>
            </div>
          </section>
        </div>

        <div className={`onboarding-listener ${listenerReady ? 'ready' : 'attention'}`}>
          <span className="state-dot" />
          <div><strong>{listenerReady ? `Your included listener is ready on port ${port}.` : 'Your included listener needs attention.'}</strong><span>The Trial listener is visible under Settings → Printer Listeners, but its configuration cannot be changed.</span></div>
          {listenerReady && <CheckCircle2 size={20} />}
        </div>

        <div className="onboarding-test-row">
          <div><FlaskConical size={19} /><span><strong>Want to confirm the viewer works?</strong><small>The built-in Test Receipt is unlimited and does not use your five daily Trial POS jobs.</small></span></div>
          <button onClick={() => void onTestReceipt()}><FlaskConical size={16} /> Print Test Receipt</button>
        </div>

        <footer className="onboarding-footer">
          <button onClick={onTroubleshoot}><LifeBuoy size={15} /> Troubleshoot Connection</button>
          <span><Network size={14} /> Need this guide again? Select <strong>Trial setup</strong> in the top bar.</span>
        </footer>
      </section>
    </div>
  )
}
