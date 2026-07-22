import { CheckCircle2, FlaskConical, LifeBuoy, Network, Printer, X } from 'lucide-react'
import type { ServiceStatus } from './types'

export function TrialOnboarding({ status, onSetup, onTestReceipt, onTroubleshoot, onDismiss }: {
  status: ServiceStatus
  onSetup: () => void
  onTestReceipt: () => Promise<void>
  onTroubleshoot: () => void
  onDismiss: () => void
}) {
  const listenerReady = status.listening
  const connectionEndpoint = status.listener.replace(/^0\.0\.0\.0:/, '127.0.0.1:')
  return (
    <div className="modal-backdrop onboarding-backdrop" role="presentation">
      <section className="onboarding-dialog" role="dialog" aria-modal="true" aria-labelledby="trial-welcome-title">
        <button className="dialog-close" onClick={onDismiss} aria-label="Close welcome guide"><X size={19} /></button>
        <div className="onboarding-kicker">Your Trial is ready</div>
        <div className="onboarding-hero">
          <div className="onboarding-icon"><Printer size={34} /></div>
          <div>
            <h1 id="trial-welcome-title">Welcome to POS Printer Emulator</h1>
            <p>Connect your POS in a few guided steps, or print an unlimited built-in Test Receipt first.</p>
          </div>
        </div>
        <div className={`onboarding-listener ${listenerReady ? 'ready' : 'attention'}`}>
          <span className="state-dot" />
          <div><strong>{listenerReady ? `POS Printer Emulator is listening on ${connectionEndpoint}.` : 'Trial listener needs attention'}</strong><span>One listener is included with Trial</span></div>
          {listenerReady && <CheckCircle2 size={20} />}
        </div>
        <div className="onboarding-actions">
          <button className="primary-action" onClick={onSetup}><Printer size={18} /><span><strong>Set Up Trial Printer</strong><small>Install and connect the Windows receipt printer</small></span></button>
          <button onClick={() => void onTestReceipt()}><FlaskConical size={18} /><span><strong>Print a Test Receipt</strong><small>Unlimited and never uses your five daily POS jobs</small></span></button>
        </div>
        <ol className="onboarding-steps">
          <li><span>1</span><div><strong>Confirm where your POS runs</strong><small>This computer or another computer on your network</small></div></li>
          <li><span>2</span><div><strong>Install the Trial printer</strong><small>The wizard handles the Epson driver, TCP/IP port, and Windows printer</small></div></li>
          <li><span>3</span><div><strong>Send up to five complete POS jobs daily</strong><small>Built-in Test Receipts remain unlimited</small></div></li>
        </ol>
        <footer className="onboarding-footer">
          <button onClick={onTroubleshoot}><LifeBuoy size={15} /> Troubleshoot Connection</button>
          <span><Network size={14} /> Remote POS testing works with this one Trial listener; additional listeners require Pro or Enterprise.</span>
        </footer>
      </section>
    </div>
  )
}
