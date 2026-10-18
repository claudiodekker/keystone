export type InitiateShape = 'clientCeremony' | 'delivered' | 'form' | 'redirect'

export type SignInType = {
  name: string
  shape: InitiateShape
}

export type SignInPage = {
  status: string | null
  types: SignInType[]
}
