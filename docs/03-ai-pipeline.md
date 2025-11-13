# IA Pipeline — Fases i arquitectura

## Resum
El pipeline IA té 4 fases:

1. **Extractor (PDF → text/JSON)**  
   - Ús de `pdftotext`, OCR si cal.
   - Neteja de text, detecció de taules i seccions.

2. **Heuristic Analyzer**  
   - Regles locals: contacte, data, patchlist, gearlist, etc.
   - Score base (0–100)
   - Flags de risc

3. **Semantic IA layer (opcional)**  
   - Envia només text i metadades heurístiques a un model GPT.
   - Retorna sub-scores, comentaris i suggeriments.

4. **Final Score + Persistència**  
   - Calcula score final
   - Desa `ia_runs`
   - Actualitza `Riders` amb resultat

## Worker
Cron cada 2 minuts:
```
php cron/ia_worker.php
```

Responsabilitats:
- Agafar jobs queued
- Actualitzar status
- Escriure log i estat en /tmp
- Tornar resultat

## Estat de job
`/tmp/ai-<job_uid>.json`:
```
{
  "pct": 40,
  "stage": "extracting_pdf",
  "logs": [...],
  "done": false
}
```

## Auto-publish Seal
- Si score > 80 i condicions complertes:
  - S’incrusta segell i QR
  - S’actualitza rider
  - S’envia email
