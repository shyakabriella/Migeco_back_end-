<?php

namespace App\Services;

use App\Models\Document;
use App\Models\DocumentSandboxLog;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;
use ZipArchive;

class DocumentSandboxService
{
    /**
     * Test document safely.
     *
     * Important:
     * This service does NOT execute the document.
     * It only performs static inspection.
     */
    public function test(Document $document, ?User $user = null, string $sandboxType = 'manual'): array
    {
        $log = DocumentSandboxLog::create([
            'document_id' => $document->id,
            'tested_by' => $user?->id,
            'sandbox_type' => $sandboxType,
            'status' => 'pending',
            'risk_score' => 0,
            'source_file_path' => $document->file_path,
            'source_extension' => $document->extension,
            'source_mime_type' => $document->mime_type,
            'message' => 'Sandbox inspection started.',
            'started_at' => now(),
        ]);

        try {
            if (!config('dms.sandbox.enabled')) {
                throw new RuntimeException('Sandbox module is disabled.');
            }

            if ($document->isRejected() || $document->isQuarantined() || $document->isInfected()) {
                throw new RuntimeException('Rejected, quarantined, or infected documents cannot be sandbox tested.');
            }

            if ($document->status !== 'active' || $document->scan_status !== 'clean') {
                throw new RuntimeException('Only active and antivirus-clean documents can be sandbox tested.');
            }

            $document->update([
                'sandbox_status' => 'pending',
                'sandbox_message' => 'Sandbox inspection is running.',
            ]);

            $prepared = $this->prepareSourceFile($document, $user);

            if (!$prepared['success']) {
                throw new RuntimeException($prepared['message']);
            }

            $absolutePath = $prepared['absolute_path'];

            $report = $this->inspectFile($document, $absolutePath);

            $unsafeScore = (int) config('dms.sandbox.unsafe_score', 50);
            $status = $report['risk_score'] >= $unsafeScore ? 'unsafe' : 'safe';

            $message = $status === 'safe'
                ? 'Sandbox inspection completed. No dangerous indicators were found.'
                : 'Sandbox inspection found suspicious or dangerous indicators. Document is blocked.';

            $document->update([
                'sandbox_status' => $status,
                'sandbox_tested_by' => $user?->id,
                'sandbox_score' => $report['risk_score'],
                'sandbox_message' => $message,
                'sandbox_report' => $report,
                'sandbox_tested_at' => now(),
            ]);

            $log->update([
                'status' => $status,
                'risk_score' => $report['risk_score'],
                'indicators' => $report['indicators'],
                'report' => $report,
                'message' => $message,
                'completed_at' => now(),
            ]);

            if (($prepared['delete_after_use'] ?? false) && !empty($prepared['temporary_relative_path'])) {
                Storage::disk('local')->delete($prepared['temporary_relative_path']);
            }

            return [
                'status' => $status,
                'message' => $message,
                'risk_score' => $report['risk_score'],
                'indicators' => $report['indicators'],
                'report' => $report,
            ];
        } catch (Throwable $e) {
            $document->update([
                'sandbox_status' => 'failed',
                'sandbox_message' => $e->getMessage(),
                'sandbox_tested_at' => now(),
            ]);

            $log->update([
                'status' => 'failed',
                'message' => 'Sandbox inspection failed.',
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
     * Prepare source file for sandbox inspection.
     *
     * If encrypted, decrypt temporarily first.
     */
    private function prepareSourceFile(Document $document, ?User $user = null): array
    {
        $disk = $document->disk ?: 'local';

        if ($document->isEncrypted()) {
            /** @var DocumentEncryptionService $encryptionService */
            $encryptionService = app(DocumentEncryptionService::class);

            $result = $encryptionService->decryptToTemporaryFile(
                $document,
                $user,
                'decrypt_for_view'
            );

            if (($result['status'] ?? null) !== 'success') {
                return [
                    'success' => false,
                    'message' => $result['message'] ?? 'Unable to decrypt document for sandbox inspection.',
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
     * Inspect file without executing it.
     */
    private function inspectFile(Document $document, string $absolutePath): array
    {
        $indicators = [];

        $extension = strtolower((string) $document->extension);
        $originalName = strtolower((string) $document->original_file_name);

        $contentSample = $this->readSample($absolutePath);

        /*
        |--------------------------------------------------------------------------
        | Basic file checks
        |--------------------------------------------------------------------------
        */
        $this->checkExecutableSignature($contentSample, $indicators);
        $this->checkDoubleExtension($originalName, $indicators);
        $this->checkExtensionMismatch($document, $contentSample, $indicators);

        /*
        |--------------------------------------------------------------------------
        | Type-specific checks
        |--------------------------------------------------------------------------
        */
        if ($extension === 'pdf') {
            $this->inspectPdf($contentSample, $indicators);
        }

        if (in_array($extension, ['docx', 'xlsx', 'pptx', 'docm', 'xlsm', 'pptm'], true)) {
            $this->inspectOfficeZip($absolutePath, $extension, $indicators);
        }

        if (in_array($extension, ['zip'], true)) {
            $this->inspectGenericZip($absolutePath, $indicators);
        }

        if (in_array($extension, ['txt', 'csv', 'dxf'], true)) {
            $this->inspectTextLikeFile($contentSample, $indicators);
        }

        $riskScore = $this->calculateRiskScore($indicators);

        return [
            'document_id' => $document->id,
            'document_code' => $document->document_code,
            'file_name' => $document->original_file_name,
            'extension' => $extension,
            'mime_type' => $document->mime_type,
            'file_size' => $document->file_size,
            'risk_score' => $riskScore,
            'unsafe_score' => (int) config('dms.sandbox.unsafe_score', 50),
            'indicators' => $indicators,
            'tested_at' => now()->toDateTimeString(),
            'note' => 'Static sandbox inspection only. File was not executed.',
        ];
    }

    /**
     * Read a safe sample of file bytes.
     */
    private function readSample(string $absolutePath): string
    {
        $maxBytes = (int) config('dms.sandbox.max_bytes_to_inspect', 2097152);

        $handle = fopen($absolutePath, 'rb');

        if (!$handle) {
            throw new RuntimeException('Unable to open file for sandbox inspection.');
        }

        $content = fread($handle, $maxBytes);

        fclose($handle);

        return $content ?: '';
    }

    /**
     * Detect executable signatures.
     */
    private function checkExecutableSignature(string $content, array &$indicators): void
    {
        if (str_starts_with($content, "MZ")) {
            $indicators[] = [
                'level' => 'critical',
                'score' => 60,
                'code' => 'windows_executable_signature',
                'message' => 'File contains Windows executable signature.',
            ];
        }

        if (str_starts_with($content, "\x7FELF")) {
            $indicators[] = [
                'level' => 'critical',
                'score' => 60,
                'code' => 'linux_executable_signature',
                'message' => 'File contains Linux ELF executable signature.',
            ];
        }
    }

    /**
     * Detect dangerous double extensions.
     */
    private function checkDoubleExtension(string $originalName, array &$indicators): void
    {
        $dangerousExtensions = [
            'exe',
            'bat',
            'cmd',
            'com',
            'scr',
            'js',
            'vbs',
            'ps1',
            'sh',
            'jar',
            'msi',
            'dll',
        ];

        $parts = explode('.', $originalName);

        if (count($parts) < 3) {
            return;
        }

        $lastExtension = end($parts);

        if (in_array($lastExtension, $dangerousExtensions, true)) {
            $indicators[] = [
                'level' => 'critical',
                'score' => 60,
                'code' => 'dangerous_double_extension',
                'message' => 'File has a dangerous double extension.',
            ];
        }
    }

    /**
     * Detect file extension mismatch using magic bytes.
     */
    private function checkExtensionMismatch(Document $document, string $content, array &$indicators): void
    {
        $extension = strtolower((string) $document->extension);

        $magicMap = [
            'pdf' => '%PDF',
            'png' => "\x89PNG",
            'jpg' => "\xFF\xD8\xFF",
            'jpeg' => "\xFF\xD8\xFF",
            'zip' => "PK\x03\x04",
            'docx' => "PK\x03\x04",
            'xlsx' => "PK\x03\x04",
            'pptx' => "PK\x03\x04",
        ];

        if (!isset($magicMap[$extension])) {
            return;
        }

        if (!str_starts_with($content, $magicMap[$extension])) {
            $indicators[] = [
                'level' => 'medium',
                'score' => 20,
                'code' => 'extension_magic_mismatch',
                'message' => 'File content signature does not match file extension.',
            ];
        }
    }

    /**
     * Inspect PDF indicators.
     */
    private function inspectPdf(string $content, array &$indicators): void
    {
        $pdfIndicators = [
            '/JavaScript' => [
                'level' => 'high',
                'score' => 35,
                'code' => 'pdf_javascript',
                'message' => 'PDF contains JavaScript indicator.',
            ],
            '/JS' => [
                'level' => 'medium',
                'score' => 20,
                'code' => 'pdf_js',
                'message' => 'PDF contains JS action indicator.',
            ],
            '/OpenAction' => [
                'level' => 'high',
                'score' => 35,
                'code' => 'pdf_open_action',
                'message' => 'PDF contains automatic open action.',
            ],
            '/AA' => [
                'level' => 'medium',
                'score' => 20,
                'code' => 'pdf_additional_action',
                'message' => 'PDF contains additional action indicator.',
            ],
            '/Launch' => [
                'level' => 'critical',
                'score' => 60,
                'code' => 'pdf_launch_action',
                'message' => 'PDF contains launch action indicator.',
            ],
            '/EmbeddedFile' => [
                'level' => 'high',
                'score' => 35,
                'code' => 'pdf_embedded_file',
                'message' => 'PDF contains embedded file indicator.',
            ],
            '/RichMedia' => [
                'level' => 'high',
                'score' => 35,
                'code' => 'pdf_rich_media',
                'message' => 'PDF contains rich media indicator.',
            ],
        ];

        foreach ($pdfIndicators as $needle => $indicator) {
            if (stripos($content, $needle) !== false) {
                $indicators[] = $indicator;
            }
        }
    }

    /**
     * Inspect Office ZIP files like DOCX, XLSX, PPTX.
     */
    private function inspectOfficeZip(string $absolutePath, string $extension, array &$indicators): void
    {
        $zip = new ZipArchive();

        if ($zip->open($absolutePath) !== true) {
            $indicators[] = [
                'level' => 'medium',
                'score' => 20,
                'code' => 'office_zip_unreadable',
                'message' => 'Office document could not be inspected as ZIP.',
            ];

            return;
        }

        if (in_array($extension, ['docm', 'xlsm', 'pptm'], true)) {
            $indicators[] = [
                'level' => 'high',
                'score' => 40,
                'code' => 'macro_enabled_office_extension',
                'message' => 'Office document uses macro-enabled extension.',
            ];
        }

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = strtolower($zip->getNameIndex($i));

            if (str_contains($name, 'vbaproject.bin')) {
                $indicators[] = [
                    'level' => 'critical',
                    'score' => 60,
                    'code' => 'office_macro_vba_project',
                    'message' => 'Office document contains VBA macro project.',
                ];
            }

            if (str_contains($name, 'embeddings/')) {
                $indicators[] = [
                    'level' => 'high',
                    'score' => 35,
                    'code' => 'office_embedded_object',
                    'message' => 'Office document contains embedded object.',
                ];
            }

            if (str_contains($name, 'externallinks/')) {
                $indicators[] = [
                    'level' => 'medium',
                    'score' => 20,
                    'code' => 'office_external_link',
                    'message' => 'Office document contains external link reference.',
                ];
            }
        }

        $zip->close();
    }

    /**
     * Inspect generic ZIP archive.
     */
    private function inspectGenericZip(string $absolutePath, array &$indicators): void
    {
        $zip = new ZipArchive();

        if ($zip->open($absolutePath) !== true) {
            $indicators[] = [
                'level' => 'medium',
                'score' => 20,
                'code' => 'zip_unreadable',
                'message' => 'Archive could not be inspected.',
            ];

            return;
        }

        $dangerousExtensions = [
            'exe',
            'bat',
            'cmd',
            'com',
            'scr',
            'js',
            'vbs',
            'ps1',
            'sh',
            'jar',
            'msi',
            'dll',
        ];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = strtolower($zip->getNameIndex($i));
            $extension = pathinfo($name, PATHINFO_EXTENSION);

            if (in_array($extension, $dangerousExtensions, true)) {
                $indicators[] = [
                    'level' => 'critical',
                    'score' => 60,
                    'code' => 'archive_contains_executable',
                    'message' => 'Archive contains potentially executable file: ' . $name,
                ];
            }
        }

        $zip->close();
    }

    /**
     * Inspect text-like files for risky script indicators.
     */
    private function inspectTextLikeFile(string $content, array &$indicators): void
    {
        $patterns = [
            'powershell' => [
                'level' => 'medium',
                'score' => 20,
                'code' => 'text_contains_powershell',
                'message' => 'Text-like file contains PowerShell keyword.',
            ],
            'cmd.exe' => [
                'level' => 'medium',
                'score' => 20,
                'code' => 'text_contains_cmd',
                'message' => 'Text-like file contains cmd.exe keyword.',
            ],
            'eval(' => [
                'level' => 'medium',
                'score' => 20,
                'code' => 'text_contains_eval',
                'message' => 'Text-like file contains eval pattern.',
            ],
            'base64_decode' => [
                'level' => 'medium',
                'score' => 20,
                'code' => 'text_contains_base64_decode',
                'message' => 'Text-like file contains base64 decode pattern.',
            ],
        ];

        foreach ($patterns as $needle => $indicator) {
            if (stripos($content, $needle) !== false) {
                $indicators[] = $indicator;
            }
        }
    }

    /**
     * Calculate total risk score.
     */
    private function calculateRiskScore(array $indicators): int
    {
        $score = 0;

        foreach ($indicators as $indicator) {
            $score += (int) ($indicator['score'] ?? 0);
        }

        return min($score, 100);
    }
}