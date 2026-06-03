# Gamindo — Export Engine

Microservizio backend in **PHP 7.3 + Lumen 8** che espone API REST per:

1. **Ingerire** grandi moli di dati statistici di una *version* di gioco/campagna
   (player, eventi, transazioni, risposte, premi) con `payload` JSON liberi.
2. **Richiedere export XLSX configurabili** (fogli, colonne, filtri anche su campi
   JSON, ordinamenti, aggregazioni, intervallo temporale).
3. **Generarli in modo asincrono** via coda, con stato/`progress` e download.

Dimensionato per i volumi target: **10M+ eventi, 1M+ player, export fino a 500k
righe, più export concorrenti**, a **memoria costante** (streaming in lettura e
in scrittura).

> Documentazione di design dettagliata: **[docs/ARCHITECTURE.md](docs/ARCHITECTURE.md)**.
> Specifica API: **[docs/openapi.yaml](docs/openapi.yaml)**.

---

## Indice

- [Requisiti](#requisiti)
- [Avvio rapido (Docker)](#avvio-rapido-docker)
- [Generare un dataset di test/demo](#generare-un-dataset-di-testdemo)
- [Comandi principali (Makefile)](#comandi-principali-makefile)
- [Test](#test)
- [API & esempi cURL](#api--esempi-curl)
  - [1. Creare una version](#1-creare-una-version)
  - [2. Ingestione dati](#2-ingestione-dati)
  - [3. Richiedere un export](#3-richiedere-un-export)
  - [4. Stato e download](#4-stato-e-download)
  - [5. Preview, cancel, retry, template](#5-preview-cancel-retry-template-bonus)
- [Struttura del progetto](#struttura-del-progetto)
- [Configurazione (.env)](#configurazione-env)
- [Scelte tecniche in breve](#scelte-tecniche-in-breve)
- [Funzionalità bonus implementate](#funzionalità-bonus-implementate)

---

## Requisiti

- **Docker** e **docker-compose** (unico requisito per eseguire tutto).
- In alternativa, in locale: PHP 7.3, Composer, MySQL 8, estensioni `pdo_mysql`,
  `zip`, `mbstring`.

---

## Avvio rapido (Docker)

```bash
# 1. Copia la configurazione
cp .env.example .env

# 2. Builda e avvia lo stack (app + nginx + worker + mysql + redis)
make build
make up

# 3. Installa le dipendenze ed esegui le migration
make install
make migrate

# (Opzionale) un piccolo seed di esempio
make seed
```

L'API è ora su **http://localhost:8080**. Verifica:

```bash
curl http://localhost:8080/api/v1/health
# {"status":"ok","time":"..."}
```

> Senza `make`: gli stessi comandi sono `docker-compose build`, `docker-compose up -d`,
> `docker-compose exec app composer install`, `docker-compose exec app php artisan migrate`.

---

## Generare un dataset di test/demo

Comando dimensionabile (richiesto dall'esercizio):

```bash
# Default: 1.000 player + 10.000 eventi (+ transazioni/risposte/premi)
make demo

# Volumi grandi (parametrizzabili)
make demo ARGS="--players=1000000 --events=10000000"

# Equivalente diretto
docker-compose exec app php artisan demo:seed --players=1000000 --events=10000000
```

Il comando stampa il `version_id` generato, da usare nelle chiamate successive.
Gli inserimenti sono a blocchi (chunk) e i `payload` sono variabili (lingue, utm,
campi custom) per esercitare filtri JSON e aggregazioni.

### Dataset con anomalie (per il foglio Data Quality)

`make demo` genera dati **coerenti** (Data Quality ~0). Per dimostrare i controlli di
qualità del report esiste un seeder separato che inietta anomalie controllate (date
incoerenti, lingua mancante, payload vuoti, eventi orfani, email duplicate):

```bash
# Default: ~15% di anomalie
make demo_random

# Più aggressivo / dimensionabile
make demo_random ARGS="--anomaly-rate=30 --players=500 --events=8000"

# Equivalente diretto
docker-compose exec app php artisan demo:seed-random --anomaly-rate=25
```

Genera poi un report (vedi sotto) sulla version creata: nel foglio `Data_Quality`
i contatori delle anomalie saranno diversi da zero.

---

## Comandi principali (Makefile)

```text
make help      Mostra l'elenco dei comandi
make build     Builda le immagini Docker
make up        Avvia lo stack
make down      Ferma lo stack
make migrate   Esegue le migration
make fresh     Ricrea il DB da zero
make seed      Seed di base
make demo      Dataset demo grande coerente (ARGS=...)
make demo_random  Dataset demo con anomalie per Data Quality (ARGS=...)
make worker    Avvia un worker di coda in foreground (debug)
make test      Esegue la suite PHPUnit
make client    Esegue il client di integrazione end-to-end
make logs      Segue i log
make shell     Shell nel container app
```

---

## Test

```bash
make test
# oppure
docker-compose exec app ./vendor/bin/phpunit
```

I test girano su **SQLite in memoria** con coda **`sync`**: una sola esecuzione
copre l'intero flusso (ingestione → export → stato → download), inclusi
aggregazioni, filtri su JSON, validazione 422 e retry. Vedi `tests/`.

---

## API & esempi cURL

Base URL: `http://localhost:8080/api/v1`

### 1. Creare una version

```bash
curl -s -X POST http://localhost:8080/api/v1/versions \
  -H 'Content-Type: application/json' \
  -d '{"name":"Campagna Q1 2026","game":"quiz-natalizio"}'
# => {"id":1,"name":"Campagna Q1 2026",...}
```

### 2. Ingestione dati

**Player** (UPSERT idempotente su `external_id`):

```bash
curl -s -X POST http://localhost:8080/api/v1/versions/1/players \
  -H 'Content-Type: application/json' \
  -d '{
    "players": [
      {"external_id":"p1","email":"mario@example.com","registered_at":"2026-01-05","total_score":1200,"payload":{"language":"it","country":"IT"}},
      {"external_id":"p2","email":"jane@example.com","registered_at":"2026-01-08","total_score":800,"payload":{"language":"en","country":"GB"}}
    ]
  }'
# => {"inserted":2}
```

**Eventi** (`payload` JSON libero):

```bash
curl -s -X POST http://localhost:8080/api/v1/versions/1/events \
  -H 'Content-Type: application/json' \
  -d '{
    "events": [
      {"player_id":1,"type":"open","occurred_at":"2026-01-05T10:00:00Z","payload":{"score":120,"level":3,"language":"it","utm_source":"linkedin","custom_field_1":"azienda-x"}},
      {"player_id":1,"type":"complete","occurred_at":"2026-01-06T11:00:00Z","payload":{"score":900,"level":10,"language":"it"}}
    ]
  }'
# => {"inserted":2}
```

Endpoint analoghi: `/transactions`, `/answers`, `/rewards`.

### 3. Richiedere un export

Payload completo (anche in [examples/export-request.json](examples/export-request.json)):

```bash
curl -s -X POST http://localhost:8080/api/v1/versions/1/exports \
  -H 'Content-Type: application/json' \
  -d '{
    "format": "xlsx",
    "date_from": "2026-01-01",
    "date_to": "2026-01-31",
    "sheets": [
      {
        "name": "players",
        "columns": ["player_id", "email", "registered_at", "total_score", "payload.language"],
        "filters": {"payload.language": "it"},
        "sort": ["registered_at:desc"]
      },
      {
        "name": "events_summary",
        "source": "events",
        "group_by": ["type", "payload.language"],
        "metrics": ["count", "unique_players"]
      }
    ]
  }'
# => HTTP 202
# {"id":"<uuid>","status":"pending","progress":0,...}
```

Capacità configurabili dal client:
- **fogli** multipli (`sheets`)
- **colonne** standard e **dentro JSON** (`payload.language`)
- **filtri** su campi standard e JSON (scalare o array per `IN`)
- **ordinamenti** (`"campo:asc|desc"`)
- **aggregazioni** (`group_by` + `metrics`: `count`, `unique_players`, `sum|avg|min|max:<campo>`)
- **intervallo temporale** (`date_from`/`date_to`)
- **formato** output (`format`)

### 3-bis. Report completo (profilo "full")

Oltre agli export "custom" (fogli scelti dal client), il motore sa produrre un
**report completo e curato**, fedele all'esempio di consegna, con 8 fogli:
`README`, `KPIs`, `Configurazione_Richiesta`, `Players` (con colonne derivate via
join: `events_count`, `levels_completed`, `reward`, `status`, `revenue`, …),
`Events_Summary` (con `events_per_player` e `avg_score`), `Answers` (con
`percentage`), `Transactions` (con email/lingua del player) e `Data_Quality`
(controlli di anomalie).

Si attiva con `"report": "full"` **oppure** semplicemente non passando `sheets`:

```bash
curl -s -X POST http://localhost:8080/api/v1/versions/1/exports \
  -H 'Content-Type: application/json' \
  -d @examples/report-request.json
# => 202; poi GET stato/download come sotto
```

> Un report reale generato dal motore è in [`examples/sample_report.xlsx`](examples/sample_report.xlsx)
> (struttura verificata 1:1 rispetto all'esempio di consegna).

### 4. Stato e download

```bash
# Stato (poll finché status = completed)
curl -s http://localhost:8080/api/v1/exports/<uuid>
# {"status":"processing","progress":42,"rows_written":210000,"rows_estimated":500000,...}
# {"status":"completed","progress":100,"download_url":".../download",...}

# Download del file XLSX
curl -s -L -o export.xlsx http://localhost:8080/api/v1/exports/<uuid>/download
```

> **Esempio di export generato**: nel repo trovi un file reale prodotto dal motore
> in [`examples/sample_export.xlsx`](examples/sample_export.xlsx) (foglio `players`
> filtrato `language=it` + foglio `events_summary` aggregato per `type`/`language`
> con `count` e `unique_players`), generato dalla definizione
> [`examples/export-request.json`](examples/export-request.json).

### 5. Preview, cancel, retry, template (bonus)

```bash
# Anteprima sincrona (max 100 righe per foglio, nessun file generato)
curl -s -X POST http://localhost:8080/api/v1/versions/1/exports/preview \
  -H 'Content-Type: application/json' \
  -d @examples/export-request.json

# Cancellazione di un export in coda/in corso
curl -s -X POST http://localhost:8080/api/v1/exports/<uuid>/cancel

# Retry di un export fallito/cancellato
curl -s -X POST http://localhost:8080/api/v1/exports/<uuid>/retry

# Salvare un template riutilizzabile
curl -s -X POST http://localhost:8080/api/v1/versions/1/export-templates \
  -H 'Content-Type: application/json' \
  -d '{"name":"report-it","definition":{"sheets":[{"name":"players","filters":{"payload.language":"it"}}]}}'

# Usare il template in un export
curl -s -X POST http://localhost:8080/api/v1/versions/1/exports \
  -H 'Content-Type: application/json' \
  -d '{"template_id":1,"date_from":"2026-01-01","date_to":"2026-01-31"}'
```

### Client di integrazione end-to-end

Uno script che esegue *tutto* il flusso (crea version → ingerisce → esporta →
polling → download):

```bash
make client
# oppure
docker-compose exec app php client/client.php http://localhost:8080
```

---

## Struttura del progetto

```
app/
  Console/Commands/SeedDemoData.php   Generatore dataset demo dimensionabile
  Http/Controllers/                   Version, Ingestion, Export, Template
  Jobs/ProcessExportJob.php           Job asincrono (retry, backoff, failed)
  Models/                             Eloquent (Version, Player, Event, Export, ...)
  Services/
    IngestionService.php              INSERT/UPSERT a blocchi
    Export/
      DatasetRegistry.php             Whitelist delle sorgenti/colonne
      FieldResolver.php               Campo client -> espressione SQL (sicura)
      SqlJsonPath.php                 Path JSON -> SQL (MySQL/SQLite)
      SheetQueryBuilder.php           Foglio -> query (righe o aggregazione)
      XlsxWriter.php                  Scrittura XLSX in streaming (OpenSpout)
      ExportDefinitionValidator.php   Validazione "fail fast" (422)
      ExportEngine.php                Orchestrazione: cursor -> XLSX + progress
config/                               app, database, queue, cache, filesystems, export
database/migrations/                  Schema (versions, players, events, exports, ...)
database/seeders/                     Seed di base
routes/api.php                        Rotte API v1
tests/                                Unit + Feature (SQLite in memoria)
client/client.php                     Client di integrazione
docs/                                 ARCHITECTURE.md + openapi.yaml
docker/, Dockerfile, docker-compose.yml, Makefile
```

---

## Configurazione (.env)

| Variabile | Default | Descrizione |
|---|---|---|
| `DB_*` | mysql/export | Connessione MySQL |
| `QUEUE_CONNECTION` | `database` | Backend coda (`database`/`redis`/`sync`) |
| `CACHE_DRIVER` | `redis` | Backend cache |
| `EXPORT_DISK` | `local` | Disco file generati (`local`/`s3`) |
| `EXPORT_MAX_ROWS` | `500000` | Tetto righe per export (anti-abuso) |
| `EXPORT_PREVIEW_ROWS` | `100` | Righe della preview sincrona |
| `EXPORT_PROGRESS_BATCH` | `2000` | Ogni quante righe aggiornare progress/cancel |
| `EXPORT_JOB_TRIES` | `3` | Tentativi (retry automatico) |
| `EXPORT_JOB_TIMEOUT` | `3600` | Timeout job (s) |

---

## Scelte tecniche in breve

- **Lumen 8 / PHP 7.3**: framework microservizio dell'ecosistema Laravel.
- **Asincronia via coda**: la richiesta HTTP *accoda* e risponde `202`; il worker
  genera il file. Stato e `progress` persistiti sulla tabella `exports`.
- **OpenSpout + cursor unbuffered**: lettura e scrittura **in streaming**, RAM
  costante anche a 500k righe.
- **Whitelist rigorosa** (`DatasetRegistry`/`FieldResolver`) + binding dei valori:
  filtri/colonne/sort dinamici **senza** superficie di SQL injection.
- **Campi JSON dinamici**: `payload.x.y` utilizzabili in colonne, filtri, sort,
  group_by, metriche; tradotti per MySQL e SQLite.

Dettagli e diagrammi: [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md).

---

## Funzionalità bonus implementate

- ✅ **Client** Client↔Server (`client/client.php`)
- ✅ **Preview** limitata (100 righe) — `POST /exports/preview`
- ✅ **Template** salvabili — `export-templates`
- ✅ **Campi JSON dinamici** — `payload.*` ovunque
- ✅ **Retry automatico** degli export falliti — `tries`/`backoff` + `/retry`
- ✅ **Progress percentage** — campo `progress`
- ✅ **Cancellazione** export in corso — `/cancel`
