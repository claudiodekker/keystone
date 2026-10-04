export type InitiateShape = 'form' | 'clientCeremony' | 'redirect' | 'delivered';

export type Surface = 'sign-in' | 'challenge' | 'enrollment';

export type CredentialTypeOption = {
    type: string;
    shape: InitiateShape;
    ceremony?: Record<string, string>;
};

export type SignInPage = {
    types: CredentialTypeOption[];
    status: string | null;
    identifier: string | null;
};

export type ChallengePage = {
    types: CredentialTypeOption[];
    preselect: string;
};

export type EnrollmentPage = {
    types: CredentialTypeOption[];
    preselect: string | null;
    origin: string;
};

export type EnrollmentFormPage = {
    type: string;
    shape: InitiateShape;
    ceremony: Record<string, string>;
    status: string | null;
};

export type RecoveryCodesPage = {
    codes: string[];
};
