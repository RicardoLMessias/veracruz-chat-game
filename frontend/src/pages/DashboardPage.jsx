import { useEffect, useMemo, useRef, useState } from "react";
import AppShell from "../app/AppShell";
import { VIEWS } from "../app/navigation";
import ChatView from "../features/chat/ChatView";
import CommunityPanel from "../features/community/CommunityPanel";
import UserDetail from "../features/community/UserDetail";
import EconomyAdmin from "../features/economy/EconomyAdmin";
import EventsFeed from "../features/events/EventsFeed";
import { useLiveStatus } from "../features/live/useLiveStatus";
import OverviewView from "../features/overview/OverviewView";
import { TWITCH_LOGIN_URL } from "../services/api";
import { usePolling } from "../shared/usePolling";
import { useNow } from "../shared/useNow";

const BROADCASTER_TYPES = { partner: "Parceiro", affiliate: "Afiliado", "": "Padrão" };

const SCOPE_LABELS = {
  "user:read:chat": "ler o chat",
  "moderator:read:chatters": "ver quem está no chat",
  "channel:read:redemptions": "ver resgates da loja de pontos",
  "bits:read": "ver bits",
  "user:bot": "receber o chat por webhook",
  "channel:bot": "receber o chat do canal por webhook",
  "channel:read:subscriptions": "ver quem é sub (para o multiplicador de XP e moedas)",
};

// Lê a lista de telas na hora (não guarda numa constante): assim uma tela nova nunca é tratada
// como desconhecida, mesmo se o código for atualizado com a página aberta.
function isView(id) {
  return VIEWS.some((v) => v.id === id);
}

function viewFromHash() {
  const id = window.location.hash.replace("#", "");
  return isView(id) ? id : "overview";
}

// Para quem está online, soma o tempo desde a última checagem do servidor: o número sobe segundo
// a segundo em vez de pular a cada atualização.
function withLiveWatch(chatters, serverNow, gapSeconds) {
  return chatters.map((c) => {
    if (!c.online || !c.last_seen_at) return c;
    const since = (serverNow - Date.parse(c.last_seen_at)) / 1000;
    return { ...c, watch_seconds: c.watch_seconds + Math.min(Math.max(since, 0), gapSeconds) };
  });
}

export default function DashboardPage({ me, onLogout, onReauth }) {
  const { channel } = me;
  const liveStatus = useLiveStatus({ onUnauthorized: onReauth });
  const overview = usePolling("/chat/overview", 10_000, { onUnauthorized: onReauth });
  const [view, setView] = useState(viewFromHash);
  const [search, setSearch] = useState("");
  const [selectedUser, setSelectedUser] = useState(null);

  const data = overview.data;
  const now = useNow(Boolean(data?.live));
  const clockOffset = data?.fetched_at && overview.receivedAt ? Date.parse(data.fetched_at) - overview.receivedAt : 0;

  const chatters = useMemo(
    () => (data ? withLiveWatch(data.chatters, now + clockOffset, data.presence_gap_seconds) : []),
    [data, now, clockOffset],
  );
  const online = useMemo(() => chatters.filter((c) => c.online), [chatters]);
  const totalWatchSeconds = useMemo(() => chatters.reduce((sum, c) => sum + c.watch_seconds, 0), [chatters]);

  // Tela pedida pelo menu: o hashchange que vem logo depois nunca pode desfazer esse clique.
  const requestedView = useRef(view);

  useEffect(() => {
    const onHash = () => {
      const id = window.location.hash.replace("#", "") || "overview";
      setView(id === requestedView.current ? id : viewFromHash());
    };
    window.addEventListener("hashchange", onHash);
    return () => window.removeEventListener("hashchange", onHash);
  }, []);

  const navigate = (id) => {
    requestedView.current = id;
    setView(id);
    const hash = id === "overview" ? "" : `#${id}`;
    // Troca o endereço sem disparar hashchange (o voltar do navegador continua funcionando).
    if (window.location.hash !== hash) {
      window.history.pushState(null, "", hash || window.location.pathname + window.location.search);
    }
    window.scrollTo({ top: 0, behavior: "smooth" });
  };

  // Buscar a partir da visão geral ou das interações leva para o ranking da comunidade.
  const handleSearch = (value) => {
    setSearch(value);
    if (value && (view === "overview" || view === "events")) navigate("community");
  };

  const missing = channel.missing_scopes ?? [];
  const live = Boolean(liveStatus.data?.live ?? data?.live);

  return (
    <AppShell
      channel={channel}
      view={view}
      onNavigate={navigate}
      onLogout={onLogout}
      online={online}
      live={live}
      onSelectUser={setSelectedUser}
      search={search}
      onSearch={handleSearch}
    >
      {missing.length > 0 && (
        <section className="reauth">
          <div>
            <strong>Autorize as novas permissões</strong>
            <p className="muted small">
              O dashboard precisa {missing.map((s) => SCOPE_LABELS[s] ?? s).join(", ")}. A Twitch vai pedir sua
              confirmação.
            </p>
          </div>
          <a className="btn btn-primary" href={TWITCH_LOGIN_URL}>
            Autorizar na Twitch
          </a>
        </section>
      )}

      {view === "overview" && (
        <OverviewView
          overview={overview}
          liveStatus={liveStatus}
          channel={channel}
          chatters={chatters}
          online={online}
          totalWatchSeconds={totalWatchSeconds}
          onSelectUser={setSelectedUser}
          onNavigate={navigate}
        />
      )}

      {view === "chat" && (
        <ChatView overview={overview} chatters={chatters} search={search} onSelectUser={setSelectedUser} />
      )}

      {view === "events" && <EventsFeed events={data?.events ?? []} onSelectUser={setSelectedUser} />}

      {view === "community" && <CommunityPanel search={search} onSelectUser={setSelectedUser} />}

      {view === "economy" && <EconomyAdmin />}

      {view === "overview" && (
        // Detalhes técnicos ficam fechados por padrão para não aparecer em gravação/live.
        <details className="card details">
          <summary>Detalhes da conexão</summary>
          <dl className="facts">
            <div>
              <dt>Tipo de canal</dt>
              <dd>{BROADCASTER_TYPES[channel.broadcaster_type ?? ""] ?? channel.broadcaster_type}</dd>
            </div>
            <div>
              <dt>Permissões concedidas</dt>
              <dd>{channel.scopes.length ? channel.scopes.join(", ") : "nenhuma"}</dd>
            </div>
            <div>
              <dt>Token renova antes de</dt>
              <dd>{new Date(channel.token_expires_at).toLocaleString("pt-BR")}</dd>
            </div>
          </dl>
        </details>
      )}

      {selectedUser && (
        <UserDetail
          key={selectedUser}
          userId={selectedUser}
          onClose={() => setSelectedUser(null)}
          onSelectUser={setSelectedUser}
        />
      )}
    </AppShell>
  );
}
