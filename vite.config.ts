/// <reference types="vitest/config" />
import fs from 'node:fs';
import { defineConfig, type Plugin } from 'vite';
import react from '@vitejs/plugin-react';

/** فایل mock هرگز نباید در بیلد release باشد */
function dropMockFromRelease(): Plugin {
  return {
    name: 'drop-mock-prices-from-release',
    apply: 'build',
    closeBundle() {
      const p = 'dist/prices.mock.json';
      if (fs.existsSync(p)) fs.unlinkSync(p);
    },
  };
}

export default defineConfig({
  plugins: [react(), dropMockFromRelease()],
  build: {
    target: 'es2018',
    sourcemap: false,
    cssMinify: true,
    assetsInlineLimit: 8192,
  },
  test: {
    environment: 'node',
    include: ['src/**/*.test.{ts,tsx}'],
  },
});
