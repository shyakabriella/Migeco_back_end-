<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SystemSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'group',
        'key',
        'label',
        'value',
        'type',
        'is_public',
        'updated_by',
    ];

    protected $casts = [
        'is_public' => 'boolean',
    ];

    public function getDecodedValueAttribute()
    {
        if ($this->value === null || $this->value === '') {
            return null;
        }

        $decoded = json_decode($this->value, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return $this->value;
        }

        return $decoded;
    }

    public static function getSetting(string $group, string $key, mixed $default = null): mixed
    {
        $setting = self::query()
            ->where('group', $group)
            ->where('key', $key)
            ->first();

        return $setting ? $setting->decoded_value : $default;
    }

    public static function putSetting(
        string $group,
        string $key,
        mixed $value,
        ?int $updatedBy = null,
        ?string $label = null,
        string $type = 'json',
        bool $isPublic = false
    ): self {
        return self::query()->updateOrCreate(
            [
                'group' => $group,
                'key' => $key,
            ],
            [
                'label' => $label ?? self::humanLabel($key),
                'value' => json_encode($value),
                'type' => $type,
                'is_public' => $isPublic,
                'updated_by' => $updatedBy,
            ]
        );
    }

    public static function getGroupValues(string $group, array $defaults = []): array
    {
        $settings = self::query()
            ->where('group', $group)
            ->get()
            ->keyBy('key');

        $values = $defaults;

        foreach ($settings as $key => $setting) {
            $values[$key] = $setting->decoded_value;
        }

        return $values;
    }

    private static function humanLabel(string $key): string
    {
        return str($key)
            ->replace('_', ' ')
            ->headline()
            ->toString();
    }
}