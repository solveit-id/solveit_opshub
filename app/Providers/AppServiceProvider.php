<?php

namespace App\Providers;

use App\Infrastructure\Connectors\ConnectorSecretResolver;
use App\Infrastructure\Connectors\Cpanel\CpanelTransport;
use App\Infrastructure\Connectors\Cpanel\NativeCpanelTransport;
use App\Infrastructure\Connectors\EnvironmentConnectorSecrets;
use App\Infrastructure\Monitoring\CurlHttpTransport;
use App\Infrastructure\Monitoring\DnsRecordReader;
use App\Infrastructure\Monitoring\HttpTransport;
use App\Infrastructure\Monitoring\NativeDnsRecordReader;
use App\Infrastructure\Monitoring\NativeTlsTransport;
use App\Infrastructure\Monitoring\TlsTransport;
use App\Infrastructure\Security\HostResolver;
use App\Infrastructure\Security\NativeHostResolver;
use App\Infrastructure\Telegram\EnvironmentTelegramSecrets;
use App\Infrastructure\Telegram\NativeTelegramTransport;
use App\Infrastructure\Telegram\TelegramSecretResolver;
use App\Infrastructure\Telegram\TelegramTransport;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use LogicException;
use PDO;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(ConnectorSecretResolver::class, EnvironmentConnectorSecrets::class);
        $this->app->bind(CpanelTransport::class, NativeCpanelTransport::class);
        $this->app->bind(TelegramSecretResolver::class, EnvironmentTelegramSecrets::class);
        $this->app->bind(TelegramTransport::class, NativeTelegramTransport::class);
        $this->app->bind(HostResolver::class, NativeHostResolver::class);
        $this->app->bind(TlsTransport::class, NativeTlsTransport::class);
        $this->app->bind(DnsRecordReader::class, NativeDnsRecordReader::class);
        $this->app->bind(HttpTransport::class, CurlHttpTransport::class);

        // Laravel merges its built-in connection templates into project config.
        $this->app['config']->set('database.connections', [
            'mysql' => $this->app['config']->get('database.connections.mysql'),
        ]);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (config('database.default') !== 'mysql') {
            throw new LogicException('Solveit OpsHub requires DB_CONNECTION=mysql.');
        }

        Event::listen(ConnectionEstablished::class, function (ConnectionEstablished $event): void {
            if ($event->connection->getDriverName() !== 'mysql') {
                throw new LogicException('Solveit OpsHub requires a MySQL connection.');
            }

            $version = (string) $event->connection->getPdo()->getAttribute(PDO::ATTR_SERVER_VERSION);

            if (str_contains(strtolower($version), 'mariadb')) {
                throw new LogicException('Solveit OpsHub requires a MySQL server.');
            }
        });

        Vite::prefetch(concurrency: 3);
    }
}
