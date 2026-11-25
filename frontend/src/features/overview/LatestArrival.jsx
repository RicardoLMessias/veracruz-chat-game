import { UserPlus } from "lucide-react";
import Avatar from "../../shared/Avatar";
import { formatDuration, formatTime } from "../../shared/format";

// Recruta mais recente: quem chegou por último no chat.
export default function LatestArrival({ chatters, onSelectUser }) {
  const latest = [...chatters]
    .filter((c) => c.first_seen_at)
    .sort((a, b) => Date.parse(b.first_seen_at) - Date.parse(a.first_seen_at))[0];

  if (!latest) {
    return (
      <div className="wide-card olive">
        <span className="wide-card-icon">
          <UserPlus size={30} />
        </span>
        <div className="wide-card-body">
          <strong>Nenhum recruta ainda</strong>
          <div className="muted">Quem entrar no chat aparece aqui</div>
        </div>
      </div>
    );
  }

  return (
    <div className="wide-card olive">
      <Avatar src={latest.avatar} name={latest.display_name} size={78} online={latest.online} ring />
      <div className="wide-card-body">
        <button type="button" className="link-btn" onClick={() => onSelectUser(latest.id)}>
          <strong>{latest.display_name}</strong>
        </button>
        <div className="muted">
          chegou às {formatTime(latest.first_seen_at)}
          {latest.periods > 1 ? ` · voltou ${latest.periods - 1}x` : ""}
        </div>
      </div>
      <div className="wide-card-meta">
        <strong>{formatDuration(latest.watch_seconds)}</strong>
        no chat
      </div>
    </div>
  );
}
