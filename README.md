# Toll Calculator (MVP)

A Symfony 7 web application that calculates road toll costs for routes (city X → city Y). MVP focuses on Serbia (classic toll booths) with planned support for neighboring countries and vignettes.

## Prerequisites

- PHP >= 8.2
- Composer
- MySQL 8.x (local instance)
- macOS + zsh (assumed in examples)
- Optional for PDF debugging: Poppler (`pdftotext`) via Homebrew: `brew install poppler`

## Quick start

1) Install PHP dependencies
```bash
composer install
```

2) Configure environment
- Copy `.env` to `.env.local` and set your DB URL. Example (TCP):
```
DATABASE_URL="mysql://root:PASSWORD@127.0.0.1:3306/toll_calculator?serverVersion=8.0&charset=utf8mb4"
```

3) Create database (no entities yet in MVP)
```bash
php bin/console doctrine:database:create
```

4) Run the app (development server)
- Using Symfony CLI (recommended):
```bash
symfony server:start -d
```
- Or PHP built-in server (fallback):
```bash
php -S 127.0.0.1:8000 -t public
```
Open http://127.0.0.1:8000

## Current features

### 1) Extract Serbian toll prices (PDF → CSV)
Parses `docs/toll_price_srb.pdf` and produces a normalized CSV for later import.

- Command:
```bash
php bin/console app:extract-rs-toll-prices docs/toll_price_srb.pdf --out=data/import/rs_toll_prices.csv --dump-text=data/tmp/rs_toll_prices.txt
```
- Output files:
  - `data/import/rs_toll_prices.csv` (header: `country_code,station_from_name,station_to_name,vehicle_class_code,price_rsd,price_eur,valid_from,valid_to`)
  - `data/tmp/rs_toll_prices.txt` (raw extracted text for debugging)
- Notes:
  - MVP exports two classes: `MOTORCYCLE` (K1-a) and `CAR` (K1). Heavier classes are skipped for now.
  - `price_eur` is read from PDF and normalized (comma → dot). Currency conversion via NBS will be added later.

See `docs/IMPORT_RS.md` for more tips and troubleshooting.

## Roadmap (MVP)
- Define DB model and migrations (Country, VehicleClass, TollStation, TollSegment, TollPrice, Vignette, ExchangeRate, RouteCache)
- Seed minimal RS data and build CSV importer
- API: `POST /api/toll/calculate` (returns total + breakdown)
- Twig UI form with i18n (sr/en)
- Exchange rate service (NBS)

## Project structure
- `src/` — Symfony application code
- `public/` — Web root
- `docs/` — Documentation & sources (e.g., `toll_price_srb.pdf`)
- `data/import/` — Generated CSV files for import
- `data/tmp/` — Intermediate/debug text dumps

## Troubleshooting
- If the extractor yields 0 rows:
  - Check `data/tmp/rs_toll_prices.txt` to see the parsed text
  - Some PDFs need better text layout — install Poppler and try `pdftotext -layout` for inspection
  - Adjust regex heuristics in `src/Command/ExtractRsTollPricesCommand.php` if the PDF format changes

## License
Proprietary (see `composer.json`).