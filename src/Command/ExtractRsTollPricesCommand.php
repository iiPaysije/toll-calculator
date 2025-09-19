<?php

namespace App\Command;

use Smalot\PdfParser\Parser as PdfParser;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;

#[AsCommand(
    name: 'app:extract-rs-toll-prices',
    description: 'Extracts Serbian toll prices from a PDF into CSV (country_code, station_from_name, station_to_name, vehicle_class_code, price_rsd, price_eur, valid_from, valid_to)'
)]
class ExtractRsTollPricesCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('pdf', InputArgument::REQUIRED, 'Path to toll price PDF (e.g., docs/toll_price_srb.pdf)')
            ->addOption('out', null, InputOption::VALUE_REQUIRED, 'Output CSV path', 'data/import/rs_toll_prices.csv')
            ->addOption('dump-text', null, InputOption::VALUE_REQUIRED, 'Optional dump of extracted text to file', 'data/tmp/rs_toll_prices.txt')
            ->addOption('valid-from', null, InputOption::VALUE_REQUIRED, 'Validity start date (YYYY-MM-DD) used to fill CSV', date('Y-01-01'))
            ->addOption('valid-to', null, InputOption::VALUE_REQUIRED, 'Validity end date (YYYY-MM-DD) used to fill CSV', '')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $pdfPath = (string)$input->getArgument('pdf');
        $outCsv = (string)$input->getOption('out');
        $dumpTextPath = (string)$input->getOption('dump-text');
        $validFrom = (string)$input->getOption('valid-from');
        $validTo = (string)$input->getOption('valid-to');

        $fs = new Filesystem();
        if (!$fs->exists($pdfPath)) {
            $output->writeln("<error>PDF not found: {$pdfPath}</error>");
            return Command::FAILURE;
        }

        $parser = new PdfParser();
        $pdf = $parser->parseFile($pdfPath);
        $text = $pdf->getText();

        if ($dumpTextPath) {
            $fs->dumpFile($dumpTextPath, $text);
            $output->writeln("Dumped text to {$dumpTextPath}");
        }

        $rows = $this->extractRowsFromText($text, $validFrom, $validTo);

        // Write CSV header
        $header = ['country_code','station_from_name','station_to_name','vehicle_class_code','price_rsd','price_eur','valid_from','valid_to'];
        $csvLines = [];
        $csvLines[] = implode(',', $header);
        foreach ($rows as $r) {
            $csvLines[] = implode(',', array_map(function ($v) {
                // escape commas and quotes in CSV minimally
                $v = (string)$v;
                if (str_contains($v, ',') || str_contains($v, '"')) {
                    $v = '"' . str_replace('"', '""', $v) . '"';
                }
                return $v;
            }, $r));
        }
        $fs->dumpFile($outCsv, implode("\n", $csvLines) . "\n");
        $output->writeln(sprintf('<info>Wrote %d rows to %s</info>', count($rows), $outCsv));

        return Command::SUCCESS;
    }

    /**
     * Parser for RS toll PDF (tabular layout):
     * - Detects corridor header: "Deonica: FROM - TO"
     * - Parses rows with five RSD values, station name, five EUR values
     * - Emits two rows per line (MOTORCYCLE=K1-a, CAR=K1)
     *
     * @return array<int, array{country_code:string,station_from_name:string,station_to_name:string,vehicle_class_code:string,price_rsd:string,price_eur:string,valid_from:string,valid_to:string}>
     */
    private function extractRowsFromText(string $text, string $validFrom, string $validTo): array
    {
        $rows = [];
        $country = 'RS';

        $text = preg_replace("/\r\n|\r/", "\n", $text);
        $allLines = array_values(array_filter(array_map('trim', explode("\n", $text))));

        $fromCorridor = null; // e.g., "Beograd"

        foreach ($allLines as $rawLine) {
            $line = $rawLine;
            if ($line === '' || preg_match('/^str\.|^POSEBNA|^PUTARINA|^važi od:|^\(po srednjoj|^BEOGRAD$|^DIMITROVGRAD$|^PREŠEVO$|^ŠID$|^STARA PAZOVA$|^OBRENOVAC$/iu', $line)) {
                continue; // skip headers/footers
            }

            // Detect corridor header
            if (preg_match('/^Deonica:\s*(.+?)\s*[–-]\s*(.+)$/iu', $line, $m)) {
                $fromCorridor = trim($m[1]);
                // $toCorridor = trim($m[2]); // not used directly
                continue;
            }

            if (!$fromCorridor) {
                continue; // ignore lines before first corridor header
            }

            // Preprocess: insert spaces between concatenated numbers like "5101.0301.540" -> "510 1.030 1.540"
            $line = $this->splitConcatenatedNumbers($line);

            // Try a single regex to grab: 5 RSD numbers, station name, 5 EUR numbers
            $pattern = '/^\s*(\d{1,4}(?:\.\d{3})?)\s+(\d{1,4}(?:\.\d{3})?)\s+(\d{1,4}(?:\.\d{3})?)\s+(\d{1,4}(?:\.\d{3})?)\s+(\d{1,4}(?:\.\d{3})?)\s+(.+?)\s+(\d{1,3},\d{2})\s*(\d{1,3},\d{2})\s*(\d{1,3},\d{2})\s*(\d{1,3},\d{2})\s*(\d{1,3},\d{2})\s*$/u';
            if (!preg_match($pattern, $line, $m)) {
                continue;
            }
            $rsd5 = [$m[1], $m[2], $m[3], $m[4], $m[5]];
            $station = trim($m[6]);
            $eur5 = [$m[7], $m[8], $m[9], $m[10], $m[11]];
            if ($station === '' || preg_match('/^(K1|K2|K3|K4|K1-a)/i', $station)) {
                continue;
            }

            // Map class indices: [K1-a, K1, K2, K3, K4]
            $map = [
                0 => 'MOTORCYCLE',
                1 => 'CAR',
            ];

            foreach ($map as $idx => $classCode) {
                $priceRsd = $this->normalizeNumber($rsd5[$idx]);
                $priceEur = $this->normalizeEuro($eur5[$idx]);
                $rows[] = [
                    'country_code' => $country,
                    'station_from_name' => $fromCorridor,
                    'station_to_name' => $station,
                    'vehicle_class_code' => $classCode,
                    'price_rsd' => $priceRsd,
                    'price_eur' => $priceEur,
                    'valid_from' => $validFrom,
                    'valid_to' => $validTo,
                ];
            }
        }

        return $rows;
    }

    private function splitConcatenatedNumbers(string $line): string
    {
        // Insert a space between a 2-4 digit number and a following d.ddd token
        // e.g., "5101.010" -> "510 1.010"; avoids cascading splits like "01.220"
        $cur = preg_replace('/(?<=\d{2,4})(?=\d\.\d{3})/u', ' ', $line);
        // Also split glued thousands tokens: "1.0602.120" -> "1.060 2.120"
        $cur = preg_replace('/(\d{1,3}\.\d{3})(?=\d{1,3}\.\d{3})/u', '$1 ', $cur);
        return $cur;
    }

    private function mapClassLabelToCode(string $label): ?string
    {
        // Map common Serbian toll classes to MVP vehicle codes
        // This is a simplification for MVP: CAR and MOTORCYCLE
        $label = str_replace(['.', '–', '-'], ['','','-'], $label);
        $label = trim($label);

        // Common cues
        if (preg_match('/MOTO|MOTOR|MOTOCIKL/i', $label)) {
            return 'MOTORCYCLE';
        }
        if (preg_match('/AUTO|PUTNI|PASENGER|CAR/i', $label)) {
            return 'CAR';
        }
        // If roman numerals used, assume I ~ car, II+ skip for MVP or map some
        if (in_array($label, ['I','IA','IB','I A','I B'], true)) {
            return 'CAR';
        }
        if (in_array($label, ['II','III','IV'], true)) {
            // skip heavy vehicles in MVP
            return null;
        }
        return null;
    }

    private function normalizeNumber(string $num): string
    {
        // Convert formats like "1 200", "1.200", "1200" to plain integer-ish string
        $num = trim($num);
        $num = str_replace(['.', ' '], '', $num);
        // ensure only digits remain
        $num = preg_replace('/[^0-9]/', '', $num);
        return $num !== '' ? $num : '0';
    }

    private function normalizeEuro(string $eur): string
    {
        // Convert "1,50" -> "1.50"
        $eur = trim($eur);
        $eur = str_replace(['.'], ['',], $eur); // remove thousand sep if any
        $eur = str_replace(',', '.', $eur);
        // keep two decimals if present
        if (!preg_match('/^\d+(?:\.\d{1,2})?$/', $eur)) {
            // fallback remove non-numeric except dot
            $eur = preg_replace('/[^0-9.]/', '', $eur);
        }
        return $eur;
    }
}
