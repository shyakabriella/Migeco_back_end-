<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\API\BaseController as BaseController;
use App\Models\MetadataSchema;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

class MetadataSchemaController extends BaseController
{
    private function canManageSchemas($user): bool
    {
        return $user && (
            $user->isAdmin()
            || in_array($user->role?->slug, [
                'admin',
                'document_controller',
            ], true)
        );
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return $this->sendError('Unauthenticated.', [
                'error' => 'You must login first.',
            ], 401);
        }

        $schemas = MetadataSchema::with([
                'fields',
                'creator:id,name,email',
            ])
            ->when($request->filled('status'), function ($query) use ($request) {
                $query->where('status', $request->status);
            })
            ->when($request->filled('record_type'), function ($query) use ($request) {
                $query->where('record_type', $request->record_type);
            })
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = trim((string) $request->search);

                $query->where(function ($subQuery) use ($search) {
                    $subQuery->where('name', 'LIKE', '%' . $search . '%')
                        ->orWhere('description', 'LIKE', '%' . $search . '%')
                        ->orWhere('record_type', 'LIKE', '%' . $search . '%');
                });
            })
            ->orderBy('name')
            ->get();

        return $this->sendResponse(
            $schemas,
            'Metadata schemas retrieved successfully.'
        );
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return $this->sendError('Unauthenticated.', [
                'error' => 'You must login first.',
            ], 401);
        }

        if (!$this->canManageSchemas($user)) {
            return $this->sendError('Permission Denied.', [
                'error' => 'Only Admin or Document Controller can create metadata schemas.',
            ], 403);
        }

        $validator = $this->makeValidator($request);

        if ($validator->fails()) {
            return $this->sendError(
                'Validation Error.',
                $validator->errors(),
                422
            );
        }

        try {
            $schema = DB::transaction(function () use ($request, $user) {
                $schema = MetadataSchema::create([
                    'name' => trim($request->name),
                    'slug' => Str::slug($request->name) . '-' . strtolower(Str::random(6)),
                    'description' => $request->description,
                    'record_type' => $request->record_type,
                    'version' => 1,
                    'status' => $request->status ?? 'active',
                    'is_system' => false,
                    'created_by' => $user->id,
                ]);

                $this->replaceFields($schema, $request->input('fields', []));

                return $schema;
            });

            $schema->load([
                'fields',
                'creator:id,name,email',
            ]);

            return $this->sendResponse(
                $schema,
                'Metadata schema created successfully.'
            );
        } catch (Throwable $exception) {
            return $this->sendError('Create Failed.', [
                'error' => $exception->getMessage(),
            ], 500);
        }
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return $this->sendError('Unauthenticated.', [
                'error' => 'You must login first.',
            ], 401);
        }

        $schema = MetadataSchema::with([
                'fields',
                'creator:id,name,email',
            ])
            ->withCount('geologicalRecords')
            ->find($id);

        if (!$schema) {
            return $this->sendError('Not Found.', [
                'error' => 'Metadata schema not found.',
            ], 404);
        }

        return $this->sendResponse(
            $schema,
            'Metadata schema retrieved successfully.'
        );
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return $this->sendError('Unauthenticated.', [
                'error' => 'You must login first.',
            ], 401);
        }

        if (!$this->canManageSchemas($user)) {
            return $this->sendError('Permission Denied.', [
                'error' => 'Only Admin or Document Controller can update metadata schemas.',
            ], 403);
        }

        $schema = MetadataSchema::find($id);

        if (!$schema) {
            return $this->sendError('Not Found.', [
                'error' => 'Metadata schema not found.',
            ], 404);
        }

        $validator = $this->makeValidator($request, $schema->id, true);

        if ($validator->fails()) {
            return $this->sendError(
                'Validation Error.',
                $validator->errors(),
                422
            );
        }

        try {
            DB::transaction(function () use ($request, $schema) {
                $payload = [];

                foreach ([
                    'name',
                    'description',
                    'record_type',
                    'status',
                ] as $field) {
                    if ($request->has($field)) {
                        $payload[$field] = $request->input($field);
                    }
                }

                if ($request->has('name')) {
                    $payload['name'] = trim((string) $request->name);
                    $payload['slug'] = Str::slug($request->name) . '-' . $schema->id;
                }

                $payload['version'] = $schema->version + 1;

                $schema->update($payload);

                if ($request->has('fields')) {
                    $this->replaceFields(
                        $schema,
                        $request->input('fields', [])
                    );
                }
            });

            $schema->refresh()->load([
                'fields',
                'creator:id,name,email',
            ]);

            return $this->sendResponse(
                $schema,
                'Metadata schema updated successfully.'
            );
        } catch (Throwable $exception) {
            return $this->sendError('Update Failed.', [
                'error' => $exception->getMessage(),
            ], 500);
        }
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return $this->sendError('Unauthenticated.', [
                'error' => 'You must login first.',
            ], 401);
        }

        if (!$this->canManageSchemas($user)) {
            return $this->sendError('Permission Denied.', [
                'error' => 'Only Admin or Document Controller can deactivate metadata schemas.',
            ], 403);
        }

        $schema = MetadataSchema::withCount('geologicalRecords')->find($id);

        if (!$schema) {
            return $this->sendError('Not Found.', [
                'error' => 'Metadata schema not found.',
            ], 404);
        }

        /*
        |--------------------------------------------------------------------------
        | Safe behavior
        |--------------------------------------------------------------------------
        | A schema already used by records is deactivated, not physically deleted.
        */
        if ($schema->geological_records_count > 0 || $schema->is_system) {
            $schema->update([
                'status' => 'inactive',
                'version' => $schema->version + 1,
            ]);

            return $this->sendResponse(
                $schema,
                'Metadata schema deactivated successfully.'
            );
        }

        $schema->delete();

        return $this->sendResponse(
            [],
            'Metadata schema deleted successfully.'
        );
    }

    private function makeValidator(
        Request $request,
        ?int $schemaId = null,
        bool $isUpdate = false
    ) {
        $nameRules = [
            $isUpdate ? 'sometimes' : 'required',
            'string',
            'max:255',
        ];

        if ($schemaId) {
            $nameRules[] = Rule::unique('metadata_schemas', 'name')
                ->ignore($schemaId);
        } else {
            $nameRules[] = Rule::unique('metadata_schemas', 'name');
        }

        return Validator::make($request->all(), [
            'name' => $nameRules,
            'description' => ['nullable', 'string'],
            'record_type' => ['nullable', 'string', 'max:100'],
            'status' => [
                'nullable',
                Rule::in(['active', 'inactive']),
            ],

            'fields' => ['nullable', 'array'],
            'fields.*.field_key' => [
                'required_with:fields',
                'string',
                'max:100',
                'regex:/^[a-z][a-z0-9_]*$/',
            ],
            'fields.*.label' => [
                'required_with:fields',
                'string',
                'max:255',
            ],
            'fields.*.field_type' => [
                'required_with:fields',
                Rule::in([
                    'text',
                    'textarea',
                    'number',
                    'date',
                    'boolean',
                    'select',
                    'multi_select',
                ]),
            ],
            'fields.*.unit' => ['nullable', 'string', 'max:50'],
            'fields.*.options' => ['nullable', 'array'],
            'fields.*.options.*' => ['nullable', 'string', 'max:255'],
            'fields.*.validation_rules' => ['nullable', 'array'],
            'fields.*.validation_rules.*' => ['nullable', 'string', 'max:255'],
            'fields.*.is_required' => ['nullable', 'boolean'],
            'fields.*.is_searchable' => ['nullable', 'boolean'],
            'fields.*.is_filterable' => ['nullable', 'boolean'],
            'fields.*.sort_order' => ['nullable', 'integer', 'min:0'],
        ]);
    }

    private function replaceFields(
        MetadataSchema $schema,
        array $fields
    ): void {
        $schema->fields()->delete();

        foreach ($fields as $index => $field) {
            $schema->fields()->create([
                'field_key' => $field['field_key'],
                'label' => $field['label'],
                'field_type' => $field['field_type'] ?? 'text',
                'unit' => $field['unit'] ?? null,
                'options' => $field['options'] ?? null,
                'validation_rules' => $field['validation_rules'] ?? null,
                'is_required' => (bool) ($field['is_required'] ?? false),
                'is_searchable' => (bool) ($field['is_searchable'] ?? true),
                'is_filterable' => (bool) ($field['is_filterable'] ?? false),
                'sort_order' => (int) ($field['sort_order'] ?? $index),
            ]);
        }
    }
}
