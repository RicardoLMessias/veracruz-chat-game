import { ArrowBigUpDash, Gem, Gift, Star, Ticket } from "lucide-react";

// Ícone e cor de cada tipo de interação (classes .tone-* no CSS).
const VISUALS = {
  sub: { Icon: Star, tone: "tone-sub" },
  resub: { Icon: Star, tone: "tone-sub" },
  gift: { Icon: Gift, tone: "tone-gift" },
  bits: { Icon: Gem, tone: "tone-bits" },
  redemption: { Icon: Ticket, tone: "tone-redemption" },
  upgrade: { Icon: ArrowBigUpDash, tone: "tone-upgrade" },
};

export function eventVisual(type) {
  return VISUALS[type] ?? VISUALS.redemption;
}
