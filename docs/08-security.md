# Seguretat

## Access Control
- Middleware KS
- Sessions httpOnly + SameSite
- Rate limiting (login, verify email, IA)

## CSP (Content-Security-Policy)
- default-src 'self'
- img-src 'self' data: https:
- script-src 'self' 'unsafe-inline' …
- frame-ancestors none

## Hashos
- SHA256 per fitxers i tokens

## Passwords
- password_hash(PASSWORD_DEFAULT)
- `password_needs_rehash()` a login

## R2
- PDF només accessibles via presigned URL o proxy server-side (`document.php`)
