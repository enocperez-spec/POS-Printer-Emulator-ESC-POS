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
