import { Coins, House, MessagesSquare, Sparkles, Trophy } from "lucide-react";

// Telas do dashboard (menu lateral no PC, barra inferior no celular).
export const VIEWS = [
  { id: "overview", label: "Visão geral", icon: House },
  { id: "chat", label: "Chat", icon: MessagesSquare },
  { id: "events", label: "Interações", icon: Sparkles },
  { id: "community", label: "Comunidade", icon: Trophy },
  { id: "economy", label: "Gerenciar economia", icon: Coins },
];
