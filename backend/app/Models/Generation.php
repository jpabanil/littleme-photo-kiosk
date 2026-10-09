<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

class Generation extends Model
{
    protected $connection = 'mongodb';

    protected $collection = 'generations';

    protected $fillable = [
        'gender',
        'template_key',
        'age',
        'status',
        'output_path',
        'share_token',
        'duration_ms',
        'error_code',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'age' => 'integer',
            'duration_ms' => 'integer',
            'expires_at' => 'datetime',
        ];
    }
}
