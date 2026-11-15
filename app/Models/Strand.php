<?php

namespace App\Models;

use App\Enums\Track;
use App\Models\Concerns\Auditable;
use Database\Factories\StrandFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Strand extends Model
{
    /** @use HasFactory<StrandFactory> */
    use Auditable, HasFactory;

    protected $fillable = ['code', 'name', 'track', 'description', 'is_active', 'sort_order'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'is_active' => true,
        'sort_order' => 0,
    ];

    protected function casts(): array
    {
        return [
            'track' => Track::class,
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function sections(): HasMany
    {
        return $this->hasMany(Section::class);
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }
}
