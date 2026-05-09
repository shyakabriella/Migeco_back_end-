<?php

namespace App\Services;

use App\Models\Document;
use App\Models\DocumentAiAnalysis;
use App\Models\DocumentAiAnalysisLog;
use App\Models\DocumentCategory;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class DocumentAiAnalyzer
{
    /**
     * Analyze one document using extracted plaintext.
     */
    public function analyze(Document $document, ?User $user = null, string $analysisType = 'manual'): array
    {
        $provider = config('dms.ai.provider', 'local');
        $model = config('dms.ai.model', 'dms-local-analyzer-v1');

        $log = DocumentAiAnalysisLog::create([
            'document_id' => $document->id,
            'performed_by' => $user?->id,
            'analysis_type' => $analysisType,
            'status' => 'pending',
            'provider' => $provider,
            'model' => $model,
            'message' => 'AI document analysis started.',
            'started_at' => now(),
        ]);

        try {
            if (!config('dms.ai.enabled')) {
                throw new RuntimeException('AI analysis module is disabled.');
            }

            if (!$document->isSafeToOpen()) {
                throw new RuntimeException('Only safe, clean, and sandbox-approved documents can be analyzed by AI.');
            }

            if (!$document->hasPlaintextExtracted()) {
                throw new RuntimeException('Plaintext must be extracted before AI analysis.');
            }

            $document->update([
                'ai_status' => 'pending',
            ]);

            $text = $this->getPlaintext($document);

            if (trim($text) === '') {
                throw new RuntimeException('No plaintext content found for AI analysis.');
            }

            $maxChars = (int) config('dms.ai.max_input_characters', 20000);
            $inputText = mb_substr($text, 0, $maxChars);

            $log->update([
                'input_character_count' => mb_strlen($inputText),
            ]);

            if ($provider === 'external') {
                $analysis = $this->analyzeWithExternalProvider($document, $inputText);

                if (!$analysis && config('dms.ai.fallback_to_local', true)) {
                    $analysis = $this->analyzeLocally($document, $inputText);
                    $provider = 'local';
                    $model = 'dms-local-analyzer-v1';
                }

                if (!$analysis) {
                    throw new RuntimeException('External AI analysis failed and no fallback result was available.');
                }
            } else {
                $analysis = $this->analyzeLocally($document, $inputText);
            }

            $aiRecord = DocumentAiAnalysis::updateOrCreate(
                ['document_id' => $document->id],
                [
                    'analyzed_by' => $user?->id,
                    'provider' => $provider,
                    'model' => $model,
                    'summary' => $analysis['summary'] ?? null,
                    'detected_language' => $analysis['detected_language'] ?? 'unknown',
                    'confidence_score' => $analysis['confidence_score'] ?? 70,
                    'sensitivity_level' => $analysis['sensitivity_level'] ?? 'internal',
                    'suggested_category_id' => $analysis['suggested_category_id'] ?? null,
                    'suggested_document_type' => $analysis['suggested_document_type'] ?? null,
                    'suggested_tags' => $analysis['suggested_tags'] ?? [],
                    'key_points' => $analysis['key_points'] ?? [],
                    'detected_risks' => $analysis['detected_risks'] ?? [],
                    'entities' => $analysis['entities'] ?? [],
                    'recommended_actions' => $analysis['recommended_actions'] ?? [],
                    'raw_response' => $analysis,
                    'status' => 'analyzed',
                    'message' => 'AI analysis completed successfully.',
                ]
            );

            $document->update([
                'ai_status' => 'analyzed',
                'ai_analyzed_by' => $user?->id,
                'ai_provider' => $provider,
                'ai_model' => $model,
                'ai_summary' => $analysis['summary'] ?? null,
                'ai_detected_language' => $analysis['detected_language'] ?? 'unknown',
                'ai_confidence_score' => $analysis['confidence_score'] ?? 70,
                'ai_sensitivity_level' => $analysis['sensitivity_level'] ?? 'internal',
                'ai_suggested_category_id' => $analysis['suggested_category_id'] ?? null,
                'ai_suggested_document_type' => $analysis['suggested_document_type'] ?? null,
                'ai_suggested_tags' => $analysis['suggested_tags'] ?? [],
                'ai_key_points' => $analysis['key_points'] ?? [],
                'ai_detected_risks' => $analysis['detected_risks'] ?? [],
                'ai_entities' => $analysis['entities'] ?? [],
                'ai_recommended_actions' => $analysis['recommended_actions'] ?? [],
                'ai_analyzed_at' => now(),
            ]);

            $log->update([
                'status' => 'analyzed',
                'provider' => $provider,
                'model' => $model,
                'message' => 'AI document analysis completed successfully.',
                'completed_at' => now(),
            ]);

            return [
                'status' => 'analyzed',
                'message' => 'AI document analysis completed successfully.',
                'analysis_id' => $aiRecord->id,
                'summary' => $analysis['summary'] ?? null,
                'sensitivity_level' => $analysis['sensitivity_level'] ?? 'internal',
                'suggested_tags' => $analysis['suggested_tags'] ?? [],
                'suggested_document_type' => $analysis['suggested_document_type'] ?? null,
                'suggested_category_id' => $analysis['suggested_category_id'] ?? null,
                'detected_risks' => $analysis['detected_risks'] ?? [],
            ];
        } catch (Throwable $e) {
            $document->update([
                'ai_status' => 'failed',
            ]);

            $log->update([
                'status' => 'failed',
                'message' => 'AI document analysis failed.',
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
     * Get plaintext from database or private plaintext file.
     */
    private function getPlaintext(Document $document): string
    {
        $document->loadMissing('plaintext');

        if ($document->plaintext?->content) {
            return $document->plaintext->content;
        }

        if ($document->plaintext_file_path && Storage::disk('local')->exists($document->plaintext_file_path)) {
            return Storage::disk('local')->get($document->plaintext_file_path);
        }

        if ($document->plaintext?->plaintext_file_path && Storage::disk('local')->exists($document->plaintext->plaintext_file_path)) {
            return Storage::disk('local')->get($document->plaintext->plaintext_file_path);
        }

        return '';
    }

    /**
     * Local rule-based AI analysis.
     */
    private function analyzeLocally(Document $document, string $text): array
    {
        $normalized = mb_strtolower($text);

        $summary = $this->makeSummary($text);
        $keyPoints = $this->extractKeyPoints($text);
        $tags = $this->suggestTags($normalized, $document);
        $risks = $this->detectRisks($text, $normalized);
        $entities = $this->extractEntities($text);
        $sensitivity = $this->determineSensitivityLevel($risks, $normalized);
        $documentType = $this->suggestDocumentType($normalized, $document);
        $categoryId = $this->suggestCategoryId($tags, $documentType);

        return [
            'summary' => $summary,
            'detected_language' => $this->detectLanguage($normalized),
            'confidence_score' => $this->confidenceScore($text, $tags, $risks),
            'sensitivity_level' => $sensitivity,
            'suggested_category_id' => $categoryId,
            'suggested_document_type' => $documentType,
            'suggested_tags' => $tags,
            'key_points' => $keyPoints,
            'detected_risks' => $risks,
            'entities' => $entities,
            'recommended_actions' => $this->recommendedActions($sensitivity, $risks),
            'provider_note' => 'Local rule-based AI analysis. No external AI API was used.',
        ];
    }

    /**
     * Optional external AI provider.
     *
     * Your external endpoint should return JSON with keys like:
     * summary, suggested_tags, detected_risks, sensitivity_level, etc.
     */
    private function analyzeWithExternalProvider(Document $document, string $text): ?array
    {
        $endpoint = config('dms.ai.endpoint');
        $apiKey = config('dms.ai.api_key');

        if (!$endpoint || !$apiKey) {
            return null;
        }

        try {
            $response = Http::timeout((int) config('dms.ai.timeout', 60))
                ->withToken($apiKey)
                ->acceptJson()
                ->post($endpoint, [
                    'model' => config('dms.ai.model'),
                    'document' => [
                        'id' => $document->id,
                        'document_code' => $document->document_code,
                        'title' => $document->title,
                        'document_type' => $document->document_type,
                        'security_level' => $document->security_level,
                        'extension' => $document->extension,
                    ],
                    'task' => 'Analyze this document. Return JSON with summary, key_points, suggested_tags, sensitivity_level, detected_risks, entities, suggested_document_type, recommended_actions.',
                    'plaintext' => $text,
                ]);

            if (!$response->successful()) {
                return null;
            }

            $data = $response->json();

            return is_array($data) ? $data : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Make simple summary from first important sentences.
     */
    private function makeSummary(string $text): string
    {
        $clean = preg_replace('/\s+/', ' ', trim($text));

        $sentences = preg_split('/(?<=[.!?])\s+/', $clean) ?: [];

        $summary = implode(' ', array_slice(array_filter($sentences), 0, 4));

        if (!$summary) {
            $summary = mb_substr($clean, 0, 800);
        }

        return mb_substr($summary, 0, 1200);
    }

    /**
     * Extract key points.
     */
    private function extractKeyPoints(string $text): array
    {
        $clean = preg_replace('/\s+/', ' ', trim($text));
        $sentences = preg_split('/(?<=[.!?])\s+/', $clean) ?: [];

        $points = [];

        foreach ($sentences as $sentence) {
            $sentence = trim($sentence);

            if (mb_strlen($sentence) >= 40) {
                $points[] = mb_substr($sentence, 0, 250);
            }

            if (count($points) >= 6) {
                break;
            }
        }

        return $points;
    }

    /**
     * Suggest tags.
     */
    private function suggestTags(string $normalized, Document $document): array
    {
        $map = [
            'geology' => ['geology', 'geological', 'soil', 'rock', 'mineral', 'earth', 'strata'],
            'survey' => ['survey', 'gps', 'coordinates', 'mapping', 'topography'],
            'construction' => ['construction', 'building', 'site', 'concrete', 'foundation'],
            'technical' => ['technical', 'specification', 'engineering', 'design'],
            'contract' => ['contract', 'agreement', 'signature', 'party', 'terms'],
            'financial' => ['invoice', 'payment', 'budget', 'cost', 'price', 'amount'],
            'security' => ['confidential', 'restricted', 'secret', 'password', 'token'],
            'environment' => ['environment', 'impact', 'water', 'waste', 'pollution'],
            'report' => ['report', 'analysis', 'findings', 'recommendation'],
        ];

        $tags = [];

        foreach ($map as $tag => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($normalized, $keyword)) {
                    $tags[] = $tag;
                    break;
                }
            }
        }

        if ($document->document_type && $document->document_type !== 'other') {
            $tags[] = str_replace('_', '-', $document->document_type);
        }

        return array_values(array_unique($tags));
    }

    /**
     * Detect risks.
     */
    private function detectRisks(string $text, string $normalized): array
    {
        $risks = [];

        if (preg_match_all('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $text, $matches)) {
            $risks[] = [
                'level' => 'medium',
                'type' => 'email_addresses',
                'message' => 'Document contains email addresses.',
                'count' => count(array_unique($matches[0])),
            ];
        }

        if (preg_match_all('/(\+?\d[\d\s\-\(\)]{7,}\d)/', $text, $matches)) {
            $risks[] = [
                'level' => 'medium',
                'type' => 'phone_numbers',
                'message' => 'Document may contain phone numbers.',
                'count' => count(array_unique($matches[0])),
            ];
        }

        if (preg_match('/\b(password|secret|token|api key|private key|credential)\b/i', $text)) {
            $risks[] = [
                'level' => 'high',
                'type' => 'credentials_keywords',
                'message' => 'Document contains credential-related keywords.',
            ];
        }

        if (preg_match('/\b(confidential|restricted|classified|private)\b/i', $text)) {
            $risks[] = [
                'level' => 'high',
                'type' => 'confidentiality_keywords',
                'message' => 'Document contains confidentiality keywords.',
            ];
        }

        if (preg_match('/\b(invoice|payment|bank|account number|budget|salary|amount)\b/i', $text)) {
            $risks[] = [
                'level' => 'medium',
                'type' => 'financial_information',
                'message' => 'Document may contain financial information.',
            ];
        }

        if (preg_match('/[-+]?\d{1,2}\.\d{4,}\s*,\s*[-+]?\d{1,3}\.\d{4,}/', $text)) {
            $risks[] = [
                'level' => 'low',
                'type' => 'gps_coordinates',
                'message' => 'Document may contain GPS coordinates.',
            ];
        }

        if (str_contains($normalized, 'legal') || str_contains($normalized, 'contract')) {
            $risks[] = [
                'level' => 'medium',
                'type' => 'legal_document',
                'message' => 'Document may contain legal or contractual information.',
            ];
        }

        return $risks;
    }

    /**
     * Extract simple entities.
     */
    private function extractEntities(string $text): array
    {
        $entities = [
            'emails' => [],
            'dates' => [],
            'coordinates' => [],
        ];

        if (preg_match_all('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $text, $matches)) {
            $entities['emails'] = array_values(array_unique(array_slice($matches[0], 0, 10)));
        }

        if (preg_match_all('/\b\d{4}-\d{2}-\d{2}\b|\b\d{1,2}\/\d{1,2}\/\d{2,4}\b/', $text, $matches)) {
            $entities['dates'] = array_values(array_unique(array_slice($matches[0], 0, 10)));
        }

        if (preg_match_all('/[-+]?\d{1,2}\.\d{4,}\s*,\s*[-+]?\d{1,3}\.\d{4,}/', $text, $matches)) {
            $entities['coordinates'] = array_values(array_unique(array_slice($matches[0], 0, 10)));
        }

        return $entities;
    }

    /**
     * Determine sensitivity level.
     */
    private function determineSensitivityLevel(array $risks, string $normalized): string
    {
        foreach ($risks as $risk) {
            if (($risk['level'] ?? null) === 'high') {
                return 'restricted';
            }
        }

        foreach ($risks as $risk) {
            if (($risk['level'] ?? null) === 'medium') {
                return 'confidential';
            }
        }

        if (str_contains($normalized, 'public')) {
            return 'public';
        }

        return 'internal';
    }

    /**
     * Suggest document type.
     */
    private function suggestDocumentType(string $normalized, Document $document): string
    {
        if (str_contains($normalized, 'soil') || str_contains($normalized, 'mineral') || str_contains($normalized, 'geological')) {
            return 'geological_report';
        }

        if (str_contains($normalized, 'drawing') || str_contains($normalized, 'design') || str_contains($normalized, 'blueprint')) {
            return 'technical_drawing';
        }

        if (str_contains($normalized, 'construction') || str_contains($normalized, 'site work') || str_contains($normalized, 'foundation')) {
            return 'construction_record';
        }

        if (str_contains($normalized, 'survey') || str_contains($normalized, 'map') || str_contains($normalized, 'gps')) {
            return 'survey_map';
        }

        if (str_contains($normalized, 'contract') || str_contains($normalized, 'agreement')) {
            return 'contract';
        }

        if (in_array($document->extension, ['txt', 'csv'], true)) {
            return 'plain_text';
        }

        return $document->document_type ?: 'other';
    }

    /**
     * Suggest category ID based on tags/type.
     */
    private function suggestCategoryId(array $tags, string $documentType): ?int
    {
        $keywords = array_merge($tags, [str_replace('_', ' ', $documentType)]);

        $categories = DocumentCategory::where('status', 'active')->get();

        foreach ($categories as $category) {
            $name = mb_strtolower($category->name . ' ' . $category->description);

            foreach ($keywords as $keyword) {
                if ($keyword && str_contains($name, mb_strtolower(str_replace('-', ' ', $keyword)))) {
                    return $category->id;
                }
            }
        }

        return null;
    }

    /**
     * Recommend actions.
     */
    private function recommendedActions(string $sensitivity, array $risks): array
    {
        $actions = [];

        if ($sensitivity === 'restricted') {
            $actions[] = 'Restrict access to Admin, Security Officer, Auditor, Project Manager, and Document Controller only.';
            $actions[] = 'Review document manually before sharing.';
        }

        if ($sensitivity === 'confidential') {
            $actions[] = 'Limit access to authorized project members.';
        }

        if (!empty($risks)) {
            $actions[] = 'Review detected risks before approving document for wider access.';
        }

        $actions[] = 'Keep antivirus, sandbox, encryption, and audit records attached to this document.';

        return $actions;
    }

    /**
     * Simple language detection.
     */
    private function detectLanguage(string $normalized): string
    {
        if (preg_match('/\b(the|and|document|report|project|analysis)\b/i', $normalized)) {
            return 'en';
        }

        if (preg_match('/\b(le|la|les|rapport|projet|analyse)\b/i', $normalized)) {
            return 'fr';
        }

        if (preg_match('/\b(amakuru|raporo|umushinga|inyandiko)\b/i', $normalized)) {
            return 'rw';
        }

        return 'unknown';
    }

    /**
     * Confidence score.
     */
    private function confidenceScore(string $text, array $tags, array $risks): float
    {
        $score = 65;

        if (mb_strlen($text) > 1000) {
            $score += 10;
        }

        if (count($tags) >= 2) {
            $score += 10;
        }

        if (count($risks) > 0) {
            $score += 5;
        }

        return min($score, 95);
    }
}