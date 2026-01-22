<?php

declare(strict_types=1);

namespace YanGusik\BalancedQueue\Http\Controllers;

use Illuminate\Http\Response;
use YanGusik\BalancedQueue\Support\PrometheusExporter;

class PrometheusController
{
    /**
     * Export metrics in Prometheus format.
     */
    public function metrics(PrometheusExporter $exporter): Response
    {
        $content = $exporter->export();

        return response($content, 200, [
            'Content-Type' => 'text/plain; version=0.0.4; charset=utf-8',
        ]);
    }
}
