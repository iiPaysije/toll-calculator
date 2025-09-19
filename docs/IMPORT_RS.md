# Import RS Toll Prices

This document explains how to extract Serbian toll prices from `docs/toll_price_srb.pdf` into a normalized CSV used by the importer.

## Prerequisites

Option A (recommended): System tool `pdftotext` (Poppler)
- macOS (Homebrew): `brew install poppler`

Option B: PHP-only parser (already added)
- `smalot/pdfparser` is required and already installed via Composer.

## Steps

1. Extract text (optional, for inspection):
   - If using Poppler:
     - `pdftotext -layout docs/toll_price_srb.pdf data/tmp/rs_toll_prices.txt`
   - Or let the command dump text automatically via `--dump-text`.

2. Produce CSV:
   - Run the Symfony command:
     - `php bin/console app:extract-rs-toll-prices docs/toll_price_srb.pdf --out=data/import/rs_toll_prices.csv`
   - Options:
     - `--dump-text=data/tmp/rs_toll_prices.txt`  Save raw text for debugging
     - `--valid-from=2025-01-01`                 Validity start date
     - `--valid-to=`                              Validity end date (empty for open-ended)

3. CSV schema

Header:
```
country_code,station_from_name,station_to_name,vehicle_class_code,price_rsd,price_eur,valid_from,valid_to
```
- `country_code`: Always `RS`
- `vehicle_class_code`: `CAR` or `MOTORCYCLE` in MVP; heavy classes are skipped
- `price_eur`: left empty; importer will fill using NBS rate later
- Dates: ISO format `YYYY-MM-DD`

## Notes and reliability
- PDF layouts vary. The extractor uses heuristics to detect segments like `Beograd - Niš` and price lines with labels like `I`, `Motocikl`, `Automobil`.
- If the output looks off, check `data/tmp/rs_toll_prices.txt` and adjust the regexes in `src/Command/ExtractRsTollPricesCommand.php`.
- For tables rendered as images (scanned PDFs), use OCR first (e.g., `ocrmypdf`).
