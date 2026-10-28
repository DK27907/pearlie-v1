import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.js',
    ],

    theme: {
        extend: {
            colors: {
                midnight: '#0a1628',
                ocean: '#0891b2',
            },
            screens: {
                '3xl': '1920px',
                '4xl': '2560px',
                '5xl': '3840px',
            },
            fontFamily: {
                sans: ['ui-sans-serif', 'system-ui', ...defaultTheme.fontFamily.sans],
            },
            maxWidth: {
                '8xl': '96rem',
                '9xl': '120rem',
            },
        },
    },

    plugins: [forms],
};
