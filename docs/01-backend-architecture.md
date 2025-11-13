# Arquitectura del Backend

## Estructura de carpetes (simplificada)
```
/var/www/html
│── index.php
│── espai.php
│── visualitza.php
│── php/
│   ├── db.php
│   ├── i18n.php
│   ├── middleware.php
│   ├── audit.php
│   ├── ai/
│   │   ├── ia_start.php
│   │   ├── ia_status.php
│   │   ├── ia_worker.php
│   │   ├── ia_extract_heuristics.php
│   │   └── heuristics/
│   ├── cron/
│   │   ├── ia_worker.php
│   │   ├── ia_healthcheck.php
│   │   ├── ia_housekeeping.php
│   │   ├── mail_worker.php
│   │   └── ia_cron_cleanup.php
│   └── ...
│── assets/
│── vendor/
```

## Flux global de backend
1. L’usuari accedeix → middleware valida sessió.
2. Carreguem idioma, sessió i permisos.
3. Per a riders:
   - Upload → R2
   - IA heurística local → puntuació
   - IA LLM (opcional crèdits) → diagnòstic complet
4. Persistència:
   - BD MySQL
   - Logs al disc
   - Estat de la IA a `/tmp/ai-<job>.json`
5. Publicació: 
   - Seal + QR incrustat al PDF
   - Hash SHA256 guardat a BD

## Patró de disseny
El backend segueix un patró **MVC implícit**:
- Pàgines com a *controladors*
- Scripts PHP com a *serveis*
- BD i R2 com a *models*
- UI HTML/Bootstrap com a *vista*

## Notes d'estil
- Tot el codi PHP ha d’usar `declare(strict_types=1);`
- Totes les respostes de scripts han de ser JSON o redireccions clares.
- Logs detallats, especialment en IA i negocis.
