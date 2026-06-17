<?php

declare(strict_types=1);

namespace App\Services\ProxyDispatcher;


final class ServerNode
{
    public function __construct(
        public readonly string $id,
        public readonly string $url,
        public readonly int $weight = 1,
        public readonly bool $online = true,
        public readonly int $activeConnections = 0,
        public readonly float $avgLatencyMs = 0.0,
        public readonly float $cpuUsage = 0.0,
        public readonly float $successRate = 1.0,
        public readonly float $meshWeight = 1.0,
        public readonly int $errorCount = 0,
        public readonly int $successCount = 0,
    ) {}

    public function healthScore(): float
    {
        $cpuNorm     = $this->cpuUsage / 100.0;
        $latencyNorm = min($this->avgLatencyMs, 500.0) / 500.0;

        return $this->successRate * (1.0 - $cpuNorm) * (1.0 - $latencyNorm);
    }

    public function connectionRatio(): float
    {
        if ($this->weight === 0) {
            return PHP_FLOAT_MAX;
        }

        return $this->activeConnections / $this->weight;
    }

    public function isHealthy(): bool
    {
        return $this->online && $this->cpuUsage < 90.0;
    }

    public function withTelemetry(
        int $activeConnections,
        float $avgLatencyMs,
        float $cpuUsage,
        float $successRate,
        int $errorCount = 0,
        int $successCount = 0,
    ): self {
        return new self(
            id: $this->id,
            url: $this->url,
            weight: $this->weight,
            online: $this->online,
            activeConnections: $activeConnections,
            avgLatencyMs: $avgLatencyMs,
            cpuUsage: $cpuUsage,
            successRate: $successRate,
            meshWeight: $this->meshWeight,
            errorCount: $errorCount,
            successCount: $successCount,
        );
    }

    public function toArray(): array
    {
        return [
            'id'                 => $this->id,
            'weight'             => $this->weight,
            'online'             => $this->online,
            'active_connections' => $this->activeConnections,
            'avg_latency_ms'     => round($this->avgLatencyMs, 2),
            'cpu_usage'          => round($this->cpuUsage, 1),
            'success_rate'       => round($this->successRate, 4),
            'error_count'        => $this->errorCount,
            'success_count'      => $this->successCount,
            'health_score'       => round($this->healthScore(), 4),
            'mesh_weight'        => $this->meshWeight,
        ];
    }
}
