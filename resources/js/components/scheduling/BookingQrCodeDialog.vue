<script setup lang="ts">
/**
 * Show a booking link as a QR code, ready to print on a flyer or a sign.
 *
 * The code is drawn on the server; the PNG download rasterises that SVG in
 * the browser so nothing beyond the existing QR library is needed.
 */
import { Download } from '@lucide/vue';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { bookingQrCode } from '@/routes';

const props = defineProps<{
    /** The booking link to encode, or null while the dialog is closed. */
    url: string | null;
    /** What the link is for, used in the title and the file name. */
    name: string;
}>();

const emit = defineEmits<{ close: [] }>();

/** The pixel size of the downloaded PNG: large enough to print sharply. */
const pngSize = 1024;

const svgUrl = computed(() =>
    props.url ? bookingQrCode.url({ query: { url: props.url } }) : '',
);

const svgDownloadUrl = computed(() =>
    props.url
        ? bookingQrCode.url({
              query: { url: props.url, name: props.name, download: 1 },
          })
        : '',
);

const fileName = computed(
    () =>
        `${
            props.name
                .toLowerCase()
                .replace(/[^a-z0-9]+/g, '-')
                .replace(/^-|-$/g, '') || 'booking-link'
        }-qr-code.png`,
);

const displayUrl = computed(() => props.url?.replace(/^https?:\/\//, '') ?? '');

const downloadingPng = ref(false);

const downloadPng = async () => {
    downloadingPng.value = true;

    try {
        const image = new Image();
        image.src = svgUrl.value;
        await image.decode();

        const canvas = document.createElement('canvas');
        canvas.width = pngSize;
        canvas.height = pngSize;
        canvas.getContext('2d')?.drawImage(image, 0, 0, pngSize, pngSize);

        const blob = await new Promise<Blob | null>((resolve) =>
            canvas.toBlob(resolve, 'image/png'),
        );

        if (!blob) {
            throw new Error('The QR code could not be drawn.');
        }

        const link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = fileName.value;
        link.click();
        URL.revokeObjectURL(link.href);
    } catch {
        toast.error('Could not download the QR code');
    } finally {
        downloadingPng.value = false;
    }
};
</script>

<template>
    <Dialog
        :open="url !== null"
        @update:open="(value) => (value ? null : emit('close'))"
    >
        <DialogContent class="sm:max-w-sm">
            <DialogHeader>
                <DialogTitle>QR code for {{ name }}</DialogTitle>
                <DialogDescription>
                    Scanning it opens this booking link.
                </DialogDescription>
            </DialogHeader>

            <div class="flex flex-col items-center gap-3">
                <img
                    v-if="url"
                    :src="svgUrl"
                    :alt="`QR code for ${displayUrl}`"
                    class="size-56 rounded-lg border border-border"
                    data-test="booking-qr-code-image"
                />
                <code
                    class="w-full rounded-lg border border-border bg-muted/50 px-3 py-2 text-center font-mono text-xs"
                    data-wrap-anywhere
                >
                    {{ displayUrl }}
                </code>
            </div>

            <DialogFooter class="gap-2 sm:justify-center">
                <Button
                    class="cursor-pointer"
                    :disabled="downloadingPng"
                    data-test="booking-qr-code-download-png"
                    @click="downloadPng"
                >
                    <Download class="size-4" aria-hidden="true" />
                    Download PNG
                </Button>
                <Button variant="outline" class="cursor-pointer" as-child>
                    <a :href="svgDownloadUrl" download>
                        <Download class="size-4" aria-hidden="true" />
                        Download SVG
                    </a>
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
