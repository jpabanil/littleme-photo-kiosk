<?php

namespace App\Console\Commands;

use App\Services\MongoIndexes;
use Illuminate\Console\Command;

class EnsureIndexes extends Command
{
    protected $signature = 'littleme:indexes';

    protected $description = 'Create TTL and share-token indexes';

    public function handle(): int
    {
        MongoIndexes::ensure();
        $this->info('Indexes ready');

        return self::SUCCESS;
    }
}
