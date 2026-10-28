const pxToMm = 25.4 / 96;

function safeFilename(value) {
    const filename = String(value || 'documento.pdf')
        .replace(/[\\/:*?"<>|]+/g, '-')
        .replace(/\s+/g, '-')
        .replace(/-+/g, '-')
        .replace(/^-|-$/g, '');

    return `${filename.replace(/\.pdf$/i, '') || 'documento'}.pdf`;
}

async function waitForImages(target) {
    await Promise.all([...target.querySelectorAll('img')].map(async (image) => {
        if (!image.complete) {
            await new Promise((resolve) => {
                image.addEventListener('load', resolve, { once: true });
                image.addEventListener('error', resolve, { once: true });
            });
        }

        if (typeof image.decode === 'function') {
            try {
                await image.decode();
            } catch {
                // A failed decode is handled by html2canvas; keep visible content intact.
            }
        }
    }));
}

async function downloadPdf(button) {
    const target = document.querySelector(button.dataset.pdfTarget);

    if (!target) {
        throw new Error(`PDF target not found: ${button.dataset.pdfTarget}`);
    }

    const kind = button.dataset.pdfKind || 'document';
    const marginMm = Number(button.dataset.pdfMarginMm || 0);
    const html2canvas = {
        scale: 2,
        useCORS: true,
        backgroundColor: '#ffffff',
        logging: false,
    };
    const options = {
        margin: marginMm,
        filename: safeFilename(button.dataset.pdfFilename),
        image: { type: 'png', quality: 1 },
        html2canvas,
        enableLinks: true,
    };

    if (kind === 'ticket') {
        const widthMm = Number(button.dataset.pdfWidthMm || 80);
        const contentHeightMm = target.scrollHeight * pxToMm;
        const pageHeightMm = Math.max(50, contentHeightMm + (marginMm * 2));

        options.jsPDF = {
            unit: 'mm',
            format: [widthMm, pageHeightMm],
            orientation: 'portrait',
        };
    } else {
        options.jsPDF = {
            unit: 'mm',
            format: button.dataset.pdfPaper || 'letter',
            orientation: 'portrait',
        };
        options.pagebreak = { mode: ['css', 'legacy'] };
    }

    await document.fonts?.ready;
    await waitForImages(target);
    const html2pdfModule = await import('html2pdf.js');
    const html2pdf = html2pdfModule.default ?? html2pdfModule;
    await html2pdf().set(options).from(target).save();
}

function attachDownload(button) {
    button.addEventListener('click', async () => {
        if (button.disabled) {
            return;
        }

        const originalLabel = button.textContent;
        button.disabled = true;
        button.textContent = 'Generando PDF...';

        try {
            await downloadPdf(button);
        } catch (error) {
            console.error(error);
            window.alert('No se pudo generar el PDF.');
        } finally {
            button.disabled = false;
            button.textContent = originalLabel;
        }
    });
}

function initialisePdfDownloads() {
    document.querySelectorAll('[data-download-pdf]').forEach(attachDownload);
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initialisePdfDownloads, { once: true });
} else {
    initialisePdfDownloads();
}
