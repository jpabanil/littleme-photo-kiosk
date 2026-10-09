<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

class Consent extends Model
{
    protected $connection = 'mongodb';

    protected $collection = 'consents';

    protected $fillable = [
        'generation_id',
        'consent_version',
        'accepted_at',
        'locale',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'accepted_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }
}
