import { useCallback, useEffect, useState } from "react";
import { apiGet } from "../../services/api";

// Pergunta ao backend se as credenciais da Twitch já foram configuradas.
export function useSetup() {
  const [state, setState] = useState({ status: "loading", setup: null });

  const load = useCallback(async () => {
    try {
      const setup = await apiGet("/setup");
      setState({ status: "ready", setup });
    } catch {
      setState({ status: "offline", setup: null });
    }
  }, []);

  useEffect(() => {
    load();
  }, [load]);

  return { ...state, reload: load };
}
