export type InitiateShape = 'form' | 'clientCeremony' | 'redirect' | 'delivered';

export type Surface = 'sign-in' | 'challenge';

export type CredentialTypeOption = {
    type: string;
    shape: InitiateShape;
};

export type SignInPage = {
    types: CredentialTypeOption[];
    status: string | null;
};

export type ChallengePage = {
    types: CredentialTypeOption[];
    preselect: string;
};
