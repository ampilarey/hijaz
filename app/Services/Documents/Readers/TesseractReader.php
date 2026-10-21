<?php

namespace App\Services\Documents\Readers;

use App\Models\Document;
use App\Services\Documents\FieldParser;
use App\Services\Documents\ImagePrep;
use App\Services\Documents\PdfText;
use Symfony\Component\Process\Process;

/**
 * Tesseract as a binary on the server: no image leaves the host. A PDF's
 * text layer is used as it is (an e-visa has one); an image is prepared
 * with GD and read, with a second pass over the bottom of a passport for
 * the machine-readable zone.
 */
final class TesseractReader implements Reader
{
    public function name(): string
    {
        return 'tesseract';
    }

    public function isAvailable(): bool
    {
        $binary = (string) config('documents.tesseract.binary', 'tesseract');

        if (str_contains($binary, '/')) {
            return is_executable($binary);
        }

        $probe = new Process(['which', $binary]);
        $probe->run();

        return $probe->isSuccessful();
    }

    public function read(string $bytes, string $mime, string $type): Extraction
    {
        $x = new Extraction($this->name(), model: 'tesseract');

        if ($mime === 'application/pdf') {
            $x->rawText = PdfText::all($bytes);
        } else {
            $text = $this->ocr(ImagePrep::forOcr($bytes));

            if ($type === Document::PASSPORT) {
                // The MRZ pass first: it is read with a whitelist and wins
                // over the same lines smudged in the full-page pass.
                $text = $this->ocr(ImagePrep::forOcr(ImagePrep::bottom($bytes, 0.35)), mrz: true)."\n".$text;
            }

            $x->rawText = $text;
        }

        FieldParser::apply($x, $type);

        return $x;
    }

    private function ocr(string $png, bool $mrz = false): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'ocr').'.png';
        file_put_contents($tmp, $png);

        try {
            $command = [
                (string) config('documents.tesseract.binary', 'tesseract'),
                $tmp, 'stdout',
                '-l', (string) config('documents.tesseract.languages', 'eng'),
                '--psm', $mrz ? '6' : '4',
            ];

            if ($mrz) {
                $command[] = '-c';
                $command[] = 'tessedit_char_whitelist=ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789<';
            }

            $env = [];

            if ($prefix = config('documents.tesseract.tessdata')) {
                $env['TESSDATA_PREFIX'] = (string) $prefix;
            }

            $process = new Process($command, null, $env ?: null, null, (float) config('documents.tesseract.timeout', 60));
            $process->run();

            if (! $process->isSuccessful()) {
                throw new \RuntimeException('tesseract failed: '.trim($process->getErrorOutput()));
            }

            return $process->getOutput();
        } finally {
            @unlink($tmp);
        }
    }
}
