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
            // Fuentes DESIGN.md — alineadas al tailwind.config.js raíz (Pivot 07d).
            fontFamily: {
                sans:  ['"IBM Plex Sans"', "ui-sans-serif", "system-ui", "sans-serif"],
                mono:  ['"IBM Plex Mono"', "ui-monospace", '"SF Mono"', "monospace"],
                serif: ['"Instrument Serif"', "ui-serif", "Georgia", "serif"],
            },
        },
    },
};
