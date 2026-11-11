export type InitiateShape = 'clientCeremony' | 'delivered' | 'form' | 'redirect'

export type SignInType = {
  name: string
  shape: InitiateShape
}

export type SignInPage = {
  remember: boolean
  status: string | null
  types: SignInType[]
}

export type ChallengeType = {
  name: string
  shape: InitiateShape
}

export type ChallengePage = {
  preselected: string | null
  types: ChallengeType[]
}

export type EnrollmentType = {
  name: string
  shape: InitiateShape
  ceremony: Record<string, string> | null
}

export type EnrollmentPage = {
  preselected: string | null
  types: EnrollmentType[]
}

export type RecoveryCodesPage = {
  codes: string[]
}
