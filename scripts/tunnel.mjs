// Abre o túnel HTTPS (Cloudflare) e, quando ele estiver no ar, registra o endereço novo na Twitch
// (php artisan twitch:subscribe --url=...). Roda junto com o resto pelo `npm run dev` na raiz.
import { spawn } from "node:child_process";
import { existsSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";

const root = join(dirname(fileURLToPath(import.meta.url)), "..");
const backend = join(root, "backend-laravel");
const LARAVEL = "http://127.0.0.1:8000";

function findCloudflared() {
  const candidates = [
    "C:\\Program Files (x86)\\cloudflared\\cloudflared.exe",
    "C:\\Program Files\\cloudflared\\cloudflared.exe",
  ];
  return candidates.find((p) => existsSync(p)) ?? "cloudflared";
}

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

// Espera até a URL responder (qualquer status HTTP serve: o servidor está de pé).
async function waitFor(url, label, tries = 60) {
  for (let i = 0; i < tries; i++) {
    try {
      await fetch(url, { signal: AbortSignal.timeout(4000) });
      return true;
    } catch {
      await sleep(2000);
    }
  }
  console.error(`[tunel] ${label} não respondeu a tempo.`);
  return false;
}

function subscribe(url) {
  return new Promise((resolve) => {
    const child = spawn("php", ["artisan", "twitch:subscribe", `--url=${url}`], { cwd: backend, stdio: "inherit" });
    child.on("exit", (code) => resolve(code === 0));
  });
}

let registered = false;

async function onUrl(url) {
  if (registered) return;
  registered = true;
  console.log("[tunel] túnel criado. Esperando ficar acessível…");

  const laravelUp = await waitFor(`${LARAVEL}/up`, "Laravel");
  const tunnelUp = await waitFor(`${url}/up`, "Túnel");

  if (!laravelUp || !tunnelUp) {
    console.error("[tunel] Não registrei o webhook. Rode manualmente: php artisan twitch:subscribe --url=<endereço do túnel>");
    return;
  }

  // A Twitch só ativa a inscrição depois de chamar o webhook; dá um respiro para o DNS do túnel propagar.
  await sleep(3000);
  const ok = await subscribe(url);
  console.log(ok
    ? "[tunel] Webhook registrado na Twitch. Tudo pronto."
    : "[tunel] Falha ao registrar o webhook. Veja a mensagem acima.");
}

const cloudflared = spawn(findCloudflared(), ["tunnel", "--no-autoupdate", "--url", LARAVEL], { cwd: root });

const watch = (stream) => {
  let buffer = "";
  stream.on("data", (chunk) => {
    buffer += chunk.toString();
    const match = buffer.match(/https:\/\/[a-z0-9-]+\.trycloudflare\.com/);
    if (match) onUrl(match[0]);
    // Mostra só as linhas de erro do cloudflared (o resto é muito verboso).
    for (const line of chunk.toString().split(/\r?\n/)) {
      if (/\bERR\b/.test(line)) console.error(`[cloudflared] ${line}`);
    }
    if (buffer.length > 20000) buffer = buffer.slice(-5000);
  });
};

watch(cloudflared.stdout);
watch(cloudflared.stderr);

cloudflared.on("error", (e) => {
  console.error(`[tunel] Não consegui abrir o cloudflared (${e.message}). Ele está instalado?`);
  process.exit(1);
});
cloudflared.on("exit", (code) => {
  console.error(`[tunel] cloudflared saiu (código ${code}).`);
  process.exit(code ?? 1);
});

const stop = () => cloudflared.kill();
process.on("SIGINT", stop);
process.on("SIGTERM", stop);
