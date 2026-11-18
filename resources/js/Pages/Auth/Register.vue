<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3'
import Form from '@/Components/Form/Form.vue'
import FormInput from '@/Components/Form/FormInput.vue'
import AuthLayout from '@/Layouts/AuthLayout.vue'

/**
 * The exact payload POSTed to the register route.
 *
 * `password_confirmation` is snake_case because Laravel's `confirmed` rule
 * looks for precisely that key; renaming it would silently stop the two fields
 * being compared.
 */
interface RegisterForm {
    name: string
    email: string
    password: string
    password_confirmation: string
}

defineOptions({ layout: AuthLayout })

const form = useForm<RegisterForm>({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
})

function submit(): void {
    form.post(route('register'), {
        onFinish: () => form.reset('password', 'password_confirmation'),
    })
}
</script>

<template>
    <Head title="Create account" />

    <h1
        class="text-[2.1rem] font-bold leading-[1.12] tracking-tight text-white sm:text-[2.8rem] [@media(max-height:1100px)]:sm:text-[2.1rem]"
    >
        Create your account
    </h1>
    <p
        class="mt-[0.9rem] text-[1.1rem] leading-relaxed text-[#ecf3fd] sm:text-[1.28rem] [@media(max-height:1100px)]:mt-[0.5rem] [@media(max-height:1100px)]:sm:text-[1.1rem]"
    >
        Start generating images and tracking costs.
    </p>

    <Form
        class="mt-[2.1rem] [@media(max-height:1100px)]:mt-[1.25rem]"
        :processing="form.processing"
        @submit="submit"
    >
        <FormInput
            v-model="form.name"
            label="Name"
            placeholder="Your name"
            autocomplete="name"
            required
            :error="form.errors.name"
        />

        <FormInput
            v-model="form.email"
            label="Email"
            type="email"
            placeholder="you@example.com"
            autocomplete="username"
            required
            :error="form.errors.email"
        />

        <FormInput
            v-model="form.password"
            label="Password"
            type="password"
            placeholder="Create a password"
            autocomplete="new-password"
            required
            :error="form.errors.password"
        />

        <FormInput
            v-model="form.password_confirmation"
            label="Confirm password"
            type="password"
            placeholder="Re-enter your password"
            autocomplete="new-password"
            required
            :error="form.errors.password_confirmation"
        />

        <template #actions>
            <button
                type="submit"
                :disabled="form.processing"
                class="mt-[2.1rem] h-[3.5rem] w-full rounded-xl bg-[#8a9ffd] text-[1.05rem] font-bold text-[#0b1020] transition-opacity hover:opacity-90 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#8a9ffd] focus-visible:ring-offset-2 focus-visible:ring-offset-[#0e1522] disabled:opacity-50 sm:h-[3.875rem] sm:text-[1.15rem] [@media(max-height:1100px)]:mt-[1.4rem] [@media(max-height:1100px)]:sm:h-[3.2rem]"
            >
                {{ form.processing ? 'Creating account…' : 'Create account' }}
            </button>
        </template>

        <template #footer>
            <p
                class="mt-[1.6rem] text-center text-[1.05rem] text-white sm:text-[1.175rem] [@media(max-height:1100px)]:mt-[1.1rem]"
            >
                Already have an account?
                <Link
                    :href="route('login')"
                    class="text-white underline underline-offset-4 hover:text-[#bacffa]"
                >
                    Sign in
                </Link>
            </p>
        </template>
    </Form>
</template>
