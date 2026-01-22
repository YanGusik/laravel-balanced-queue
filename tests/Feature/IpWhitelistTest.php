<?php

declare(strict_types=1);

namespace YanGusik\BalancedQueue\Tests\Feature;

use Illuminate\Http\Request;
use YanGusik\BalancedQueue\Http\Middleware\IpWhitelist;
use YanGusik\BalancedQueue\Tests\TestCase;

class IpWhitelistTest extends TestCase
{
    public function test_allows_exact_ip_match(): void
    {
        config(['balanced-queue.prometheus.ip_whitelist' => ['192.168.1.100']]);

        $request = Request::create('/test');
        $request->server->set('REMOTE_ADDR', '192.168.1.100');

        $middleware = new IpWhitelist();
        $response = $middleware->handle($request, fn () => response('OK'));

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('OK', $response->getContent());
    }

    public function test_blocks_non_whitelisted_ip(): void
    {
        config(['balanced-queue.prometheus.ip_whitelist' => ['192.168.1.100']]);

        $request = Request::create('/test');
        $request->server->set('REMOTE_ADDR', '10.0.0.1');

        $middleware = new IpWhitelist();
        $response = $middleware->handle($request, fn () => response('OK'));

        $this->assertEquals(403, $response->getStatusCode());
    }

    public function test_allows_ip_in_cidr_range(): void
    {
        config(['balanced-queue.prometheus.ip_whitelist' => ['10.0.0.0/8']]);

        $request = Request::create('/test');
        $request->server->set('REMOTE_ADDR', '10.255.255.255');

        $middleware = new IpWhitelist();
        $response = $middleware->handle($request, fn () => response('OK'));

        $this->assertEquals(200, $response->getStatusCode());
    }

    public function test_blocks_ip_outside_cidr_range(): void
    {
        config(['balanced-queue.prometheus.ip_whitelist' => ['10.0.0.0/8']]);

        $request = Request::create('/test');
        $request->server->set('REMOTE_ADDR', '11.0.0.1');

        $middleware = new IpWhitelist();
        $response = $middleware->handle($request, fn () => response('OK'));

        $this->assertEquals(403, $response->getStatusCode());
    }

    public function test_allows_localhost(): void
    {
        config(['balanced-queue.prometheus.ip_whitelist' => ['127.0.0.1']]);

        $request = Request::create('/test');
        $request->server->set('REMOTE_ADDR', '127.0.0.1');

        $middleware = new IpWhitelist();
        $response = $middleware->handle($request, fn () => response('OK'));

        $this->assertEquals(200, $response->getStatusCode());
    }

    public function test_allows_private_network_cidr(): void
    {
        config(['balanced-queue.prometheus.ip_whitelist' => ['192.168.0.0/16']]);

        $request = Request::create('/test');
        $request->server->set('REMOTE_ADDR', '192.168.50.100');

        $middleware = new IpWhitelist();
        $response = $middleware->handle($request, fn () => response('OK'));

        $this->assertEquals(200, $response->getStatusCode());
    }

    public function test_blocks_when_whitelist_empty(): void
    {
        config(['balanced-queue.prometheus.ip_whitelist' => []]);

        $request = Request::create('/test');
        $request->server->set('REMOTE_ADDR', '127.0.0.1');

        $middleware = new IpWhitelist();
        $response = $middleware->handle($request, fn () => response('OK'));

        $this->assertEquals(403, $response->getStatusCode());
    }
}
