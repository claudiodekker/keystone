export type InitiateShape = 'form' | 'clientCeremony' | 'redirect' | 'delivered';

export type Surface = 'sign-in' | 'challenge' | 'enrollment';

export type Purpose = 'sudo';

export type CredentialTypeOption = {
    type: string;
    shape: InitiateShape;
    ceremony?: Record<string, string>;
};

export type SignInPage = {
    types: CredentialTypeOption[];
    status: string | null;
    identifier: string | null;
    rememberOffered: boolean;
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

export type SudoPage = {
    types: CredentialTypeOption[];
    preselect: string | null;
    surface: 'sign-in' | 'challenge';
};

export type HeldCredential = {
    id: number;
    label: string | null;
    addedAt: string | null;
    lastUsedAt: string | null;
    disabled: boolean;
};

export type SecurityPage = {
    types: { type: string; credentials: HeldCredential[] }[];
    leftovers: (HeldCredential & { type: string })[];
    recoveryCodes: number;
    recoveryCodesLow: boolean;
    sudoEndsAt: string | null;
    status: string | null;
};
