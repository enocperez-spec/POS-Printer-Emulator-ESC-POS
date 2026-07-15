import { useEffect, useState } from 'react'
import { AlertTriangle, CheckCircle2, ChevronLeft, ChevronRight, CircleHelp, LoaderCircle, Printer, RotateCw, Server, XCircle } from 'lucide-react'
import { api } from './api'
import type { PrinterInstallRequest, PrinterInstallResult, PrinterSetupStatus } from './types'

type DesktopBridge = { postMessage: (message: unknown) => void; addEventListener: (type: 'message', listener: (event: MessageEvent) => void) => void; removeEventListener: (type: 'message', listener: (event: MessageEvent) => void) => void }
const desktopBridge = () => (window as Window & { chrome?: { webview?: DesktopBridge } }).chrome?.webview

export function PrinterSetupWizard({ onCancel }: { onCancel: () => void }) {
  const [step, setStep] = useState(1)
  const [sameComputer, setSameComputer] = useState(true)
  const [ipAddress, setIpAddress] = useState('127.0.0.1')
  const [port] = useState(9100)
  const [printerName, setPrinterName] = useState('POS Printer Emulator')
  const [driver, setDriver] = useState<PrinterSetupStatus>()
  const [driverError, setDriverError] = useState<string>()
  const [result, setResult] = useState<PrinterInstallResult>()
  const [detailsVisible, setDetailsVisible] = useState(false)
  const [testMessage, setTestMessage] = useState<string>()

  useEffect(() => {
    api.printerSetupStatus().then(setDriver).catch(cause => setDriverError(cause instanceof Error ? cause.message : 'Driver status could not be checked.'))
  }, [])

  useEffect(() => {
    const bridge = desktopBridge()
    if (!bridge) return
    const listener = (event: MessageEvent) => {
      const message = event.data as { type?: string; result?: PrinterInstallResult; success?: boolean; message?: string }
      if (message.type === 'printer-install-result' && message.result) {
        setResult({ ...message.result, printerName: message.result.printerName || printerName, ipAddress: message.result.ipAddress || ipAddress, port: message.result.port || port, sameComputer, driverName: message.result.driverName || driver?.driverName || 'EPSON TM-T88V Receipt5' })
        setStep(6)
      } else if (message.type === 'printer-test-result') {
        setTestMessage(message.success ? 'The test receipt was sent to the emulator.' : message.message ?? 'The test receipt could not be sent.')
      }
    }
    bridge.addEventListener('message', listener)
    return () => bridge.removeEventListener('message', listener)
  }, [driver?.driverName, ipAddress, port, printerName, sameComputer])

  const request: PrinterInstallRequest = { printerName: printerName.trim(), ipAddress, port, sameComputer }
  const ipValid = /^(?:\d{1,3}\.){3}\d{1,3}$/.test(ipAddress) && ipAddress.split('.').every(part => Number(part) <= 255)
  const canContinue = step === 1 ? sameComputer || ipValid : step === 2 ? printerName.trim().length > 0 : step === 3 ? Boolean(driver) : true

  function chooseLocation(value: boolean) {
    setSameComputer(value)
    setIpAddress(value ? '127.0.0.1' : '')
  }

  function install() {
    const bridge = desktopBridge()
    if (!bridge) {
      setResult({ ...request, success: false, driverName: driver?.driverName ?? 'EPSON TM-T88V Receipt5', message: 'Open this wizard in the POS Printer Emulator Windows desktop application to install a printer.', technicalDetails: 'The Windows desktop bridge was not available.' })
      setStep(6)
      return
    }
    setResult(undefined)
    setStep(5)
    bridge.postMessage({ type: 'install-printer', printer: request })
  }

  function retry() { setDetailsVisible(false); setResult(undefined); setStep(4) }
  function printTest() { setTestMessage('Sending test receipt…'); desktopBridge()?.postMessage({ type: 'print-printer-test', printerName }) }

  return (
    <div className="printer-wizard">
      <div className="wizard-progress" aria-label={`Step ${step} of 6`}>
        {Array.from({ length: 6 }, (_, index) => <span key={index} className={index + 1 <= step ? 'active' : ''} />)}
      </div>
      <div className="wizard-step-label">Step {step} of 6</div>

      {step === 1 && <div className="wizard-page">
        <div className="wizard-heading"><Server size={28} /><div><h2>Where is your POS software?</h2><p>We’ll choose the correct connection automatically.</p></div></div>
        <fieldset className="choice-cards"><legend>Is the POS software installed on this computer?</legend>
          <label className={sameComputer ? 'selected' : ''}><input type="radio" checked={sameComputer} onChange={() => chooseLocation(true)} /><strong>Yes, this computer</strong><span>Recommended for a single-computer setup</span></label>
          <label className={!sameComputer ? 'selected' : ''}><input type="radio" checked={!sameComputer} onChange={() => chooseLocation(false)} /><strong>No, another computer</strong><span>The POS sends receipts across your local network</span></label>
        </fieldset>
        {!sameComputer && <label className="wizard-field">Emulator computer IP address<input value={ipAddress} onChange={event => setIpAddress(event.target.value)} placeholder="Example: 192.168.1.25" inputMode="decimal" /><small>On the computer running this emulator, open Windows Settings → Network & internet to find its IPv4 address.</small>{ipAddress && !ipValid && <em>Enter a valid IPv4 address.</em>}</label>}
        <div className="automatic-value"><span>Connection port</span><strong>9100 · selected automatically</strong></div>
      </div>}

      {step === 2 && <div className="wizard-page">
        <div className="wizard-heading"><Printer size={28} /><div><h2>Name the Windows printer</h2><p>Your POS software will use this name when selecting a printer.</p></div></div>
        <label className="wizard-field">Printer name<input autoFocus value={printerName} maxLength={120} onChange={event => setPrinterName(event.target.value)} /></label>
        <div className="wizard-tip"><CircleHelp size={18} /><p>The default name works for most customers. Change it only if your POS provider requires a specific name.</p></div>
      </div>}

      {step === 3 && <div className="wizard-page">
        <div className="wizard-heading"><RotateCw size={28} /><div><h2>Epson driver check</h2><p>Checking the Windows components needed by the emulated printer.</p></div></div>
        {!driver && !driverError && <div className="driver-checking"><LoaderCircle className="spin" size={28} /> Checking this computer…</div>}
        {driverError && <div className="wizard-alert error"><XCircle size={20} /><div><strong>Driver check could not finish</strong><p>{driverError}</p></div></div>}
        {driver && <>
          <div className={`wizard-alert ${driver.driverInstalled ? 'success' : driver.driverPackageAvailable ? 'info' : 'error'}`}>
            {driver.driverInstalled ? <CheckCircle2 size={22} /> : <AlertTriangle size={22} />}
            <div><strong>{driver.driverInstalled ? 'Required Epson driver is installed' : driver.driverPackageAvailable ? 'Driver will be installed automatically' : 'Driver package is unavailable'}</strong><p>{driver.message}</p></div>
          </div>
          <dl className="driver-versions">
            <div><dt>EPSON Advanced Printer Driver</dt><dd>{driver.apdVersion ?? 'Not installed'} <small>recommended {driver.recommendedApdVersion}</small></dd></div>
            <div><dt>Windows printer driver</dt><dd>{driver.driverName}<small>{driver.driverVersion ?? `recommended ${driver.recommendedDriverVersion}`}</small></dd></div>
            <div><dt>EPSON Status API</dt><dd>{driver.statusApiVersion ?? 'Not detected'} <small>tested {driver.recommendedStatusApiVersion}</small></dd></div>
          </dl>
          {driver.driverInstalled && driver.statusApiVersion !== driver.recommendedStatusApiVersion && <p className="compatibility-note">The core printer driver is ready. A different Status API version was detected; this does not prevent receipt emulation.</p>}
        </>}
      </div>}

      {step === 4 && <div className="wizard-page">
        <div className="wizard-heading"><CheckCircle2 size={28} /><div><h2>Ready to install</h2><p>Review the configuration before Windows asks for administrator approval.</p></div></div>
        <dl className="setup-summary">
          <div><dt>Printer name</dt><dd>{printerName}</dd></div><div><dt>IP address</dt><dd>{ipAddress}</dd></div><div><dt>Port</dt><dd>{port}</dd></div>
          <div><dt>Epson driver</dt><dd>{driver?.driverName}</dd></div><div><dt>Driver installation</dt><dd>{driver?.driverInstalled ? 'Already installed' : 'Install automatically'}</dd></div>
          <div><dt>POS location</dt><dd>{sameComputer ? 'Same computer as emulator' : 'Another computer'}</dd></div>
        </dl>
      </div>}

      {step === 5 && <div className="wizard-page wizard-installing"><LoaderCircle className="spin" size={42} /><h2>Installing your printer…</h2><p>Approve the Windows security prompt if it appears. Keep this window open while the Epson driver, printer port, and Windows printer are configured.</p><div className="installation-list"><span>Checking Epson components</span><span>Creating the printer connection</span><span>Adding and verifying the Windows printer</span></div></div>}

      {step === 6 && result && <div className="wizard-page">
        <div className={`result-hero ${result.success ? 'success' : 'error'}`}>{result.success ? <CheckCircle2 size={42} /> : <XCircle size={42} />}<div><h2>{result.success ? 'Printer Installed Successfully' : 'Printer installation did not finish'}</h2><p>{result.message}</p></div></div>
        {result.success ? <>
          <dl className="setup-summary compact"><div><dt>Printer</dt><dd>{result.printerName}</dd></div><div><dt>Connection</dt><dd>{result.ipAddress}:{result.port}</dd></div><div><dt>Driver</dt><dd>{result.driverName}</dd></div></dl>
          {testMessage && <p className="test-message">{testMessage}</p>}
        </> : <>
          <p className="rollback-note">Any incomplete printer or port created by this attempt was removed automatically.</p>
          {result.technicalDetails && <><button className="details-toggle" onClick={() => setDetailsVisible(value => !value)}>{detailsVisible ? 'Hide' : 'View'} troubleshooting details</button>{detailsVisible && <pre className="technical-details">{result.technicalDetails}</pre>}</>}
        </>}
      </div>}

      {step !== 5 && <footer className="wizard-actions">
        <button className="secondary-action" onClick={onCancel}>{step === 6 ? 'Finish' : 'Cancel'}</button>
        <div>
          {step > 1 && step < 5 && step !== 6 && <button className="secondary-action" onClick={() => setStep(value => value - 1)}><ChevronLeft size={16} /> Back</button>}
          {step < 4 && <button className="primary-action" disabled={!canContinue} onClick={() => setStep(value => value + 1)}>Continue <ChevronRight size={16} /></button>}
          {step === 4 && <button className="primary-action" disabled={!driver || (!driver.driverInstalled && !driver.driverPackageAvailable)} onClick={install}><Printer size={16} /> Install Printer</button>}
          {step === 6 && result?.success && <button className="primary-action" onClick={printTest}><Printer size={16} /> Print Test Receipt</button>}
          {step === 6 && !result?.success && <button className="primary-action" onClick={retry}><RotateCw size={16} /> Retry Installation</button>}
        </div>
      </footer>}
    </div>
  )
}
