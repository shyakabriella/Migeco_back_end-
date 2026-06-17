<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class GeologicalDocumentCategorySeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        $parent = DB::table('document_categories')
            ->where('name', 'Geological Records')
            ->first();

        if (!$parent) {
            $parentId = DB::table('document_categories')->insertGetId([
                'parent_id' => null,
                'created_by' => null,
                'name' => 'Geological Records',
                'slug' => 'geological-records',
                'description' => 'Technical and geological records that require structured geological metadata.',
                'status' => 'active',
                'sort_order' => 10,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } else {
            $parentId = $parent->id;

            DB::table('document_categories')
                ->where('id', $parentId)
                ->update([
                    'description' => $parent->description
                        ?: 'Technical and geological records that require structured geological metadata.',
                    'status' => 'active',
                    'updated_at' => $now,
                ]);
        }

        $categories = [
            ['Geological Reports', 10],
            ['Geological Maps', 20],
            ['Borehole Records', 30],
            ['Rock Samples', 40],
            ['Soil Profiles', 50],
            ['Lithology Logs', 60],
            ['Mineral Occurrences', 70],
            ['Laboratory Results', 80],
            ['Faults and Structures', 90],
            ['Groundwater and Aquifers', 100],
            ['Geophysical Surveys', 110],
            ['Geochemical Surveys', 120],
            ['Exploration Permits', 130],
            ['Mining Licences', 140],
            ['Field Notes', 150],
        ];

        foreach ($categories as [$name, $sortOrder]) {
            $existing = DB::table('document_categories')
                ->where('name', $name)
                ->first();

            if ($existing) {
                DB::table('document_categories')
                    ->where('id', $existing->id)
                    ->update([
                        'status' => 'active',
                        'sort_order' => $sortOrder,
                        'updated_at' => $now,
                    ]);

                continue;
            }

            DB::table('document_categories')->insert([
                'parent_id' => $parentId,
                'created_by' => null,
                'name' => $name,
                'slug' => Str::slug($name),
                'description' => $name . ' managed under the geological records taxonomy.',
                'status' => 'active',
                'sort_order' => $sortOrder,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}
