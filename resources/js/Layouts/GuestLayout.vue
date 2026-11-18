<script setup>
import { Link } from '@inertiajs/vue3'
</script>

<template>
    <div class="flex min-h-screen flex-col items-center justify-center px-4 py-10">
        <Link href="/" class="text-sm font-semibold tracking-tight text-body">
            Image studio
        </Link>
        <p class="mt-2 max-w-xs text-center text-xs leading-relaxed text-dim">
            Generate images through Pixelforge, and see exactly what each one costs.
        </p>

        <div class="panel mt-6 w-full max-w-sm rounded-lg px-6 py-7">
            <slot />
        </div>
    </div>
</template>
