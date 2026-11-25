import { useCallback, useEffect, useState } from "react";
import { apiGet, apiPost, apiPut, ApiError } from "../../services/api";
import Avatar from "../../shared/Avatar";
import { formatNumber } from "../../shared/format";

function firstError(error) {
  if (error instanceof ApiError && error.body?.errors) {
    return Object.values(error.body.errors)[0]?.[0];
  }
  return error?.message ?? "Algo deu errado.";
}

function Stat({ label, value }) {
  return (
    <div className="stat">
      <span className="stat-label">{label}</span>
      <span className="stat-value">{value}</span>
    </div>
  );
}

// Busca de pessoa (usa o ranking da comunidade).
function PersonPicker({ value, onChange }) {
  const [query, setQuery] = useState("");
  const [results, setResults] = useState([]);

  useEffect(() => {
    const q = query.trim();
    if (!q) return undefined;

    let cancelled = false;
    const id = setTimeout(() => {
      apiGet(`/community?sort=xp&q=${encodeURIComponent(q)}`)
        .then((res) => !cancelled && setResults(res.data.slice(0, 6)))
        .catch(() => !cancelled && setResults([]));
    }, 250);

    return () => {
      cancelled = true;
      clearTimeout(id);
    };
  }, [query]);

  if (value) {
    return (
      <div className="picked">
        <Avatar src={value.avatar} name={value.display_name} size={36} />
        <div>
          <strong>{value.display_name}</strong>
          <div className="muted small">
            Nível {value.level} · {formatNumber(value.xp)} XP · {formatNumber(value.coins)} moedas
          </div>
        </div>
        <button type="button" className="btn btn-ghost btn-sm" onClick={() => onChange(null)}>
          Trocar
        </button>
      </div>
    );
  }

  return (
    <div className="picker">
      <input
        className="admin-input"
        placeholder="Buscar pessoa pelo nome"
        value={query}
        onChange={(e) => {
          setQuery(e.target.value);
          if (!e.target.value.trim()) setResults([]);
        }}
      />
      {query.trim() && results.length > 0 && (
        <ul className="picker-list">
          {results.map((r) => (
            <li key={r.id}>
              <button type="button" onClick={() => onChange(r)}>
                <Avatar src={r.avatar} name={r.display_name} size={28} />
                <span>{r.display_name}</span>
                <span className="faint small">
                  Nv {r.level} · {formatNumber(r.coins)} moedas
                </span>
              </button>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

function AdjustCard({ onDone }) {
  const [person, setPerson] = useState(null);
  const [xp, setXp] = useState("");
  const [coins, setCoins] = useState("");
  const [reason, setReason] = useState("");
  const [status, setStatus] = useState(null);
  const [busy, setBusy] = useState(false);

  async function submit(event) {
    event.preventDefault();
    setBusy(true);
    setStatus(null);
    try {
      const res = await apiPost("/economy/adjust", {
        user_id: person.id,
        xp: xp ? Number(xp) : 0,
        coins: coins ? Number(coins) : 0,
        reason: reason || null,
      });
      setStatus({ ok: true, text: `Feito. Agora: ${formatNumber(res.xp)} XP e ${formatNumber(res.coins)} moedas (nível ${res.level}).` });
      setPerson({ ...person, xp: res.xp, coins: res.coins, level: res.level });
      setXp("");
      setCoins("");
      setReason("");
      onDone();
    } catch (error) {
      setStatus({ ok: false, text: firstError(error) });
    } finally {
      setBusy(false);
    }
  }

  return (
    <section className="card admin-card">
      <h3 className="admin-title">Ajuste manual</h3>
      <p className="muted small">Dá ou tira XP e moedas de uma pessoa. Use número negativo para tirar.</p>
      <form className="admin-form" onSubmit={submit}>
        <PersonPicker value={person} onChange={setPerson} />
        <div className="admin-row">
          <label>
            <span>XP</span>
            <input className="admin-input" type="number" value={xp} onChange={(e) => setXp(e.target.value)} placeholder="ex.: 500 ou -100" />
          </label>
          <label>
            <span>Moedas</span>
            <input className="admin-input" type="number" value={coins} onChange={(e) => setCoins(e.target.value)} placeholder="ex.: 50" />
          </label>
        </div>
        <label>
          <span>Motivo (opcional)</span>
          <input className="admin-input" value={reason} maxLength={200} onChange={(e) => setReason(e.target.value)} placeholder="ex.: ganhou o sorteio" />
        </label>
        {status && <p className={status.ok ? "admin-ok" : "alert"}>{status.text}</p>}
        <button type="submit" className="btn btn-primary" disabled={busy || !person || (!xp && !coins)}>
          {busy ? "Aplicando…" : "Aplicar ajuste"}
        </button>
      </form>
    </section>
  );
}

const TARGETS = [
  { id: "xp", label: "XP e nível" },
  { id: "coins", label: "Moedas" },
  { id: "streak", label: "Sequências de lives" },
];

function ResetCard({ onDone }) {
  const [scope, setScope] = useState("all");
  const [person, setPerson] = useState(null);
  const [targets, setTargets] = useState(["xp"]);
  const [confirm, setConfirm] = useState("");
  const [status, setStatus] = useState(null);
  const [busy, setBusy] = useState(false);

  const toggle = (id) => setTargets((t) => (t.includes(id) ? t.filter((x) => x !== id) : [...t, id]));
  const who = scope === "all" ? "de TODO MUNDO" : person ? `de ${person.display_name}` : "";
  const what = TARGETS.filter((t) => targets.includes(t.id)).map((t) => t.label.toLowerCase()).join(", ");

  async function submit(event) {
    event.preventDefault();
    setBusy(true);
    setStatus(null);
    try {
      const res = await apiPost("/economy/reset", {
        scope,
        user_id: scope === "user" ? person?.id : null,
        targets,
        confirm,
      });
      setStatus({ ok: true, text: `Zerado para ${res.affected} pessoa(s). Fica registrado no livro-caixa como "zerado".` });
      setConfirm("");
      setPerson(null);
      onDone();
    } catch (error) {
      setStatus({ ok: false, text: firstError(error) });
    } finally {
      setBusy(false);
    }
  }

  return (
    <section className="card admin-card admin-danger">
      <h3 className="admin-title">Zerar</h3>
      <p className="muted small">
        Não apaga o histórico: lança um ajuste de “zerado” no livro-caixa. Tempo no chat, mensagens e interações não
        são afetados.
      </p>
      <form className="admin-form" onSubmit={submit}>
        <div className="segmented" role="tablist">
          <button type="button" className={scope === "all" ? "active" : ""} onClick={() => setScope("all")}>
            Todo mundo
          </button>
          <button type="button" className={scope === "user" ? "active" : ""} onClick={() => setScope("user")}>
            Uma pessoa
          </button>
        </div>

        {scope === "user" && <PersonPicker value={person} onChange={setPerson} />}

        <fieldset className="admin-checks">
          <legend className="sr-only">O que zerar</legend>
          {TARGETS.map((t) => (
            <label key={t.id}>
              <input type="checkbox" checked={targets.includes(t.id)} onChange={() => toggle(t.id)} />
              {t.label}
            </label>
          ))}
        </fieldset>

        <label>
          <span>
            Para confirmar, digite <strong>ZERAR</strong>
          </span>
          <input className="admin-input" value={confirm} onChange={(e) => setConfirm(e.target.value)} autoComplete="off" />
        </label>

        {status && <p className={status.ok ? "admin-ok" : "alert"}>{status.text}</p>}

        <button
          type="submit"
          className="btn btn-danger"
          disabled={busy || targets.length === 0 || confirm !== "ZERAR" || (scope === "user" && !person)}
        >
          {busy ? "Zerando…" : `Zerar ${what || "…"} ${who}`}
        </button>
      </form>
    </section>
  );
}

function SettingsCard({ onSaved }) {
  const [rows, setRows] = useState(null);
  const [draft, setDraft] = useState({});
  const [status, setStatus] = useState(null);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    apiGet("/economy/settings")
      .then((data) => setRows(data))
      .catch(() => setStatus({ ok: false, text: "Não foi possível carregar as configurações." }));
  }, []);

  const changed = Object.keys(draft).length > 0;

  async function save() {
    setBusy(true);
    setStatus(null);
    try {
      const data = await apiPut("/economy/settings", { values: draft });
      setRows(data);
      setDraft({});
      setStatus({ ok: true, text: "Salvo. Os valores novos valem em até 1 minuto." });
      onSaved();
    } catch (error) {
      setStatus({ ok: false, text: firstError(error) });
    } finally {
      setBusy(false);
    }
  }

  return (
    <section className="card admin-card admin-wide">
      <div className="section-head">
        <h3 className="admin-title">Configurações da economia</h3>
        <button type="button" className="btn btn-primary btn-sm" disabled={!changed || busy} onClick={save}>
          {busy ? "Salvando…" : "Salvar alterações"}
        </button>
      </div>
      {status && <p className={status.ok ? "admin-ok" : "alert"}>{status.text}</p>}
      {!rows ? (
        <p className="muted">Carregando…</p>
      ) : (
        <div className="table-wrap" style={{ maxHeight: "none" }}>
          <table className="table admin-table">
            <thead>
              <tr>
                <th>O que é</th>
                <th>Valor</th>
                <th className="hide-sm">Padrão</th>
              </tr>
            </thead>
            <tbody>
              {rows.map((r) => {
                const value = draft[r.key] ?? r.value;
                return (
                  <tr key={r.key}>
                    <td style={{ whiteSpace: "normal" }}>
                      {r.description}
                      <div className="faint small">{r.key}</div>
                    </td>
                    <td>
                      <input
                        className={`admin-input ${draft[r.key] !== undefined ? "is-dirty" : ""}`}
                        value={value}
                        onChange={(e) => setDraft((d) => ({ ...d, [r.key]: e.target.value }))}
                        style={{ minWidth: r.key === "level_titles" ? 320 : 100 }}
                      />
                    </td>
                    <td className="hide-sm">
                      {value !== r.default ? (
                        <button
                          type="button"
                          className="section-link"
                          onClick={() => setDraft((d) => ({ ...d, [r.key]: r.default }))}
                          title="Voltar ao valor padrão"
                        >
                          {r.default.length > 24 ? "restaurar" : r.default}
                        </button>
                      ) : (
                        <span className="faint">padrão</span>
                      )}
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      )}
    </section>
  );
}

// Aba "Gerenciar economia".
export default function EconomyAdmin() {
  const [summary, setSummary] = useState(null);

  const loadSummary = useCallback(() => {
    apiGet("/economy/summary")
      .then(setSummary)
      .catch(() => setSummary(null));
  }, []);

  useEffect(() => {
    loadSummary();
  }, [loadSummary]);

  return (
    <section className="section">
      <div className="section-head">
        <div>
          <h2>Gerenciar economia // Quartermaster</h2>
          <span className="muted small">XP e moedas são saldos separados. Tudo fica registrado no livro-caixa.</span>
        </div>
      </div>

      {summary && (
        <div className="stats-row">
          <Stat label="XP distribuído" value={formatNumber(summary.xp_total)} />
          <Stat label="Moedas em circulação" value={formatNumber(summary.coins_total)} />
          <Stat label="Pessoas com XP" value={formatNumber(summary.with_xp)} />
          <Stat label="Pessoas com moedas" value={formatNumber(summary.with_coins)} />
          <Stat label="Em sequência" value={formatNumber(summary.with_streak)} />
          <Stat label="Subs" value={formatNumber(summary.subs)} />
        </div>
      )}

      <div className="admin-grid">
        <AdjustCard onDone={loadSummary} />
        <ResetCard onDone={loadSummary} />
      </div>

      <SettingsCard onSaved={loadSummary} />
    </section>
  );
}
