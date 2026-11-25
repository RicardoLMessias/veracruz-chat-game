# Economia e overlay — regras definidas

Complementa a especificação em [README.md](README.md). **Onde as duas divergirem, vale este documento.**
Todos os números abaixo são valores iniciais e ficam na tabela `settings` (ajustáveis sem mexer no código).

> Status: regras combinadas em 22/11/2026. Itens marcados com **[a definir]** ainda precisam de decisão.

---

## 1. De onde vem cada coisa

### XP (progresso e nível) — vem de **todas as interações**

| Fonte | XP | Regra |
|---|---|---|
| Tempo online no chat | 2 por minuto | proporcional ao tempo exato (checagem a cada 15s); só durante a live; parado quando a pessoa sai |
| Mensagem no chat | 5 por mensagem | **anti-spam:** no máximo 3 mensagens por minuto dão XP; as outras contam na estatística, mas não dão XP |
| Comando do jogo (`!atacar` etc.) | 3 por comando | respeita o cooldown do comando |
| Resgate de pontos do canal | 10 por resgate | qualquer recompensa; **anti-spam:** no máximo 3 resgates por minuto dão XP (os outros são registrados, mas não dão XP) |
| Sub nova / resub | 500 | Prime conta como Tier 1 |
| Sub recebida de presente | 500 | para quem recebe |
| Sub dada de presente | 300 por sub | para quem deu; anônimo não ganha |
| Bits | 30 a cada 100 bits | proporcional; anônimo não ganha |

### Moedas (para gastar) — **raras**

| Fonte | Moedas | Regra |
|---|---|---|
| Missões | definido por missão | ver seção 3 **[a definir]** |
| Resgate "Converter em moedas" (pontos do canal) | 100 por resgate de 1.000 pontos | recompensa criada pelo sistema |
| Sub nova / resub | 300 | |
| Sub recebida de presente | 300 | |
| Sub dada de presente | 200 por sub | anônimo não ganha |
| Bits | 15 a cada 100 bits | proporcional; anônimo não ganha |

**Tempo online e mensagens não dão moedas.** (Diferente da especificação original, que dava moedas por presença.)

### Multiplicador de sub — vale para **tudo** que a pessoa ganha (XP e moedas)

| Situação | Multiplicador |
|---|---|
| Não sub | ×1 |
| Tier 1 ou Prime | ×1,25 |
| Tier 2 | ×1,5 |
| Tier 3 | ×2 |

Sub ganha **as duas coisas**: o bônus na hora (tabelas acima) **e** o multiplicador em tudo enquanto for sub.
O tier vem da última sub/resub/presente recebido; volta a ×1 quando a sub acaba (`channel.subscription.end`).

Os outros multiplicadores da especificação (sequência de lives, ativo no chat) continuam valendo **somente para o XP
de presença** e se multiplicam com o de sub. Valores quebrados usam o arredondamento estocástico.

---

## 2. Overlay de combate

Uma página do sistema aberta no OBS como **fonte de navegador** mostra inimigos na tela. O chat luta junto.

| Ação | Como dispara | Efeito | Também dá |
|---|---|---|---|
| **Ataque normal** | `!atacar` no chat | dano pequeno; cooldown por pessoa **[a definir: 30s?]** | XP de comando |
| **Ataque especial** | bits | dano proporcional aos bits (ex.: 1 de dano por bit) com animação própria | XP e moedas de bits |
| **Ataque supremo** | sub, resub ou presente | golpe enorme com animação especial; presente de 5 subs = 5 golpes | XP e moedas de sub |
| **Itens** | resgate de pontos do canal | poção (cura o grupo), bomba (dano em área), escudo, buff de dano **[a definir: quais itens]** | XP de resgate |

- Cada inimigo tem vida. O dano de cada pessoa fica registrado.
- Ao derrotar o inimigo, quem causou dano divide a recompensa proporcionalmente ao dano **[a definir: XP, moedas ou missão]**.
- Os resgates de "item" precisam ser recompensas **criadas pelo sistema**: só assim ele consegue concluir o resgate
  ou devolver os pontos quando o item não puder ser usado (ex.: sem inimigo na tela).
- Entrega em tempo real: a página consulta o estado do combate a cada ~1s (funciona local e na HostGator, sem
  processo permanente).

---

## 3. Missões **[a definir]**

Fonte principal de moedas. Exemplos para escolher:

| Tipo | Exemplo | Recompensa sugerida |
|---|---|---|
| Da live | ficar 30 minutos na live | 20 moedas |
| Da live | mandar 10 mensagens | 10 moedas |
| Da live | causar 500 de dano no inimigo | 30 moedas |
| Da live | participar da derrota de um chefe | 50 moedas |
| Semanal | estar presente em 3 lives | 100 moedas |
| Sequência | completar 5 lives seguidas | 200 moedas |

Decidir: quais missões, se são fixas ou sorteadas, se renovam por live/dia/semana e se o multiplicador de sub vale.

---

## 4. Já existe no sistema (base para tudo acima)

- Presença por pessoa a cada 15s, com entradas e saídas (`presence_segments`); bots e a conta do canal ficam fora.
- Contagem de mensagens por pessoa e por live.
- Subs (com Prime), resubs, presentes dados e recebidos, upgrades, bits e resgates, por pessoa (`channel_events`).
- Webhook do EventSub com fila; canal é **Afiliado** (subs, bits e pontos do canal disponíveis).
