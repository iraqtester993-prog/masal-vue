import { fileURLToPath, URL } from 'node:url';
import { resolve } from 'node:path';
import { defineConfig, loadEnv, normalizePath } from 'vite';
import vue from '@vitejs/plugin-vue';
import { uiLocalizationPlugin } from './src/modules/preferences/ui-localization-plugin.js';

const frontendRoot = fileURLToPath(new URL('.', import.meta.url));
const ports = { admin: 5173, agents: 5174, pos: 5175, public: 5177 };
const buildId = `${Date.now()}-${Math.random().toString(36).slice(2, 10)}`;

export default defineConfig(({ mode }) => {
  if (mode === 'legacy') {
    return {
      root: resolve(frontendRoot, 'legacy'),
      base: './',
      plugins: [vue()],
      resolve: { alias: [{ find: /^vue$/, replacement: 'vue/dist/vue.esm-bundler.js' }] },
      server: { host: '127.0.0.1', port: 5176, strictPort: true },
      // Reference-only build: retain the legacy popup's serialized methods.
      build: { outDir: resolve(frontendRoot, 'dist/legacy'), minify: false },
    };
  }

  const portal = Object.hasOwn(ports, mode) ? mode : 'admin';
  const env = loadEnv(mode, frontendRoot, '');
  const proxy = {
    target: env.MASAL_BACKEND_URL || 'http://127.0.0.1:8000',
    changeOrigin: true,
    configure(server) {
      // This header exists only in the local Vite proxy. Production selects by host.
      server.on('proxyReq', (request) => request.setHeader('X-Masal-Portal', portal));
    },
  };

  return {
    root: resolve(frontendRoot, 'portals', portal),
    envDir: frontendRoot,
    base: '/',
    define: { __MASAL_BUILD_ID__: JSON.stringify(buildId) },
    plugins: [uiLocalizationPlugin(), vue(), {
      name: 'masal-release-version',
      generateBundle() { this.emitFile({ type: 'asset', fileName: 'version.json', source: JSON.stringify({ version: buildId }) }); },
    }],
    resolve: { alias: [{ find: /^\/src(?=\/)/, replacement: normalizePath(resolve(frontendRoot, 'src')) }] },
    server: {
      host: '127.0.0.1',
      port: ports[portal],
      strictPort: true,
      allowedHosts: [`${portal}.localhost`],
      fs: { allow: [frontendRoot] },
      proxy: { '/api': proxy, '/sanctum': proxy },
    },
    preview: { host: '127.0.0.1', port: ports[portal] + 100, strictPort: true },
    build: {
      outDir: resolve(frontendRoot, 'dist', portal),
      emptyOutDir: true,
      manifest: true,
      minify: true,
      sourcemap: false,
    },
  };
});
