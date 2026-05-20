export function getProductImageUrl(url: string | null, width = 300): string | null {
    if (!url) {
        return null;
    }

    if (!url.includes('res.cloudinary.com') || !url.includes('/image/upload/')) {
        return url;
    }

    return url.replace('/image/upload/', `/image/upload/f_auto,q_auto,w_${width}/`);
}
