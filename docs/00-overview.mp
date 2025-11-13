# Kinosonik Riders — Overview general

## Objectiu
Kinosonik Riders és una plataforma web que permet:
- Analitzar riders tècnics de bandes i artistes.
- Generar diagnòstics IA i heurístics.
- Gestionar documents, segells de validació i fluxos de negociació.
- Donar eines a tècnics, sales i productors.

## Públic principal
1. **Tècnics** — gestionen riders, segells, anàlisi automatitzada, repositori.
2. **Sales / promotors** — revisen riders validats, consulten informació i contacten tècnics.
3. **Productors** — (fase congelada) gestió completa d’events, incrustació IA pre-check.
4. **Admins** — control total del sistema.

## Infraestructura
- Hetzner VPS (CX22 → possible CX23/CS33 en futur)
- Nginx + PHP-FPM 8.3
- MariaDB
- Cloudflare R2 per PDFs i assets
- GitHub (repositori principal)

## Filosofia del projecte
- UX minimalista, fluida, “liquid glass”.
- Robustesa per damunt de màgia negra.
- IA com a eina d’ajuda, no de control.
- Totes les funcions disponibles 100% en català (amb traducció a ES i EN).
- Administració en català (no cal traducció).
- Costos d’IA estrictament controlats.

Aquest document és el punt d’entrada al conjunt complet de documentació.
