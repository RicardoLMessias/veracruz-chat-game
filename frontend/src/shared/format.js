// 1h 05min 12s · 12min 05s · 45s
export function formatDuration(totalSeconds) {
  const total = Math.max(0, Math.floor(totalSeconds));
  if (total === 0) return "—";
  const h = Math.floor(total / 3600);
  const m = Math.floor((total % 3600) / 60);
  const s = total % 60;
  const pad = (n) => String(n).padStart(2, "0");
  if (h > 0) return `${h}h ${pad(m)}min ${pad(s)}s`;
  if (m > 0) return `${m}min ${pad(s)}s`;
  return `${s}s`;
}

export function formatTime(iso) {
  return iso ? new Date(iso).toLocaleTimeString("pt-BR", { hour: "2-digit", minute: "2-digit" }) : "—";
}

export function formatNumber(value) {
  return Number(value ?? 0).toLocaleString("pt-BR");
}
