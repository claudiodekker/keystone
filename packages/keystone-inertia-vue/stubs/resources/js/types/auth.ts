export type InitiateShape = 'form' | 'clientCeremony' | 'redirect' | 'delivered';

export type Surface = 'sign-in' | 'challenge' | 'registration' | 'enrollment';

export type Purpose = 'sudo' | 'settings';

export type RemovableCredential = {
    id: number;
    label: string | null;
    removable: boolean;
};

export type CredentialTypeOption = {
    type: string;
    shape: InitiateShape;
    ceremony?: Record<string, string>;
    held?: RemovableCredential[];
};

export type SignInPage = {
    types: CredentialTypeOption[];
    status: string | null;
    identifier: string | null;
    rememberOffered: boolean;
    registrationOpen: boolean;
};

export type RegisterPage = {
    status: string | null;
    mailsLink: boolean;
    email: string | null;
};

export type EmailedLinkPage = {
    action: string;
};

export type RegisterFinishPage = {
    address: string;
    types: CredentialTypeOption[];
    status: string | null;
    name: string | null;
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
    held: RemovableCredential[];
    origin?: string | null;
};

export type RecoveryCodesPage = {
    codes: string[];
    origin: string;
};

export type RegenerateRecoveryCodesPage = {
    codes: string[];
    replaces: boolean;
    status: string | null;
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
    types: { type: string; enrollable: boolean; credentials: HeldCredential[] }[];
    leftovers: (HeldCredential & { type: string })[];
    recoveryCodes: number;
    recoveryCodesLow: boolean;
    sudoEndsAt: string | null;
    status: string | null;
    sessions: SessionRow[];
    sessionsStatus: string | null;
    offersSignOutOthers: boolean;
};

export type SessionRow = {
    handle: string;
    platform: string | null;
    browser: string | null;
    ipAddress: string | null;
    location: string | null;
    lastActiveAt: string;
    current: boolean;
};

export type CredentialRemovalPage = {
    id: number;
    type: string;
    label: string | null;
    listed: boolean;
};
