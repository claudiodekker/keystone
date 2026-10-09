<script setup lang="ts">
import { Form, Head, Link, usePage } from '@inertiajs/vue3';
import { typeName } from '@/lib/credentialTypes';
import { sessionName } from '@/lib/sessions';
import { enroll } from '@/routes/security';
import { remove } from '@/routes/security/credentials';
import { revoke } from '@/routes/security/sessions';
import { revoke as revokeOthersStep } from '@/routes/security/sessions/others';
import { submit as revokeOthers } from '@/routes/security/sessions/others/revoke';
import { end } from '@/routes/sudo';
import type { SecurityPage } from '@/types/auth';

defineProps<SecurityPage>();

const page = usePage<{ errors: Partial<Record<string, string>> }>();

const date = (value: string | null) => (value === null ? 'never' : new Date(value).toLocaleString());

const time = (value: string) => new Date(value).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
</script>

<template>
    <Head title="Security settings" />

    <div class="flex min-h-svh justify-center bg-gray-50 p-6">
        <main class="flex w-full max-w-2xl flex-col gap-8">
            <h1 class="text-xl font-semibold text-gray-900">Security settings</h1>

            <div v-if="status" class="flex flex-col gap-2">
                <p class="text-sm font-medium text-green-600">{{ status }}</p>
                <Form v-if="offersSignOutOthers" v-bind="revokeOthers.form()" v-slot="{ processing }">
                    <button type="submit" :disabled="processing" class="text-sm font-medium text-gray-900 underline">
                        Sign out your other sessions
                    </button>
                </Form>
            </div>

            <section v-for="group in types" :key="group.type" class="flex flex-col gap-2 rounded-lg bg-white p-6 shadow-sm ring-1 ring-gray-200">
                <h2 class="font-medium text-gray-900">{{ typeName(group.type) }}</h2>

                <template v-if="group.type === 'password'">
                    <p class="text-sm text-gray-600">{{ group.credentials.some((credential) => !credential.disabled) ? 'Set' : 'Not set' }}</p>
                    <p v-for="credential in group.credentials" :key="credential.id" class="text-sm text-gray-600">
                        Added {{ date(credential.addedAt) }}, last used {{ date(credential.lastUsedAt) }}
                        <span v-if="credential.disabled" class="font-medium text-red-600">Disabled</span>
                        <Link :href="remove(credential.id)" :aria-label="`Remove ${typeName(group.type)}`" class="font-medium text-gray-900 underline"
                            >Remove</Link
                        >
                    </p>
                </template>

                <ul v-else-if="group.credentials.length" class="flex flex-col gap-2">
                    <li v-for="credential in group.credentials" :key="credential.id" class="text-sm">
                        <span v-if="credential.label" class="font-medium text-gray-900">{{ credential.label }}</span>
                        <span class="block text-gray-600">Added {{ date(credential.addedAt) }}, last used {{ date(credential.lastUsedAt) }}</span>
                        <span v-if="credential.disabled" class="block font-medium text-red-600">Disabled</span>
                        <Link
                            :href="remove(credential.id)"
                            :aria-label="`Remove ${credential.label ?? typeName(group.type)}`"
                            class="font-medium text-gray-900 underline"
                            >Remove</Link
                        >
                    </li>
                </ul>

                <p v-else class="text-sm text-gray-600">None added.</p>

                <Link
                    v-if="group.enrollable"
                    :href="enroll(group.type)"
                    :aria-label="`Set up ${typeName(group.type)}`"
                    class="text-sm font-medium text-gray-900 underline"
                    >Set up</Link
                >
            </section>

            <section v-if="leftovers.length" class="flex flex-col gap-2 rounded-lg bg-white p-6 shadow-sm ring-1 ring-gray-200">
                <h2 class="font-medium text-gray-900">No longer accepted</h2>
                <p class="text-sm text-gray-600">
                    This app no longer accepts these to sign in. They don't count as a way to sign in or as a second factor.
                </p>
                <ul class="flex flex-col gap-2">
                    <li v-for="credential in leftovers" :key="credential.id" class="text-sm">
                        <span class="font-medium text-gray-900">{{ credential.label ?? typeName(credential.type) }}</span>
                        <span class="block text-gray-600">Added {{ date(credential.addedAt) }}, last used {{ date(credential.lastUsedAt) }}</span>
                        <span v-if="credential.disabled" class="block font-medium text-red-600">Disabled</span>
                        <Link
                            :href="remove(credential.id)"
                            :aria-label="`Remove ${credential.label ?? typeName(credential.type)}`"
                            class="font-medium text-gray-900 underline"
                            >Remove</Link
                        >
                    </li>
                </ul>
            </section>

            <section class="flex flex-col gap-2 rounded-lg bg-white p-6 shadow-sm ring-1 ring-gray-200">
                <h2 class="font-medium text-gray-900">Recovery codes</h2>
                <p v-if="recoveryCodes === 0" class="text-sm text-gray-600">No recovery codes</p>
                <template v-else>
                    <p class="text-sm text-gray-600">{{ recoveryCodes === 1 ? '1 code left' : `${recoveryCodes} codes left` }}</p>
                    <p v-if="recoveryCodesLow" class="text-sm font-medium text-amber-700">You're running low on recovery codes.</p>
                </template>
            </section>

            <section class="flex flex-col gap-2 rounded-lg bg-white p-6 shadow-sm ring-1 ring-gray-200">
                <h2 class="font-medium text-gray-900">Sessions</h2>
                <p v-if="page.props.errors.session" class="text-sm text-red-600">{{ page.props.errors.session }}</p>
                <p v-if="sessionsStatus" class="text-sm text-gray-600">{{ sessionsStatus }}</p>
                <ul v-else class="flex flex-col gap-2">
                    <li v-for="session in sessions" :key="session.handle" class="text-sm">
                        <span class="font-medium text-gray-900">{{ sessionName(session) }}</span>
                        <span v-if="session.current" class="ml-2 text-green-600">This device</span>
                        <span class="block text-gray-600">
                            {{ session.ipAddress ?? 'Unknown IP address' }}<template v-if="session.location">, {{ session.location }}</template
                            >, last active {{ date(session.lastActiveAt) }}
                        </span>
                        <Link
                            v-if="!session.current"
                            :href="revoke(session.handle)"
                            :aria-label="`Sign out ${sessionName(session)}`"
                            class="font-medium text-gray-900 underline"
                            >Sign out</Link
                        >
                    </li>
                </ul>
                <p class="text-sm text-gray-600">Signed in somewhere you don't recognise, or on a device you no longer use?</p>
                <Link :href="revokeOthersStep()" class="text-sm font-medium text-gray-900 underline">Sign out other sessions</Link>
            </section>

            <section class="flex flex-col gap-2 rounded-lg bg-white p-6 shadow-sm ring-1 ring-gray-200">
                <h2 class="font-medium text-gray-900">Sudo</h2>
                <template v-if="sudoEndsAt">
                    <p class="text-sm text-gray-600">You recently confirmed it's you. Changes won't ask again until {{ time(sudoEndsAt) }}.</p>
                    <Form v-bind="end.form()">
                        <button type="submit" class="text-sm font-medium text-gray-900 underline">End sudo</button>
                    </Form>
                </template>
                <p v-else class="text-sm text-gray-600">Your next change will ask you to confirm it's you.</p>
            </section>
        </main>
    </div>
</template>
