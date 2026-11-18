<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
import StarFour from '@/Components/Icons/StarFour.vue'
import { useMediaQuery } from '@/Composables/useMediaQuery'

const showArtwork = useMediaQuery('(min-width: 1024px)')
</script>

<template>
    <div class="min-h-screen bg-[#0e1522] lg:grid lg:h-screen lg:min-h-0 lg:grid-cols-[48fr_52fr] lg:overflow-hidden">
        <div
            class="relative flex min-h-screen justify-center px-6 py-16 sm:px-10 sm:py-20 lg:h-screen lg:min-h-0 lg:overflow-y-auto lg:py-[5.5rem] lg:pl-[3.875rem] [@media(max-height:800px)]:py-10 [@media(max-height:800px)]:lg:py-[5.5rem]"
        >
            <Link
                href="/"
                class="absolute left-6 top-8 inline-flex items-center gap-2.5 rounded text-[1.35rem] font-semibold tracking-tight text-white sm:left-10 lg:left-[3.2rem] lg:top-[2.4rem]"
            >
                <StarFour class="size-7 shrink-0" />
                Image Studio
            </Link>

            <div class="m-auto w-full max-w-[30.9rem]">
                <slot />
            </div>
        </div>

        <div v-if="showArtwork" class="hidden justify-end py-[1.3rem] pr-[1.3rem] lg:flex lg:h-screen">
            <figure
                class="relative h-full w-auto max-w-full shrink-0 overflow-hidden rounded-2xl [aspect-ratio:803/950]"
            >
                <img
                    src="/images/login-hero.webp"
                    alt=""
                    width="803"
                    height="950"
                    class="size-full object-cover"
                />
                <figcaption
                    class="absolute bottom-[2.2rem] left-[1.7rem] text-[1.18rem] font-semibold text-white drop-shadow-[0_1px_6px_rgba(0,0,0,0.55)]"
                >
                    Studio sample
                </figcaption>
            </figure>
        </div>
    </div>
</template>
