<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PDO;

class CheckDatabase extends Command
{
    protected $signature = 'opshub:database:check';

    protected $description = 'Verify the configured MySQL server and database connection';

    public function handle(): int
    {
        $connection = DB::connection();
        $version = $connection->getPdo()->getAttribute(PDO::ATTR_SERVER_VERSION);

        $this->info('MySQL '.$version.' connected to '.$connection->getDatabaseName().'.');

        return self::SUCCESS;
    }
}
