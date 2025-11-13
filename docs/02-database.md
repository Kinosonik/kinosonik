# Base de dades — Esquema actual

## Taules principals

### Usuaris
```
Usuaris:
- ID_Usuari (PK)
- Nom_Usuari
- Cognoms_Usuari
- Email_Usuari
- Contrasenya_Hash
- Tipus_Usuari (tecnic, sala, productor, admin)
- Idioma
- Email_Verificat
- Email_Verify_Token_Hash
- Email_Verify_Expira
- Publica_Telefon
- Telefon_Usuari
- Ultim_Acces_Usuari
- Data_Alta_Usuari
```

### Riders
```
Riders:
- ID_Rider (PK)
- ID_Usuari (FK)
- Nom_Rider
- Fitxer_PDF
- Hash_SHA256
- Mida_Bytes
- Estat_Segell (cap, pendent, validat, caducat)
- Data_Publicacio
- Valoracio (puntuació heurística/IA)
- Data_IA
```

### ia_jobs (queue)
- job_uid
- rider_id
- mode (full, heuristics)
- status (queued, running, ok, error)
- created_at / updated_at
- intento / error_code
- input_sha256

### ia_runs (execucions)
- run_id
- job_uid
- rider_id
- timestamp
- engine
- status
- summary
- score

### Documents (per futur mode productor)
- `Document_ID`
- `R2_key`
- `sha256`
- `size_bytes`
- `type`
- timestamps

### Event & Producer mode (congelat)
Tot documentat a `/docs/20-producer-mode.md`.

## Índexs importants
- `Riders(ID_Usuari)`
- `ia_jobs(status)`
- `ia_runs(rider_id)`
- `Documents(sha256)`

## Bones pràctiques
- Totes les dates → `DATETIME` amb zona Europe/Madrid
- Hashos sempre en HEX (64 caràcters)
- FK estrictes en cascada quan s’autoritzi
