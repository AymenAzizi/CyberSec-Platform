import axios from 'axios';

// Bind axios to the window for legacy code that relies on window.axios.
window.axios = axios;

// Pick up the CSRF token meta tag if present.
const token = document.head?.querySelector('meta[name="csrf-token"]');
if (token) {
    window.axios.defaults.headers.common['X-CSRF-TOKEN'] = token.content;
}

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
