<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { CircleCheck } from '@lucide/vue';
import InputError from '@/components/InputError.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { login } from '@/routes';
import { email } from '@/routes/password';

defineOptions({
    layout: {
        title: 'Reset your password',
        description: "Enter your email and we'll send you a reset link.",
    },
});

defineProps<{
    status?: string;
}>();
</script>

<template>
    <Head title="Forgot password" />

    <p
        v-if="status"
        role="status"
        class="mb-6 flex items-start gap-2 rounded-md border border-success/30 bg-success-soft px-3 py-2 text-sm text-success"
    >
        <CircleCheck class="mt-0.5 size-4 shrink-0" />
        {{ status }}
    </p>

    <div class="space-y-6">
        <Form v-bind="email.form()" v-slot="{ errors, processing }">
            <div class="grid gap-2">
                <Label for="email">Email address</Label>
                <Input
                    id="email"
                    type="email"
                    name="email"
                    autocomplete="email"
                    v-focus
                    placeholder="email@example.com"
                />
                <InputError :message="errors.email" />
            </div>

            <Button
                class="mt-6 w-full"
                :disabled="processing"
                data-test="email-password-reset-link-button"
            >
                <Spinner v-if="processing" />
                Email me a reset link
            </Button>
        </Form>

        <p class="text-center text-sm text-muted-foreground">
            Remembered it?
            <TextLink :href="login()">Log in</TextLink>
        </p>
    </div>
</template>
