<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

final class MongoIndexes
{
    public static function ensure(): void
    {
        $database = DB::connection('mongodb')->getMongoDB();

        $database->selectCollection('generations')->createIndex(
            ['expires_at' => 1],
            ['name' => 'expires_at_ttl', 'expireAfterSeconds' => 0],
        );
        $database->selectCollection('generations')->createIndex(
            ['share_token' => 1],
            ['name' => 'share_token_unique', 'unique' => true],
        );
        $database->selectCollection('consents')->createIndex(
            ['expires_at' => 1],
            ['name' => 'expires_at_ttl', 'expireAfterSeconds' => 0],
        );
    }
}
