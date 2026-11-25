import { usePolling } from "../../shared/usePolling";

// Status da live, atualizado a cada 30s.
export function useLiveStatus({ onUnauthorized }) {
  return usePolling("/channel/status", 30_000, { onUnauthorized });
}
