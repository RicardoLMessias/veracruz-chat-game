import { useEffect, useState } from "react";
import { apiGet } from "../../services/api";
import Avatar from "../../shared/Avatar";
import { formatDuration, formatNumber } from "../../shared/format";

const SORTS = [
  { id: "xp", label: "XP" },
  { id: "coins", label: "Moedas" },
  { id: "watch", label: "Tempo" },
  { id: "messages", label: "Mensagens" },
  { id: "subs", label: "Subs" },
  { id: "gifts", label: "Presentes" },
  { id: "bits", label: "Bits" },
  { id: "redemptions", label: "Resgates" },
  { id: "recent", label: "Recentes" },
];

// Tela "Comunidade": ranking de todas as lives somadas. A busca vem do campo do cabeçalho.
export default function CommunityPanel({ search, onSelectUser }) {
  const [sort, setSort] = useState("xp");
  const [debounced, setDebounced] = useState(search.trim());
  const [page, setPage] = useState(1);
  const [state, setState] = useState({ rows: [], meta: null, error: null, loading: true });

  useEffect(() => {
    const id = setTimeout(() => {
      setDebounced(search.trim());
      setPage(1);
    }, 300);
    return () => clearTimeout(id);
  }, [search]);

  useEffect(() => {
    let cancelled = false;
    const params = new URLSearchParams({ sort, page: String(page) });
    if (debounced) params.set("q", debounced);

    apiGet(`/community?${params}`)
      .then((res) => !cancelled && setState({ rows: res.data, meta: res.meta, error: null, loading: false }))
      .catch(() => !cancelled && setState((prev) => ({ ...prev, error: "Não foi possível carregar.", loading: false })));

    return () => {
      cancelled = true;
    };
  }, [sort, debounced, page]);

  const { rows, meta, error, loading } = state;
  const offset = meta ? (meta.page - 1) * 50 : 0;

  return (
    <section className="section">
      <div className="section-head">
        <div>
          <h2>Ranking da tropa // Leaderboard</h2>
          <span className="muted small">
            Todas as lives somadas{debounced ? ` · buscando “${debounced}”` : ""}
          </span>
        </div>
        <div className="segmented" role="tablist">
          {SORTS.map((s) => (
            <button
              key={s.id}
              type="button"
              role="tab"
              aria-selected={sort === s.id}
              className={sort === s.id ? "active" : ""}
              onClick={() => {
                setSort(s.id);
                setPage(1);
              }}
            >
              {s.label}
            </button>
          ))}
        </div>
      </div>

      {error && <p className="alert">{error}</p>}

      {rows.length === 0 ? (
        <p className="empty">{loading ? "Carregando…" : "Ninguém registrado ainda."}</p>
      ) : (
        <div className="table-wrap">
          <table className="table">
            <thead>
              <tr>
                <th className="num">#</th>
                <th>Pessoa</th>
                <th className="num">Nível</th>
                <th className="num">XP</th>
                <th className="num">Moedas</th>
                <th className="num">Tempo</th>
                <th className="num">Mensagens</th>
                <th className="num hide-sm">Lives</th>
                <th className="num hide-sm">Subs</th>
                <th className="num hide-sm">Presentes</th>
                <th className="num hide-sm">Bits</th>
                <th className="num hide-sm">Resgates</th>
              </tr>
            </thead>
            <tbody>
              {rows.map((r, i) => (
                <tr key={r.id}>
                  <td className="num faint">{offset + i + 1}</td>
                  <td>
                    <span className="person">
                      <Avatar src={r.avatar} name={r.display_name} size={34} />
                      <button type="button" className="link-btn" onClick={() => onSelectUser(r.id)}>
                        {r.display_name}
                      </button>
                    </span>
                  </td>
                  <td className="num">
                    <span className="level-pill" title={r.title}>{r.level}</span>
                  </td>
                  <td className="num">{formatNumber(r.xp)}</td>
                  <td className="num">{formatNumber(r.coins)}</td>
                  <td className="num">{formatDuration(r.watch_seconds)}</td>
                  <td className="num">{formatNumber(r.message_count)}</td>
                  <td className="num hide-sm">{r.lives_count}</td>
                  <td className="num hide-sm">{r.subs_count || "—"}</td>
                  <td className="num hide-sm">{r.gifts_given || "—"}</td>
                  <td className="num hide-sm">{r.bits_total ? formatNumber(r.bits_total) : "—"}</td>
                  <td className="num hide-sm">{r.redemptions_count || "—"}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {meta && meta.last_page > 1 && (
        <div className="pager">
          <button type="button" className="btn btn-ghost btn-sm" disabled={page <= 1} onClick={() => setPage(page - 1)}>
            Anterior
          </button>
          <span className="muted small">
            Página {meta.page} de {meta.last_page} · {formatNumber(meta.total)} pessoas
          </span>
          <button
            type="button"
            className="btn btn-ghost btn-sm"
            disabled={page >= meta.last_page}
            onClick={() => setPage(page + 1)}
          >
            Próxima
          </button>
        </div>
      )}
    </section>
  );
}
