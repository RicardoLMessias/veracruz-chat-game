import { LogOut, Search, Shield, Swords } from "lucide-react";
import Avatar from "../shared/Avatar";
import { VIEWS } from "./navigation";

function NavButtons({ view, onNavigate }) {
  return VIEWS.map(({ id, label, icon: Icon }) => (
    <button
      key={id}
      type="button"
      className={`icon-btn ${view === id ? "active" : ""}`}
      onClick={() => onNavigate(id)}
      aria-label={label}
      aria-current={view === id ? "page" : undefined}
      title={label}
    >
      <Icon size={22} strokeWidth={2.2} />
    </button>
  ));
}

// Barras de sinal: força da presença de cada pessoa (tempo no chat comparado ao de quem está há mais tempo).
function Signal({ strength }) {
  return (
    <span className="signal" aria-hidden="true">
      {[1, 2, 3, 4, 5].map((n) => (
        <i key={n} className={n <= strength ? `on ${strength <= 2 ? "low" : ""}` : ""} />
      ))}
    </span>
  );
}

function strengthFor(seconds, max) {
  if (max <= 0) return 1;
  return Math.max(1, Math.ceil((seconds / max) * 5));
}

function OnlineList({ online, onSelectUser, size = 44, withSignal = false }) {
  const max = Math.max(0, ...online.map((c) => c.watch_seconds));

  return online.map((c) => (
    <button
      key={c.id}
      type="button"
      className="avatar-btn rail-person"
      onClick={() => onSelectUser(c.id)}
      title={c.display_name}
      aria-label={`${c.display_name} (online)`}
    >
      <Avatar src={c.avatar} name={c.display_name} size={size} online ring />
      {withSignal && <Signal strength={strengthFor(c.watch_seconds, max)} />}
    </button>
  ));
}

// Estrutura: menu à esquerda, conteúdo no meio, tropa online à direita.
export default function AppShell({
  channel,
  view,
  onNavigate,
  onLogout,
  online,
  live,
  onSelectUser,
  search,
  onSearch,
  children,
}) {
  return (
    <div className="shell">
      <nav className="rail" aria-label="Menu">
        <div className="rail-logo" aria-hidden="true">
          <Shield size={26} strokeWidth={2.4} />
        </div>
        <div className="rail-nav">
          <NavButtons view={view} onNavigate={onNavigate} />
        </div>
        <div className="rail-spacer" />
        <button type="button" className="icon-btn rail-exit" onClick={onLogout} aria-label="Sair" title="Sair">
          <LogOut size={20} />
        </button>
      </nav>

      <main className="main">
        <header className="header">
          <h1 className="greeting">
            Mission Brief // <strong>{channel.display_name}</strong>
          </h1>
          <label className="search">
            <Search size={18} aria-hidden="true" />
            <span className="sr-only">Buscar pessoa</span>
            <input
              type="search"
              placeholder="Buscar soldado // viewer search"
              value={search}
              onChange={(e) => onSearch(e.target.value)}
            />
          </label>
          <span className={`header-status ${live ? "is-live" : "is-off"}`}>
            {live && <span className="live-dot" aria-hidden="true" />}
            {live ? "Ao vivo" : "Offline"}
          </span>
        </header>

        {online.length > 0 && (
          <div className="online-strip" aria-label="Online no chat">
            <OnlineList online={online} onSelectUser={onSelectUser} size={48} />
          </div>
        )}

        {children}
      </main>

      <aside className="rail rail-right" aria-label="Online no chat">
        <Avatar src={channel.profile_image_url} name={channel.display_name} size={56} ring />
        <div className="rail-divider" />
        <div className="rail-section">
          <div className="rail-count">
            <Swords size={20} aria-hidden="true" />
            <strong>{online.length}</strong>
            online
          </div>
          <div className="rail-list">
            {online.length === 0 ? (
              <span className="faint small" style={{ textAlign: "center", padding: "0 8px" }}>
                {live ? "tropa chegando" : "base fechada"}
              </span>
            ) : (
              <OnlineList online={online} onSelectUser={onSelectUser} withSignal />
            )}
          </div>
        </div>
      </aside>

      <nav className="bottom-nav" aria-label="Menu">
        <NavButtons view={view} onNavigate={onNavigate} />
        <button type="button" className="icon-btn" onClick={onLogout} aria-label="Sair">
          <LogOut size={18} />
        </button>
      </nav>
    </div>
  );
}
