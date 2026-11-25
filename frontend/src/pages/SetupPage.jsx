import { useState } from "react";
import { apiPost, ApiError } from "../services/api";

function CopyField({ value }) {
  const [copied, setCopied] = useState(false);

  async function copy() {
    try {
      await navigator.clipboard.writeText(value);
      setCopied(true);
      setTimeout(() => setCopied(false), 2000);
    } catch {
      // Sem permissão de área de transferência: o texto continua selecionável.
    }
  }

  return (
    <div className="copy-field">
      <code>{value}</code>
      <button type="button" className="btn btn-ghost btn-sm" onClick={copy}>
        {copied ? "Copiado" : "Copiar"}
      </button>
    </div>
  );
}

export default function SetupPage({ setup, onDone }) {
  const [clientId, setClientId] = useState("");
  const [clientSecret, setClientSecret] = useState("");
  const [saving, setSaving] = useState(false);
  const [errors, setErrors] = useState({});
  const [message, setMessage] = useState(null);

  async function submit(event) {
    event.preventDefault();
    setSaving(true);
    setErrors({});
    setMessage(null);

    try {
      await apiPost("/setup/twitch", {
        client_id: clientId.trim(),
        client_secret: clientSecret.trim(),
      });
      onDone();
    } catch (error) {
      if (error instanceof ApiError && error.status === 422) {
        setErrors(error.body?.errors ?? {});
      } else {
        setMessage(error.message || "Não foi possível salvar.");
      }
    } finally {
      setSaving(false);
    }
  }

  return (
    <main className="center">
      <section className="setup metal">
        <div className="metal-inner">
        <header>
          <h1>Conectar app da Twitch</h1>
          <p className="muted">Só precisa fazer isso uma vez.</p>
        </header>

        <div className="notice">
          Gravando ou em live? Pause antes de abrir o console da Twitch: lá o Client ID aparece e o Secret é
          mostrado ao ser gerado.
        </div>

        <ol className="steps">
          <li>
            Abra o{" "}
            <a href={setup.console_url} target="_blank" rel="noreferrer">
              console de desenvolvedor da Twitch
            </a>{" "}
            e faça login na sua conta.
          </li>
          <li>
            Clique em <strong>Registrar seu aplicativo</strong>. Dê um nome (ex.: “Meu Dashboard”) e em{" "}
            <strong>URLs de redirecionamento OAuth</strong> cole:
            <CopyField value={setup.redirect_uri} />
          </li>
          <li>
            Categoria: <strong>Website Integration</strong>. Tipo de cliente: <strong>Confidencial</strong>.
            Salve.
          </li>
          <li>
            Abra o app criado em <strong>Gerenciar</strong>, copie o <strong>Client ID</strong>, clique em{" "}
            <strong>Novo segredo</strong> e copie o <strong>Client Secret</strong>.
          </li>
        </ol>

        <form className="form" onSubmit={submit} noValidate>
          <label>
            <span>Client ID</span>
            {/* Mascarado como senha para não aparecer em gravação/live. */}
            <input
              type="password"
              value={clientId}
              onChange={(e) => setClientId(e.target.value)}
              autoComplete="off"
              spellCheck="false"
              required
            />
            {errors.client_id && <small className="field-error">{errors.client_id[0]}</small>}
          </label>

          <label>
            <span>Client Secret</span>
            <input
              type="password"
              value={clientSecret}
              onChange={(e) => setClientSecret(e.target.value)}
              autoComplete="off"
              spellCheck="false"
              required
            />
            {errors.client_secret && <small className="field-error">{errors.client_secret[0]}</small>}
          </label>

          {message && (
            <div className="alert" role="alert">
              {message}
            </div>
          )}

          <button type="submit" className="btn btn-primary" disabled={saving || !clientId || !clientSecret}>
            {saving ? "Conferindo com a Twitch…" : "Salvar e continuar"}
          </button>
          <p className="muted small">
            O backend confere as credenciais com a Twitch antes de salvar. O Secret fica criptografado e nunca volta
            para o navegador.
          </p>
        </form>
        </div>
      </section>
    </main>
  );
}
