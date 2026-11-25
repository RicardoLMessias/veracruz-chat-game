import { useState } from "react";
import Avatar from "../../shared/Avatar";
import { formatTime } from "../../shared/format";
import { describeEvent, EVENT_TYPES, FILTER_TYPES } from "./describeEvent";
import { eventVisual } from "./eventVisuals";

export function UserLink({ id, name, onSelectUser }) {
  if (!id) return <strong>{name}</strong>;
  return (
    <button type="button" className="link-btn" onClick={() => onSelectUser(id)}>
      {name}
    </button>
  );
}

export function EventItem({ event, onSelectUser, showUser = true, showDate = false }) {
  const { action, detail } = describeEvent(event);
  const { Icon, tone } = eventVisual(event.type);
  const when = showDate
    ? new Date(event.occurred_at).toLocaleString("pt-BR", { dateStyle: "short", timeStyle: "short" })
    : formatTime(event.occurred_at);

  return (
    <li className="event">
      <span className={`event-icon ${tone}`} aria-hidden="true">
        <Icon size={20} />
      </span>
      {showUser && <Avatar src={event.avatar} name={event.user_name} size={34} />}
      <div className="feed-main">
        {showUser && <UserLink id={event.user_id} name={event.user_name} onSelectUser={onSelectUser} />} {action}
        {event.user_input && <p className="feed-input">“{event.user_input}”</p>}
      </div>
      <div className="feed-meta">
        {detail && <strong>{detail}</strong>}
        <span className="faint">{when}</span>
      </div>
    </li>
  );
}

// Tela "Interações": feed com filtros por tipo.
export default function EventsFeed({ events, onSelectUser }) {
  const [filter, setFilter] = useState("all");
  const visible = filter === "all" ? events : events.filter((e) => FILTER_TYPES[filter].includes(e.type));

  return (
    <section className="section">
      <div className="section-head">
        <h2>Interações de campo // Field logs</h2>
        <div className="segmented" role="tablist">
          {EVENT_TYPES.map((t) => (
            <button
              key={t.id}
              type="button"
              role="tab"
              aria-selected={filter === t.id}
              className={filter === t.id ? "active" : ""}
              onClick={() => setFilter(t.id)}
            >
              {t.label}
            </button>
          ))}
        </div>
      </div>

      {visible.length === 0 ? (
        <p className="empty">Nenhuma interação registrada ainda.</p>
      ) : (
        <ul className="feed">
          {visible.map((e) => (
            <EventItem key={e.id} event={e} onSelectUser={onSelectUser} />
          ))}
        </ul>
      )}
    </section>
  );
}
