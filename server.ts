import express from "express";
import path from "path";
import { spawn, ChildProcess } from "child_process";
import { createServer as createViteServer } from "vite";

const app = express();
const PORT = 3000;
const LARAVEL_PORT = 8000;

let phpServer: ChildProcess | null = null;

function startLaravelServer() {
  const backendPublicDir = path.join(process.cwd(), "backend", "public");
  console.log(`Starting Laravel PHP server on 127.0.0.1:${LARAVEL_PORT} from ${backendPublicDir}`);

  phpServer = spawn("php", ["-S", `127.0.0.1:${LARAVEL_PORT}`, "-t", backendPublicDir], {
    stdio: "inherit",
    cwd: path.join(process.cwd(), "backend"),
  });

  phpServer.on("error", (err) => {
    console.error("Failed to start PHP Laravel server:", err);
  });

  phpServer.on("exit", (code, signal) => {
    console.warn(`PHP Laravel server exited with code ${code}, signal ${signal}`);
  });

  process.on("exit", () => {
    if (phpServer) phpServer.kill();
  });
}

// Start PHP Laravel server
startLaravelServer();

// Buffer raw body for proxying
app.use(express.raw({ type: "*/*", limit: "50mb" }));

// Forward /api and /sanctum requests to Laravel backend
app.all(["/api", "/api/*", "/sanctum/*"], async (req, res) => {
  try {
    const targetUrl = `http://127.0.0.1:${LARAVEL_PORT}${req.originalUrl}`;
    
    const headers: Record<string, string> = {};
    for (const [key, val] of Object.entries(req.headers)) {
      if (key !== "host" && typeof val === "string") {
        headers[key] = val;
      }
    }

    const hasBody = !["GET", "HEAD"].includes(req.method) && req.body && Buffer.isBuffer(req.body) && req.body.length > 0;

    const response = await fetch(targetUrl, {
      method: req.method,
      headers,
      body: hasBody ? req.body : undefined,
    });

    response.headers.forEach((val, key) => {
      // Don't forward transfer-encoding to avoid conflicts
      if (key !== "transfer-encoding") {
        res.setHeader(key, val);
      }
    });

    res.status(response.status);
    const arrayBuffer = await response.arrayBuffer();
    res.send(Buffer.from(arrayBuffer));
  } catch (err: any) {
    console.error("Proxy error to Laravel:", err?.message);
    res.status(502).json({
      success: false,
      error: {
        code: "GATEWAY_ERROR",
        message: "Failed to communicate with Laravel backend engine",
        details: err?.message,
      },
    });
  }
});

async function startServer() {
  if (process.env.NODE_ENV !== "production") {
    const vite = await createViteServer({
      server: { middlewareMode: true },
      appType: "spa",
    });
    app.use(vite.middlewares);
  } else {
    const distPath = path.join(process.cwd(), "dist");
    app.use(express.static(distPath));
    app.get("*", (req, res) => {
      res.sendFile(path.join(distPath, "index.html"));
    });
  }

  app.listen(PORT, "::", () => {
    console.log(`HASSAL Platform Gateway running on http://localhost:${PORT}`);
  });
}

startServer();
