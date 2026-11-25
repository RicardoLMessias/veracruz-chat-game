# Twitch Dashboard

Dashboard local do canal da Twitch: frontend React + Vite e API Laravel com MySQL,
na mesma estrutura do IS Coach Treinamento.

## Estrutura

- `frontend/`: interface React + Vite (`src/app`, `features`, `pages`, `services`, `shared`, `hooks`, `styles`).
- `backend-laravel/`: API Laravel 13 com Sanctum. Cuida do OAuth da Twitch, dos tokens e do acesso ao MySQL.
- `docs/`: decisões e documentação futura.

## Requisitos

- PHP 8.3+ com as extensões `pdo_mysql` e `mysqli` ativadas no `php.ini`
- Composer
- Node 20+
- MySQL rodando em `127.0.0.1:3306`, com o banco `twitch_dashboard` criado

## Desenvolvimento

### Tudo com um comando

Na pasta raiz do projeto (`twitch-dashboard`):

```bash
npm install   # só na primeira vez
npm run dev
```

Sobe junto, num terminal só: Laravel (`api`), agendador (`agenda`), fila (`fila`), Vite (`web`) e o túnel
(`tunel`). O túnel espera o Laravel e o endereço novo ficarem acessíveis e roda sozinho o
`php artisan twitch:subscribe --url=...`. Quando aparecer `[tunel] Webhook registrado na Twitch. Tudo pronto.`,
está tudo no ar. `Ctrl+C` desliga tudo.

> Gravando? Não mostre esse terminal: ele imprime o endereço do túnel.

### Backend Laravel (primeira instalação)

```bash
cd backend-laravel
composer install
cp .env.example .env   # só na primeira vez; depois preencha DB_* e TWITCH_*
php artisan key:generate
php artisan migrate
php artisan serve
```

### Frontend

```bash
cd frontend
npm install
npm run dev
```

Acesse `http://localhost:5173`. Em desenvolvimento, o Vite encaminha `/api`, `/auth` e `/sanctum`
para o Laravel em `http://127.0.0.1:8000`. O callback do OAuth da Twitch passa pelo
mesmo endereço do frontend, então o cookie de sessão fica na mesma origem.

## Twitch

Na primeira vez que você abre `http://localhost:5173`, o dashboard mostra a tela **Conectar app da Twitch**:

1. Crie uma aplicação em https://dev.twitch.tv/console.
2. Em OAuth Redirect URLs, cadastre `http://localhost:5173/auth/twitch/callback` (a tela tem botão de copiar).
3. Cole o Client ID e o Client Secret na tela. O backend confere com a Twitch antes de salvar e guarda tudo
   criptografado na tabela `app_settings`.

Depois de salvo, só um usuário logado pode trocar as credenciais. Como alternativa, ainda dá para usar
`TWITCH_CLIENT_ID` e `TWITCH_CLIENT_SECRET` no `backend-laravel/.env`; o que foi salvo pela tela tem prioridade.

Abra sempre por `http://localhost:5173` (não `127.0.0.1`); o front redireciona sozinho se precisar.

### Como o login funciona

1. "Logar com Twitch" abre `/auth/twitch/redirect`. O Laravel gera um `state`, guarda na sessão e manda para a Twitch.
2. Você autoriza e a Twitch volta em `/auth/twitch/callback?code=...&state=...`.
3. O Laravel confere o `state`, troca o `code` pelos tokens, busca o canal em `/helix/users` e grava tudo em
   `twitch_accounts`. Os tokens ficam criptografados com a `APP_KEY`.
4. A sessão dura 7 dias (`SESSION_LIFETIME`). O React consulta `GET /api/me`, que renova o token perto de
   expirar e valida na Twitch a cada hora.
5. "Sair" chama `POST /api/auth/logout`, que revoga o token na Twitch e encerra a sessão.

### Processos que ficam rodando (local)

Além do `php artisan serve` e do `npm run dev`, deixe abertos (em `backend-laravel`, cada um no seu terminal):

```bash
php artisan schedule:work   # a cada 15s: quem está no chat; a cada 5 min: confere o webhook; a cada hora: token
php artisan queue:work      # processa na hora os eventos que chegam pelo webhook
cloudflared tunnel --url http://127.0.0.1:8000   # túnel HTTPS para a Twitch alcançar o webhook
```

Sempre que o túnel reiniciar, o endereço muda. Rode com o endereço novo:

```bash
php artisan twitch:subscribe --url=https://NOVO-ENDERECO.trycloudflare.com
php artisan twitch:subscribe --status   # só confere
```

Pelo túnel, só `POST /twitch/eventsub` responde; dashboard, API e login continuam só no localhost.
O painel avisa quando o webhook, a fila ou o agendador param.

### Economia (XP, nível e moedas)

Regras em [docs/economia-e-overlay.md](docs/economia-e-overlay.md). Todos os valores ficam na tabela `settings`
(mudanças no banco valem em até 60s). Toda movimentação passa por `EconomyService::grant()`, que grava em
`transactions` (livro-caixa) e atualiza o saldo em `chatters` — o saldo sempre bate com a soma do livro-caixa.

- `economy:presence` (agendador, a cada minuto): XP do tempo no chat, com sequência, sub e "ativo no chat".
- `twitch:sync-subs` (agendador, de hora em hora): alinha o tier de sub com a Twitch (precisa de
  `channel:read:subscriptions`).
- Mensagens, resgates, subs, presentes e bits pagam na hora, quando o evento chega.

### Eventos (EventSub por webhook)

A Twitch faz `POST /twitch/eventsub`. O Laravel confere a assinatura HMAC com `TWITCH_EVENTSUB_SECRET`, rejeita
mensagens com mais de 10 minutos, grava o evento bruto em `events_log` (o `message_id` único impede processar
reenvios) e o job `ProcessTwitchEvent` aplica na fila. As inscrições usam o App Access Token; eventos de chat
exigem também os scopes `user:bot` e `channel:bot`.

O antigo listener por WebSocket (`php artisan twitch:listen`) continua no código como alternativa, mas não deve rodar
junto com o webhook (contaria os eventos em dobro).

### Chat

- **Quem está online:** a lista vem de `/helix/chat/chatters` a cada `CHAT_PRESENCE_INTERVAL` segundos (padrão 15).
  Só aparecem contas logadas, e a própria Twitch demora alguns minutos para pôr ou tirar alguém da lista.
- **Tempo no chat:** a Twitch não informa a hora de entrada. A cada checagem, quem está na lista ganha o tempo exato
  desde a última vez em que foi visto. Mensagens também contam como "visto agora". Até 90s sem aparecer ainda conta
  como presença contínua (a lista oscila); mais que isso conta como saiu e voltou. Só conta durante a live.
  No painel, o tempo de quem está online sobe segundo a segundo entre as checagens.
- **Bots e o próprio canal:** ficam fora da presença, da contagem de mensagens, da tabela do chat e do ranking.
  A lista está em `TWITCH_IGNORED_LOGINS` (vírgula). Para a sua conta contar, use `TWITCH_COUNT_BROADCASTER=true`.
  Subs, bits e resgates feitos por essas contas continuam no feed de interações.
- **Mensagens:** só a contagem por pessoa (o texto não é salvo), via `channel.chat.message`, durante a live.
- **Subs, Prime, resubs, presentes e upgrades:** `channel.chat.notification` (único evento que informa Prime).
- **Bits:** `channel.cheer`. **Resgates:** `channel.channel_points_custom_reward_redemption.add`.

Para adicionar permissões (seguidores, subs, bits...), inclua os scopes em `TWITCH_SCOPES`, separados por espaço,
e faça login de novo.

## Testes

```bash
cd backend-laravel
php artisan test
```

## Segurança

- Segredos ficam somente em `backend-laravel/.env`, nunca no frontend.
- Variáveis `VITE_*` são públicas e não devem conter credenciais.
- Os tokens da Twitch são guardados e renovados apenas pelo backend.
