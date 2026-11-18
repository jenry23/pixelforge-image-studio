<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3'
import Form from '@/Components/Form/Form.vue'
import FormInput from '@/Components/Form/FormInput.vue'
import AuthLayout from '@/Layouts/AuthLayout.vue'

interface Props {
    status?: string
}

interface LoginForm {
    email: string
    password: string
    remember: boolean
}

defineOptions({ layout: AuthLayout })

defineProps<Props>()

const form = useForm<LoginForm>({
    email: '',
    password: '',
    remember: false,
})

function submit(): void {
    form.post(route('login'), {
        onFinish: () => form.reset('password'),
    })
}
</script>

<template>
    <Head title="Sign in" />

    <h1
        class="text-[2.1rem] font-bold leading-[1.12] tracking-tight text-white sm:text-[2.8rem] [@media(max-height:800px)]:sm:text-[2.1rem]"
    >
        Sign in to Image Studio
    </h1>
    <p
        class="mt-[0.9rem] text-[1.1rem] leading-relaxed text-[#ecf3fd] sm:text-[1.28rem] [@media(max-height:800px)]:mt-[0.5rem] [@media(max-height:800px)]:sm:text-[1.1rem]"
    >
        Generate images and track what each one costs.
    </p>

    <div
        v-if="status"
        class="mt-6 rounded-lg border border-ok/30 bg-ok/10 px-4 py-2.5 text-sm text-ok"
    >
        {{ status }}
    </div>

    <Form
        class="mt-[2.1rem] [@media(max-height:800px)]:mt-[1.25rem]"
        :processing="form.processing"
        @submit="submit"
    >
        <FormInput
            v-model="form.email"
            name="email"
            label="Email"
            type="email"
            placeholder="you@example.com"
            autocomplete="username"
            required
            :error="form.errors.email"
        />

        <FormInput
            v-model="form.password"
            name="password"
            label="Password"
            type="password"
            placeholder="Enter your password"
            autocomplete="current-password"
            required
            :error="form.errors.password"
        />

        <template #actions>
            <div
                class="mt-[1.8rem] flex flex-wrap items-center justify-between gap-3 [@media(max-height:800px)]:mt-[1.1rem]"
            >
                <label class="flex cursor-pointer items-center gap-3 text-[1rem] text-white sm:text-[1.1rem]">
                    <input
                        v-model="form.remember"
                        type="checkbox"
                        name="remember"
                        class="size-[1.45rem] shrink-0 rounded-md border-2 border-[#3a4454] bg-transparent text-[#8a9ffd] focus:ring-1 focus:ring-[#8a9ffd] focus:ring-offset-0 sm:size-[1.7rem]"
                    />
                    Remember me
                </label>
            </div>

            <button
                type="submit"
                :disabled="form.processing"
                class="mt-[2.4rem] h-[3.5rem] w-full rounded-xl bg-[#8a9ffd] text-[1.05rem] font-bold text-[#0b1020] transition-opacity hover:opacity-90 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#8a9ffd] focus-visible:ring-offset-2 focus-visible:ring-offset-[#0e1522] disabled:opacity-50 sm:h-[3.875rem] sm:text-[1.15rem] [@media(max-height:800px)]:mt-[1.4rem] [@media(max-height:800px)]:sm:h-[3.2rem]"
            >
                {{ form.processing ? 'Signing in…' : 'Sign in' }}
            </button>
        </template>

        <template #footer>
            <p
                class="mt-[1.75rem] text-center text-[1.05rem] text-white sm:text-[1.175rem] [@media(max-height:800px)]:mt-[1.1rem]"
            >
                New to Image Studio?
                <Link
                    :href="route('register')"
                    class="text-white underline underline-offset-4 hover:text-[#bacffa]"
                >
                    Create an account
                </Link>
            </p>
        </template>
    </Form>
</template>
