export type TrialStatus = {
  mode: string
  dailyLimit: number
  usedToday: number
  remaining: number
  localDate: string
}

export type ServiceStatus = {
  listening: boolean
  listener: string
  lastConnection?: string
  version: string
  trial: TrialStatus
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
  kind: 'text' | 'barcode' | 'qr'
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
