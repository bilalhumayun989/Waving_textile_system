import '@fontsource-variable/dm-sans';
import '@fontsource-variable/manrope';
import '../css/app.css';
import React from 'react';
import { createRoot } from 'react-dom/client';
import { createInertiaApp } from '@inertiajs/react';
createInertiaApp({title: title => `${title} \u00b7 Threadline`, resolve: name => { const pages = import.meta.glob('./Pages/*.jsx'); return pages[`./Pages/${name}.jsx`](); }, setup({el, App, props}) { createRoot(el).render(<App {...props} />); }, progress: {color: '#7860df'} });
