import { useCallback, useEffect, useRef, useState } from "react";
import { apiGet, ApiError } from "../services/api";

// Busca um endpoint de tempos em tempos. Pausa com a aba escondida e mantém o último dado se falhar.
export function usePolling(path, intervalMs, { onUnauthorized } = {}) {
  const [state, setState] = useState({ data: null, error: null, loading: true });
  const onUnauthorizedRef = useRef(onUnauthorized);

  useEffect(() => {
    onUnauthorizedRef.current = onUnauthorized;
  }, [onUnauthorized]);

  const load = useCallback(async () => {
    try {
      const data = await apiGet(path);
      // receivedAt: relógio do navegador quando a resposta chegou (para alinhar com o do servidor).
      setState({ data, error: null, loading: false, receivedAt: Date.now() });
    } catch (error) {
      if (error instanceof ApiError && error.status === 401) {
        onUnauthorizedRef.current?.();
        return;
      }
      setState((prev) => ({ ...prev, error: "Não foi possível atualizar agora.", loading: false }));
    }
  }, [path]);

  useEffect(() => {
    let timer = null;

    const start = () => {
      load();
      timer = setInterval(load, intervalMs);
    };
    const stop = () => clearInterval(timer);
    const onVisibility = () => (document.hidden ? stop() : start());

    if (!document.hidden) start();
    document.addEventListener("visibilitychange", onVisibility);

    return () => {
      stop();
      document.removeEventListener("visibilitychange", onVisibility);
    };
  }, [load, intervalMs]);

  return { ...state, refresh: load };
}
