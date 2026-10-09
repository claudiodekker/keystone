import type { SessionRow } from '@/types/auth';

export const sessionName = (session: Pick<SessionRow, 'browser' | 'platform'>) => {
    if (session.browser && session.platform) {
        return `${session.browser} on ${session.platform}`;
    }

    return session.browser ?? session.platform ?? 'Unknown device';
};
