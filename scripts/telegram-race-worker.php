<?php

use App\Application\RenewalFollowups\FollowupWorkflow;
use App\Application\RenewalFollowups\RenewalScheduler;
use App\Application\TelegramNotifications\DeliveryMaterializer;
use App\Application\TelegramNotifications\DeliverySender;
use App\Infrastructure\Telegram\TelegramResult;
use App\Infrastructure\Telegram\TelegramTransport;
use App\Models\ClientFollowup;
use App\Models\Evidence;
use App\Models\Organization;
use App\Models\ServiceSubscription;
use App\Models\TelegramBot;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || config('database.default') !== 'mysql' || ! str_ends_with(config('database.connections.mysql.database'), '_test') || config('database.connections.mysql.url') || config('opshub.live_connectors_enabled') || config('opshub.public_probes_enabled')) {
    fwrite(STDERR, "Refusing non-isolated fake environment.\n");
    exit(1);
}
[$script, $operation, $id, $readyAt] = $argv;
CarbonImmutable::setTestNow('2026-10-04T03:00:00Z');
app()->instance(TelegramTransport::class, new class implements TelegramTransport
{
    public function request(TelegramBot $bot, string $method, array $payload = []): TelegramResult
    {
        usleep(250000);

        return new TelegramResult('sent', 'FAKE_RACE_ACCEPTED', 200, messageId: 'fake-race', fake: true);
    }
});
// Both workers snapshot the same optimistic version before the barrier.
$followup = $operation === 'verify' ? ClientFollowup::findOrFail((int) $id) : null;
$service = $followup?->cycle->subscription;
$version = $followup?->version;
while (microtime(true) < (float) $readyAt) {
    usleep(1000);
}
if ($operation === 'schedule') {
    $subscription = ServiceSubscription::findOrFail((int) $id);
    app(RenewalScheduler::class)->tick(Organization::findOrFail($subscription->organization_id));
    echo 'scheduled';
} elseif ($operation === 'materialize') {
    app(DeliveryMaterializer::class)->materialize(Organization::findOrFail((int) $id));
    echo 'materialized';
} elseif ($operation === 'send') {
    echo app(DeliverySender::class)->send((int) $id)->state;
} elseif ($operation === 'verify') {
    $org = Organization::findOrFail($followup->organization_id);
    $owner = User::findOrFail($followup->cycle->subscription->asset->usages()->firstOrFail()->project->internal_pic_user_id);
    $evidence = Evidence::forOrganization($org)->where('source', 'fictitious_race_new_period')->sole();
    try {
        app(FollowupWorkflow::class)->act($org, $owner, $followup, 'verify_renewal', ['version' => $version, 'subscription_version' => $service->version, 'date_precision' => 'date', 'expiry_date' => '2027-10-18', 'source_timezone' => 'Asia/Jakarta', 'source' => 'fictitious_race_new_period', 'evidence_id' => $evidence->id]);
        echo 'verified';
    } catch (HttpExceptionInterface $e) {
        if ($e->getStatusCode() !== 409) {
            throw $e;
        }
        echo 'conflict';
    }
} else {
    fwrite(STDERR, "Unsupported race operation.\n");
    exit(1);
}
