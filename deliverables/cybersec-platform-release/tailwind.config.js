/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './resources/**/*.blade.php',
        './resources/**/*.js',
        './app/**/*.php',
    ],
    darkMode: 'class',
    theme: {
        extend: {
            colors: {
                background: '#0a0e1a',
                surface: '#131826',
                primary: '#7c3aed',
                secondary: '#06b6d4',
                accent: '#f59e0b',
                success: '#10b981',
                danger: '#ef4444',
                critical: '#ef4444',
                high: '#f97316',
                medium: '#f59e0b',
                low: '#06b6d4',
                info: '#6b7280',
            },
            fontFamily: {
                sans: ['Inter', 'ui-sans-serif', 'system-ui', 'sans-serif'],
                display: ['Space Grotesk', 'Inter', 'sans-serif'],
                mono: ['JetBrains Mono', 'ui-monospace', 'monospace'],
            },
            boxShadow: {
                glow: '0 0 20px rgba(124, 58, 237, 0.35)',
            },
        },
    },
    plugins: [require('@tailwindcss/forms')],
};
