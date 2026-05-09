<?php

namespace App\Services;

use App\Models\Document;
use App\Models\DocumentPlaintext;
use App\Models\DocumentPlaintextLog;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;
use ZipArchive;

class DocumentPlaintextExtractor
{
    /**
     * Extract plaintext from one clean document.
     */
    public function extract(Document $document, ?User $user = null, string $extractionType = 'manual'): array
    {
        $log = DocumentPlaintextLog::create([
            'document_id' => $document->id,
            'performed_by' => $user?->id,
            'extraction_type' => $extractionType,
            'status' => 'pending',
            'extraction_engine' => 'laravel_plaintext_extractor',
            'source_file_path' => $document->file_path,
            'message' => 'Plaintext extraction started.',
            'started_at' => now(),
        ]);

        try {
            if (!config('dms.plaintext.enabled')) {
                throw new RuntimeException('Plaintext extraction is disabled.');
            }

            if (!$document->isSafeToOpen()) {
                throw new RuntimeException('Only clean and active documents can be converted to plaintext.');
            }

            $document->update([
                'plaintext_status' => 'pending',
            ]);

            $prepared = $this->prepareSourceFile($document, $user);

            if (!$prepared['success']) {
                throw new RuntimeException($prepared['message']);
            }

            $absolutePath = $prepared['absolute_path'];
            $extension = strtolower($document->extension ?? pathinfo($absolutePath, PATHINFO_EXTENSION));

            $result = $this->extractByExtension($absolutePath, $extension, $document);

            $text = $this->cleanText($result['text'] ?? '');

            if (trim($text) === '') {
                throw new RuntimeException('No readable text was found in this document.');
            }

            $characterCount = mb_strlen($text);
            $wordCount = str_word_count($text);
            $previewLength = (int) config('dms.plaintext.preview_length', 500);
            $preview = mb_substr($text, 0, $previewLength);

            /*
            |--------------------------------------------------------------------------
            | Save plaintext as private .txt file
            |--------------------------------------------------------------------------
            */
            $plaintextFilePath = null;
            $sha256Hash = hash('sha256', $text);

            if (config('dms.plaintext.save_plaintext_file', true)) {
                $folder = 'dms/plaintext/' . now()->format('Y/m');
                $fileName = $document->document_code . '.txt';
                $plaintextFilePath = $folder . '/' . $fileName;

                Storage::disk('local')->put($plaintextFilePath, $text);
            }

            /*
            |--------------------------------------------------------------------------
            | Save content in database
            |--------------------------------------------------------------------------
            */
            $databaseContent = null;

            if (config('dms.plaintext.save_content_to_database', true)) {
                $maxChars = (int) config('dms.plaintext.max_database_characters', 500000);
                $databaseContent = mb_substr($text, 0, $maxChars);
            }

            $plainTextRecord = DocumentPlaintext::updateOrCreate(
                ['document_id' => $document->id],
                [
                    'extracted_by' => $user?->id,
                    'content' => $databaseContent,
                    'plaintext_file_path' => $plaintextFilePath,
                    'extraction_engine' => $result['engine'] ?? 'unknown',
                    'source_extension' => $extension,
                    'source_mime_type' => $document->mime_type,
                    'character_count' => $characterCount,
                    'word_count' => $wordCount,
                    'sha256_hash' => $sha256Hash,
                    'preview' => $preview,
                    'status' => 'extracted',
                    'message' => 'Plaintext extracted successfully.',
                ]
            );

            $document->update([
                'plaintext_status' => 'extracted',
                'plaintext_file_path' => $plaintextFilePath,
                'plaintext_extracted_by' => $user?->id,
                'plaintext_character_count' => $characterCount,
                'plaintext_word_count' => $wordCount,
                'plaintext_sha256_hash' => $sha256Hash,
                'plaintext_preview' => $preview,
                'plaintext_extracted_at' => now(),
            ]);

            $log->update([
                'status' => 'extracted',
                'extraction_engine' => $result['engine'] ?? 'unknown',
                'plaintext_file_path' => $plaintextFilePath,
                'character_count' => $characterCount,
                'word_count' => $wordCount,
                'sha256_hash' => $sha256Hash,
                'message' => 'Plaintext extracted successfully.',
                'completed_at' => now(),
            ]);

            /*
            |--------------------------------------------------------------------------
            | Delete temporary decrypted file if document was encrypted.
            |--------------------------------------------------------------------------
            */
            if (($prepared['delete_after_use'] ?? false) && !empty($prepared['temporary_relative_path'])) {
                Storage::disk('local')->delete($prepared['temporary_relative_path']);
            }

            return [
                'status' => 'extracted',
                'message' => 'Plaintext extracted successfully.',
                'document_id' => $document->id,
                'plaintext_id' => $plainTextRecord->id,
                'plaintext_file_path' => $plaintextFilePath,
                'character_count' => $characterCount,
                'word_count' => $wordCount,
                'preview' => $preview,
            ];
        } catch (Throwable $e) {
            $document->update([
                'plaintext_status' => 'failed',
            ]);

            $log->update([
                'status' => $this->isUnsupportedError($e->getMessage()) ? 'unsupported' : 'failed',
                'message' => 'Plaintext extraction failed.',
                'error_details' => $e->getMessage(),
                'completed_at' => now(),
            ]);

            return [
                'status' => 'failed',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Prepare source file.
     *
     * If encrypted, decrypt temporarily first.
     * If not encrypted, use original private file.
     */
    private function prepareSourceFile(Document $document, ?User $user = null): array
    {
        $disk = $document->disk ?: 'local';

        if ($document->isEncrypted()) {
            /** @var DocumentEncryptionService $encryptionService */
            $encryptionService = app(DocumentEncryptionService::class);

            /*
            |--------------------------------------------------------------------------
            | We use decrypt_for_view because the current encryption logs enum
            | already supports this action from Step 7.
            |--------------------------------------------------------------------------
            */
            $result = $encryptionService->decryptToTemporaryFile(
                $document,
                $user,
                'decrypt_for_view'
            );

            if (($result['status'] ?? null) !== 'success') {
                return [
                    'success' => false,
                    'message' => $result['message'] ?? 'Unable to decrypt document for plaintext extraction.',
                ];
            }

            return [
                'success' => true,
                'absolute_path' => $result['temporary_absolute_path'],
                'temporary_relative_path' => $result['temporary_relative_path'],
                'delete_after_use' => true,
            ];
        }

        if (!Storage::disk($disk)->exists($document->file_path)) {
            return [
                'success' => false,
                'message' => 'Source document file was not found in storage.',
            ];
        }

        return [
            'success' => true,
            'absolute_path' => Storage::disk($disk)->path($document->file_path),
            'delete_after_use' => false,
        ];
    }

    /**
     * Extract text depending on file extension.
     */
    private function extractByExtension(string $absolutePath, string $extension, Document $document): array
    {
        return match ($extension) {
            'txt', 'csv', 'log' => [
                'engine' => 'native_text_reader',
                'text' => file_get_contents($absolutePath),
            ],

            'pdf' => [
                'engine' => 'smalot_pdfparser',
                'text' => $this->extractPdf($absolutePath),
            ],

            'docx' => [
                'engine' => 'zip_docx_reader',
                'text' => $this->extractDocx($absolutePath),
            ],

            'xlsx', 'xls' => [
                'engine' => 'phpoffice_phpspreadsheet',
                'text' => $this->extractSpreadsheet($absolutePath),
            ],

            'pptx' => [
                'engine' => 'zip_pptx_reader',
                'text' => $this->extractPptx($absolutePath),
            ],

            'dxf' => [
                'engine' => 'native_dxf_text_reader',
                'text' => file_get_contents($absolutePath),
            ],

            default => throw new RuntimeException(
                'Unsupported file type for plaintext extraction: .' . $extension
            ),
        };
    }

    /**
     * Extract text from PDF.
     */
    private function extractPdf(string $absolutePath): string
    {
        if (!class_exists(\Smalot\PdfParser\Parser::class)) {
            throw new RuntimeException('PDF parser package is missing. Run: composer require smalot/pdfparser');
        }

        $parser = new \Smalot\PdfParser\Parser();
        $pdf = $parser->parseFile($absolutePath);

        return $pdf->getText();
    }

    /**
     * Extract text from DOCX.
     */
    private function extractDocx(string $absolutePath): string
    {
        $zip = new ZipArchive();

        if ($zip->open($absolutePath) !== true) {
            throw new RuntimeException('Unable to open DOCX file.');
        }

        $text = '';

        $files = [
            'word/document.xml',
            'word/header1.xml',
            'word/header2.xml',
            'word/footer1.xml',
            'word/footer2.xml',
        ];

        foreach ($files as $file) {
            $xml = $zip->getFromName($file);

            if ($xml !== false) {
                $text .= ' ' . $this->xmlToText($xml);
            }
        }

        $zip->close();

        return $text;
    }

    /**
     * Extract text from PPTX.
     */
    private function extractPptx(string $absolutePath): string
    {
        $zip = new ZipArchive();

        if ($zip->open($absolutePath) !== true) {
            throw new RuntimeException('Unable to open PPTX file.');
        }

        $text = '';

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);

            if (str_starts_with($name, 'ppt/slides/slide') && str_ends_with($name, '.xml')) {
                $xml = $zip->getFromName($name);

                if ($xml !== false) {
                    $text .= ' ' . $this->xmlToText($xml);
                }
            }
        }

        $zip->close();

        return $text;
    }

    /**
     * Extract text from Excel files.
     */
    private function extractSpreadsheet(string $absolutePath): string
    {
        if (!class_exists(\PhpOffice\PhpSpreadsheet\IOFactory::class)) {
            throw new RuntimeException('Spreadsheet package is missing. Run: composer require phpoffice/phpspreadsheet');
        }

        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($absolutePath);

        $text = '';

        foreach ($spreadsheet->getWorksheetIterator() as $worksheet) {
            $text .= "\nSheet: " . $worksheet->getTitle() . "\n";

            foreach ($worksheet->toArray(null, true, true, true) as $row) {
                $text .= implode(' ', array_filter($row, fn ($value) => $value !== null && $value !== '')) . "\n";
            }
        }

        return $text;
    }

    /**
     * Convert XML content to readable text.
     */
    private function xmlToText(string $xml): string
    {
        $xml = preg_replace('/<w:tab\/>/', ' ', $xml);
        $xml = preg_replace('/<\/w:p>/', "\n", $xml);
        $xml = preg_replace('/<\/a:p>/', "\n", $xml);

        $text = strip_tags($xml);

        return html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    /**
     * Clean text.
     */
    private function cleanText(string $text): string
    {
        $text = str_replace("\0", '', $text);
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/\R{3,}/', "\n\n", $text);

        return trim($text);
    }

    /**
     * Know if error means unsupported file type.
     */
    private function isUnsupportedError(string $message): bool
    {
        return str_contains(strtolower($message), 'unsupported file type');
    }
}