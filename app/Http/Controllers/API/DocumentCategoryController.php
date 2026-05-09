<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\API\BaseController as BaseController;
use App\Models\DocumentCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class DocumentCategoryController extends BaseController
{
    /**
     * Check if current user can manage document categories.
     */
    private function canManageCategories($user): bool
    {
        return in_array($user->role?->slug, [
            'admin',
            'document_controller',
        ]);
    }

    /**
     * Display all categories.
     */
    public function index(Request $request): JsonResponse
    {
        $categories = DocumentCategory::with([
                'parent:id,name,slug',
                'children:id,parent_id,name,slug,status,sort_order',
                'creator:id,name,email',
            ])
            ->when($request->status, function ($query) use ($request) {
                $query->where('status', $request->status);
            })
            ->when($request->search, function ($query) use ($request) {
                $query->where(function ($q) use ($request) {
                    $q->where('name', 'LIKE', '%' . $request->search . '%')
                        ->orWhere('description', 'LIKE', '%' . $request->search . '%');
                });
            })
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return $this->sendResponse($categories, 'Document categories retrieved successfully.');
    }

    /**
     * Store new category.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return $this->sendError('Unauthenticated.', [
                'error' => 'You must login first.'
            ], 401);
        }

        if (!$this->canManageCategories($user)) {
            return $this->sendError('Permission Denied.', [
                'error' => 'Only Admin or Document Controller can create categories.'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'parent_id' => [
                'nullable',
                'integer',
                'exists:document_categories,id',
            ],
            'name' => [
                'required',
                'string',
                'max:255',
                'unique:document_categories,name',
            ],
            'description' => [
                'nullable',
                'string',
            ],
            'status' => [
                'nullable',
                Rule::in(['active', 'inactive']),
            ],
            'sort_order' => [
                'nullable',
                'integer',
                'min:0',
            ],
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation Error.', $validator->errors(), 422);
        }

        $category = DocumentCategory::create([
            'parent_id' => $request->parent_id,
            'created_by' => $user->id,
            'name' => $request->name,
            'slug' => Str::slug($request->name) . '-' . time(),
            'description' => $request->description,
            'status' => $request->status ?? 'active',
            'sort_order' => $request->sort_order ?? 0,
        ]);

        $category->load([
            'parent:id,name,slug',
            'creator:id,name,email',
        ]);

        return $this->sendResponse($category, 'Document category created successfully.');
    }

    /**
     * Show one category.
     */
    public function show(string $id): JsonResponse
    {
        $category = DocumentCategory::with([
                'parent:id,name,slug',
                'children:id,parent_id,name,slug,status,sort_order',
                'creator:id,name,email',
            ])
            ->find($id);

        if (!$category) {
            return $this->sendError('Not Found.', [
                'error' => 'Document category not found.'
            ], 404);
        }

        return $this->sendResponse($category, 'Document category retrieved successfully.');
    }

    /**
     * Update category.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return $this->sendError('Unauthenticated.', [
                'error' => 'You must login first.'
            ], 401);
        }

        if (!$this->canManageCategories($user)) {
            return $this->sendError('Permission Denied.', [
                'error' => 'Only Admin or Document Controller can update categories.'
            ], 403);
        }

        $category = DocumentCategory::find($id);

        if (!$category) {
            return $this->sendError('Not Found.', [
                'error' => 'Document category not found.'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'parent_id' => [
                'nullable',
                'integer',
                'exists:document_categories,id',
                Rule::notIn([$category->id]),
            ],
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('document_categories', 'name')->ignore($category->id),
            ],
            'description' => [
                'nullable',
                'string',
            ],
            'status' => [
                'nullable',
                Rule::in(['active', 'inactive']),
            ],
            'sort_order' => [
                'nullable',
                'integer',
                'min:0',
            ],
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation Error.', $validator->errors(), 422);
        }

        $category->update([
            'parent_id' => $request->parent_id,
            'name' => $request->name,
            'slug' => Str::slug($request->name) . '-' . $category->id,
            'description' => $request->description,
            'status' => $request->status ?? $category->status,
            'sort_order' => $request->sort_order ?? $category->sort_order,
        ]);

        $category->load([
            'parent:id,name,slug',
            'children:id,parent_id,name,slug,status,sort_order',
            'creator:id,name,email',
        ]);

        return $this->sendResponse($category, 'Document category updated successfully.');
    }

    /**
     * Delete category.
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return $this->sendError('Unauthenticated.', [
                'error' => 'You must login first.'
            ], 401);
        }

        if (!$this->canManageCategories($user)) {
            return $this->sendError('Permission Denied.', [
                'error' => 'Only Admin or Document Controller can delete categories.'
            ], 403);
        }

        $category = DocumentCategory::withCount('children')->find($id);

        if (!$category) {
            return $this->sendError('Not Found.', [
                'error' => 'Document category not found.'
            ], 404);
        }

        if ($category->children_count > 0) {
            return $this->sendError('Delete Failed.', [
                'error' => 'This category has child categories. Delete or move child categories first.'
            ], 400);
        }

        $category->delete();

        return $this->sendResponse([], 'Document category deleted successfully.');
    }
}