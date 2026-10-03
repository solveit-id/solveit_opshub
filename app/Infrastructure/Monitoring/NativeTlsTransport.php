<?php

namespace App\Infrastructure\Monitoring;

class NativeTlsTransport implements TlsTransport
{
    public function inspect(string $host, int $port, string $address): array
    {
        $context = stream_context_create(['ssl' => [
            'peer_name' => $host, 'SNI_enabled' => true, 'verify_peer' => true,
            'verify_peer_name' => true, 'allow_self_signed' => false, 'capture_peer_cert' => true,
        ]]);
        $target = 'tcp://'.(str_contains($address, ':') ? '['.$address.']' : $address).':'.$port;
        $deadline = microtime(true) + 10;
        $socket = @stream_socket_client($target, $code, $error, 10, STREAM_CLIENT_CONNECT, $context);
        if ($socket === false) {
            throw new ProbeTransportException($code === 110 || $code === 10060 ? 'NETWORK_TIMEOUT' : 'NETWORK_ERROR');
        }
        try {
            stream_set_timeout($socket, max(1, (int) ceil($deadline - microtime(true))));
            if (@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) !== true) {
                throw new ProbeTransportException('TLS_INVALID');
            }
            $params = stream_context_get_params($socket);
            $certificate = openssl_x509_parse($params['options']['ssl']['peer_certificate']);
            $peer = stream_socket_get_name($socket, true);
            $peerAddress = str_starts_with($peer, '[') ? substr($peer, 1, strpos($peer, ']') - 1) : substr($peer, 0, strrpos($peer, ':'));

            return ['peer' => $peerAddress, 'expires_at' => $certificate['validTo_time_t'], 'hostname_valid' => true, 'chain_valid' => true];
        } finally {
            fclose($socket);
        }
    }
}
