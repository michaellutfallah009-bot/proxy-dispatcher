<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\ProxyDispatcher\ServerNode;
use App\Services\ProxyDispatcher\StrategyFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Illuminate\View\View;

final class DispatcherController extends Controller
{
    private const KEY_STRATEGY  = 'dispatcher:active_strategy';
    private const KEY_NODE_PFX  = 'dispatcher:node:';
    private const KEY_TELEMETRY = 'dispatcher:telemetry:';

    private function nodeDefinitions(): array
    {
        return [
            ['id' => 'server_node_a', 'url' => env('NODE_A_URL', 'http://server_node_a:3000'), 'weight' => 3],
            ['id' => 'server_node_b', 'url' => env('NODE_B_URL', 'http://server_node_b:3000'), 'weight' => 2],
            ['id' => 'server_node_c', 'url' => env('NODE_C_URL', 'http://server_node_c:3000'), 'weight' => 1],
        ];
    }


    public function dashboard(): View
    {
        $this->resetAllState();

        $activeStrategy = Redis::get(self::KEY_STRATEGY) ?? 'round_robin';
        $nodes          = $this->hydrateNodes()->map(fn(ServerNode $n) => $n->toArray())->values()->toArray();
        $strategies     = StrategyFactory::catalog();

        return view('dashboard', compact('nodes', 'strategies', 'activeStrategy'));
    }


    public function telemetry(): JsonResponse
    {
        $this->refreshTelemetry();

        $nodes = $this->hydrateNodes()
            ->map(fn(ServerNode $n) => $n->toArray())
            ->values();

        return response()->json([
            'nodes'    => $nodes,
            'strategy' => Redis::get(self::KEY_STRATEGY) ?? 'round_robin',
        ]);
    }


    public function dispatch(Request $request): JsonResponse
    {
        $strategyKey = $request->input('strategy', Redis::get(self::KEY_STRATEGY) ?? 'round_robin');
        $payloadKey  = $request->input('payload_key', (string) str()->uuid());

        $strategy = StrategyFactory::make($strategyKey);
        $nodes    = $this->hydrateNodes();
        $node     = $strategy->select($nodes, $payloadKey);

        if ($node === null) {
            return response()->json(['error' => 'No healthy nodes available'], 503);
        }

        Redis::incr(self::KEY_NODE_PFX . $node->id . ':connections');

        $start = microtime(true);

        try {
            $response = Http::timeout(5)->post($node->url . '/dispatch', [
                'payload_key' => $payloadKey,
            ]);

            $latencyMs = (microtime(true) - $start) * 1000;

            $current = (float) (Redis::get(self::KEY_TELEMETRY . $node->id . ':latency') ?? 0);
            $ema     = $current > 0
                ? 0.2 * $latencyMs + 0.8 * $current
                : $latencyMs;
            Redis::set(self::KEY_TELEMETRY . $node->id . ':latency', $ema);

            Redis::decr(self::KEY_NODE_PFX . $node->id . ':connections');

            if ($response->successful()) {
                Redis::incr(self::KEY_TELEMETRY . $node->id . ':success');

                return response()->json([
                    'node'       => $node->id,
                    'latency_ms' => round($latencyMs, 2),
                    'status'     => 'dispatched',
                ]);
            }

            Redis::incr(self::KEY_TELEMETRY . $node->id . ':errors');

            return response()->json(['error' => 'Node returned error', 'node' => $node->id], 502);
        } catch (\Exception $e) {
            Redis::decr(self::KEY_NODE_PFX . $node->id . ':connections');
            Redis::incr(self::KEY_TELEMETRY . $node->id . ':errors');

            return response()->json(['error' => $e->getMessage()], 503);
        }
    }


    public function setStrategy(Request $request): JsonResponse
    {
        $key = $request->input('strategy', 'round_robin');

        if (!array_key_exists($key, StrategyFactory::STRATEGIES)) {
            return response()->json(['error' => 'Unknown strategy'], 422);
        }

        Redis::set(self::KEY_STRATEGY, $key);

        return response()->json(['strategy' => $key]);
    }


    public function toggleNode(Request $request): JsonResponse
    {
        $nodeId = $request->input('node_id');
        $online = (bool) $request->input('online', true);

        Redis::set(self::KEY_NODE_PFX . $nodeId . ':online', $online ? '1' : '0');

        return response()->json(['node_id' => $nodeId, 'online' => $online]);
    }


    public function chaosPeakLoad(): JsonResponse
    {
        $nodeId = 'server_node_c';

        Redis::set(self::KEY_NODE_PFX  . $nodeId . ':connections', 155);
        Redis::set(self::KEY_TELEMETRY . $nodeId . ':cpu',         95.0);

        try {
            $url = env('NODE_C_URL', 'http://server_node_c:3000');
            Http::timeout(2)->post($url . '/chaos/peak-load');
        } catch (\Exception) {
        }

        return response()->json(['chaos' => 'peak-load activated on ' . $nodeId]);
    }


    public function chaosReset(): JsonResponse
    {
        $this->resetAllState();

        return response()->json(['chaos' => 'reset']);
    }

    private function resetAllState(): void
    {
        foreach ($this->nodeDefinitions() as $def) {
            $id = $def['id'];

            Redis::set(self::KEY_NODE_PFX  . $id . ':online',      '1');
            Redis::set(self::KEY_NODE_PFX  . $id . ':connections',  0);
            Redis::set(self::KEY_TELEMETRY . $id . ':cpu',          0);
            Redis::del(self::KEY_TELEMETRY . $id . ':latency');
            Redis::set(self::KEY_TELEMETRY . $id . ':errors',       0);
            Redis::set(self::KEY_TELEMETRY . $id . ':success',      0);

            try {
                Http::timeout(2)->post($def['url'] . '/chaos/reset');
            } catch (\Exception) {
            }
        }
    }
    private function hydrateNodes(): Collection
    {
        return collect($this->nodeDefinitions())->map(function (array $def) {
            $id = $def['id'];

            $online      = Redis::get(self::KEY_NODE_PFX  . $id . ':online') !== '0';
            $connections = (int)   (Redis::get(self::KEY_NODE_PFX  . $id . ':connections') ?? 0);
            $latency     = (float) (Redis::get(self::KEY_TELEMETRY . $id . ':latency')     ?? 0);
            $cpu         = (float) (Redis::get(self::KEY_TELEMETRY . $id . ':cpu')         ?? 0);
            $success     = (int)   (Redis::get(self::KEY_TELEMETRY . $id . ':success')     ?? 0);
            $errors      = (int)   (Redis::get(self::KEY_TELEMETRY . $id . ':errors')      ?? 0);
            $total       = $success + $errors;
            $successRate = $total > 0 ? $success / $total : 1.0;

            return new ServerNode(
                id: $id,
                url: $def['url'],
                weight: $def['weight'],
                online: $online,
                activeConnections: max(0, $connections),
                avgLatencyMs: $latency,
                cpuUsage: $cpu,
                successRate: $successRate,
                errorCount: $errors,
                successCount: $success,
            );
        });
    }



    private function refreshTelemetry(): void
    {
        foreach ($this->nodeDefinitions() as $def) {
            $id = $def['id'];

            if (Redis::get(self::KEY_NODE_PFX . $id . ':online') === '0') {
                continue;
            }

            try {
                $stats = Http::timeout(1)->get($def['url'] . '/stats')->json();

                if (!empty($stats)) {
                    Redis::set(
                        self::KEY_TELEMETRY . $id . ':cpu',
                        (float) ($stats['cpu_usage'] ?? 0)
                    );
                    Redis::set(
                        self::KEY_NODE_PFX . $id . ':connections',
                        (int) ($stats['active_connections'] ?? 0)
                    );
                }
                $currentLatency = (float) (Redis::get(self::KEY_TELEMETRY . $id . ':latency') ?? 0);
                if ($currentLatency <= 0 && isset($stats['latency_ms'])) {
                    Redis::set(
                        self::KEY_TELEMETRY . $id . ':latency',
                        (float) $stats['latency_ms']
                    );
                }
            } catch (\Exception) {
            }
        }
    }
}
