import {StrictMode} from 'react';
import {createRoot} from 'react-dom/client';
import App from '@/app/App';
import { applyStoredTheme } from '@/core/theme';
import '../index.css';

const locale = typeof window !== 'undefined' && localStorage.getItem('hassal_locale') === 'en' ? 'en' : 'ar';
document.documentElement.lang = locale;
document.documentElement.dir = locale === 'ar' ? 'rtl' : 'ltr';

// Before the first render: a remembered dark preference must not open on a
// white page for a frame.
applyStoredTheme();

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <App />
  </StrictMode>,
);
