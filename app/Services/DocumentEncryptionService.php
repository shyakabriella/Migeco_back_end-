<?php

namespace App\Services;

use App\Models\Document;
use App\Models\DocumentEncryptionLog;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class DocumentEncryptionService
{
    /**
     * Get encryption key from config.
     */
    private function encryptionKey(): string
    {
        if (!extension_loaded('sodium')) {
            throw new RuntimeException('PHP Sodium extension is not enabled.');
        }

        $base64Key = config('dms.encryption.key');

        if (!$base64Key) {
            throw new RuntimeException('DMS encryption key is missing. Please set DMS_ENCRYPTION_KEY in .env.');
        }

        $key = base64_decode($base64Key, true);

        if (!$key || strlen($key) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES) {
            throw new RuntimeException('Invalid DMS encryption key. It must be base64 encoded 32 bytes.');
        }

        return $key;
    }

    /**
     * Encrypt a clean document.
     */
    public function encrypt(Document $document, ?User $user = null): array
    {
        $startedAt = now();

        $log = DocumentEncryptionLog::create([
            'document_id' => $document->id,
            'performed_by' => $user?->id,
            'action' => 'encrypt',
            'status' => 'failed',
            'algorithm' => config('dms.encryption.algorithm'),
            'key_id' => config('dms.encryption.key_id'),
            'source_file_path' => $document->file_path,
            'source_sha256_hash' => $document->sha256_hash,
            'message' => 'Encryption started.',
            'started_at' => $startedAt,
        ]);

        try {
            if (!config('dms.encryption.enabled')) {
                throw new RuntimeException('DMS encryption is disabled.');
            }

            if (!$document->isSafeToOpen()) {
                throw new RuntimeException('Only clean and active documents can be encrypted.');
            }

            if ($document->isEncrypted()) {
                return [
                    'status' => 'already_encrypted',
                    'message' => 'Document is already encrypted.',
                    'encrypted_file_path' => $document->encrypted_file_path,
                ];
            }

            $disk = $document->disk ?: 'local';

            if (!Storage::disk($disk)->exists($document->file_path)) {
                throw new RuntimeException('Source file not found in storage.');
            }

            $sourcePath = Storage::disk($disk)->path($document->file_path);

            $encryptedFolder = 'dms/encrypted/' . now()->format('Y/m');
            Storage::disk($disk)->makeDirectory($encryptedFolder);

            $encryptedFileName = $document->stored_file_name . '.enc';
            $encryptedRelativePath = $encryptedFolder . '/' . $encryptedFileName;
            $encryptedAbsolutePath = Storage::disk($disk)->path($encryptedRelativePath);

            if (Storage::disk($disk)->exists($encryptedRelativePath)) {
                $encryptedRelativePath = $encryptedFolder . '/'
                    . pathinfo($document->stored_file_name, PATHINFO_FILENAME)
                    . '-' . time()
                    . '.' . $document->extension
                    . '.enc';

                $encryptedAbsolutePath = Storage::disk($disk)->path($encryptedRelativePath);
            }

            $this->encryptFile($sourcePath, $encryptedAbsolutePath);

            $encryptedSize = filesize($encryptedAbsolutePath);
            $encryptedHash = hash_file('sha256', $encryptedAbsolutePath);

            /*
            |--------------------------------------------------------------------------
            | Remove raw clean file after successful encryption
            |--------------------------------------------------------------------------
            */
            $deletePlainFile = (bool) config('dms.encryption.delete_plain_file_after_encrypt', true);

            if ($deletePlainFile && Storage::disk($disk)->exists($document->file_path)) {
                Storage::disk($disk)->delete($document->file_path);
            }

            $oldCleanPath = $document->file_path;

            $document->update([
                'original_clean_file_path' => $oldCleanPath,
                'file_path' => $encryptedRelativePath,
                'encrypted_file_path' => $encryptedRelativePath,
                'encryption_status' => 'encrypted',
                'encryption_algorithm' => config('dms.encryption.algorithm'),
                'encryption_key_id' => config('dms.encryption.key_id'),
                'encrypted_file_size' => $encryptedSize,
                'encrypted_sha256_hash' => $encryptedHash,
                'encrypted_at' => now(),
            ]);

            $log->update([
                'status' => 'success',
                'encrypted_file_path' => $encryptedRelativePath,
                'encrypted_sha256_hash' => $encryptedHash,
                'message' => 'Document encrypted successfully.',
                'completed_at' => now(),
            ]);

            return [
                'status' => 'encrypted',
                'message' => 'Document encrypted successfully.',
                'original_clean_file_path' => $oldCleanPath,
                'encrypted_file_path' => $encryptedRelativePath,
                'encrypted_file_size' => $encryptedSize,
                'encrypted_sha256_hash' => $encryptedHash,
            ];
        } catch (Throwable $e) {
            $document->update([
                'encryption_status' => 'failed',
            ]);

            $log->update([
                'status' => 'failed',
                'message' => 'Document encryption failed.',
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
     * Decrypt encrypted document into a temporary private file.
     *
     * This is used by secure view/download controller.
     */
    public function decryptToTemporaryFile(
        Document $document,
        ?User $user = null,
        string $action = 'decrypt_for_view'
    ): array {
        $startedAt = now();

        $log = DocumentEncryptionLog::create([
            'document_id' => $document->id,
            'performed_by' => $user?->id,
            'action' => $action,
            'status' => 'failed',
            'algorithm' => $document->encryption_algorithm,
            'key_id' => $document->encryption_key_id,
            'encrypted_file_path' => $document->encrypted_file_path ?: $document->file_path,
            'encrypted_sha256_hash' => $document->encrypted_sha256_hash,
            'message' => 'Temporary decryption started.',
            'started_at' => $startedAt,
        ]);

        try {
            if (!$document->isEncrypted()) {
                throw new RuntimeException('Document is not encrypted.');
            }

            $disk = $document->disk ?: 'local';
            $encryptedPath = $document->encrypted_file_path ?: $document->file_path;

            if (!Storage::disk($disk)->exists($encryptedPath)) {
                throw new RuntimeException('Encrypted file not found in storage.');
            }

            $encryptedAbsolutePath = Storage::disk($disk)->path($encryptedPath);

            $tmpFolder = 'dms/tmp/decrypted/' . now()->format('Y/m/d');
            Storage::disk($disk)->makeDirectory($tmpFolder);

            $safeName = Str::uuid()->toString() . '-' . preg_replace('/[^A-Za-z0-9\.\-_]/', '_', $document->original_file_name);
            $tmpRelativePath = $tmpFolder . '/' . $safeName;
            $tmpAbsolutePath = Storage::disk($disk)->path($tmpRelativePath);

            $this->decryptFile($encryptedAbsolutePath, $tmpAbsolutePath);

            /*
            |--------------------------------------------------------------------------
            | Integrity check
            |--------------------------------------------------------------------------
            | Decrypted temporary file must match original SHA256 hash.
            */
            $decryptedHash = hash_file('sha256', $tmpAbsolutePath);

            if ($document->sha256_hash && $decryptedHash !== $document->sha256_hash) {
                Storage::disk($disk)->delete($tmpRelativePath);

                throw new RuntimeException('Decrypted file integrity check failed.');
            }

            $log->update([
                'status' => 'success',
                'source_file_path' => $tmpRelativePath,
                'source_sha256_hash' => $decryptedHash,
                'message' => 'Temporary decryption completed successfully.',
                'completed_at' => now(),
            ]);

            return [
                'status' => 'success',
                'message' => 'Document decrypted temporarily.',
                'temporary_relative_path' => $tmpRelativePath,
                'temporary_absolute_path' => $tmpAbsolutePath,
                'delete_after_send' => true,
            ];
        } catch (Throwable $e) {
            $log->update([
                'status' => 'failed',
                'message' => 'Temporary decryption failed.',
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
     * Verify encrypted document by decrypting and checking original hash.
     */
    public function verify(Document $document, ?User $user = null): array
    {
        $result = $this->decryptToTemporaryFile($document, $user, 'verify');

        if (($result['status'] ?? null) !== 'success') {
            return $result;
        }

        $disk = $document->disk ?: 'local';

        if (!empty($result['temporary_relative_path'])) {
            Storage::disk($disk)->delete($result['temporary_relative_path']);
        }

        return [
            'status' => 'success',
            'message' => 'Encrypted document verified successfully.',
        ];
    }

    /**
     * Encrypt file using Sodium secretstream.
     */
    private function encryptFile(string $sourcePath, string $destinationPath): void
    {
        $key = $this->encryptionKey();
        $chunkSize = (int) config('dms.encryption.chunk_size', 8192);

        $input = fopen($sourcePath, 'rb');
        $output = fopen($destinationPath, 'wb');

        if (!$input || !$output) {
            throw new RuntimeException('Unable to open source or destination file for encryption.');
        }

        [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);

        fwrite($output, $header);

        while (!feof($input)) {
            $chunk = fread($input, $chunkSize);

            if ($chunk === false) {
                throw new RuntimeException('Failed to read source file during encryption.');
            }

            $tag = feof($input)
                ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL
                : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE;

            fwrite(
                $output,
                sodium_crypto_secretstream_xchacha20poly1305_push($state, $chunk, '', $tag)
            );
        }

        fclose($input);
        fclose($output);
    }

    /**
     * Decrypt file using Sodium secretstream.
     */
    private function decryptFile(string $sourcePath, string $destinationPath): void
    {
        $key = $this->encryptionKey();
        $chunkSize = (int) config('dms.encryption.chunk_size', 8192);

        $input = fopen($sourcePath, 'rb');
        $output = fopen($destinationPath, 'wb');

        if (!$input || !$output) {
            throw new RuntimeException('Unable to open source or destination file for decryption.');
        }

        $headerSize = SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES;
        $header = fread($input, $headerSize);

        if (!$header || strlen($header) !== $headerSize) {
            throw new RuntimeException('Invalid encrypted file header.');
        }

        $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $key);

        $encryptedChunkSize = $chunkSize + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES;

        $finalTagFound = false;

        while (!feof($input)) {
            $cipherText = fread($input, $encryptedChunkSize);

            if ($cipherText === false) {
                throw new RuntimeException('Failed to read encrypted file during decryption.');
            }

            if ($cipherText === '') {
                break;
            }

            $plain = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $cipherText);

            if ($plain === false) {
                throw new RuntimeException('Encrypted file authentication failed.');
            }

            [$message, $tag] = $plain;

            fwrite($output, $message);

            if ($tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) {
                $finalTagFound = true;
                break;
            }
        }

        fclose($input);
        fclose($output);

        if (!$finalTagFound) {
            throw new RuntimeException('Encrypted file final tag missing.');
        }
    }
}