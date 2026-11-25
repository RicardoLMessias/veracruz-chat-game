// Avisa quando algum processo de fundo parou (webhook, fila ou checagem de presença).
export default function HealthNotice({ health, live }) {
  const problems = [];
  if (!health.webhook) {
    problems.push([
      "php artisan twitch:subscribe",
      "webhook inativo: confira o túnel e rode o comando (mensagens, subs, bits, resgates)",
    ]);
  }
  if (!health.queue) problems.push(["php artisan queue:work", "eventos recebidos esperando processamento"]);
  if (!health.tracker) problems.push(["php artisan schedule:work", "quem está online e o tempo no chat"]);
  if (problems.length === 0) return null;

  return (
    <div className="notice" role="status">
      {live ? "Algo não está sendo registrado." : "Quando a live começar, isto precisa estar funcionando:"}
      <ul className="notice-list">
        {problems.map(([cmd, what]) => (
          <li key={cmd}>
            <code>{cmd}</code> — {what}
          </li>
        ))}
      </ul>
    </div>
  );
}
