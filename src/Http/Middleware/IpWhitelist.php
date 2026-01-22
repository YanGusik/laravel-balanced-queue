<?php

declare(strict_types=1);

namespace YanGusik\BalancedQueue\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware to restrict access based on IP whitelist.
 * Supports both exact IP addresses and CIDR notation.
 */
class IpWhitelist
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $whitelist = config('balanced-queue.prometheus.ip_whitelist', []);
        $clientIp = $request->ip();

        if ($clientIp === null || ! $this->isAllowed($clientIp, $whitelist)) {
            return response('Forbidden', 403);
        }

        return $next($request);
    }

    /**
     * Check if the IP is allowed based on the whitelist.
     *
     * @param array<string> $whitelist
     */
    protected function isAllowed(string $ip, array $whitelist): bool
    {
        foreach ($whitelist as $allowed) {
            if ($this->ipMatches($ip, $allowed)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if an IP matches a pattern (exact or CIDR).
     */
    protected function ipMatches(string $ip, string $pattern): bool
    {
        // Exact match
        if ($ip === $pattern) {
            return true;
        }

        // CIDR notation
        if (str_contains($pattern, '/')) {
            return $this->ipInCidr($ip, $pattern);
        }

        return false;
    }

    /**
     * Check if an IP is within a CIDR range.
     */
    protected function ipInCidr(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr);
        $bits = (int) $bits;

        // Handle IPv4
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $ipLong = ip2long($ip);
            $subnetLong = ip2long($subnet);

            if ($ipLong === false || $subnetLong === false) {
                return false;
            }

            $mask = -1 << (32 - $bits);
            $subnetLong &= $mask;

            return ($ipLong & $mask) === $subnetLong;
        }

        // Handle IPv6
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $ipBin = inet_pton($ip);
            $subnetBin = inet_pton($subnet);

            if ($ipBin === false || $subnetBin === false) {
                return false;
            }

            $mask = str_repeat("\xff", (int) ($bits / 8));
            if ($bits % 8) {
                $mask .= chr(0xff << (8 - ($bits % 8)));
            }
            $mask = str_pad($mask, 16, "\x00");

            return ($ipBin & $mask) === ($subnetBin & $mask);
        }

        return false;
    }
}
