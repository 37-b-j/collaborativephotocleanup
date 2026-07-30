# Changelog

## v1.0.14

- **Forced delete**: Removed consensus guard from cleanup execute — admin can force-delete without full team consensus
- **Z-index fixes**: Overlays now render above Nextcloud header and app's internal navigation bar
- **±5 threshold steps**: Cluster sensitivity slider now increments in steps of 5 for better usability
- **"Alle behalten" button**: Added "Keep all" button in cluster overview for batch-keeping entire clusters
- **"Forciert löschen" button**: Execute button text updated to reflect forced deletion mode
- **New app icon**: AI-generated modern app icon (192px + 512px)
- **"Exclusively vibe coded"**: Added to app footer, README, and app description
- **Code cleanup**: Removed all .bak backup files from repository

## v1.0.10

- Initial release with cluster-based photo review
- Perceptual hash (pHash) duplicate detection (16x16 average hash)
- Swipe gestures for mobile photo voting
- Quarantine folder system for safe deletion
- PWA support with service worker caching
- Configurable Hamming distance threshold (0-100)
