export type FeatureStatus = {
  history: boolean
  exports: boolean
  premiumFeatures: boolean
  watermark: boolean
  storedLogos: boolean
  printerState: boolean
  printerProfiles: boolean
  updates: boolean
  support: boolean
}

export type LicenseStatus = {
  mode: 'Trial' | 'Pro' | 'Enterprise'
  hasProAccess: boolean
  isEnterprise: boolean
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

export type StoredGraphic = {
  keyCode: string
  name: string
  fileName: string
  contentType: string
  size: number
  updatedAt: string
  contentUrl: string
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

export type PrinterCapabilities = {
  cutter: boolean
  cashDrawer: boolean
  rasterImages: boolean
  nvGraphics: boolean
  barcodes: boolean
  qrCodes: boolean
  twoColor: boolean
  dleEotStatus: boolean
  automaticStatusBack: boolean
}

export type PrinterProfile = {
  id: string
  name: string
  description: string
  builtIn: boolean
  paperWidthMm: number
  printableDots: number
  maximumRasterWidthDots: number
  maximumRasterHeightDots: number
  defaultCodePage: number
  supportedCodePages: number[]
  fontAColumns: number
  fontBColumns: number
  capabilities: PrinterCapabilities
}

export type PrinterProfileInput = Omit<PrinterProfile, 'id' | 'builtIn'>

export type PrinterProfileStatus = {
  selectedProfileId: string
  profiles: PrinterProfile[]
}

export type JobSummary = {
  id: string
  receivedAt: string
  sourceIp: string
  payloadSize: number
  status: string
  unsupportedCount: number
  preview: string
  origin: 'Live' | 'Imported' | 'Replayed'
  rendererVersion: string
  parentJobId?: string
  importedFileName?: string
  profileId: string
  profileName: string
  profilePaperWidthMm: number
  profilePrintableDots: number
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
  originalReceivedAt?: string
  originalSourceIp?: string
  capturedProfileId?: string
}
