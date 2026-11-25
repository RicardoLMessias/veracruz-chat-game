import { Gem, MessageSquare, Star } from "lucide-react";
import { formatNumber } from "../../shared/format";

function formatRadar(seconds) {
  const total = Math.max(0, Math.floor(seconds));
  const h = Math.floor(total / 3600);
  const m = Math.floor((total % 3600) / 60);
  return h > 0 ? `${h}H${String(m).padStart(2, "0")}` : `${m}MIN`;
}

// Arco de um círculo (graus, 0 = topo, sentido horário).
function arc(cx, cy, r, from, to) {
  const rad = (deg) => ((deg - 90) * Math.PI) / 180;
  const x1 = cx + r * Math.cos(rad(from));
  const y1 = cy + r * Math.sin(rad(from));
  const x2 = cx + r * Math.cos(rad(to));
  const y2 = cy + r * Math.sin(rad(to));
  const large = to - from > 180 ? 1 : 0;
  return `M ${x1} ${y1} A ${r} ${r} 0 ${large} 1 ${x2} ${y2}`;
}

// Radar: verde = parte da tropa da live que está online agora; vermelho = quem já saiu.
function Radar({ seconds, onlineShare, live }) {
  const green = Math.round(Math.min(1, Math.max(0, onlineShare)) * 360);
  const g = Math.min(green, 359.9);

  return (
    <div className="radar">
      <svg viewBox="0 0 200 200" aria-hidden="true">
        <defs>
          <filter id="glow" x="-50%" y="-50%" width="200%" height="200%">
            <feGaussianBlur stdDeviation="3" result="b" />
            <feMerge>
              <feMergeNode in="b" />
              <feMergeNode in="SourceGraphic" />
            </feMerge>
          </filter>
          <radialGradient id="radarBg" cx="50%" cy="50%" r="50%">
            <stop offset="0%" stopColor="#3a0b0a" />
            <stop offset="70%" stopColor="#1a0606" />
            <stop offset="100%" stopColor="#0b0f14" />
          </radialGradient>
          <linearGradient id="sweepGrad" x1="0" y1="0" x2="1" y2="0">
            <stop offset="0%" stopColor="rgba(109,255,120,0)" />
            <stop offset="100%" stopColor="rgba(109,255,120,0.35)" />
          </linearGradient>
        </defs>

        <circle cx="100" cy="100" r="74" fill="url(#radarBg)" />
        <circle cx="100" cy="100" r="58" fill="none" stroke="rgba(229,50,45,0.35)" strokeWidth="1" strokeDasharray="3 5" />
        <circle cx="100" cy="100" r="40" fill="none" stroke="rgba(229,50,45,0.25)" strokeWidth="1" />

        {live && (
          <g className="radar-sweep">
            <path d="M100 100 L100 26 A74 74 0 0 1 152 47 Z" fill="url(#sweepGrad)" />
          </g>
        )}

        {/* Anel principal: vermelho por baixo, verde por cima na proporção de quem está online */}
        <circle cx="100" cy="100" r="82" fill="none" stroke="#e5322d" strokeWidth="11" filter="url(#glow)" />
        {g > 0 && (
          <path d={arc(100, 100, 82, -g / 2, g / 2)} fill="none" stroke="#45d14f" strokeWidth="11" filter="url(#glow)" />
        )}

        {/* Arcos externos decorativos */}
        <path d={arc(100, 100, 96, 200, 330)} fill="none" stroke="#45d14f" strokeWidth="4" strokeLinecap="round" filter="url(#glow)" />
        <path d={arc(100, 100, 96, 30, 160)} fill="none" stroke="#e5322d" strokeWidth="4" strokeLinecap="round" filter="url(#glow)" />
      </svg>
      <div className="radar-center">
        <strong>{formatRadar(seconds)}</strong>
        <span>{live ? "tempo do chat" : "última live"}</span>
      </div>
    </div>
  );
}

// Estatísticas de guerra: radar com o tempo somado do chat, painel de metal e caixas de munição.
export default function StatsRing({ totals, totalWatchSeconds, live, onlineCount }) {
  const share = live && totals.chatters > 0 ? onlineCount / totals.chatters : 0;

  return (
    <section className="stats-card" aria-label="Estatísticas da live">
      <Radar seconds={totalWatchSeconds} onlineShare={share} live={live} />

      <div className="plate metal">
        <div className="plate-row">
          <div className="plate-cell">
            <MessageSquare size={30} color="#f5f3ea" fill="#f5f3ea" fillOpacity={0.15} aria-hidden="true" />
            <strong>{formatNumber(totals.messages)}</strong>
            <span>Mensagens</span>
          </div>
          <div className="plate-cell">
            <Star size={30} color="#f4c430" fill="#f4c430" aria-hidden="true" />
            <strong>{formatNumber(totals.subs)}</strong>
            <span>Subs</span>
          </div>
          <div className="plate-cell">
            <Gem size={30} color="#e5322d" fill="#e5322d" fillOpacity={0.35} aria-hidden="true" />
            <strong>{formatNumber(totals.bits)}</strong>
            <span>Bits</span>
          </div>
        </div>
      </div>

      <div className="ammo-row">
        <div className="ammo metal">
          <div className="metal-inner">
            <strong>{formatNumber(totals.chatters)}</strong>
            <span>Pessoas</span>
          </div>
        </div>
        <div className="ammo metal">
          <div className="metal-inner">
            <strong>{formatNumber(totals.redemptions)}</strong>
            <span>Resgates</span>
          </div>
        </div>
        <div className="ammo metal">
          <div className="metal-inner">
            <strong>{formatNumber(totals.gifts)}</strong>
            <span>Presentes</span>
          </div>
        </div>
      </div>
    </section>
  );
}
