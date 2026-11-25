import { useAuth } from "./features/auth/useAuth";
import { useSetup } from "./features/setup/useSetup";
import LoginPage from "./pages/LoginPage";
import DashboardPage from "./pages/DashboardPage";
import SetupPage from "./pages/SetupPage";

function Loading() {
  return (
    <main className="center">
      <p className="muted">Carregando…</p>
    </main>
  );
}

export default function App() {
  const setup = useSetup();
  const auth = useAuth();

  if (setup.status === "loading" || auth.status === "loading") return <Loading />;

  if (setup.status === "offline") {
    return <LoginPage error="Backend fora do ar. Rode php artisan serve em backend-laravel." />;
  }

  if (auth.status === "authenticated") {
    return <DashboardPage me={auth.me} onLogout={auth.logout} onReauth={auth.reload} />;
  }

  if (!setup.setup.twitch_configured) {
    return <SetupPage setup={setup.setup} onDone={setup.reload} />;
  }

  return <LoginPage error={auth.error} />;
}
