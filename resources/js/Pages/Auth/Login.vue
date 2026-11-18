<script setup>
import { ref } from 'vue'
import { Head, Link, useForm } from '@inertiajs/vue3'
import InputError from '@/Components/InputError.vue'
import Eye from '@/Components/Icons/Eye.vue'
import EyeSlash from '@/Components/Icons/EyeSlash.vue'
import StarFour from '@/Components/Icons/StarFour.vue'
import { useMediaQuery } from '@/Composables/useMediaQuery.js'

defineProps({
    canResetPassword: { type: Boolean },
    status: { type: String },
})

const form = useForm({
    email: '',
    password: '',
    remember: false,
})

const showPassword = ref(false)

/**
 * The artwork is decorative, so it is not worth a download on a phone where it
 * is never shown. `v-if` on this keeps the <img> out of the DOM entirely —
 * hiding it with CSS would still fetch it.
 */
const showArtwork = useMediaQuery('(min-width: 1024px)')

const submit = () => {
    form.post(route('login'), {
        onFinish: () => form.reset('password'),
    })
}
</script>

<template>
    <Head title="Sign in" />

    <div class="min-h-screen bg-[#0e1522] lg:grid lg:h-screen lg:min-h-0 lg:grid-cols-[48fr_52fr] lg:overflow-hidden">
        <!-- Form side -->
        <div class="relative flex min-h-screen justify-center px-6 py-16 sm:px-10 sm:py-20 [@media(max-height:800px)]:py-10 lg:h-screen lg:min-h-0 lg:overflow-y-auto lg:pl-[3.875rem]">
            <Link
                href="/"
                class="absolute left-6 top-8 inline-flex items-center gap-2.5 rounded text-[1.35rem] font-semibold tracking-tight text-white sm:left-10 lg:left-[3.2rem] lg:top-[2.4rem]"
            >
                <StarFour class="size-7 shrink-0" />
                Image Studio
            </Link>

            <div class="m-auto w-full max-w-[30.9rem]">
                    <h1 class="text-[2.1rem] font-bold leading-[1.12] tracking-tight text-white sm:text-[2.8rem] [@media(max-height:800px)]:sm:text-[2.1rem]">
                        Sign in to Image Studio
                    </h1>
                    <p class="mt-[0.9rem] text-[1.1rem] leading-relaxed text-[#ecf3fd] sm:text-[1.28rem] [@media(max-height:800px)]:mt-[0.5rem] [@media(max-height:800px)]:sm:text-[1.1rem]">
                        Generate images and track what each one costs.
                    </p>

                    <div
                        v-if="status"
                        class="mt-6 rounded-lg border border-ok/30 bg-ok/10 px-4 py-2.5 text-sm text-ok"
                    >
                        {{ status }}
                    </div>

                    <form class="mt-[2.1rem] [@media(max-height:800px)]:mt-[1.25rem]" @submit.prevent="submit">
                        <div>
                            <label for="email" class="block text-[1.05rem] font-semibold leading-none text-white sm:text-[1.25rem]">
                                Email
                            </label>
                            <input
                                id="email"
                                v-model="form.email"
                                type="email"
                                required
                                autocomplete="username"
                                placeholder="you@example.com"
                                class="mt-[0.7rem] [@media(max-height:800px)]:mt-[0.45rem] block h-[3.4rem] w-full sm:h-[3.75rem] [@media(max-height:800px)]:sm:h-[3.1rem] rounded-xl border border-[#262d39] bg-[#101723] px-4 text-[1.0625rem] text-white placeholder:text-[#a5acb7] sm:text-[1.2rem] focus:border-[#8a9ffd] focus:outline-none focus:ring-1 focus:ring-[#8a9ffd]"
                            />
                            <InputError class="mt-2" :message="form.errors.email" />
                        </div>

                        <div class="mt-[1.55rem] [@media(max-height:800px)]:mt-[1rem]">
                            <label for="password" class="block text-[1.05rem] font-semibold leading-none text-white sm:text-[1.25rem]">
                                Password
                            </label>
                            <div class="relative mt-[0.7rem] [@media(max-height:800px)]:mt-[0.45rem]">
                                <input
                                    id="password"
                                    v-model="form.password"
                                    :type="showPassword ? 'text' : 'password'"
                                    required
                                    autocomplete="current-password"
                                    placeholder="Enter your password"
                                    class="block h-[3.4rem] w-full rounded-xl border border-[#262d39] bg-[#101723] pl-4 pr-14 sm:h-[3.75rem] [@media(max-height:800px)]:sm:h-[3.1rem] text-[1.0625rem] text-white placeholder:text-[#a5acb7] sm:text-[1.2rem] focus:border-[#8a9ffd] focus:outline-none focus:ring-1 focus:ring-[#8a9ffd]"
                                />
                                <button
                                    type="button"
                                    :aria-label="showPassword ? 'Hide password' : 'Show password'"
                                    :aria-pressed="showPassword"
                                    class="absolute inset-y-0 right-0 flex w-12 items-center justify-center rounded-r-xl text-[#eef1f6] transition-colors hover:text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#8a9ffd]"
                                    @click="showPassword = !showPassword"
                                >
                                    <component :is="showPassword ? EyeSlash : Eye" class="size-5" />
                                </button>
                            </div>
                            <InputError class="mt-2" :message="form.errors.password" />
                        </div>

                        <div class="mt-[1.8rem] [@media(max-height:800px)]:mt-[1.1rem] flex flex-wrap items-center justify-between gap-3">
                            <label class="flex cursor-pointer items-center gap-3 text-[1rem] text-white sm:text-[1.1rem]">
                                <input
                                    v-model="form.remember"
                                    type="checkbox"
                                    name="remember"
                                    class="size-[1.45rem] shrink-0 rounded-md border-2 border-[#3a4454] sm:size-[1.7rem] bg-transparent text-[#8a9ffd] focus:ring-1 focus:ring-[#8a9ffd] focus:ring-offset-0"
                                />
                                Remember me
                            </label>

                            <Link
                                v-if="canResetPassword"
                                :href="route('password.request')"
                                class="text-[1rem] text-[#bacffa] underline underline-offset-4 hover:text-white sm:text-[1.1rem]"
                            >
                                Forgot password?
                            </Link>
                        </div>

                        <button
                            type="submit"
                            :disabled="form.processing"
                            class="mt-[2.4rem] [@media(max-height:800px)]:mt-[1.4rem] h-[3.5rem] w-full sm:h-[3.875rem] [@media(max-height:800px)]:sm:h-[3.2rem] rounded-xl bg-[#8a9ffd] text-[1.05rem] font-bold sm:text-[1.15rem] text-[#0b1020] transition-opacity hover:opacity-90 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#8a9ffd] focus-visible:ring-offset-2 focus-visible:ring-offset-[#0e1522] disabled:opacity-50"
                        >
                            {{ form.processing ? 'Signing in…' : 'Sign in' }}
                        </button>

                        <p class="mt-[1.75rem] [@media(max-height:800px)]:mt-[1.1rem] text-center text-[1.05rem] text-white sm:text-[1.175rem]">
                            New to Image Studio?
                            <Link
                                :href="route('register')"
                                class="text-white underline underline-offset-4 hover:text-[#aec0f5]"
                            >
                                Create an account
                            </Link>
                        </p>
                    </form>
            </div>
        </div>

        <!-- Artwork side. Desktop only: see useMediaQuery above. -->
        <!--
            The panel keeps the artwork's own 803:950 proportions rather than
            stretching to fill the column. Filling it meant that on any window
            that is not the design's exact shape, `cover` cropped the image
            vertically -- 27% of it on a wide, short window -- so the
            composition was never the one in the design. Holding the ratio
            shows the whole picture at every window shape, and at the design's
            proportions it still fills the column exactly.
        -->
        <div v-if="showArtwork" class="hidden justify-end py-[1.3rem] pr-[1.3rem] lg:flex lg:h-screen">
            <figure class="relative h-full w-auto max-w-full shrink-0 overflow-hidden rounded-2xl [aspect-ratio:803/950]">
                <img
                    src="/images/login-hero.webp"
                    alt=""
                    width="803"
                    height="950"
                    class="size-full object-cover"
                />
                <!-- Caption lives in HTML, not baked into the asset, so it stays
                     selectable, translatable and legible to screen readers. -->
                <figcaption
                    class="absolute bottom-6 left-6 text-[1.0625rem] font-semibold text-white drop-shadow-[0_1px_6px_rgba(0,0,0,0.55)]"
                >
                    Studio sample
                </figcaption>
            </figure>
        </div>
    </div>
</template>
