// Cliente HTTP do frontend. Em desenvolvimento, o Vite encaminha /api, /auth e /sanctum para o Laravel,
// então tudo roda no mesmo endereço e o cookie de sessão funciona sem CORS.

export class ApiError extends Error {
  constructor(status, body) {
    super(body?.message || `Erro ${status}`);
    this.status = status;
    this.body = body;
  }
}

function readCookie(name) {
  const match = document.cookie.match(new RegExp(`(?:^|; )${name}=([^;]*)`));
  return match ? decodeURIComponent(match[1]) : null;
}

async function request(method, path, body) {
  const headers = { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" };

  if (method !== "GET") {
    // CSRF do Sanctum: garante o cookie XSRF-TOKEN e devolve o valor no header.
    if (!readCookie("XSRF-TOKEN")) {
      await fetch("/sanctum/csrf-cookie", { credentials: "same-origin" });
    }
    headers["X-XSRF-TOKEN"] = readCookie("XSRF-TOKEN") ?? "";
  }

  if (body !== undefined) headers["Content-Type"] = "application/json";

  const response = await fetch(`/api${path}`, {
    method,
    headers,
    credentials: "same-origin",
    body: body === undefined ? undefined : JSON.stringify(body),
  });

  const data = await response.json().catch(() => null);
  if (!response.ok) throw new ApiError(response.status, data);
  return data;
}

export const apiGet = (path) => request("GET", path);
export const apiPost = (path, body) => request("POST", path, body);
export const apiPut = (path, body) => request("PUT", path, body);

// O login é uma navegação de página inteira: o Laravel redireciona para a Twitch.
export const TWITCH_LOGIN_URL = "/auth/twitch/redirect";
