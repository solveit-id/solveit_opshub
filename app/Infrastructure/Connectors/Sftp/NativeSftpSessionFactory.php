<?php

namespace App\Infrastructure\Connectors\Sftp;

use App\Infrastructure\Connectors\ConnectorConfig;
use App\Infrastructure\Connectors\ConnectorFailure;
use App\Infrastructure\Connectors\ConnectorReason;
use App\Infrastructure\Connectors\ConnectorSecretResolver;
use App\Infrastructure\Connectors\ConnectorTargetGuard;
use phpseclib4\Net\SFTP;

class NativeSftpSessionFactory implements SftpSessionFactory
{
    public function __construct(private ConnectorTargetGuard $targets, private ConnectorSecretResolver $secrets) {}

    public function fake(): bool
    {
        return false;
    }

    public function open(ConnectorConfig $config): SftpSession
    {
        if (! app()->runningInConsole() || ! config('opshub.live_connectors_enabled') || $config->kind !== 'sftp') {
            throw new ConnectorFailure(ConnectorReason::NotConfigured);
        }
        try {
            $ip = $this->targets->addresses($config)[0];
        } catch (\Throwable) {
            throw new ConnectorFailure(ConnectorReason::TargetBlocked);
        }
        $address = str_contains($ip, ':') ? '['.$ip.']' : $ip;
        $socket = @stream_socket_client('tcp://'.$address.':22', $errno, $error, 5);
        if (! is_resource($socket)) {
            throw new ConnectorFailure(ConnectorReason::Network);
        }
        $peer = stream_socket_get_name($socket, true);
        $peerIp = $peer ? trim(substr($peer, 0, strrpos($peer, ':')), '[]') : '';
        if (inet_pton($peerIp) !== inet_pton($ip)) {
            fclose($socket);
            throw new ConnectorFailure(ConnectorReason::TargetBlocked);
        }
        $client = new SFTP($socket, 22, 5);
        $client->disableStatCache();
        $client->disableArbitraryLengthPackets();

        return new NativeSftpSession($client, $config, $this->secrets);
    }
}
