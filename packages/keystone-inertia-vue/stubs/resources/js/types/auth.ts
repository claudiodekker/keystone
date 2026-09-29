export type InitiateShape = 'form' | 'clientCeremony' | 'redirect' | 'delivered';

export type CredentialTypeOption = {
    type: string;
    shape: InitiateShape;
};

export type SignInPage = {
    types: CredentialTypeOption[];
    status: string | null;
};
