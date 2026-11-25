import { defineConfig, loadEnv } from "vite";
import react from "@vitejs/plugin-react";
import { cwd } from "node:process";

export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, cwd(), "");
  const apiTarget = env.VITE_API_PROXY_TARGET || "http://127.0.0.1:8000";

  return {
    plugins: [react()],
    server: {
      host: "localhost",
      port: 5173,
      proxy: {
        "/api": { target: apiTarget, changeOrigin: false },
        "/auth": { target: apiTarget, changeOrigin: false },
        "/sanctum": { target: apiTarget, changeOrigin: false },
      },
    },
    build: {
      sourcemap: false,
    },
  };
});
