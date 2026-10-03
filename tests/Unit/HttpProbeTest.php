<?php

namespace Tests\Unit;

use App\Infrastructure\Monitoring\HttpProbe;
use App\Infrastructure\Monitoring\HttpTransport;
use App\Infrastructure\Monitoring\ProbeTransportException;
use App\Infrastructure\Security\HostResolver;
use App\Infrastructure\Security\OutboundTargetValidator;
use Tests\TestCase;

class HttpProbeTest extends TestCase
{
    private function probe(array $responses): HttpProbe
    {
        $resolver = new class implements HostResolver
        {
            public function resolve(string $host): array
            {
                return $host === 'public.example' ? ['8.8.8.8'] : ['127.0.0.1'];
            }
        };
        $transport = new class($responses) implements HttpTransport
        {
            public function __construct(private array $responses) {}

            public function get(string $url, string $address, float $timeout, int $bodyLimit): array
            {
                if ($address !== '8.8.8.8' || $timeout > 10 || $bodyLimit > 1048576) {
                    throw new \LogicException('Unsafe request');
                }
                $next = array_shift($this->responses);
                if (is_string($next)) {
                    throw new ProbeTransportException($next);
                }

                return [...['status' => 200, 'body' => 'secret page content', 'location' => null, 'peer' => $address], ...$next];
            }
        };

        return new HttpProbe(new OutboundTargetValidator($resolver), $transport);
    }

    public function test_status_content_and_sanitized_evidence(): void
    {
        $result = $this->probe([[]])->check('https://public.example');
        $this->assertSame('pass', $result->outcome);
        $this->assertSame('not_configured', $result->evidence['content_check']);
        $this->assertStringNotContainsString('secret', json_encode($result));
        $this->assertSame('CONTENT_MISMATCH', $this->probe([[]])->check('https://public.example', ['expected_text' => 'healthy'])->reason);
        $this->assertSame('pass', $this->probe([[]])->check('https://public.example', ['expected_text' => 'page'])->outcome);
        $this->assertSame('fail', $this->probe([['status' => 503]])->check('https://public.example')->outcome);
        $this->assertSame('pass', $this->probe([['status' => 404]])->check('https://public.example', ['allowed_statuses' => [404]])->outcome);
    }

    public function test_redirects_rebinding_limits_and_timeout(): void
    {
        $this->assertSame('TARGET_BLOCKED', $this->probe([['status' => 302, 'location' => 'http://private.example']])->check('https://public.example')->reason);
        $this->assertSame('TARGET_BLOCKED', $this->probe([['peer' => '127.0.0.1']])->check('https://public.example')->reason);
        $this->assertSame('pass', $this->probe([['status' => 302, 'location' => '/next'], []])->check('https://public.example')->outcome);
        $this->assertSame('REDIRECT_LIMIT', $this->probe(array_fill(0, 6, ['status' => 302, 'location' => '/loop']))->check('https://public.example')->reason);
        $this->assertSame('NETWORK_TIMEOUT', $this->probe(['NETWORK_TIMEOUT'])->check('https://public.example')->reason);
        $this->assertSame('BODY_LIMIT', $this->probe([['body' => 'large']])->check('https://public.example', ['body_limit' => 1])->reason);
    }

    public function test_private_reserved_and_ambiguous_targets_never_connect(): void
    {
        foreach (['http://127.0.0.1', 'http://169.254.169.254', 'http://100.64.0.1', 'http://198.18.0.1', 'http://224.0.0.1', 'http://[::1]', 'http://[::ffff:127.0.0.1]', 'http://[2001:db8::1]', 'file:///etc/passwd', 'http://user:secret@public.example', 'http://public.example:22'] as $url) {
            $this->assertSame('TARGET_BLOCKED', $this->probe([])->check($url)->reason, $url);
        }
    }
}
