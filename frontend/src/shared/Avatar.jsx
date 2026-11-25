import { useState } from "react";

const COLORS = ["#ff7a62", "#a78bfa", "#3ddc84", "#f5c26b", "#ff6fb5", "#5ec8ff", "#ff5a5f"];

function colorFor(name = "") {
  let hash = 0;
  for (const ch of name) hash = (hash * 31 + ch.charCodeAt(0)) >>> 0;
  return COLORS[hash % COLORS.length];
}

// Foto de perfil da Twitch; sem foto (ou se falhar), mostra a inicial numa cor fixa por nome.
export default function Avatar({ src, name = "", size = 40, online, tag, ring = false }) {
  const [broken, setBroken] = useState(false);
  const showImg = src && !broken;

  return (
    <span className={`avatar ${ring ? "avatar-ring" : ""}`} style={{ width: size, height: size }}>
      {showImg ? (
        <img src={src} alt="" loading="lazy" onError={() => setBroken(true)} />
      ) : (
        <span
          className="avatar-fallback"
          style={{ background: colorFor(name), fontSize: Math.max(11, size * 0.4) }}
          aria-hidden="true"
        >
          {name.slice(0, 1).toUpperCase()}
        </span>
      )}
      {online !== undefined && <span className={`avatar-dot ${online ? "" : "off"}`} aria-hidden="true" />}
      {tag && <span className="avatar-tag">{tag}</span>}
    </span>
  );
}
