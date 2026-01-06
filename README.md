# FP Hosting Cleaner

Plugin WordPress per pulire file ridondanti, duplicati, cache obsolete e file temporanei dall'hosting WordPress.

## Caratteristiche

- **Scansione intelligente**: Identifica automaticamente file ridondanti, duplicati, cache obsolete, backup vecchi e file temporanei
- **Pulizia sicura**: Verifica sempre che i file siano all'interno di ABSPATH e non siano file critici del sistema
- **Interfaccia admin**: Pagina dedicata in Strumenti > Hosting Cleaner per gestire scansioni e pulizie
- **Logging completo**: Registra tutte le operazioni di pulizia in una tabella dedicata
- **PSR-4**: Architettura moderna con autoload Composer

## Installazione

1. Assicurati che il plugin sia nella cartella `wp-content/plugins/FP-Hosting-Cleaner`
2. Esegui `composer install --no-dev` nella cartella del plugin
3. Attiva il plugin dalla pagina Plugin di WordPress

## Utilizzo

1. Vai su **Strumenti > Hosting Cleaner**
2. Clicca su **Avvia Scansione** per analizzare l'hosting
3. Esamina i risultati per categoria (Duplicati, File Temporanei, Cache, Backup, ecc.)
4. Clicca su **Pulisci** per ogni categoria che vuoi pulire
5. Conferma l'operazione

## Tipi di file identificati

- **Duplicati**: File con lo stesso contenuto (hash MD5)
- **File Temporanei**: File con estensioni .tmp, .temp, .bak, .old, .swp, ~
- **File Cache**: File nelle directory cache (w3tc, wp-rocket, litespeed, ecc.)
- **File Backup**: File di backup vecchi (.sql, .zip, .tar, .gz, .backup)
- **File Vecchi**: File non modificati da più di X giorni (configurabile)
- **Directory Vuote**: Directory senza contenuto

## Impostazioni

- **Giorni minimi per file vecchi**: Definisce dopo quanti giorni un file è considerato "vecchio"
- **Dimensione massima per controllo duplicati**: Limite di dimensione per il controllo hash (per performance)

## Sicurezza e Protezioni - Modalità Ultra-Sicura

Il plugin include un **sistema completo di protezione multi-livello** che impedisce l'eliminazione di file necessari:

### File Protetti Automaticamente

1. **WordPress Core**: Tutti i file e directory di WordPress (wp-admin, wp-includes, wp-config.php, .htaccess, index.php, ecc.)
2. **Plugin Attivi**: Tutti i file di tutti i plugin attualmente attivi
3. **Temi Attivi**: Tutti i file del tema attivo e del tema parent (se presente)
4. **Uploads Recenti**: File nella cartella uploads modificati negli ultimi 30 giorni
5. **File di Configurazione**: wp-config.php, .htaccess, web.config, robots.txt, sitemap.xml, ecc.
6. **File di Database**: Tutti i file .sql (backup database)
7. **File di Sistema**: File di log importanti (debug.log, error_log), file .gitignore, .gitkeep, ecc.
8. **File di Autenticazione**: Directory .well-known/

### Misure di Sicurezza

- **Verifica ABSPATH**: Tutti i file devono essere all'interno di ABSPATH
- **Protezione Percorsi**: Sistema di whitelist per percorsi protetti
- **Protezione Pattern**: Pattern regex per identificare file critici
- **Verifica Permessi**: Solo amministratori possono eseguire operazioni
- **Nonce Verification**: Tutte le operazioni AJAX richiedono nonce valido
- **Protezione Real-time**: Ogni file viene verificato prima dell'eliminazione

### Protezione Multi-Livello

Il sistema di protezione opera su **quattro livelli**:

1. **Scanner**: Esclude automaticamente i file protetti dalla scansione
2. **Cleaner**: Verifica nuovamente ogni file prima dell'eliminazione
3. **ProtectionManager**: Classe centralizzata che gestisce tutte le regole di protezione
4. **BackupManager**: Crea backup automatici prima di ogni eliminazione

### Backup Automatico

**IMPORTANTE**: Per default, il plugin crea automaticamente un backup di ogni file prima di eliminarlo.

- **Posizione backup**: `wp-content/fp-hosting-cleaner-backups/`
- **Formato**: Ogni file viene salvato con timestamp e hash univoco
- **Metadati**: Ogni backup include informazioni sul file originale (percorso, dimensione, data)
- **Ripristino**: I file eliminati possono essere ripristinati dai backup
- **Pulizia automatica**: I backup vecchi (>30 giorni) possono essere puliti automaticamente

### Doppia Conferma

Prima di eliminare qualsiasi file, il plugin richiede:
1. **Prima conferma**: Mostra dettagli completi (numero file, dimensione totale)
2. **Seconda conferma**: Conferma finale esplicita

### Visualizzazione Preventiva

Prima di procedere con l'eliminazione, puoi:
- Vedere esattamente quali file verranno eliminati
- Controllare dimensioni e percorsi
- Verificare che non ci siano file importanti nella lista

### Impostazioni di Sicurezza

Nelle impostazioni puoi:
- **Abilitare/Disabilitare backup automatico** (consigliato: sempre ON)
- Configurare giorni minimi per file vecchi
- Configurare dimensione massima per controllo duplicati

## Struttura

```
FP-Hosting-Cleaner/
├── composer.json          # Configurazione Composer e PSR-4
├── fp-hosting-cleaner.php # File principale del plugin
├── src/
│   ├── Plugin.php        # Classe principale
│   ├── Scanner.php       # Scanner per file ridondanti
│   ├── Cleaner.php        # Esecuzione pulizia
│   ├── Admin.php         # Interfaccia admin
│   └── Logger.php         # Sistema di logging
├── assets/
│   └── admin.js          # JavaScript per interfaccia admin
└── vendor/               # Autoloader Composer
```

## Note

- Le scansioni possono richiedere tempo su hosting con molti file
- Si consiglia di fare un backup prima di eseguire pulizie massicce
- Il plugin esclude automaticamente se stesso dalla scansione

## Licenza

GPL v2 or later
