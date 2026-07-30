/**
 * Helper utilities for Nextcloud integration
 */

// Nextcloud global type declarations
declare global {
  interface Window {
    oc_csrf_token: string
    OC: {
      generateUrl: (url: string, params?: Record<string, string>) => string
      getRootPath: () => string
    }
  }
}

/**
 * Get the CSRF token from Nextcloud global context
 */
export function getCSRFToken(): string {
  return window.oc_csrf_token || ""
}

/**
 * Generate a Nextcloud URL for the app
 * Always uses /index.php/apps/ prefix to avoid query string loss
 * in the Apache /apps/ rewrite rule (mod_rewrite drops query params)
 */
export function generateUrl(path: string, params?: Record<string, string>): string {
  return (window.OC.getRootPath() || "") + "/index.php/apps/collaborativephotocleanup/" + path
}
