# Flux de desplegament — Kinosonik Riders

## Entorns
- **Dev:** https://dev.kinosonik.com  
  Carpeta: `/var/www/html-dev`  
  Entorn de proves i desenvolupament.  
  Connectat a Visual Studio Code (edites aquí).

- **Producció:** https://riders.kinosonik.com  
  Carpeta: `/var/www/html`  
  Actualització automàtica via GitHub Actions.

## Flux de treball
1. Edita fitxers a `/var/www/html-dev`.
2. Desa i prepara canvis:
   ```bash
   git add .
   git commit -m "Descripció dels canvis"
   git pull --rebase
   git push
