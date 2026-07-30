/**
 * Generate a Nextcloud preview URL.
 * Always uses /index.php/core/preview to avoid query string loss
 * in the Apache /apps/ rewrite rule.
 */
export function previewUrl(fileId: number, x: number = 256, y: number = 256, a: boolean = true): string {
    const query = "fileId=" + fileId + "&x=" + x + "&y=" + y + "&a=" + (a ? 1 : 0);
    return (window.OC.getRootPath() || "") + "/index.php/core/preview?" + query;
}
