import { LogIn, Shield } from "lucide-react";
import { TWITCH_LOGIN_URL } from "../services/api";

export default function LoginPage({ error }) {
  return (
    <main className="center">
      <section className="auth-card metal">
        <div className="metal-inner">
          <div className="rail-logo" style={{ marginBottom: 4 }} aria-hidden="true">
            <Shield size={26} strokeWidth={2.4} />
          </div>
          <h1>Base de comando</h1>
          <p>Conecte seu canal para ver o chat, as interações e a tropa. A Twitch vai pedir sua autorização.</p>
          {error && (
            <div className="alert" role="alert">
              {error}
            </div>
          )}
          {/* Navegação de página inteira: o Laravel cria o state e manda para a Twitch. */}
          <a className="btn btn-primary" href={TWITCH_LOGIN_URL}>
            <LogIn size={18} aria-hidden="true" />
            Logar com Twitch
          </a>
        </div>
      </section>
    </main>
  );
}
