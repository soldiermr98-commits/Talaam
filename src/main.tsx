import React from 'react';
import { createRoot } from 'react-dom/client';
import './styles/fonts.css';
import './styles/global.css';
import App from './App';

createRoot(document.getElementById('root')!).render(
  <React.StrictMode>
    <App />
  </React.StrictMode>
);

// ثبت سرویس‌ورکر فقط در بیلد تولیدی — آفلاین بعد از اولین اجرا
if ('serviceWorker' in navigator && import.meta.env.PROD) {
  window.addEventListener('load', () => {
    navigator.serviceWorker.register('/sw.js').catch(() => {
      /* بی‌صدا */
    });
  });
}
