import HealthNotice from "../chat/HealthNotice";
import Highlights from "./Highlights";
import LatestArrival from "./LatestArrival";
import LiveHero from "./LiveHero";
import RecentEvents from "./RecentEvents";
import StatsRing from "./StatsRing";

export default function OverviewView({
  overview,
  liveStatus,
  channel,
  chatters,
  online,
  totalWatchSeconds,
  onSelectUser,
  onNavigate,
}) {
  const data = overview.data;

  return (
    <div className="overview">
      <div className="overview-main">
        <LiveHero status={liveStatus} channel={channel} online={online} onSelectUser={onSelectUser} />

        {data && <HealthNotice health={data.health} live={data.live} />}

        <section className="section">
          <div className="section-head">
            <h2>Destaques do pelotão // Squad MVPs</h2>
            <button type="button" className="section-link" onClick={() => onNavigate("chat")}>
              Ver todos
            </button>
          </div>
          <Highlights chatters={chatters} onSelectUser={onSelectUser} />
        </section>

        <section className="section">
          <div className="section-head">
            <h2>Recruta mais recente // New recruit</h2>
            <button type="button" className="section-link" onClick={() => onNavigate("chat")}>
              Ver todos
            </button>
          </div>
          <LatestArrival chatters={chatters} onSelectUser={onSelectUser} />
        </section>
      </div>

      <div className="overview-side">
        <section className="section">
          <div className="section-head">
            <h2>Estatísticas de guerra // War stats</h2>
          </div>
          {data ? (
            <StatsRing
              totals={data.totals}
              totalWatchSeconds={totalWatchSeconds}
              live={data.live}
              onlineCount={online.length}
            />
          ) : (
            <div className="card muted">{overview.error ?? "Carregando…"}</div>
          )}
        </section>

        <section className="section">
          <div className="section-head">
            <h2>Interações de campo // Field logs</h2>
            <button type="button" className="section-link" onClick={() => onNavigate("events")}>
              Ver todas
            </button>
          </div>
          <RecentEvents events={data?.events ?? []} onSelectUser={onSelectUser} onSeeAll={() => onNavigate("events")} />
        </section>
      </div>
    </div>
  );
}
