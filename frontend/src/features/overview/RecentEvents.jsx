import { Radio } from "lucide-react";
import Avatar from "../../shared/Avatar";
import { formatTime } from "../../shared/format";
import { describeEvent } from "../events/describeEvent";
import { eventVisual } from "../events/eventVisuals";

// Field logs: as três interações mais recentes, em placas oliva.
export default function RecentEvents({ events, onSelectUser, onSeeAll }) {
  const items = events.slice(0, 3);

  if (items.length === 0) {
    return (
      <div className="list-cards">
        <div className="list-card olive" style={{ cursor: "default" }}>
          <span className="list-card-icon tone-redemption">
            <Radio size={24} />
          </span>
          <span className="list-card-body">
            <span className="list-card-title">Rádio em silêncio</span>
            <span className="list-card-sub">Subs, bits e resgates aparecem aqui</span>
          </span>
        </div>
      </div>
    );
  }

  return (
    <div className="list-cards">
      {items.map((e) => {
        const { Icon, tone } = eventVisual(e.type);
        const { action, detail } = describeEvent(e);
        return (
          <button
            key={e.id}
            type="button"
            className="list-card olive"
            onClick={() => (e.user_id ? onSelectUser(e.user_id) : onSeeAll())}
          >
            <span className="list-card-avatar">
              <Avatar src={e.avatar} name={e.user_name} size={46} />
            </span>
            <span className="list-card-body">
              <span className="list-card-title">{e.user_name}</span>
              <span className="list-card-sub">
                {action}
                {detail ? ` · ${detail}` : ""} · {formatTime(e.occurred_at)}
              </span>
            </span>
            <span className={`list-card-icon ${tone}`} aria-hidden="true">
              <Icon size={24} />
            </span>
          </button>
        );
      })}
    </div>
  );
}
