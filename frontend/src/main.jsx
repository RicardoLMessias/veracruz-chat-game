import { StrictMode } from "react";
import { createRoot } from "react-dom/client";
import "./styles/global.css";
import App from "./App.jsx";

// O callback da Twitch volta para "localhost". Se abrirem por 127.0.0.1, o cookie de sessão
// ficaria em outro endereço e o login falharia; então padronizamos em localhost.
if (window.location.hostname === "127.0.0.1") {
  window.location.replace(window.location.href.replace("//127.0.0.1", "//localhost"));
} else {
  createRoot(document.getElementById("root")).render(
    <StrictMode>
      <App />
    </StrictMode>,
  );
}
