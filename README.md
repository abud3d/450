# Baloot 450 — PHP Native

The 400 repository is preserved as the Node.js reference. This repository is the PHP migration target.

## Approved product requirements
- Email + password registration and email verification.
- Secure optional remember-me using HttpOnly cookies; never store passwords or session tokens in browser storage.
- Independent sessions on multiple devices, with revoke-current and revoke-all.
- Prevent one account from occupying multiple seats in one match.
- Elo-based rating, four skill tiers (beginner, intermediate, advanced, excellent), achievements, and leaderboards.

## Hosting requirements
- PHP 8.1+; web document root must point to `public/` only.
- Private data directory outside the web root, writable only by the PHP service user.
- HTTPS required; configure the application origin and mail sender before enabling account activation.

## Migration status
This is a staged migration, not production-ready software. The legacy engine and latest UI must be reviewed before replacing them. JSON storage is only an initial low-volume option; load testing is required before public launch. No production deployment is performed without approval.
