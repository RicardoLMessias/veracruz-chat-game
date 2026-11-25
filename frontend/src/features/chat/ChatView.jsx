import { useMemo, useState } from "react";
import Avatar from "../../shared/Avatar";
import { formatDuration, formatNumber, formatTime } from "../../shared/format";
import { tierLabel } from "../events/describeEvent";
import HealthNotice from "./HealthNotice";

const FILTERS = [
  { id: "online", label: "Online agora" },
  { id: "all", label: "Todos da live" },
];

function Stat({ label, value }) {
  return (
    <div className="stat">
      <span className="stat-label">{label}</span>
      <span className="stat-value">{value}</span>
    </div>
  );
}

// Tela "Chat": números da live e a tabela de cada pessoa.
export default function ChatView({ overview, chatters, search, onSelectUser }) {
  const data = overview.data;
  const [chosenFilter, setFilter] = useState("online");
  // Com a live desligada ninguém está "online": mostra todo mundo da última live.
  const filter = data?.live ? chosenFilter : "all";

  const visible = useMemo(() => {
    const q = search.trim().toLowerCase();
    return chatters.filter(
      (c) =>
        (filter === "all" || c.online) &&
        (!q || c.login.includes(q) || c.display_name.toLowerCase().includes(q)),
    );
  }, [chatters, filter, search]);

  if (!data) {
    return <div className="card muted">{overview.error ?? "Carregando chat…"}</div>;
  }

  const { totals, session, live } = data;

  return (
    <section className="section">
      <div className="section-head">
        <div>
          <h2>Rádio do chat // Chat comms</h2>
          <span className="muted small">
            {live
              ? `Live desde ${formatTime(session.started_at)}`
              : session
                ? `Offline · última live em ${new Date(session.started_at).toLocaleDateString("pt-BR")}`
                : "Nenhuma live registrada ainda"}
            {overview.error && ` · ${overview.error}`}
          </span>
        </div>
        {live && (
          <div className="segmented" role="tablist">
            {FILTERS.map((f) => (
              <button
                key={f.id}
                type="button"
                role="tab"
                aria-selected={filter === f.id}
                className={filter === f.id ? "active" : ""}
                onClick={() => setFilter(f.id)}
              >
                {f.label}
              </button>
            ))}
          </div>
        )}
      </div>

      <HealthNotice health={data.health} live={live} />

      <div className="stats-row">
        <Stat label="Online agora" value={live ? formatNumber(totals.online) : "—"} />
        <Stat label="Passaram pela live" value={formatNumber(totals.chatters)} />
        <Stat label="Mensagens" value={formatNumber(totals.messages)} />
        <Stat label="Resgates" value={formatNumber(totals.redemptions)} />
        <Stat label="Subs" value={formatNumber(totals.subs)} />
        <Stat label="Presentes" value={formatNumber(totals.gifts)} />
        <Stat label="Bits" value={formatNumber(totals.bits)} />
      </div>

      {visible.length === 0 ? (
        <p className="empty">
          {search
            ? "Ninguém com esse nome nesta live."
            : filter === "online" && live
              ? "Ninguém na lista ainda. A Twitch atualiza quem está no chat com alguns minutos de atraso."
              : "Ninguém para mostrar."}
        </p>
      ) : (
        <div className="table-wrap">
          <table className="table">
            <thead>
              <tr>
                <th>Pessoa</th>
                <th className="num">Tempo no chat</th>
                <th className="num">Mensagens</th>
                <th className="num hide-sm">Resgates</th>
                <th className="num hide-sm">Bits</th>
                <th className="num hide-sm">Presentes</th>
                <th className="num hide-sm">Chegou</th>
              </tr>
            </thead>
            <tbody>
              {visible.map((c) => (
                <tr key={c.id}>
                  <td>
                    <span className="person">
                      <Avatar src={c.avatar} name={c.display_name} size={34} online={c.online} />
                      <span>
                        <span className="level-pill" title="Nível">{c.level}</span>{" "}
                        <button type="button" className="link-btn" onClick={() => onSelectUser(c.id)}>
                          {c.display_name}
                        </button>
                        {c.sub && (
                          <span className="pill">
                            {c.sub.is_gift ? "Sub presente" : tierLabel(c.sub.tier, c.sub.is_prime)}
                          </span>
                        )}
                        {c.periods > 1 && (
                          <span className="pill pill-muted" title="Saiu e voltou; o tempo fora não conta">
                            voltou {c.periods - 1}x
                          </span>
                        )}
                        <span className="sr-only">{c.online ? " (online)" : " (saiu)"}</span>
                      </span>
                    </span>
                  </td>
                  <td className="num">{formatDuration(c.watch_seconds)}</td>
                  <td className="num">{formatNumber(c.message_count)}</td>
                  <td className="num hide-sm">{c.redemptions || "—"}</td>
                  <td className="num hide-sm">{c.bits ? formatNumber(c.bits) : "—"}</td>
                  <td className="num hide-sm">{c.gifts || "—"}</td>
                  <td className="num hide-sm">{formatTime(c.first_seen_at)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      <p className="faint small">
        Só aparecem contas logadas e conectadas ao chat. Quem está no chat é conferido a cada{" "}
        {data.presence_interval_seconds}s durante a live; mensagens também contam como presença. A própria Twitch
        demora alguns minutos para pôr ou tirar alguém da lista.
      </p>
    </section>
  );
}
