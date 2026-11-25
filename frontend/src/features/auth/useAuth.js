import { useCallback, useEffect, useState } from "react";
import { apiGet, apiPost, ApiError } from "../../services/api";

const ERROR_MESSAGES = {
  denied: "Você cancelou a autorização na Twitch.",
  invalid_state: "A sessão de login expirou. Tente de novo.",
  not_configured: "O app da Twitch ainda não foi configurado.",
  twitch_error: "A Twitch recusou o login. Tente de novo.",
  twitch_unavailable: "Não foi possível falar com a Twitch agora. Tente em instantes.",
  twitch_reauth: "Sua autorização da Twitch expirou. Faça login de novo.",
};

// Lê ?auth_error= deixado pelo callback do Laravel e limpa a URL.
function takeAuthError() {
  const params = new URLSearchParams(window.location.search);
  const code = params.get("auth_error");
  if (!code) return null;
  params.delete("auth_error");
  const query = params.toString();
  window.history.replaceState(null, "", window.location.pathname + (query ? `?${query}` : ""));
  return ERROR_MESSAGES[code] ?? "Não foi possível concluir o login.";
}

// Lido uma vez ao carregar o módulo (o StrictMode renderiza duas vezes e a URL já estaria limpa).
const initialAuthError = takeAuthError();

export function useAuth() {
  const [state, setState] = useState({ status: "loading", me: null, error: initialAuthError });

  const load = useCallback(async () => {
    try {
      const me = await apiGet("/me");
      setState({ status: "authenticated", me, error: null });
    } catch (error) {
      if (error instanceof ApiError && error.status === 401) {
        setState((prev) => ({
          status: "guest",
          me: null,
          error: error.body?.code === "twitch_reauth" ? ERROR_MESSAGES.twitch_reauth : prev.error,
        }));
      } else {
        setState((prev) => ({
          status: "guest",
          me: null,
          error: prev.error ?? "Backend fora do ar. Rode php artisan serve em backend-laravel.",
        }));
      }
    }
  }, []);

  useEffect(() => {
    load();
  }, [load]);

  const logout = useCallback(async () => {
    try {
      await apiPost("/auth/logout");
    } finally {
      setState({ status: "guest", me: null, error: null });
    }
  }, []);

  return { ...state, reload: load, logout };
}
