import { formatNumber } from "../../shared/format";

const TIERS = { 1000: "Tier 1", 2000: "Tier 2", 3000: "Tier 3" };

export const EVENT_TYPES = [
  { id: "all", label: "Tudo" },
  { id: "sub", label: "Subs" },
  { id: "gift", label: "Presentes" },
  { id: "bits", label: "Bits" },
  { id: "redemption", label: "Resgates" },
];

// Tipos que entram em cada filtro do feed.
export const FILTER_TYPES = {
  sub: ["sub", "resub", "upgrade"],
  gift: ["gift"],
  bits: ["bits"],
  redemption: ["redemption"],
};

export function tierLabel(tier, isPrime) {
  if (isPrime) return "Prime";
  return TIERS[tier] ?? "Sub";
}

// Texto curto da ação, sem o nome da pessoa (o nome é renderizado à parte, clicável).
export function describeEvent(e) {
  switch (e.type) {
    case "redemption":
      return { action: `resgatou ${e.title ?? "uma recompensa"}`, detail: `${formatNumber(e.cost)} pts`, tag: "Resgate" };
    case "sub":
      if (e.is_gift) {
        return {
          action: `ganhou sub de presente${e.related_user_name ? ` de ${e.related_user_name}` : ""}`,
          detail: tierLabel(e.tier, false),
          tag: "Sub presente",
        };
      }
      return {
        action: e.is_prime ? "se inscreveu com Prime" : "se inscreveu",
        detail: tierLabel(e.tier, e.is_prime),
        tag: e.is_prime ? "Prime" : "Sub",
      };
    case "resub":
      return {
        action: `renovou a sub${e.months ? ` · ${e.months} meses` : ""}${e.streak_months ? ` (sequência de ${e.streak_months})` : ""}`,
        detail: tierLabel(e.tier, e.is_prime),
        tag: e.is_prime ? "Prime" : "Resub",
      };
    case "gift":
      return {
        action: e.amount > 1 ? `presenteou ${e.amount} subs` : `presenteou uma sub${e.related_user_name ? ` para ${e.related_user_name}` : ""}`,
        detail: tierLabel(e.tier, false),
        tag: "Presente",
      };
    case "upgrade":
      return { action: `fez upgrade da sub (${e.title})`, detail: tierLabel(e.tier, false), tag: "Upgrade" };
    case "bits":
      return { action: `mandou ${formatNumber(e.amount)} ${e.amount === 1 ? "bit" : "bits"}`, detail: "", tag: "Bits" };
    default:
      return { action: e.type, detail: "", tag: e.type };
  }
}
