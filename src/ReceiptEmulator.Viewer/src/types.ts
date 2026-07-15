export type FeatureStatus = {
  history: boolean
  exports: boolean
  premiumFeatures: boolean
  watermark: boolean
}

export type LicenseStatus = {
  mode: string
  isFull: boolean
  dailyLimit: number
  usedToday: number
  remaining: number
  localDate: string
  customerName: string
  emailAddress: string
  licenseId?: string
  features: FeatureStatus
}

export type ServiceStatus = {
  listening: boolean
  listener: string
  lastConnection?: string
  version: string
  license: LicenseStatus
}

export type ActivationRequest = {
  customerName: string
  emailAddress: string
  activationKey: string
}

export type UpdateStatus = {
  currentVersion: string
  latestVersion?: string
  updateAvailable: boolean
  checkSucceeded: boolean
  releaseUrl?: string
  downloadUrl?: string
  checkedAt: string
  message: string
}

export type PrinterSetupStatus = {
  isWindows: boolean
  driverInstalled: boolean
  apdVersion?: string
  driverVersion?: string
  statusApiVersion?: string
  driverName: string
  recommendedApdVersion: string
  recommendedDriverVersion: string
  recommendedStatusApiVersion: string
  driverPackageAvailable: boolean
  message: string
}

export type PrinterInstallRequest = {
  printerName: string
  ipAddress: string
  port: number
  sameComputer: boolean
}

export type PrinterInstallResult = PrinterInstallRequest & {
  success: boolean
  message: string
  driverName: string
  technicalDetails?: string
}

export type PaperStatus = 'Ready' | 'Low' | 'Out'

export type PrinterStateUpdate = {
  online: boolean
  paperStatus: PaperStatus
  coverOpen: boolean
  cutterError: boolean
  recoverableError: boolean
  unrecoverableError: boolean
  autoRecoverableError: boolean
  drawerOpen: boolean
}

export type PrinterStateStatus = PrinterStateUpdate & {
  effectiveOnline: boolean
  summary: string
  responsesSent: number
  asbConnections: number
  lastStatusQuery?: string
  dleEotSupported: boolean
  asbSupported: boolean
}

export type JobSummary = {
  id: string
  receivedAt: string
  sourceIp: string
  payloadSize: number
  status: string
  unsupportedCount: number
  preview: string
}

export type ReceiptSpan = {
  text: string
  bold: boolean
  underline: boolean
  width: number
  height: number
  inverted?: boolean
  rotated?: boolean
  upsideDown?: boolean
  color?: 'black' | 'red'
  font?: 'A' | 'B'
}

export type ReceiptLine = {
  alignment: 'left' | 'center' | 'right'
  spans: ReceiptSpan[]
  kind: 'text' | 'barcode' | 'qr' | 'image'
  data?: string
}

export type ParsedCommand = {
  offset: number
  hex: string
  name: string
  details: string
  supported: boolean
}

export type ReceiptJob = JobSummary & {
  lines: ReceiptLine[]
  commands: ParsedCommand[]
  plainText: string
  hex: string[]
}
