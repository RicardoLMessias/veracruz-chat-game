# Vera Cruz Live

Sistema da live do canal na Twitch: registra quem assiste, distribui **moedas** e **XP**, controla **níveis**, reage a eventos do canal e tem um **bot** no chat. É a base do futuro **RPG da live "Vera Cruz"** (Brasil pós-apocalíptico), que vai ser construído em cima desta economia.

> Este documento é a especificação do projeto. O código ainda não existe. A ideia é implementar seguindo as fases do fim deste arquivo.

---

## 1. Stack

- **PHP 8.2+ / Laravel 11+**
- **MySQL 8+ ou MariaDB 10.5+**
- **Twitch EventSub via webhook**: a Twitch faz POST numa rota do Laravel a cada evento. Não existe processo rodando o tempo todo.
- **Fila** com driver `database`
- **Scheduler** do Laravel para a checagem de presença
- Cliente HTTP nativo (`Illuminate\Support\Facades\Http`) para a API Helix
- OAuth: `socialiteproviders/twitch` ou implementação manual (authorization code flow)

**Ambientes**
- **Local:** `php artisan serve` + túnel HTTPS (ngrok ou Cloudflare Tunnel) para receber os webhooks, mais `php artisan schedule:work` e `php artisan queue:work`.
- **Produção:** hospedagem compartilhada (HostGator) com domínio e SSL. Um cron a cada minuto roda `php artisan schedule:run`, e a fila é processada pelo scheduler com `queue:work --stop-when-empty`.

---

## 2. Visão geral

```
Twitch ──webhook──▶ POST /twitch/eventsub ──▶ valida assinatura ──▶ events_log ──▶ Job na fila
                                                                                   │
                                                          handler por tipo de evento
                                                          (chat, follow, sub, bits, resgate...)
                                                                                   │
Scheduler (a cada 2 min) ──▶ presence:tick ──▶ Get Chatters ──▶ EconomyService::grant()
                                                                                   │
                                                          transactions (livro-caixa) + viewers (saldo)
                                                                                   │
                                                          ChatService ──▶ Send Chat Message (bot)
```

Princípios:
1. **Toda movimentação de moedas e XP passa por `EconomyService::grant()`**, que grava uma linha em `transactions` e atualiza o saldo em `viewers` dentro de uma transação de banco. O saldo em `viewers` é só um cache do livro-caixa: a soma de `coins_delta` e `xp_delta` de uma pessoa tem que ser sempre igual ao saldo dela.
2. **Todos os valores ajustáveis ficam na tabela `settings`**, nunca fixos no código.
3. **O webhook responde rápido** (a Twitch exige resposta em poucos segundos) e passa o processamento para a fila.

---

## 3. Regras da economia

### Duas moedas
| | Moedas | XP |
|---|---|---|
| Para que serve | gastar (loja, duelos, apostas, RPG) | progresso e status |
| Pode diminuir? | sim | **nunca** (mínimo 0) |
| Define | o que a pessoa pode comprar | nível e título |

Moedas não podem ficar negativas, exceto em ajuste manual de moderador. Gastar sem saldo lança `InsufficientFundsException`.

### Presença (base dos ganhos)
A cada tick (padrão 120 s), para cada pessoa conectada ao chat (Get Chatters):

```
moedas = presence_coins_per_min × minutos × multiplicador
XP     = presence_xp_per_min    × minutos × multiplicador

multiplicador = mult_sub × mult_sequencia × mult_ativo
```

- **mult_sub:** Tier 1 = 1,25 · Tier 2 = 1,5 · Tier 3 = 2 · não sub = 1
- **mult_ativo:** 1,5 se a pessoa mandou mensagem nos últimos 10 minutos (`viewers.last_message_at`), senão 1
- **mult_sequencia:** `1 + min((streak - 1) × 0,10, 0,50)`, ou seja, +10% por live seguida a partir da 2ª, até +50%

**Arredondamento estocástico:** valores quebrados são arredondados de forma aleatória e proporcional (2,3 vira 3 com 30% de chance e 2 com 70%). Na média nenhum bônus pequeno se perde.

```php
function stochasticRound(float $x): int {
    $base = (int) floor($x);
    return $base + ((mt_rand() / mt_getrandmax()) < ($x - $base) ? 1 : 0);
}
```

**Ignorados na presença:** os logins da setting `ignored_bots`, a conta do próprio bot e a conta do streamer (a menos que `count_broadcaster = 1`).

### Sequência de lives (streak)
- Uma live conta para a sequência quando a pessoa acumula `streak_min_minutes` (15) nela.
- Ao contar: se o `last_counted_stream_id` da pessoa for a live **imediatamente anterior**, `current_streak + 1`. Senão, volta para 1. Atualiza `best_streak` e `last_counted_stream_id`, e marca `stream_presence.counted_for_streak = 1`.
- Quando uma live nova abre, zera o `current_streak` de quem não completou a live anterior.
- Lives de teste (`twitch_stream_id` começando com `dev-`) só se encadeiam com outras lives de teste.

### Níveis
- XP para ir do nível *n* ao *n+1* = `level_base × n ^ level_exponent` (padrão `100 × n^1,5`, arredondado)
- O nível é sempre calculado a partir do XP total. Quando o `grant()` detecta subida de nível, o bot anuncia no chat.

| Nível | Título | XP acumulado | ≈ horas (a 120 XP/h) |
|---|---|---|---|
| 1 | Andarilho | 0 | 0 |
| 5 | Sobrevivente | 1.703 | 14 |
| 10 | Batedor | 11.106 | 92 |
| 20 | Veterano das Ruínas | 67.135 | 560 |
| 30 | Lenda de Vera Cruz | 189.029 | 1.575 |

Os títulos ficam na setting `level_titles` (JSON `[[nivel_minimo, "Título"], ...]`).

### Eventos
| Evento | Moedas | XP | Regra |
|---|---|---|---|
| Primeiro follow | 50 | 100 | só se `viewers.followed_at` for nulo (unfollow e follow de novo não dão bônus) |
| Sub novo | 300 | 500 | atualiza `sub_tier`; quem recebe sub de presente também ganha |
| Resub (com mensagem) | 300 | 500 | atualiza `sub_tier` |
| Sub de presente dado | 200 por sub | 300 por sub | para quem deu; anônimo não ganha |
| Bits | 10 a cada 100 | 20 a cada 100 | proporcional, com arredondamento estocástico; anônimo não ganha |
| Fim do sub | — | — | `sub_tier = 0` |
| Raid recebida | — | — | bot agradece no chat |
| Live começou / acabou | — | — | abre / fecha a linha em `streams` |

### Pontos do canal
A API da Twitch **não informa o saldo de pontos do canal** de ninguém. A ponte com o sistema é feita por duas recompensas, **criadas pela API** (só recompensas criadas pelo mesmo Client ID podem ser concluídas ou canceladas pelo sistema). Requer canal Afiliado ou Parceiro.

- **"Converter em 100 moedas"** (1.000 pontos): credita as moedas e marca o resgate como `FULFILLED`.
- **"Check-in em Vera Cruz"** (100 pontos, limite de 1 por pessoa por live na própria Twitch): dá 20 XP e marca `FULFILLED`. Se já existir transação `checkin` dessa pessoa nesta live, ou se não houver live aberta, marca `CANCELED` (a Twitch devolve os pontos).
- Resgates de outras recompensas são só registrados em log.

---

## 4. Banco de dados (migrations)

**`viewers`**: um registro por espectador
- `twitch_id` (string, PK), `login` (index), `display_name`
- `first_seen_at`, `last_seen_at`, `last_message_at` (nullable), `followed_at` (nullable)
- `sub_tier` (tinyint, 0 a 3)
- `total_minutes`, `messages_total`
- `coins` (bigint), `xp` (bigint, index), `level`
- `current_streak`, `best_streak`, `last_counted_stream_id` (nullable)

**`streams`**: cada live
- `id`, `twitch_stream_id` (unique, nullable; lives de teste usam `dev-<timestamp>`)
- `started_at`, `ended_at` (nullable = aberta), `title`, `game_name`, `peak_viewers`

**`stream_presence`**: uma linha por pessoa por live
- PK composta (`stream_id`, `viewer_id`)
- `minutes`, `messages`, `first_seen_at`, `last_seen_at`, `counted_for_streak` (bool)

**`transactions`**: livro-caixa
- `id`, `viewer_id` (FK), `stream_id` (nullable), `type` (index), `coins_delta`, `xp_delta`, `meta` (JSON), `created_at`
- Tipos: `presenca`, `follow`, `sub`, `resub`, `sub_presente_recebido`, `sub_presente_dado`, `bits`, `conversao_pontos`, `checkin`, `ajuste_manual` (e no futuro: `loja`, `duelo`, `expedicao`...)

**`events_log`**: evento bruto recebido da Twitch
- `id`, `message_id` (unique: protege contra reenvio da Twitch), `type`, `payload` (JSON), `created_at`

**`settings`**
- `key` (PK), `value` (text), `description`

**`oauth_tokens`**
- `role` (PK: `broadcaster` | `bot`), `user_id`, `login`, `access_token`, `refresh_token` (ambos criptografados com o cast `encrypted`), `scopes`, `expires_at`

Mais as tabelas padrão da fila (`jobs`, `failed_jobs`).

### Settings padrão (via seeder, sem sobrescrever valores existentes)

| key | valor |
|---|---|
| currency_name | moedas |
| tick_seconds | 120 |
| presence_coins_per_min | 1 |
| presence_xp_per_min | 2 |
| active_window_min | 10 |
| active_multiplier | 1.5 |
| sub_mult_t1 / t2 / t3 | 1.25 / 1.5 / 2 |
| streak_min_minutes | 15 |
| streak_bonus_per_live | 0.10 |
| streak_bonus_max | 0.50 |
| follow_coins / follow_xp | 50 / 100 |
| sub_coins / sub_xp | 300 / 500 |
| gift_coins_per_sub / gift_xp_per_sub | 200 / 300 |
| bits_coins_per_100 / bits_xp_per_100 | 10 / 20 |
| reward_convert_id | (vazio; preenchido ao criar a recompensa) |
| reward_convert_cost / reward_convert_coins | 1000 / 100 |
| reward_checkin_id | (vazio) |
| reward_checkin_cost / reward_checkin_xp / reward_checkin_coins | 100 / 20 / 0 |
| level_base / level_exponent | 100 / 1.5 |
| level_titles | `[[1,"Andarilho"],[5,"Sobrevivente"],[10,"Batedor"],[20,"Veterano das Ruínas"],[30,"Lenda de Vera Cruz"]]` |
| announce_level_up | 1 |
| command_cooldown_sec | 10 |
| count_broadcaster | 0 |
| ignored_bots | nightbot,streamelements,streamlabs,moobot,fossabot,wizebot,soundalerts,sery_bot,kofistreambot,commanderroot |

Leitura via `SettingsService` com `Cache::remember` de 60 s, para que mudanças feitas direto no banco valham sem reiniciar nada.

---

## 5. Integração com a Twitch

### App
Criado em https://dev.twitch.tv/console: categoria Chat Bot, cliente Confidencial, redirect `{APP_URL}/twitch/callback`.

### Tokens
| Token | Como obter | Usado para |
|---|---|---|
| **App Access Token** | client credentials (guardar em cache e renovar quando expirar) | criar inscrições do EventSub por webhook; bot enviar mensagens (ganha o selo de Chat Bot) |
| **Usuário: canal** | OAuth com a conta do streamer | Get Chatters, Get Streams, Get Broadcaster Subscriptions, criar recompensas, atualizar status de resgate |
| **Usuário: bot** | OAuth com a conta do bot | só a autorização (`user:bot`); o envio usa o App Access Token |

**Escopos**
- **Canal:** `channel:bot`, `moderator:read:chatters`, `moderator:read:followers`, `channel:read:subscriptions`, `bits:read`, `channel:read:redemptions`, `channel:manage:redemptions`
- **Bot:** `user:read:chat`, `user:write:chat`, `user:bot`

Depois de autorizar, dar moderador ao bot no canal (`/mod nomedobot`) libera limites maiores de envio.

**Renovação:** em qualquer resposta 401 da API, renovar o token com o refresh token e repetir a chamada uma vez. Validar os tokens de usuário a cada hora (`GET https://id.twitch.tv/oauth2/validate`), como a Twitch exige.

### Webhook (`POST /twitch/eventsub`)
- Rota fora do middleware CSRF.
- **Verificar a assinatura:** `HMAC-SHA256(secret, message_id + timestamp + corpo_bruto)`, comparado com o cabeçalho `Twitch-Eventsub-Message-Signature` (formato `sha256=<hex>`) usando `hash_equals`. Rejeitar com 403 se não bater.
- Rejeitar mensagens com `Twitch-Eventsub-Message-Timestamp` mais antigo que 10 minutos.
- Pelo cabeçalho `Twitch-Eventsub-Message-Type`:
  - `webhook_callback_verification`: responder **200** com o `challenge` em `text/plain`
  - `notification`: gravar em `events_log` (ignorar se o `message_id` já existir), despachar o job e responder **204**
  - `revocation`: registrar em log e responder 204
- O secret fica no `.env` (`TWITCH_EVENTSUB_SECRET`, de 10 a 100 caracteres).

### Inscrições (comando `twitch:subscribe`)
Cria (ou recria) as inscrições com o App Access Token, `transport: { method: "webhook", callback, secret }`:

| Tipo | Versão | Condição |
|---|---|---|
| `channel.chat.message` | 1 | `broadcaster_user_id` = canal, `user_id` = bot |
| `channel.follow` | 2 | `broadcaster_user_id`, `moderator_user_id` = canal |
| `channel.subscribe` | 1 | `broadcaster_user_id` |
| `channel.subscription.message` | 1 | `broadcaster_user_id` |
| `channel.subscription.gift` | 1 | `broadcaster_user_id` |
| `channel.subscription.end` | 1 | `broadcaster_user_id` |
| `channel.cheer` | 1 | `broadcaster_user_id` |
| `channel.raid` | 1 | `to_broadcaster_user_id` |
| `channel.channel_points_custom_reward_redemption.add` | 1 | `broadcaster_user_id` |
| `stream.online` | 1 | `broadcaster_user_id` |
| `stream.offline` | 1 | `broadcaster_user_id` |

O comando lista as inscrições existentes, apaga as que apontam para outro callback (útil quando a URL do túnel muda) e cria as que faltam. Uma falha numa inscrição (ex.: canal sem afiliado não tem pontos do canal) não pode impedir as outras.

### Endpoints da API usados
- `GET /helix/chat/chatters`: paginado (`first=1000`, cursor), `broadcaster_id` e `moderator_id` = canal
- `GET /helix/streams?user_id=`: saber se está ao vivo e o `viewer_count` (pico)
- `GET /helix/subscriptions`: sincronizar `sub_tier` (falha silenciosa se não for afiliado)
- `POST /helix/chat/messages`: `broadcaster_id`, `sender_id` (bot), `message` (máx. 500 caracteres)
- `POST /helix/channel_points/custom_rewards`
- `PATCH /helix/channel_points/custom_rewards/redemptions`: `status` `FULFILLED` ou `CANCELED`
- `GET|POST|DELETE /helix/eventsub/subscriptions`

Em resposta 429, esperar até o horário do cabeçalho `Ratelimit-Reset` e tentar de novo.

---

## 6. Componentes

**Services**
- `TwitchAuthService`: OAuth, salvar, validar e renovar tokens, App Access Token
- `TwitchApiService`: chamadas Helix com renovação em 401 e espera em 429
- `SettingsService`
- `EconomyService`: `grant(viewerId, type, coins, xp, meta, streamId, allowNegative)`. Usa `DB::transaction` e `lockForUpdate` no viewer, grava em `transactions`, recalcula o nível e retorna `{coins, xp, level, leveledUp}`. Também concentra os cálculos de multiplicador de sub, de sequência e o arredondamento estocástico.
- `LevelService`: `xpForNext(n)`, `levelFromXp(xp)` (retorna nível, XP dentro do nível e XP necessário), `titleFor(level)`
- `StreamService`: abrir e fechar live, live atual, live anterior, pico de espectadores, modo de teste
- `ChatService`: enviar mensagem; agrupar anúncios de subida de nível (se forem vários no mesmo tick, uma mensagem só com até 8 nomes)

**Jobs**: um por tipo de evento (ou um `ProcessTwitchEvent` que despacha para handlers)

**Controllers**
- `EventSubController` (webhook)
- `TwitchAuthController`: `/twitch/connect/broadcaster`, `/twitch/connect/bot`, `/twitch/callback`. Só acessível para o dono do sistema (rota protegida, ou só em ambiente local).

**Comandos Artisan**
| Comando | O que faz |
|---|---|
| `presence:tick` | agendado com `->everyTwoMinutes()->withoutOverlapping()`; não faz nada se não houver live aberta |
| `twitch:subscribe` | cria e sincroniza as inscrições do EventSub |
| `twitch:rewards` | cria as duas recompensas e salva os IDs em `settings` |
| `twitch:sync` | sincroniza o estado da live e os subs (rodar na inicialização e de hora em hora) |
| `twitch:validate-tokens` | agendado de hora em hora |

### Tick de presença (`presence:tick`)
1. Se não houver live aberta, sai.
2. Get Chatters (todas as páginas) e remove os ignorados.
3. Atualiza `peak_viewers` com o `viewer_count` do Get Streams.
4. Para cada pessoa, numa transação:
   - upsert em `viewers` (login e nome podem mudar), somando os minutos em `total_minutes` e atualizando `last_seen_at`
   - upsert em `stream_presence`, somando os minutos
   - processa a sequência (seção 3)
   - calcula o multiplicador e chama `grant()` com type `presenca` e meta `{minutes, active, mult}`
5. Anuncia as subidas de nível (agrupadas).
6. Um erro com uma pessoa não pode interromper o processamento das outras.

### Mensagem no chat (`channel.chat.message`)
- Upsert do viewer, `messages_total + 1`, `last_message_at = now()`
- Se houver live aberta: `stream_presence.messages + 1`
- Se começar com `!` e não for o próprio bot: processar comando

---

## 7. Comandos do chat

| Comando | Apelidos | Resposta |
|---|---|---|
| `!moedas [@nome]` | `!pontos` `!saldo` | saldo de moedas |
| `!nivel [@nome]` | `!xp` `!level` | nível, título, XP no nível / XP para o próximo, posição no ranking |
| `!tempo [@nome]` | | tempo total assistido e nesta live (ex.: `12h 30min`) |
| `!streak [@nome]` | | sequência atual e recorde |
| `!rank` | `!ranking` `!top` | top 5 por XP |
| `!comandos` | `!ajuda` | lista os comandos |
| `!dar @nome N [motivo]` | | **mods:** dá ou tira moedas (`ajuste_manual`, meta com quem fez e o motivo) |
| `!darxp @nome N [motivo]` | | **mods:** dá XP |

- Mod = badge `broadcaster` ou `moderator` no evento da mensagem.
- Cooldown de `command_cooldown_sec` por pessoa por comando, guardado em cache (mods não têm cooldown).
- `@nome` desconhecido: "Não conheço @nome ainda."
- Respostas como *reply* à mensagem original (`reply_parent_message_id`) quando fizer sentido.

Exemplos de mensagens do bot:
- `⭐ Ana: nível 4 (Andarilho) — 179/800 XP para o próximo. #1 no ranking.`
- `🎉 Joao subiu para o nível 5 — agora é Sobrevivente!`
- `🎉 Subiram de nível: Ana (Nv 4), Joao (Nv 6), Carla (Nv 2)!`
- `Valeu pelo follow, Ana! Ganhou 50 moedas e 100 XP 🙌`
- `⚔️ Raid de Fulano chegando com 12 pessoas! Sejam bem-vindos a Vera Cruz!`

---

## 8. Modo de teste

`DEV_FORCE_LIVE=true` no `.env`: se o canal estiver offline, o sistema abre uma live de teste (`twitch_stream_id = dev-<timestamp>`) e o tick conta presença normalmente. O chat e o Get Chatters funcionam com o canal offline, então dá para testar tudo com uma segunda conta. Um `stream.offline` não fecha a live de teste.

Recomendação: usar um banco separado para testes (`DB_DATABASE=veracruz_dev`).

---

## 9. Variáveis de ambiente

```
APP_URL=https://seu-tunel-ou-dominio
TWITCH_CLIENT_ID=
TWITCH_CLIENT_SECRET=
TWITCH_EVENTSUB_SECRET=
DEV_FORCE_LIVE=false
QUEUE_CONNECTION=database
DB_*=...
```

---

## 10. Limitações da Twitch (conhecidas)

- Só aparece no Get Chatters quem está **logado e conectado ao chat**. Quem assiste deslogado não conta.
- A lista de chatters tem alguns minutos de atraso para entradas e saídas.
- Não existe API para ler o saldo de pontos do canal nem o tempo assistido. Por isso o sistema calcula o próprio tempo e usa a recompensa de conversão.
- Limite de envio do bot: 1 mensagem por segundo por canal se ele não for mod/VIP. Passar do limite faz a Twitch ignorar o bot por 1 hora.

---

## 11. Fases de implementação

1. **Base:** projeto Laravel, migrations, seeder de settings, `SettingsService`, `LevelService`, `EconomyService` com testes
2. **Autenticação:** rotas OAuth (canal e bot), App Access Token, renovação e validação de tokens
3. **Webhook:** `EventSubController` com verificação de assinatura, `events_log`, fila e o comando `twitch:subscribe`
4. **Live e presença:** `StreamService`, `stream.online`/`offline`, `twitch:sync`, `presence:tick` com sequência e multiplicadores
5. **Eventos:** follow, subs, gift, bits, raid
6. **Bot e comandos:** `ChatService`, comandos, cooldown, anúncios de nível
7. **Pontos do canal:** `twitch:rewards` e o tratamento dos resgates
8. **Depois:** overlay no OBS (página web com ranking e animação de subida de nível), conquistas, site público com perfil e login com a Twitch, e o RPG Vera Cruz (facções, `!explorar`, loja, boss coletivo, PvP, temporadas). Toda nova forma de ganhar ou gastar usa `grant()`.

## 12. Critérios de aceite / testes

- `grant()` mantém saldo = soma do livro-caixa; gastar sem saldo lança exceção e não grava nada; XP nunca fica negativo
- `levelFromXp` bate com a tabela da seção 3 (ex.: 1.703 XP = nível 5; 11.106 XP = nível 10)
- Multiplicadores: não sub inativo = 1; sub Tier 2 ativo com sequência 3 = 1,5 × 1,2 × 1,5 = 2,7
- Sequência: 3 lives seguidas com 15+ min = streak 3; faltar uma live zera; ficar menos de 15 min não conta
- Follow repetido do mesmo usuário não dá bônus
- Check-in repetido na mesma live é cancelado (pontos devolvidos)
- Webhook com assinatura inválida = 403; `message_id` repetido não é processado de novo; verificação responde com o `challenge`
- Bots ignorados, o próprio bot e o streamer (com `count_broadcaster = 0`) não ganham nada
- Comandos de mod são ignorados quando enviados por quem não é mod
- Testes de feature com `Http::fake()` simulando a API da Twitch
