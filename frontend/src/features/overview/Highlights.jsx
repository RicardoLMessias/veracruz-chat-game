import Avatar from "../../shared/Avatar";
import { formatDuration, formatNumber } from "../../shared/format";

// Destaques do pelotão: quem mais ficou, quem mais falou, quem mais mandou bits e quem mais resgatou.
function pickHighlights(chatters) {
  const by = (key) => [...chatters].filter((c) => c[key] > 0).sort((a, b) => b[key] - a[key])[0];
  const plural = (n, one, many) => `${formatNumber(n)} ${n === 1 ? one : many}`;
  const list = [
    { key: "watch_seconds", label: "Mais tempo", value: (c) => formatDuration(c.watch_seconds) },
    { key: "message_count", label: "Mais falou", value: (c) => plural(c.message_count, "msg", "msgs") },
    { key: "bits", label: "Mais bits", value: (c) => plural(c.bits, "bit", "bits") },
    { key: "redemptions", label: "Mais resgates", value: (c) => plural(c.redemptions, "resgate", "resgates") },
  ];
  return list.map((h) => ({ ...h, person: by(h.key) })).filter((h) => h.person);
}

export default function Highlights({ chatters, onSelectUser }) {
  const items = pickHighlights(chatters);

  if (items.length === 0) {
    return <p className="empty">Os destaques aparecem quando a tropa começar a se mexer no chat.</p>;
  }

  return (
    <div className="carousel">
      {items.map(({ key, label, value, person }) => (
        <button key={key} type="button" className="hl-card metal metal-rivets" onClick={() => onSelectUser(person.id)}>
          <div className="metal-inner">
            <div className="hl-card-top">
              <span className="hl-name">{person.display_name}</span>
              <Avatar src={person.avatar} name={person.display_name} size={54} online={person.online} ring />
            </div>
            <div className="hl-band">{label}</div>
            <div className="hl-value">{value(person)}</div>
          </div>
        </button>
      ))}
    </div>
  );
}
