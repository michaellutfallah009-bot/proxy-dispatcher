<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Proxy Dispatcher — Load Balancing Dashboard</title>

    <script src="https://cdn.tailwindcss.com"></script>

    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        dispatch: {
                            bg:      '#0a0e1a',
                            panel:   '#0f1629',
                            border:  '#1e2d4a',
                            accent:  '#3b82f6',
                            green:   '#10b981',
                            amber:   '#f59e0b',
                            red:     '#ef4444',
                            muted:   '#64748b',
                        }
                    },
                    fontFamily: {
                        mono: ['JetBrains Mono', 'Fira Code', 'monospace'],
                        sans: ['Inter', 'system-ui', 'sans-serif'],
                    }
                }
            }
        }
    </script>

    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap');

        body {
            background-color: #0a0e1a;
            color: #e2e8f0;
            font-family: 'Inter', sans-serif;
        }

        body::before {
            content: '';
            position: fixed;
            inset: 0;
            background: repeating-linear-gradient(
                0deg,
                transparent,
                transparent 2px,
                rgba(0,0,0,0.03) 2px,
                rgba(0,0,0,0.03) 4px
            );
            pointer-events: none;
            z-index: 9999;
        }

        .node-card {
            background: #0f1629;
            border: 1px solid #1e2d4a;
            transition: border-color 0.3s ease, box-shadow 0.3s ease;
        }

        .node-card.healthy  { border-color: #10b981; box-shadow: 0 0 12px rgba(16,185,129,0.12); }
        .node-card.degraded { border-color: #f59e0b; box-shadow: 0 0 12px rgba(245,158,11,0.12); }
        .node-card.offline  { border-color: #ef4444; box-shadow: 0 0 12px rgba(239,68,68,0.15);  }

        .stat-value {
            font-family: 'JetBrains Mono', monospace;
            font-size: 1.5rem;
            font-weight: 500;
            line-height: 1;
        }

        .pulse-dot {
            width: 8px; height: 8px;
            border-radius: 50%;
            display: inline-block;
        }
        .pulse-dot.online  { background: #10b981; animation: pulse-green 2s infinite; }
        .pulse-dot.offline { background: #ef4444; }

        @keyframes pulse-green {
            0%, 100% { opacity: 1; }
            50%       { opacity: 0.4; }
        }

        #alert-banner {
            display: none;
            background: linear-gradient(90deg, #7f1d1d, #991b1b);
            border-bottom: 2px solid #ef4444;
            animation: banner-flash 1s ease-in-out 3;
        }
        #alert-banner.visible { display: block; }

        @keyframes banner-flash {
            0%, 100% { opacity: 1; }
            50%       { opacity: 0.7; }
        }

        .btn {
            padding: 0.5rem 1.25rem;
            border-radius: 0.375rem;
            font-size: 0.875rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.15s ease;
            border: 1px solid transparent;
        }
        .btn:disabled { opacity: 0.4; cursor: not-allowed; }
        .btn-blue  { background: #1d4ed8; color: #fff; border-color: #2563eb; }
        .btn-blue:hover:not(:disabled)  { background: #2563eb; }
        .btn-red   { background: #7f1d1d; color: #fca5a5; border-color: #ef4444; }
        .btn-red:hover:not(:disabled)   { background: #991b1b; }
        .btn-amber { background: #78350f; color: #fcd34d; border-color: #f59e0b; }
        .btn-amber:hover:not(:disabled) { background: #92400e; }
        .btn-ghost { background: transparent; color: #94a3b8; border-color: #1e2d4a; }
        .btn-ghost:hover:not(:disabled) { background: #1e2d4a; color: #e2e8f0; }

        select.strategy-select {
            background: #0f1629;
            border: 1px solid #1e2d4a;
            color: #e2e8f0;
            padding: 0.5rem 2rem 0.5rem 0.75rem;
            border-radius: 0.375rem;
            font-size: 0.875rem;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='%2364748b' viewBox='0 0 20 20'%3E%3Cpath d='M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 0.5rem center;
            background-size: 1.25rem;
        }

        .chart-container { position: relative; height: 80px; }

        #dispatch-log {
            height: 180px;
            overflow-y: auto;
            font-family: 'JetBrains Mono', monospace;
            font-size: 0.75rem;
            scrollbar-width: thin;
            scrollbar-color: #1e2d4a transparent;
        }

        .log-entry { padding: 2px 0; border-bottom: 1px solid rgba(30,45,74,0.4); }
        .log-entry.success { color: #10b981; }
        .log-entry.error   { color: #ef4444; }
        .log-entry.info    { color: #64748b; }

        #burst-progress { transition: width 0.3s ease; }
    </style>
</head>

<body class="min-h-screen">

<div id="alert-banner" role="alert" class="px-6 py-3">
    <div class="max-w-7xl mx-auto flex items-center justify-between">
        <div class="flex items-center gap-3">
            <svg class="w-5 h-5 text-red-300 shrink-0" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495zM10 5a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 0110 5zm0 9a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd"/>
            </svg>
            <span class="font-semibold text-red-100 text-sm" id="alert-message">
                NODE OFFLINE — Traffic redistributed to healthy nodes
            </span>
        </div>
        <button onclick="dismissAlert()" class="text-red-300 hover:text-white text-lg leading-none">&times;</button>
    </div>
</div>


<header class="border-b border-dispatch-border px-6 py-4">
    <div class="max-w-7xl mx-auto flex items-center justify-between">
        <div class="flex items-center gap-4">
            <div class="w-8 h-8 rounded bg-blue-600 flex items-center justify-center">
                <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l3 3-3 3m5 0h3M5 20h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                </svg>
            </div>
            <div>
                <h1 class="text-base font-semibold text-white tracking-tight">Proxy Dispatcher</h1>
                <p class="text-xs text-dispatch-muted font-mono">Load Balancing Control Plane</p>
            </div>
        </div>

        <div class="flex items-center gap-6">
            <span class="text-xs font-mono text-dispatch-muted" id="live-clock"></span>

            <div class="text-right">
                <div class="text-xs text-dispatch-muted">Total Dispatched</div>
                <div class="text-sm font-mono text-white" id="total-dispatched">0</div>
            </div>

            <div class="flex items-center gap-2">
                <span class="pulse-dot online" id="system-status-dot"></span>
                <span class="text-xs text-dispatch-muted" id="system-status-text">All Systems Operational</span>
            </div>
        </div>
    </div>
</header>

<main class="max-w-7xl mx-auto px-6 py-6 space-y-6">


    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">

        <div class="node-card rounded-lg p-5">
            <h2 class="text-xs font-semibold text-dispatch-muted uppercase tracking-widest mb-4">Routing Strategy</h2>

            <select class="strategy-select w-full mb-3" id="strategy-select" onchange="changeStrategy(this.value)">
                @foreach($strategies as $strategy)
                    <option value="{{ $strategy['key'] }}"
                        {{ $strategy['key'] === $activeStrategy ? 'selected' : '' }}>
                        {{ $strategy['name'] }}
                    </option>
                @endforeach
            </select>

            <p class="text-xs text-dispatch-muted leading-relaxed" id="strategy-description">
                {{ $strategies[$activeStrategy]['description'] ?? '' }}
            </p>

            <div class="mt-4 pt-4 border-t border-dispatch-border flex items-center gap-2">
                <div class="w-2 h-2 rounded-full bg-blue-500"></div>
                <span class="text-xs font-mono text-blue-400" id="active-strategy-label">
                    Active: {{ $strategies[$activeStrategy]['name'] ?? 'Round Robin' }}
                </span>
            </div>
        </div>

        <div class="node-card rounded-lg p-5">
            <h2 class="text-xs font-semibold text-dispatch-muted uppercase tracking-widest mb-4">Traffic Burst Simulator</h2>

            <div class="flex gap-2 mb-4">
                <button class="btn btn-blue flex-1" onclick="simulateBurst(100)" id="burst-100">
                    100 Payloads
                </button>
                <button class="btn btn-blue flex-1" onclick="simulateBurst(500)" id="burst-500">
                    500 Payloads
                </button>
                <button class="btn btn-red flex-1" onclick="simulateBurst(1000)" id="burst-1000">
                    1000 Payloads
                </button>
            </div>

            <div class="h-1.5 bg-dispatch-border rounded-full overflow-hidden mb-2" id="burst-bar-container" style="display:none">
                <div class="h-full bg-blue-500 rounded-full" id="burst-progress" style="width:0%"></div>
            </div>

            <div class="flex items-center justify-between">
                <span class="text-xs text-dispatch-muted" id="burst-status">Ready to simulate</span>
                <span class="text-xs font-mono text-white" id="burst-counter"></span>
            </div>
        </div>
    </div>

    <div>
        <h2 class="text-xs font-semibold text-dispatch-muted uppercase tracking-widest mb-3">Replication Nodes</h2>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-4" id="nodes-grid">
            @foreach($nodes as $node)
            <div class="node-card rounded-lg p-5 {{ $node['online'] ? 'healthy' : 'offline' }}"
                 id="node-card-{{ $node['id'] }}">

                <div class="flex items-center justify-between mb-4">
                    <div class="flex items-center gap-2">
                        <span class="pulse-dot {{ $node['online'] ? 'online' : 'offline' }}"
                              id="dot-{{ $node['id'] }}"></span>
                        <span class="text-sm font-semibold text-white font-mono">
                            {{ strtoupper(str_replace('_', ' ', $node['id'])) }}
                        </span>
                    </div>

                    <div class="flex items-center gap-2">
                        <span class="text-xs px-2 py-0.5 rounded font-mono
                              {{ $node['online'] ? 'bg-green-900 text-green-300' : 'bg-red-900 text-red-300' }}"
                              id="status-badge-{{ $node['id'] }}">
                            {{ $node['online'] ? 'ONLINE' : 'OFFLINE' }}
                        </span>
                        <button onclick="toggleNode('{{ $node['id'] }}')"
                                id="toggle-{{ $node['id'] }}"
                                class="btn btn-ghost text-xs py-0.5 px-2">
                            {{ $node['online'] ? 'Take Offline' : 'Bring Online' }}
                        </button>
                    </div>
                </div>

                <div class="text-xs text-dispatch-muted mb-4 font-mono">
                    Weight: <span class="text-blue-400">{{ $node['weight'] }}</span>
                </div>

                <div class="grid grid-cols-3 gap-3 mb-4">
                    <div>
                        <div class="text-xs text-dispatch-muted mb-1">Active Connections</div>
                        <div class="stat-value text-white" id="conn-{{ $node['id'] }}">
                            {{ $node['active_connections'] }}
                        </div>
                    </div>
                    <div>
                        <div class="text-xs text-dispatch-muted mb-1">Avg Latency</div>
                        <div class="stat-value text-blue-400" id="latency-{{ $node['id'] }}">
                            {{ number_format($node['avg_latency_ms'], 1) }}<span class="text-xs text-dispatch-muted">ms</span>
                        </div>
                    </div>
                    <div>
                        <div class="text-xs text-dispatch-muted mb-1">CPU Usage</div>
                        <div class="stat-value" id="cpu-{{ $node['id'] }}"
                             style="color: {{ $node['cpu_usage'] > 80 ? '#ef4444' : ($node['cpu_usage'] > 50 ? '#f59e0b' : '#10b981') }}">
                            {{ number_format($node['cpu_usage'], 1) }}<span class="text-xs text-dispatch-muted">%</span>
                        </div>
                    </div>
                    <div>
                        <div class="text-xs text-dispatch-muted mb-1">Success Rate</div>
                        <div class="stat-value text-green-400" id="success-{{ $node['id'] }}">
                            {{ number_format($node['success_rate'] * 100, 1) }}<span class="text-xs text-dispatch-muted">%</span>
                        </div>
                    </div>
                    <div>
                        <div class="text-xs text-dispatch-muted mb-1">Errors</div>
                            <div class="stat-value" id="errors-{{ $node['id'] }}"
                             style="color: {{ $node['error_count'] > 0 ? '#ef4444' : '#64748b' }}">
                            {{ $node['error_count'] }}
                            </div>
                    </div>
                </div>

                <div class="mb-3">
                    <div class="flex justify-between text-xs text-dispatch-muted mb-1">
                        <span>CPU</span>
                        <span id="cpu-bar-label-{{ $node['id'] }}">{{ number_format($node['cpu_usage'], 1) }}%</span>
                    </div>
                    <div class="h-1 bg-dispatch-border rounded-full overflow-hidden">
                        <div class="h-full rounded-full transition-all duration-500"
                             id="cpu-bar-{{ $node['id'] }}"
                             style="width: {{ $node['cpu_usage'] }}%;
                                    background: {{ $node['cpu_usage'] > 80 ? '#ef4444' : ($node['cpu_usage'] > 50 ? '#f59e0b' : '#10b981') }}">
                        </div>
                    </div>
                </div>

                <div class="chart-container">
                    <canvas id="chart-{{ $node['id'] }}"></canvas>
                </div>
            </div>
            @endforeach
        </div>
    </div>


    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">

        <div class="node-card rounded-lg p-5">
            <div class="flex items-center gap-2 mb-4">
                <svg class="w-4 h-4 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M13 10V3L4 14h7v7l9-11h-7z"/>
                </svg>
                <h2 class="text-xs font-semibold text-dispatch-muted uppercase tracking-widest">Chaos Engineering</h2>
            </div>

            <div class="space-y-3">
                <div class="bg-black bg-opacity-30 rounded p-3 border border-dispatch-border">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <div class="text-sm font-medium text-white mb-1">Peak Load Chaos</div>
                            <div class="text-xs text-dispatch-muted leading-relaxed">
                                Forces Server C to 95% CPU and 155 active connections.
                                Validates that Least Connections and Performance-Based
                                algorithms immediately isolate it.
                            </div>
                        </div>
                        <button onclick="triggerPeakLoad()" class="btn btn-red shrink-0">
                            Trigger
                        </button>
                    </div>
                </div>

                <div class="bg-black bg-opacity-30 rounded p-3 border border-dispatch-border">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <div class="text-sm font-medium text-white mb-1">Reset All Nodes</div>
                            <div class="text-xs text-dispatch-muted leading-relaxed">
                                Clears all chaos states. Brings offline nodes back online
                                and resets CPU and connection counters to baseline.
                            </div>
                        </div>
                        <button onclick="resetChaos()" class="btn btn-ghost shrink-0">
                            Reset
                        </button>
                    </div>
                </div>

                <div class="bg-black bg-opacity-30 rounded p-3 border border-dispatch-border">
                    <div class="text-sm font-medium text-white mb-2">Manual Node Control</div>
                    <div class="text-xs text-dispatch-muted mb-3">
                        Toggle individual nodes offline to test failover and payload redistribution.
                    </div>
                    <div class="flex gap-2">
                        @foreach($nodes as $node)
                        <button onclick="toggleNode('{{ $node['id'] }}')"
                                class="btn btn-ghost text-xs flex-1"
                                id="chaos-toggle-{{ $node['id'] }}">
                            {{ strtoupper(substr($node['id'], -1)) }} {{ $node['online'] ? '▲' : '▼' }}
                        </button>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>

        <div class="node-card rounded-lg p-5">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-xs font-semibold text-dispatch-muted uppercase tracking-widest">Dispatch Log</h2>
                <button onclick="clearLog()" class="btn btn-ghost text-xs py-0.5 px-2">Clear</button>
            </div>
            <div id="dispatch-log" class="space-y-0.5">
                <div class="log-entry info">— Dispatcher ready. Select a strategy and simulate traffic.</div>
            </div>
        </div>
    </div>


    <div class="node-card rounded-lg p-5">
        <h2 class="text-xs font-semibold text-dispatch-muted uppercase tracking-widest mb-4">Algorithm Comparison</h2>
        <div class="overflow-x-auto">
            <table class="w-full text-xs">
                <thead>
                    <tr class="border-b border-dispatch-border">
                        <th class="text-left py-2 pr-4 text-dispatch-muted font-medium">Strategy</th>
                        <th class="text-left py-2 pr-4 text-dispatch-muted font-medium">Decision Cost</th>
                        <th class="text-left py-2 pr-4 text-dispatch-muted font-medium">Load Aware</th>
                        <th class="text-left py-2 pr-4 text-dispatch-muted font-medium">Redis State</th>
                        <th class="text-left py-2 text-dispatch-muted font-medium">Best For</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-dispatch-border">
                    @php
                    $table = [
                        ['Round Robin',               'O(1)',    'No',  'Counter',      'Uniform, equal-cost requests'],
                        ['Weighted Round Robin',       'O(N)',    'No',  'Counter',      'Heterogeneous server capacity'],
                        ['Smooth Weighted RR',         'O(N)',    'No',  'Weight hash',  'WRR without traffic bursts'],
                        ['Consistent Hashing',         'O(log N)','No',  'Sorted set',   'Cache affinity, scaling events'],
                        ['Adaptive Feedback',          'O(N)',    'Yes', 'None',         'Self-healing, error recovery'],
                        ['Latency-Based',              'O(N)',    'Yes', 'None',         'Latency-sensitive workloads'],
                        ['Performance-Based',          'O(N)',    'Yes', 'None',         'CPU + latency combined'],
                        ['Server Mesh',                'O(N)',    'Yes', 'None',         'Istio/Envoy-style environments'],
                        ['Idle-Join Queue',            'O(1)',    'Yes', 'List queue',   'Zero queue buildup guarantee'],
                        ['Least Connections',          'O(N)',    'Yes', 'None',         'Uneven request durations'],
                        ['Weighted Least Connections', 'O(N)',    'Yes', 'None',         'Mixed capacity + duration'],
                    ];
                    @endphp
                    @foreach($table as $row)
                    <tr class="hover:bg-white hover:bg-opacity-5 transition-colors">
                        <td class="py-2 pr-4 font-mono text-blue-300">{{ $row[0] }}</td>
                        <td class="py-2 pr-4 font-mono text-white">{{ $row[1] }}</td>
                        <td class="py-2 pr-4">
                            <span class="{{ $row[2] === 'Yes' ? 'text-green-400' : 'text-dispatch-muted' }}">
                                {{ $row[2] }}
                            </span>
                        </td>
                        <td class="py-2 pr-4 text-amber-300 font-mono">{{ $row[3] }}</td>
                        <td class="py-2 text-dispatch-muted">{{ $row[4] }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

</main>


<script>
const POLL_INTERVAL    = 2000;
const MAX_CHART_POINTS = 20;
const CSRF             = document.querySelector('meta[name="csrf-token"]').content;

let charts           = {};
let chartData        = {};
let totalDispatched  = 0;
let offlineNodes     = new Set();
let burstRunning     = false;
let activeStrategy   = '{{ $activeStrategy }}';

const strategyDescriptions = @json(array_column($strategies, 'description', 'key'));
const strategyNames        = @json(array_column($strategies, 'name', 'key'));

function updateClock() {
    document.getElementById('live-clock').textContent =
        new Date().toLocaleTimeString('en-GB', { hour12: false });
}
setInterval(updateClock, 1000);
updateClock();

function initCharts(nodeIds) {
    nodeIds.forEach(id => {
        chartData[id] = new Array(MAX_CHART_POINTS).fill(0);
        const ctx = document.getElementById(`chart-${id}`);
        if (!ctx) return;

        charts[id] = new Chart(ctx, {
            type: 'line',
            data: {
                labels: new Array(MAX_CHART_POINTS).fill(''),
                datasets: [{
                    data:            chartData[id],
                    borderColor:     '#3b82f6',
                    backgroundColor: 'rgba(59,130,246,0.08)',
                    borderWidth:     1.5,
                    pointRadius:     0,
                    fill:            true,
                    tension:         0.4,
                }]
            },
            options: {
                responsive:          true,
                maintainAspectRatio: false,
                animation:           { duration: 400 },
                plugins:             { legend: { display: false }, tooltip: { enabled: false } },
                scales: {
                    x: { display: false },
                    y: { display: false, min: 0 },
                }
            }
        });
    });
}

function updateChart(id, value) {
    if (!charts[id]) return;
    chartData[id].push(value);
    chartData[id].shift();
    charts[id].data.datasets[0].data = [...chartData[id]];
    charts[id].update('none');
}

async function pollTelemetry() {
    try {
        const res  = await fetch('/api/telemetry');
        const data = await res.json();

        data.nodes.forEach(node => updateNodeCard(node));

    } catch (err) {
        log('Telemetry fetch failed: ' + err.message, 'error');
    }
}

function updateNodeCard(node) {
    const id = node.id;

    const card   = document.getElementById(`node-card-${id}`);
    const dot    = document.getElementById(`dot-${id}`);
    const badge  = document.getElementById(`status-badge-${id}`);
    const toggle = document.getElementById(`toggle-${id}`);
    const chaos  = document.getElementById(`chaos-toggle-${id}`);

    if (!card) return;

    const isOnline = node.online && !offlineNodes.has(id);

    card.className  = `node-card rounded-lg p-5 ${isOnline ? (node.cpu_usage > 80 ? 'degraded' : 'healthy') : 'offline'}`;
    dot.className   = `pulse-dot ${isOnline ? 'online' : 'offline'}`;
    badge.textContent = isOnline ? 'ONLINE' : 'OFFLINE';
    badge.className = `text-xs px-2 py-0.5 rounded font-mono ${isOnline ? 'bg-green-900 text-green-300' : 'bg-red-900 text-red-300'}`;

    if (toggle) toggle.textContent = isOnline ? 'Take Offline' : 'Bring Online';
    if (chaos)  chaos.textContent  = strtoupper(id.slice(-1)) + (isOnline ? ' ▲' : ' ▼');

    setText(`conn-${id}`,    node.active_connections);
    setText(`latency-${id}`, node.avg_latency_ms.toFixed(1) + '<span class="text-xs text-dispatch-muted">ms</span>');
    setText(`success-${id}`, (node.success_rate * 100).toFixed(1) + '<span class="text-xs text-dispatch-muted">%</span>');
    const errorsEl = document.getElementById(`errors-${id}`);
    if (errorsEl) {
        errorsEl.textContent = node.error_count;
        errorsEl.style.color = node.error_count > 0 ? '#ef4444' : '#64748b';
    }
    const cpuColor = node.cpu_usage > 80 ? '#ef4444' : (node.cpu_usage > 50 ? '#f59e0b' : '#10b981');
    const cpuEl    = document.getElementById(`cpu-${id}`);
    if (cpuEl) {
        cpuEl.innerHTML = node.cpu_usage.toFixed(1) + '<span class="text-xs text-dispatch-muted">%</span>';
        cpuEl.style.color = cpuColor;
    }

    const cpuBar = document.getElementById(`cpu-bar-${id}`);
    if (cpuBar) {
        cpuBar.style.width      = Math.min(node.cpu_usage, 100) + '%';
        cpuBar.style.background = cpuColor;
    }

    const cpuLabel = document.getElementById(`cpu-bar-label-${id}`);
    if (cpuLabel) cpuLabel.textContent = node.cpu_usage.toFixed(1) + '%';

    updateChart(id, node.avg_latency_ms);

    const anyOffline  = data => data.some(n => !n.online || offlineNodes.has(n.id));
    const anyDegraded = data => data.some(n => n.cpu_usage > 80);
    checkSystemStatus();
}

function setText(id, html) {
    const el = document.getElementById(id);
    if (el) el.innerHTML = html;
}

function checkSystemStatus() {
    const offlineCount = offlineNodes.size;
    const dot  = document.getElementById('system-status-dot');
    const text = document.getElementById('system-status-text');

    if (offlineCount > 0) {
        dot.className  = 'pulse-dot offline';
        text.textContent = `${offlineCount} Node${offlineCount > 1 ? 's' : ''} Offline`;
    } else {
        dot.className  = 'pulse-dot online';
        text.textContent = 'All Systems Operational';
    }
}

async function changeStrategy(key) {
    try {
        await fetch('/api/strategy', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF },
            body:    JSON.stringify({ strategy: key }),
        });

        activeStrategy = key;
        document.getElementById('active-strategy-label').textContent = 'Active: ' + strategyNames[key];
        document.getElementById('strategy-description').textContent  = strategyDescriptions[key] || '';
        log(`Strategy changed → ${strategyNames[key]}`, 'info');

    } catch (err) {
        log('Strategy change failed: ' + err.message, 'error');
    }
}

async function simulateBurst(count) {
    if (burstRunning) return;
    burstRunning = true;

    setBurstButtonsDisabled(true);
    document.getElementById('burst-bar-container').style.display = 'block';
    document.getElementById('burst-status').textContent = `Dispatching ${count} payloads…`;

    log(`▶ Burst started: ${count} payloads via ${strategyNames[activeStrategy]}`, 'info');

    let completed = 0;
    let errors    = 0;

    const batchSize = Math.min(10, count);
    const batches   = Math.ceil(count / batchSize);

    for (let b = 0; b < batches; b++) {
        const batch = Math.min(batchSize, count - b * batchSize);
        const promises = [];

        for (let i = 0; i < batch; i++) {
            promises.push(
                fetch('/api/dispatch', {
                    method:  'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF },
                    body:    JSON.stringify({
                        strategy:    activeStrategy,
                        payload_key: crypto.randomUUID(),
                    }),
                })
                .then(r => {
                    if (!r.ok) {
                        throw new Error(`HTTP Error ${r.status}`);
                    }
                    return r.json();
                })
                .then(data => {
                    completed++;
                    totalDispatched++;
                    document.getElementById('total-dispatched').textContent = totalDispatched;
                    const pct = Math.round((completed / count) * 100);
                    document.getElementById('burst-progress').style.width = pct + '%';
                    document.getElementById('burst-counter').textContent = `${completed}/${count}`;

                    if (data.node) {
                        log(`✓ → ${data.node} (${data.latency_ms?.toFixed(0) ?? '?'}ms)`, 'success');
                    }
                })
                .catch(() => {
                    errors++;
                    log(`✗ Dispatch failed`, 'error');
                })
            );
        }

        await Promise.all(promises);
        await sleep(50);
    }

    log(`▶ Burst complete: ${completed} dispatched, ${errors} errors`, errors > 0 ? 'error' : 'success');
    document.getElementById('burst-status').textContent = `Done — ${completed} dispatched, ${errors} errors`;
    document.getElementById('burst-counter').textContent = '';

    setTimeout(() => {
        document.getElementById('burst-bar-container').style.display = 'none';
        document.getElementById('burst-progress').style.width = '0%';
        document.getElementById('burst-status').textContent = 'Ready to simulate';
    }, 3000);

    burstRunning = false;
    setBurstButtonsDisabled(false);
}

function setBurstButtonsDisabled(disabled) {
    ['burst-100','burst-500','burst-1000'].forEach(id => {
        document.getElementById(id).disabled = disabled;
    });
}

async function toggleNode(nodeId) {
    const goingOffline = !offlineNodes.has(nodeId);

    try {
        await fetch('/api/nodes/toggle', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF },
            body:    JSON.stringify({ node_id: nodeId, online: !goingOffline }),
        });

        if (goingOffline) {
            offlineNodes.add(nodeId);
            showAlert(`⚠ ${nodeId.replace(/_/g,' ').toUpperCase()} taken offline — payloads redistributed to healthy nodes.`);
            log(`⚠ Node ${nodeId} taken offline`, 'error');
        } else {
            offlineNodes.delete(nodeId);
            log(`✓ Node ${nodeId} brought back online`, 'success');
            if (offlineNodes.size === 0) dismissAlert();
        }

        checkSystemStatus();

    } catch (err) {
        log('Toggle failed: ' + err.message, 'error');
    }
}

async function triggerPeakLoad() {
    try {
        await fetch('/api/chaos/peak-load', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF },
        });
        showAlert('🔥 PEAK LOAD TRIGGERED — Server C at 95% CPU / 155 connections. Watch Least Connections and Performance-Based isolate it.');
        log('💥 Chaos: Peak Load triggered on server_node_c', 'error');
    } catch (err) {
        log('Chaos trigger failed: ' + err.message, 'error');
    }
}

async function resetChaos() {
    try {
        await fetch('/api/chaos/reset', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF },
        });
        offlineNodes.clear();
        dismissAlert();
        checkSystemStatus();
        log('✓ All chaos states reset', 'success');
    } catch (err) {
        log('Reset failed: ' + err.message, 'error');
    }
}

function showAlert(msg) {
    document.getElementById('alert-message').textContent = msg;
    const banner = document.getElementById('alert-banner');
    banner.classList.add('visible');
}

function dismissAlert() {
    document.getElementById('alert-banner').classList.remove('visible');
}

function log(msg, type = 'info') {
    const container = document.getElementById('dispatch-log');
    const time      = new Date().toLocaleTimeString('en-GB', { hour12: false });
    const el        = document.createElement('div');
    el.className    = `log-entry ${type}`;
    el.textContent  = `[${time}] ${msg}`;
    container.prepend(el);

    while (container.children.length > 200) {
        container.removeChild(container.lastChild);
    }
}

function clearLog() {
    document.getElementById('dispatch-log').innerHTML =
        '<div class="log-entry info">— Log cleared.</div>';
}

function sleep(ms) { return new Promise(r => setTimeout(r, ms)); }
function strtoupper(s) { return s.toUpperCase(); }

document.addEventListener('DOMContentLoaded', () => {
    const nodeIds = @json(array_column($nodes, 'id'));
    initCharts(nodeIds);

    pollTelemetry();
    setInterval(pollTelemetry, POLL_INTERVAL);
});
</script>

</body>
</html>
