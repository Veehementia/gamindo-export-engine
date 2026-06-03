# Architettura — Gamindo Export Engine

Questo documento spiega **come è fatto il sistema e perché**, componente per
componente. Per l'uso pratico (setup, comandi, cURL) vedi il [README](../README.md).

---

## 1. Il problema in una frase

Ingerire **grandi volumi** di dati statistici di una *version* di gioco
(10M+ eventi, 1M+ player) ed esporli in **export XLSX configurabili** dal client
(fogli, colonne, filtri anche su campi JSON, ordinamenti, aggregazioni,
intervallo temporale), generati in modo **asincrono** con stato e download.

I due vincoli che guidano *tutte* le scelte sono:

1. **Volume**: non si può tenere in RAM né l'input né l'output.
2. **Latenza percepita**: la richiesta HTTP non può bloccarsi minuti per generare
   un file da 500k righe.

---

## 2. Vista d'insieme

```
                  ┌──────────────┐      HTTP        ┌──────────────────────┐
   Client  ───────▶  nginx       ├────────────────▶ │  PHP-FPM (Lumen)      │
                  └──────────────┘                  │  Controllers          │
                                                     │   ├─ Ingestion        │
                                                     │   ├─ Export (enqueue) │
                                                     │   └─ Status/Download  │
                                                     └─────────┬────────────┘
                                                               │ dispatch
                                                               ▼
                                                     ┌──────────────────────┐
   exports (stato/progress) ◀── DB ──────────────────┤   Queue (database/    │
   jobs / failed_jobs                                 │   redis)              │
                                                       └─────────┬───────────┘
                                                                 │ queue:work
                                                                 ▼
                                                     ┌──────────────────────┐
   storage (xlsx) ◀── stream ─────────────────────── │  Worker               │
                                                       │  ProcessExportJob    │
                                                       │   └─ ExportEngine    │
                                                       │       (cursor→XLSX)  │
                                                       └──────────────────────┘
```

- **app (PHP-FPM)** gestisce le richieste: ingestione, *accodamento* degli export,
  consultazione stato e download.
- **worker** consuma la coda ed esegue la generazione pesante, *fuori* dal ciclo HTTP.
- **mysql** è lo storage; **redis** è cache e backend di coda opzionale.

---

## 3. Stack e perché

| Scelta | Motivo |
|---|---|
| **Lumen 8** (PHP 7.3) | È il framework "microservizio" di casa Laravel: stesso ecosistema (Eloquent, coda, validazione) ma più snello di Laravel full. Coerente con il termine *microservizio* dell'esercizio e con il vincolo PHP 7.3. |
| **MySQL 8 / InnoDB** | Richiesto. JSON nativo per i `payload` liberi, indici compositi per le query di export. |
| **Coda `database` (default), `redis` opzionale** | `database` = zero infrastruttura extra, perfetta per pochi job "grossi". `redis` consigliato sotto alta concorrenza. Si cambia da `.env`. |
| **OpenSpout** (non PhpSpreadsheet) | OpenSpout scrive l'XLSX **in streaming** riga per riga: RAM ~costante anche a 500k righe. PhpSpreadsheet tiene tutto in memoria e non regge i volumi richiesti. |
| **Connessione MySQL unbuffered dedicata** | Per leggere milioni di righe senza caricarle in RAM lato PHP. Isolata dalla connessione applicativa (vedi §6). |

---

## 4. Modello dati

```
versions ──1:N── players
         ──1:N── events         (≈ 10M righe, la tabella critica)
         ──1:N── transactions
         ──1:N── answers
         ──1:N── rewards
         ──1:N── exports          (stato/progress dei job)
         ──1:N── export_templates (bonus)
```

Punti chiave:

- **`payload JSON` su ogni entità**: i campi variabili (`score`, `level`,
  `language`, `utm_source`, `custom_field_1`, …) vivono qui. Permette schema
  flessibile senza migrazioni a ogni nuovo campo.
- **`events.player_id` indicizzato ma SENZA foreign key**: l'ingestione di eventi
  deve poter arrivare anche prima o indipendentemente dall'anagrafica player, ed
  evitiamo il costo del controllo FK su inserimenti massivi.
- **Indice `(version_id, type, occurred_at)` su `events`**: copre il pattern di
  query dominante (filtra per version+tipo, ordina/filtra per data).
- **`players` UNIQUE `(version_id, external_id)`**: rende l'ingestione idempotente
  (UPSERT).
- **`exports`** è una **macchina a stati**: `pending → processing → completed |
  failed | cancelled`, con `progress`, `rows_estimated/rows_written`,
  `cancel_requested`, `attempts`, e i metadati del file.

---

## 5. Ingestione (scrittura ad alto volume)

`IngestionController` → `IngestionService`:

- **INSERT a blocchi** (`array_chunk`, 1000 righe) invece di una INSERT per record:
  meno round-trip, throughput molto più alto.
- **Player in UPSERT** su `(version_id, external_id)`: rinviare lo stesso batch non
  crea duplicati (importante per retry lato client).
- **Validazione volutamente leggera** (solo i campi indispensabili): i `payload`
  sono liberi e validarli a fondo ucciderebbe il throughput.

---

## 6. Il motore di export (lettura ad alto volume)

Il flusso vive in `app/Services/Export/`. Pipeline di una richiesta:

```
ExportDefinitionValidator   → valida e fa "dry build" dei fogli (fail fast, 422)
        │
ExportController            → crea Export(pending) + dispatch(ProcessExportJob)
        │  (HTTP risponde 202 subito)
        ▼
ProcessExportJob (worker)   → ricarica Export, gestisce retry/cancel/failed
        │
ExportEngine.process()
        ├─ prepareSheets()        → per ogni foglio: SheetQueryBuilder + stima righe
        ├─ check max_rows         → 500k di default, altrimenti fallisce subito
        ├─ XlsxWriter.open()      → file temporaneo
        ├─ per ogni foglio:
        │     query->cursor()     → STREAMING dal DB (1 riga per volta)
        │       └─ XlsxWriter.addRow()
        │       └─ ogni N righe: update progress + check cancellazione
        └─ Storage::putFileAs()   → deposito su disco (local/s3) + stato completed
```

### 6.1 SheetQueryBuilder + whitelist (sicurezza)

Ogni foglio è tradotto in SQL da `SheetQueryBuilder`, che si appoggia a:

- **`DatasetRegistry`**: dichiara le sorgenti esportabili (`players`, `events`, …)
  e, per ciascuna, la **whitelist** di colonne esponibili, la colonna data, se ha
  `payload`, e la colonna identità player.
- **`FieldResolver`**: traduce un riferimento del client in espressione SQL **solo
  se** è in whitelist o è un path `payload.*` valido. Tutto il resto → 422.
- **`SqlJsonPath`**: traduce `payload.language` in
  `JSON_UNQUOTE(JSON_EXTRACT(\`payload\`, '$."language"'))` (MySQL) o
  `json_extract(...)` (SQLite), validando i segmenti con whitelist di caratteri.

> **SQL injection**: i valori dei filtri sono sempre *bound* (placeholder `?`).
> I nomi di colonna/campo non sono mai concatenati grezzi: passano dalla whitelist
> del dataset o dalla validazione del path JSON. Non c'è superficie di injection.

Due modalità:

- **righe**: `columns` selezionate (incluse `payload.*`), `filters`, `sort`.
- **aggregazione**: `group_by` + `metrics` (`count`, `unique_players`,
  `sum|avg|min|max:<campo>`). Attivata quando il foglio ha `group_by`/`metrics`.

### 6.2 Streaming a memoria costante

Il punto più importante per i volumi:

- **Lettura**: `->cursor()` su una **connessione MySQL dedicata e *unbuffered***
  (`PDO::MYSQL_ATTR_USE_BUFFERED_QUERY = false`). Il driver non carica l'intero
  result set in RAM: le righe arrivano dal server una per volta.
- **Perché una connessione separata**: in modalità unbuffered non si possono
  eseguire altre query sulla *stessa* connessione finché il cursore è aperto.
  Gli update di `progress` e il check di `cancel` girano quindi sulla connessione
  applicativa (bufferizzata), che è un'altra connessione. Le due non si pestano i piedi.
- **Scrittura**: OpenSpout butta ogni riga sul file. RAM ~costante.

Risultato: un export da 500k righe usa più o meno la stessa memoria di uno da 100.

### 6.3 Progress, cancellazione, retry (bonus)

- **Progress %**: `floor(100 * rows_written / rows_estimated)` aggiornato ogni
  `EXPORT_PROGRESS_BATCH` righe (default 2000) — non a ogni riga, per non
  martellare il DB. La stima righe usa `getCountForPagination()` (gestisce anche
  i gruppi nelle aggregazioni).
- **Cancellazione**: l'endpoint imposta `cancel_requested`; il job lo rilegge dal
  DB a ogni batch e interrompe pulendo il file temporaneo. Se l'export è ancora
  `pending`, viene chiuso subito.
- **Retry automatico**: il job ha `tries` e `backoff` crescente. Esauriti i
  tentativi, `failed()` marca l'export `failed` con il messaggio d'errore.
  L'endpoint `/retry` ri-accoda export `failed`/`cancelled`.

---

## 7. Come scala (oltre l'esercizio)

- **Worker orizzontali**: più container `worker` = più export in parallelo. Con
  coda `redis` la concorrenza è più efficiente.
- **events partizionata** per `version_id`/range temporale, o spostata su uno
  storage colonnare/OLAP se le aggregazioni diventano il collo di bottiglia.
- **Indici dedicati** sui campi JSON più filtrati, tramite *generated columns*
  indicizzate (es. `language` estratta da `payload`), senza cambiare le API.
- **Storage S3** per i file: già supportato, basta `EXPORT_DISK=s3`.
- **TTL / cleanup** dei file vecchi via task schedulato (placeholder già nel
  `Console\Kernel`).

---

## 8. Test

- **Unit** (`SqlJsonPathTest`): traduzione path JSON e difesa anti-injection.
- **Feature** (`IngestionTest`, `ExportTest`): girano su **SQLite in memoria**
  con `QUEUE_CONNECTION=sync` (il job viene eseguito inline), così un singolo
  `phpunit` copre l'intero flusso end-to-end — ingestione → export → stato →
  download — senza dipendere da infrastruttura esterna. Il codice JSON è
  *driver-aware*, quindi gli stessi test validano la logica che in produzione gira
  su MySQL.
