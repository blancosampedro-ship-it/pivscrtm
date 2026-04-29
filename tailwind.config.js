import forms from "@tailwindcss/forms";

/** @type {import('tailwindcss').Config} */
export default {
  content: [
    "./resources/**/*.blade.php",
    "./resources/**/*.js",
    "./resources/**/*.vue",
    "./vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php",
    "./vendor/filament/**/*.blade.php",
  ],
  theme: {
    extend: {
      // --- Tokens de DESIGN.md — única fuente de verdad ---
      colors: {
        // Azul cobalto Winfin (señalética pública española, NO bright SaaS blue)
        primary: {
          50:  "#E6EBF5",
          100: "#C7D1E8",
          200: "#9FB1D5",
          300: "#7791C2",
          400: "#4F71AF",
          500: "#1D3F8C",   // base
          600: "#163070",
          700: "#102358",
          800: "#0B1A40",
          900: "#06122B",
        },
        // Fondos cálidos (no blanco hospital)
        canvas: {
          base:    "#FAFAF7",
          surface: "#FFFFFF",
          subtle:  "#F2F2EC",
        },
        // Tipografía
        ink: {
          DEFAULT: "#0F1115",
          muted:   "#5A6068",
          faint:   "#8A8F96",
        },
        // Bordes
        line: {
          DEFAULT: "#E5E5E0",
          strong:  "#C9C9C2",
        },
        // Semánticos profundos (NO lima, NO bright red)
        success: { DEFAULT: "#0F766E", soft: "#E6F2F1" },
        warning: { DEFAULT: "#B45309", soft: "#FBEFD9" },
        error:   { DEFAULT: "#B91C1C", soft: "#FBE6E6" },
      },
      fontFamily: {
        // Editorial serif para titulares y KPIs grandes
        serif: ['"Instrument Serif"', "ui-serif", "Georgia", "serif"],
        // Humanist sans para body, UI, tablas, formularios
        sans:  ['"General Sans"', "ui-sans-serif", "system-ui", "sans-serif"],
      },
      borderRadius: {
        sm:  "4px",
        DEFAULT: "6px",
        md:  "6px",
        lg:  "8px",
        xl:  "12px",
      },
      // Modular scale 1.250
      fontSize: {
        xs:   "12px",
        sm:   "14px",
        base: "16px",
        lg:   "20px",
        xl:   "25px",
        "2xl": "31px",
        "3xl": "39px",
        "4xl": "49px",
        "5xl": "61px",
      },
      transitionDuration: {
        micro: "150ms",
        short: "200ms",
        med:   "300ms",
      },
    },
  },
  plugins: [
    forms,
  ],
};
