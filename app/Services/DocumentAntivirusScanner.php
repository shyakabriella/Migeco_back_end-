<?php

namespace App\Services;

use App\Models\Document;
use App\Models\DocumentScanLog;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Throwable;

class DocumentAntivirusScanner
{
    /**
     * Scan one document using ClamAV.
     */
    public function scan(Document $document, ?User $scanner = null, string $scanType = 'manual'): array
    {
        $disk = $document->disk ?: 'local';

        if (!Storage::disk($disk)->exists($document->file_path)) {
            $document->update([
                'scan_status' => 'failed',
                'scan_message' => 'File not found in storage.',
                'scanned_at' => now(),
            ]);

            return [
                'status' => 'failed',
                'message' => 'File not found in storage.',
            ];
        }

        $absolutePath = Storage::disk($disk)->path($document->file_path);

        $scanLog = DocumentScanLog::create([
            'document_id' => $document->id,
            'scanned_by' => $scanner?->id,
            'scan_engine' => 'clamav',
            'scan_type' => $scanType,
            'status' => 'pending',
            'file_path' => $document->file_path,
            'sha256_hash' => $document->sha256_hash,
            'message' => 'Scan started.',
            'started_at' => now(),
        ]);

        if (!config('dms.antivirus.enabled')) {
            $message = 'Antivirus scanning is disabled. Document cannot be activated.';

            $scanLog->update([
                'status' => 'failed',
                'message' => $message,
                'completed_at' => now(),
            ]);

            $document->update([
                'scan_status' => 'failed',
                'scan_message' => $message,
                'scanned_at' => now(),
            ]);

            return [
                'status' => 'failed',
                'message' => $message,
            ];
        }

        try {
            $clamScanPath = config('dms.antivirus.clamscan_path', 'clamscan');
            $timeout = (int) config('dms.antivirus.timeout', 120);

            /*
            |--------------------------------------------------------------------------
            | ClamAV scan command
            |--------------------------------------------------------------------------
            | Exit code:
            | 0 = clean
            | 1 = infected
            | 2 = error
            */
            $process = new Process([
                $clamScanPath,
                '--no-summary',
                $absolutePath,
            ]);

            $process->setTimeout($timeout);
            $process->run();

            $exitCode = $process->getExitCode();
            $output = trim($process->getOutput() . "\n" . $process->getErrorOutput());

            if ($exitCode === 0) {
                return $this->markClean($document, $scanLog, $output);
            }

            if ($exitCode === 1) {
                return $this->markInfected($document, $scanLog, $output);
            }

            return $this->markFailed(
                $document,
                $scanLog,
                'Antivirus scan failed. Please check ClamAV installation or scanner permission.',
                $output
            );

        } catch (Throwable $e) {
            return $this->markFailed(
                $document,
                $scanLog,
                'Antivirus scan failed: ' . $e->getMessage(),
                $e->getTraceAsString()
            );
        }
    }

    /**
     * Mark document as clean and move it to clean folder.
     */
    private function markClean(Document $document, DocumentScanLog $scanLog, ?string $rawOutput = null): array
    {
        $oldPath = $document->file_path;
        $newPath = $this->moveFile($document, 'dms/clean');

        $message = 'Document is virus-free and safe after antivirus scan.';

        $document->update([
            'file_path' => $newPath,
            'status' => 'active',
            'scan_status' => 'clean',
            'scan_message' => $message,
            'scanned_at' => now(),
        ]);

        $scanLog->update([
            'status' => 'clean',
            'message' => $message,
            'raw_output' => $rawOutput,
            'file_path' => $newPath,
            'completed_at' => now(),
        ]);

        return [
            'status' => 'clean',
            'message' => $message,
            'old_path' => $oldPath,
            'new_path' => $newPath,
        ];
    }

    /**
     * Mark document as infected and move it to quarantine folder.
     */
    private function markInfected(Document $document, DocumentScanLog $scanLog, ?string $rawOutput = null): array
    {
        $threatName = $this->extractThreatName($rawOutput);
        $newPath = $this->moveFile($document, 'dms/quarantine');

        $message = 'Virus or malware detected. Document has been quarantined and blocked.';

        $document->update([
            'file_path' => $newPath,
            'status' => 'quarantined',
            'scan_status' => 'infected',
            'scan_message' => $message,
            'scanned_at' => now(),
        ]);

        $scanLog->update([
            'status' => 'infected',
            'threat_name' => $threatName,
            'message' => $message,
            'raw_output' => $rawOutput,
            'file_path' => $newPath,
            'completed_at' => now(),
        ]);

        return [
            'status' => 'infected',
            'message' => $message,
            'threat_name' => $threatName,
            'new_path' => $newPath,
        ];
    }

    /**
     * Mark scan as failed.
     */
    private function markFailed(
        Document $document,
        DocumentScanLog $scanLog,
        string $message,
        ?string $rawOutput = null
    ): array {
        $document->update([
            'scan_status' => 'failed',
            'scan_message' => $message,
            'scanned_at' => now(),
        ]);

        $scanLog->update([
            'status' => 'failed',
            'message' => $message,
            'raw_output' => $rawOutput,
            'completed_at' => now(),
        ]);

        return [
            'status' => 'failed',
            'message' => $message,
        ];
    }

    /**
     * Move file to clean or quarantine folder.
     */
    private function moveFile(Document $document, string $baseFolder): string
    {
        $disk = $document->disk ?: 'local';

        $oldPath = $document->file_path;
        $newFolder = $baseFolder . '/' . now()->format('Y/m');
        $newPath = $newFolder . '/' . $document->stored_file_name;

        if ($oldPath === $newPath) {
            return $newPath;
        }

        Storage::disk($disk)->makeDirectory($newFolder);

        if (Storage::disk($disk)->exists($newPath)) {
            $newPath = $newFolder . '/' . pathinfo($document->stored_file_name, PATHINFO_FILENAME)
                . '-' . time()
                . '.' . $document->extension;
        }

        Storage::disk($disk)->move($oldPath, $newPath);

        return $newPath;
    }

    /**
     * Extract threat name from ClamAV output.
     */
    private function extractThreatName(?string $rawOutput): ?string
    {
        if (!$rawOutput) {
            return null;
        }

        if (preg_match('/:\s(.+)\sFOUND/i', $rawOutput, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }
}