import React from 'react';
import ReactDOM from 'react-dom/client';
import App from './App';
import './styles/global.css';

// Apply saved theme immediately to prevent flash
const saved = (() => {
  try {
    const raw = localStorage.getItem('hg-settings');
    if (raw) {
      const parsed = JSON.parse(raw);
      return parsed?.state?.theme;
    }
  } catch { /* ignore */ }
  return null;
})();

if (saved === 'dark') {
  document.documentElement.setAttribute('data-theme', 'dark');
} else if (saved === 'light') {
  document.documentElement.setAttribute('data-theme', 'light');
} else {
  const isDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
  document.documentElement.setAttribute('data-theme', isDark ? 'dark' : 'light');
}

ReactDOM.createRoot(document.getElementById('root')!).render(
  <React.StrictMode>
    <App />
  </React.StrictMode>
);
