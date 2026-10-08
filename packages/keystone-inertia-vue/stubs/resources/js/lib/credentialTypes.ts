const typeNames: Record<string, string> = {
    password: 'Password',
    totp: 'Authenticator app',
};

export const typeName = (type: string) => typeNames[type] ?? type;
