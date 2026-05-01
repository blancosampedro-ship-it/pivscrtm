import preset from "../../../../vendor/filament/support/tailwind.config.preset";

/** @type {import('tailwindcss').Config} */
export default {
    presets: [preset],
    content: [
        "./app/Filament/**/*.php",
        "./resources/views/filament/**/*.blade.php",
        "./vendor/filament/**/*.blade.php",
    ],
    theme: {
        extend: {
            // Fuentes DESIGN.md — alineadas al tailwind.config.js raíz pero
            // reaplicadas aquí porque Filament theme tiene su propio config.
            fontFamily: {
                sans:  ['"General Sans"', "ui-sans-serif", "system-ui", "sans-serif"],
                serif: ['"Instrument Serif"', "ui-serif", "Georgia", "serif"],
            },
        },
    },
};
