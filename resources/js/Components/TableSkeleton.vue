<script setup lang="ts">
interface SkeletonColumn {
    cellClass: string
    pillClass: string
    boxClass?: string
    align?: 'left' | 'right'
}

withDefaults(
    defineProps<{
        rows: number
        columns: SkeletonColumn[]
        rowClass?: string
        boxClass?: string
    }>(),
    {
        rowClass: '',
        boxClass: 'h-5',
    },
)
</script>

<template>
    <tbody aria-hidden="true">
        <tr v-for="row in rows" :key="row" :class="rowClass">
            <td v-for="(column, index) in columns" :key="index" :class="column.cellClass">
                <span
                    class="flex items-center"
                    :class="[column.boxClass ?? boxClass, column.align === 'right' ? 'justify-end' : '']"
                >
                    <span
                        class="block animate-pulse bg-[#3f4a5e] motion-reduce:animate-none"
                        :class="column.pillClass"
                    />
                </span>
            </td>
        </tr>
    </tbody>
</template>
