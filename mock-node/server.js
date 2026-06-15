const http = require("http");

const NODE_ID = process.env.NODE_ID || "unknown";
const PORT = parseInt(process.env.PORT || "3000");

console.log("PORT env:", process.env.PORT);
console.log("Parsed PORT:", PORT);

let activeConnections = 0;
let cpuUsage = Math.random() * 30;
let totalRequests = 0;
let errors = 0;

setInterval(() => {
    cpuUsage = Math.max(5, Math.min(85, cpuUsage + (Math.random() * 10 - 5)));
}, 3000);

const server = http.createServer((req, res) => {
    const url = req.url;

    if (req.method === "POST" && url === "/dispatch") {
        activeConnections++;
        totalRequests++;

        const latency = 10 + Math.random() * 80;
        setTimeout(() => {
            activeConnections = Math.max(0, activeConnections - 1);

            if (Math.random() < 0.05) {
                errors++;
                res.writeHead(500);
                res.end(
                    JSON.stringify({
                        node: NODE_ID,
                        error: "simulated failure",
                    }),
                );
            } else {
                res.writeHead(200);
                res.end(
                    JSON.stringify({
                        node: NODE_ID,
                        status: "dispatched",
                        latency_ms: latency,
                    }),
                );
            }
        }, latency);
        return;
    }

    if (req.method === "GET" && url === "/stats") {
        res.writeHead(200, { "Content-Type": "application/json" });
        res.end(
            JSON.stringify({
                node_id: NODE_ID,
                active_connections: activeConnections,
                cpu_usage: parseFloat(cpuUsage.toFixed(1)),
                total_requests: totalRequests,
                error_count: errors,
                success_rate:
                    totalRequests > 0
                        ? parseFloat(
                              (
                                  (totalRequests - errors) /
                                  totalRequests
                              ).toFixed(4),
                          )
                        : 1.0,
                online: true,
            }),
        );
        return;
    }

    if (req.method === "POST" && url === "/chaos/peak-load") {
        cpuUsage = 95;
        activeConnections = 155;
        res.writeHead(200);
        res.end(
            JSON.stringify({ node: NODE_ID, chaos: "peak-load activated" }),
        );
        return;
    }

    if (req.method === "POST" && url === "/chaos/reset") {
        cpuUsage = 20;
        activeConnections = 0;
        errors = 0;
        res.writeHead(200);
        res.end(JSON.stringify({ node: NODE_ID, chaos: "reset" }));
        return;
    }

    res.writeHead(404);
    res.end("not found");
});

server.listen(PORT, () => {
    console.log(`[${NODE_ID}] listening on :${PORT}`);
});
