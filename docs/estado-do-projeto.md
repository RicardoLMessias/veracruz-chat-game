# Estado do projeto — Vera Cruz Live (twitch-dashboard)

Resumo para retomar o trabalho numa conversa nova. Atualizado em 22/11/2026.

## Onde está tudo
- Projeto: `C:\Users\ricar\OneDrive\Desktop\trabalhos\twitch-dashboard`
  - `backend-laravel/` — Laravel 13, PHP 8.5, MySQL 8 (banco `twitch_dashboard`, usuário root; senha no `.env`)
  - `frontend/` — React + Vite (porta 5173), ícones lucide-react
  - `scripts/tunnel.mjs` — abre o túnel Cloudflare e registra o webhook sozinho
  - `docs/README.md` — especificação original do Vera Cruz Live
  - `docs/economia-e-overlay.md` — regras de XP, moedas e overlay combinadas (vale sobre a especificação)
- Canvas no Obsidian: `E:\ADS\veracruz live\Vera Cruz Live - Sistema.canvas` (o original do usuário é `canvas vera cruz.canvas`)
- Canal: tiodark94 (Afiliado). Amigo que ajuda nos testes: rwyvern_. Sub ativo: junho_tv (Tier 1).

## Como rodar
Na raiz `twitch-dashboard`: `npm run dev` → sobe Laravel (api), agendador (agenda), fila, Vite (web) e túnel.
O túnel espera tudo ficar acessível e roda `php artisan twitch:subscribe --url=...`.
Pronto quando aparece "[tunel] Webhook registrado na Twitch. Tudo pronto." Fechar com Ctrl+C.
Testes: `cd backend-laravel && php artisan test` (87 passando).

## O que já funciona
- Login OAuth com a Twitch; tokens criptografados, renovação e validação de hora em hora.
- Tela de configuração do Client ID/Secret no próprio dashboard (salvos criptografados em `app_settings`).
- EventSub por **webhook** (rota `POST /twitch/eventsub`, assinatura HMAC, `events_log`, fila `ProcessTwitchEvent`).
  Pelo túnel só o webhook responde (middleware `OnlyWebhookThroughTunnel`, cabeçalho CF-Ray).
  O antigo listener WebSocket (`twitch:listen`) ainda existe no código, mas não deve rodar junto.
- Scopes: user:read:email user:read:chat moderator:read:chatters channel:read:redemptions bits:read user:bot channel:bot channel:read:subscriptions.
- Presença: `twitch:track-chatters` a cada 15s (`CHAT_PRESENCE_INTERVAL`). Tolerância 35s (2 checagens + 5s).
  Saiu → tempo para; voltou → continua. Entradas/saídas em `presence_segments`.
  Bots (`TWITCH_IGNORED_LOGINS`) e a conta do canal ficam fora (`TWITCH_COUNT_BROADCASTER=false`).
- Interações por pessoa em `channel_events`: mensagens (só contagem, sem texto), sub/Prime/resub (via `channel.chat.notification`), presentes dados e recebidos, upgrades, bits (`channel.cheer`), resgates.
- Fotos de perfil buscadas em lote (`ChatterProfiles`).
- Economia (`settings` com 24 valores editáveis, `transactions` = livro-caixa, `EconomyService::grant()`):
  - XP de tudo: tempo 2/min, mensagem 5 (máx. 3/min), resgate 10 (máx. 3/min), sub 500, presente dado 300/sub, 100 bits = 30.
  - Moedas só de sub 300, presente 200/sub, 100 bits = 15 (missões e pontos do canal ainda não existem).
  - Multiplicador de sub em tudo (T1/Prime 1,25 · T2 1,5 · T3 2); ativo no chat ×1,5 e sequência (+10%/live até +50%) só no XP de presença.
  - Níveis: `100 × n^1,5`; títulos Andarilho/Sobrevivente/Batedor/Veterano das Ruínas/Lenda de Vera Cruz.
  - `economy:presence` a cada minuto; `twitch:sync-subs` de hora em hora. Sem pagamento retroativo (decisão do usuário).
- Dashboard tema militar (o tema ferrugem foi rejeitado e removido): Visão geral (hero da live, Estatísticas de guerra ACIMA de Field logs, Squad MVPs, New recruit), Chat, Interações, Ranking da tropa (XP/moedas/…), Gerenciar economia (resumo, ajuste manual, zerar com "ZERAR" — lança linha "zerado", não apaga histórico — e editor de settings). Ficha da pessoa com nível, barra de XP, moedas, sequência, entradas/saídas e livro-caixa.

## Preferências do usuário
- Fala português. Quer textos naturais, sem tom de trailer/"revolucionário".
- Não copiar arte de jogos existentes (tanque/soldados da referência); só o estilo.
- Grava vídeos: nada sensível na tela (.env, console da Twitch, endereço do túnel, Detalhes da conexão).

## Próximas fases (ordem sugerida)
1. Pontos do canal → moedas (recompensas "Converter 1.000 pts = 100 moedas" e "Check-in", criadas pelo sistema; precisa `channel:manage:redemptions`).
2. Missões (falta o usuário definir quais, período e se o multiplicador vale).
3. Bot no chat (!moedas, !nivel, !rank, !tempo; anúncio de nível).
4. Comandos de combate (!ataque, !defesa, !esquiva) e overlay de combate (bits = especial, sub = supremo, pontos = itens).
5. Publicação na HostGator (webhook sem túnel; cron para o agendador e a fila).
Pendências pequenas: definir se o limite de 3 resgates/min vale também para itens do overlay; prévia da curva de níveis na aba de economia.
