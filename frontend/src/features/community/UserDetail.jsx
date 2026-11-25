import { X } from "lucide-react";
import { useEffect, useRef, useState } from "react";
import { apiGet } from "../../services/api";
import Avatar from "../../shared/Avatar";
import { formatDuration, formatNumber } from "../../shared/format";
import { EventItem } from "../events/EventsFeed";
import { describeEvent, tierLabel } from "../events/describeEvent";

function formatClock(iso) {
  return new Date(iso).toLocaleTimeString("pt-BR", { hour: "2-digit", minute: "2-digit", second: "2-digit" });
}

const SUB_TIERS = { 1: "Tier 1", 2: "Tier 2", 3: "Tier 3" };

const LEDGER_LABELS = {
  presenca: "Tempo no chat",
  mensagem: "Mensagem",
  comando: "Comando",
  resgate: "Resgate",
  sub: "Sub",
  resub: "Resub",
  sub_presente_recebido: "Sub de presente",
  sub_presente_dado: "Presenteou subs",
  bits: "Bits",
  ajuste_manual: "Ajuste manual",
};

// Nível, título, barra de XP, moedas, sub e sequência.
function EconomyCard({ economy: e }) {
  const pct = e.xp_needed > 0 ? Math.min(100, (e.xp_in_level / e.xp_needed) * 100) : 0;

  return (
    <section className="economy metal">
      <div className="metal-inner">
        <div className="economy-head">
          <span className="level-badge">
            <small>Nível</small>
            {e.level}
          </span>
          <div className="economy-title">
            <strong>{e.title}</strong>
            <div className="xp-bar" role="progressbar" aria-valuemin={0} aria-valuemax={e.xp_needed} aria-valuenow={e.xp_in_level}>
              <span style={{ width: `${pct}%` }} />
            </div>
            <span className="muted small">
              {formatNumber(e.xp_in_level)} / {formatNumber(e.xp_needed)} XP para o nível {e.level + 1} ·{" "}
              {formatNumber(e.xp)} XP no total
            </span>
          </div>
        </div>
        <div className="economy-stats">
          <div>
            <span>Moedas</span>
            <strong>{formatNumber(e.coins)}</strong>
          </div>
          <div>
            <span>Sequência</span>
            <strong>{e.streak} {e.streak === 1 ? "live" : "lives"}</strong>
          </div>
          <div>
            <span>Recorde</span>
            <strong>{e.best_streak}</strong>
          </div>
          <div>
            <span>Sub</span>
            <strong>{e.sub_tier ? (e.sub_is_prime ? "Prime" : SUB_TIERS[e.sub_tier]) : "—"}</strong>
          </div>
        </div>
      </div>
    </section>
  );
}

function Ledger({ items }) {
  if (items.length === 0) {
    return <p className="muted">Ainda não ganhou XP nem moedas.</p>;
  }

  return (
    <div className="table-wrap" style={{ maxHeight: 320 }}>
      <table className="table">
        <thead>
          <tr>
            <th>Quando</th>
            <th>Origem</th>
            <th className="num">XP</th>
            <th className="num">Moedas</th>
          </tr>
        </thead>
        <tbody>
          {items.map((t) => (
            <tr key={t.id}>
              <td className="faint">
                {new Date(t.at).toLocaleString("pt-BR", { dateStyle: "short", timeStyle: "short" })}
              </td>
              <td>
                {LEDGER_LABELS[t.type] ?? t.type}
                {t.meta?.mult && t.meta.mult !== 1 && <span className="pill pill-muted">×{t.meta.mult}</span>}
              </td>
              <td className="num">{t.xp ? `${t.xp > 0 ? "+" : ""}${formatNumber(t.xp)}` : "—"}</td>
              <td className="num">{t.coins ? `${t.coins > 0 ? "+" : ""}${formatNumber(t.coins)}` : "—"}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function Stat({ label, value }) {
  return (
    <div className="stat">
      <span className="stat-label">{label}</span>
      <span className="stat-value">{value}</span>
    </div>
  );
}

// Ficha de uma pessoa do chat: totais, situação da sub, cada live e todas as interações.
export default function UserDetail({ userId, onClose, onSelectUser }) {
  const [state, setState] = useState({ data: null, error: null });
  const dialogRef = useRef(null);

  useEffect(() => {
    let cancelled = false;
    apiGet(`/community/${userId}`)
      .then((data) => !cancelled && setState({ data, error: null }))
      .catch(() => !cancelled && setState({ data: null, error: "Não foi possível carregar essa pessoa." }));
    return () => {
      cancelled = true;
    };
  }, [userId]);

  // Abre como modal. Não fecha na limpeza do efeito: no StrictMode o React monta duas vezes e o
  // close() da primeira montagem dispararia o onClose, fechando a ficha logo depois de abrir.
  // Ao desmontar, o <dialog> sai da página e fecha sozinho.
  useEffect(() => {
    const dialog = dialogRef.current;
    if (dialog && !dialog.open) dialog.showModal();
  }, []);

  const { data, error } = state;
  const t = data?.totals;

  return (
    <dialog
      ref={dialogRef}
      className="drawer"
      onClose={onClose}
      onClick={(e) => e.target === dialogRef.current && onClose()}
      aria-label="Detalhes da pessoa"
    >
      <div className="drawer-body">
        <header className="drawer-head">
          {data && <Avatar src={data.avatar} name={data.display_name} size={56} />}
          <div>
            <h2>{data?.display_name ?? "Carregando…"}</h2>
            {data && <small className="muted">twitch.tv/{data.login}</small>}
          </div>
          <button type="button" className="icon-btn" onClick={onClose} aria-label="Fechar">
            <X size={20} />
          </button>
        </header>

        {error && <p className="muted">{error}</p>}

        {data && (
          <>
            <EconomyCard economy={data.economy} />

            {data.last_sub && (
              <p className="sub-status">
                <span className="pill">{data.last_sub.is_gift ? "Sub presente" : tierLabel(data.last_sub.tier, data.last_sub.is_prime)}</span>
                Última sub: {describeEvent(data.last_sub).action} em{" "}
                {new Date(data.last_sub.occurred_at).toLocaleDateString("pt-BR")}
              </p>
            )}

            <div className="stats-row">
              <Stat label="Tempo no chat" value={formatDuration(t.watch_seconds)} />
              <Stat label="Mensagens" value={formatNumber(t.message_count)} />
              <Stat label="Lives" value={formatNumber(t.lives_count)} />
              <Stat label="Subs" value={formatNumber(t.subs_count)} />
              <Stat label="Presentes dados" value={formatNumber(t.gifts_given)} />
              <Stat label="Bits" value={formatNumber(t.bits_total)} />
              <Stat label="Resgates" value={formatNumber(t.redemptions_count)} />
              <Stat label="Pontos gastos" value={formatNumber(t.points_spent)} />
            </div>

            <h3>Por live</h3>
            {data.lives.length === 0 ? (
              <p className="muted">Ainda não apareceu em nenhuma live registrada.</p>
            ) : (
              <div className="table-wrap">
                <table className="table">
                  <thead>
                    <tr>
                      <th>Live</th>
                      <th className="num">Tempo</th>
                      <th className="num">Msgs</th>
                      <th className="num hide-sm">Resgates</th>
                      <th className="num hide-sm">Bits</th>
                      <th className="num hide-sm">Presentes</th>
                    </tr>
                  </thead>
                  <tbody>
                    {data.lives.map((l) => (
                      <tr key={l.session_id}>
                        <td>
                          {new Date(l.started_at).toLocaleDateString("pt-BR")}
                          {l.subscribed && <span className="pill">Sub</span>}
                          <div className="muted small ellipsis">{l.game_name ?? ""}</div>
                        </td>
                        <td className="num">{formatDuration(l.watch_seconds)}</td>
                        <td className="num">{formatNumber(l.message_count)}</td>
                        <td className="num hide-sm">{l.redemptions || "—"}</td>
                        <td className="num hide-sm">{l.bits ? formatNumber(l.bits) : "—"}</td>
                        <td className="num hide-sm">{l.gifts || "—"}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}

            {data.lives.length > 0 && (
              <>
                <h3>Entradas e saídas</h3>
                <p className="muted small">
                  O tempo só conta enquanto a pessoa está no chat. Ao sair, para; ao voltar, continua do total que ela
                  já tinha.
                </p>
                {data.lives.map((l) => (
                  <div key={l.session_id} className="periods">
                    <div className="periods-head">
                      <strong>{new Date(l.started_at).toLocaleDateString("pt-BR")}</strong>
                      <span className="muted small">
                        {l.periods.length === 1 ? "1 período" : `${l.periods.length} períodos`} ·{" "}
                        {formatDuration(l.watch_seconds)} no total
                      </span>
                    </div>
                    <ol className="period-list">
                      {l.periods.map((p, i) => (
                        <li key={p.started_at}>
                          <span className="period-range">
                            {i > 0 && <span className="muted">voltou </span>}
                            {formatClock(p.started_at)} → {p.open ? <em>no chat agora</em> : formatClock(p.last_seen_at)}
                          </span>
                          <span className="period-time">{formatDuration(p.seconds)}</span>
                          {i < l.periods.length - 1 && (
                            <span className="period-gap muted small">
                              fora por {formatDuration((Date.parse(l.periods[i + 1].started_at) - Date.parse(p.last_seen_at)) / 1000)} (não conta)
                            </span>
                          )}
                        </li>
                      ))}
                    </ol>
                  </div>
                ))}
              </>
            )}

            <h3>Livro-caixa // XP e moedas</h3>
            <Ledger items={data.ledger} />

            <h3>Interações</h3>
            {data.timeline.length === 0 ? (
              <p className="muted">Nenhum resgate, sub ou bits ainda.</p>
            ) : (
              <ul className="feed">
                {data.timeline.map((e) => (
                  <EventItem key={e.id} event={e} onSelectUser={onSelectUser} showUser={false} showDate />
                ))}
              </ul>
            )}

            <p className="muted small">Primeira vez vista em {new Date(data.first_seen_at).toLocaleDateString("pt-BR")}.</p>
          </>
        )}
      </div>
    </dialog>
  );
}
