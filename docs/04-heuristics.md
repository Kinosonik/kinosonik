# Heurística de Riders — Versió 2025-11

## Objectiu
Detectar problemes evidents i generar una puntuació barata (0€) per:
- Productors
- Sales
- Tècnics sense crèdits IA

## Camps analitzats
- `dateMeta` → detecció de dates, versions
- `contactMeta` → telèfon, email, tècnic responsable
- `patchMeta` → instruments, canals, inputs
- `stagePlotMeta` → presència de diagrama
- `gearListMeta` → equips i backline
- `divMeta` → seccions generiques

## Regles principals
- Si no hi ha *cap contacte* → -20 punts
- Si no hi ha *patchlist* però sí *gearlist* → -10 punts
- Si no hi ha *plot* → -15 punts
- Si detectem VERSION < 2020 → -5 punts
- Si hi ha incongruències (p.ex., RF sense antenes) → flags

## Classificació
- **> 80** → Acceptable / segell possible
- **60–80** → A revisar
- **< 60** → Risc alt

## Diferenciació
- Riders minimalistes (tipus Oren Ambarchi) → tolerància especial
