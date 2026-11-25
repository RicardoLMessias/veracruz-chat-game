import { Clock, Eye } from "lucide-react";
import Avatar from "../../shared/Avatar";
import { formatNumber } from "../../shared/format";
import { useNow } from "../../shared/useNow";

function formatUptime(startedAt, now) {
  const total = Math.max(0, Math.floor((now - Date.parse(startedAt)) / 1000));
  const h = Math.floor(total / 3600);
  const m = Math.floor((total % 3600) / 60);
  const s = total % 60;
  const pad = (n) => String(n).padStart(2, "0");
  return `${h}:${pad(m)}:${pad(s)}`;
}

// Card de destaque em moldura de metal: prévia da live de fundo, categoria, título e tropa no chat.
export default function LiveHero({ status, channel, online, onSelectUser }) {
  const data = status.data;
  const live = Boolean(data?.live);
  const now = useNow(live);
  const stack = online.slice(0, 4);
  const extra = online.length - stack.length;

  return (
    <section className="hero metal" aria-label="Status da live">
      <div className="metal-inner">
        {live && data.thumbnail_url ? (
          <img
            className="hero-art"
            src={`${data.thumbnail_url}?t=${encodeURIComponent(data.fetched_at)}`}
            alt=""
          />
        ) : null}
        <div className="hero-shade" aria-hidden="true" />
        {!live && channel.profile_image_url && <img className="hero-avatar" src={channel.profile_image_url} alt="" />}

        <div className="hero-top">
          {data?.game_name && <span className="chip">{data.game_name}</span>}
          <span className={`chip ${live ? "chip-live" : ""}`}>
            <span className={`live-dot ${live ? "" : "off"}`} aria-hidden="true" />
            {live ? "Ao vivo" : "Offline"}
          </span>
        </div>

        <div className="hero-body">
          <h2 className="hero-title">{data?.title || (status.loading ? "Carregando…" : "Sem título definido")}</h2>
          <p className="hero-sub">
            {live ? `twitch.tv/${channel.login}` : "A live está desligada. Os números abaixo são da última live."}
            {status.error && ` · ${status.error}`}
          </p>
        </div>

        <div className="hero-bottom">
          {live && (
            <div className="hero-stats">
              <div className="hero-stat">
                <span>
                  <Eye size={13} aria-hidden="true" /> Espectadores
                </span>
                <strong>{formatNumber(data.viewer_count)}</strong>
              </div>
              <div className="hero-stat">
                <span>
                  <Clock size={13} aria-hidden="true" /> No ar
                </span>
                <strong>{formatUptime(data.started_at, now)}</strong>
              </div>
            </div>
          )}

          {stack.length > 0 && (
            <div className="avatar-stack" style={{ marginLeft: live ? "auto" : 0 }}>
              {stack.map((c) => (
                <button
                  key={c.id}
                  type="button"
                  className="avatar-btn"
                  onClick={() => onSelectUser(c.id)}
                  title={c.display_name}
                >
                  <Avatar src={c.avatar} name={c.display_name} size={42} ring />
                </button>
              ))}
              <span className="chip" style={{ marginLeft: 12, fontSize: "1rem" }}>
                {extra > 0 ? `+${extra} no chat` : `${online.length} no chat`}
              </span>
            </div>
          )}
        </div>
      </div>
    </section>
  );
}
