const maxImageWidth = 1200;
const jpegQuality = 0.8;

function canvasToBlob(canvas: HTMLCanvasElement): Promise<Blob> {
    return new Promise((resolve, reject) => {
        canvas.toBlob(
            (blob) => {
                if (blob) {
                    resolve(blob);
                    return;
                }

                reject(new Error('Canvas export failed'));
            },
            'image/jpeg',
            jpegQuality,
        );
    });
}

function loadImage(file: File): Promise<HTMLImageElement> {
    return new Promise((resolve, reject) => {
        const image = new Image();
        const url = URL.createObjectURL(file);

        image.onload = () => {
            URL.revokeObjectURL(url);
            resolve(image);
        };
        image.onerror = () => {
            URL.revokeObjectURL(url);
            reject(new Error('Image load failed'));
        };
        image.src = url;
    });
}

export async function compressImage(file: File): Promise<File> {
    const bitmap = 'createImageBitmap' in window
        ? await createImageBitmap(file)
        : await loadImage(file);

    const sourceWidth = bitmap.width;
    const sourceHeight = bitmap.height;
    const width = sourceWidth > maxImageWidth ? maxImageWidth : sourceWidth;
    const height = Math.round((width / sourceWidth) * sourceHeight);

    const canvas = document.createElement('canvas');
    canvas.width = width;
    canvas.height = height;

    const context = canvas.getContext('2d');

    if (!context) {
        throw new Error('Canvas context unavailable');
    }

    context.drawImage(bitmap, 0, 0, width, height);

    if ('close' in bitmap) {
        bitmap.close();
    }

    const blob = await canvasToBlob(canvas);
    const name = file.name.replace(/\.[^.]+$/, '') || 'product';

    return new File([blob], `${name}.jpg`, {
        type: 'image/jpeg',
        lastModified: Date.now(),
    });
}
