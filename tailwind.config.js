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
      colors: {
        // Carbon Blue 60 — único acento. Reemplaza cobalto editorial #1D3F8C.
        primary: {
          10:  "#EDF5FF",
          20:  "#D0E2FF",
          30:  "#A6C8FF",
          40:  "#78A9FF",
          50:  "#4589FF",
          60:  "#0F62FE",
          70:  "#0353E9",
          80:  "#002D9C",
          90:  "#001D6C",
          100: "#001141",
        },
        // Layers Carbon — profundidad por capas, no shadows
        layer: {
          0:     "#FFFFFF",
          1:     "#F4F4F4",
          2:     "#E0E0E0",
          hover: "#E8E8E8",
        },
        // Texto Carbon
        ink: {
          primary:     "#161616",
          secondary:   "#525252",
          placeholder: "#6F6F6F",
          on_color:    "#FFFFFF",
          disabled:    "#8D8D8D",
        },
        // Bordes Carbon
        line: {
          subtle: "#C6C6C6",
          strong: "#8D8D8D",
        },
        // Status Carbon
        success: { DEFAULT: "#24A148", soft: "#DEFBE6" },
        warning: { DEFAULT: "#F1C21B", soft: "#FCF4D6" },
        error:   { DEFAULT: "#DA1E28", soft: "#FFF1F1" },
        info:    { DEFAULT: "#0F62FE", soft: "#EDF5FF" },
      },
      fontFamily: {
        sans:  ['"IBM Plex Sans"', '"Helvetica Neue"', "Arial", "sans-serif"],
        mono:  ['"IBM Plex Mono"', "Menlo", "Courier", "monospace"],
        serif: ['"Instrument Serif"', "ui-serif", "Georgia", "serif"],
      },
      borderRadius: {
        none: "0",
        DEFAULT: "0",
        sm:  "0",
        md:  "0",
        lg:  "0",
        pill: "24px",
        full: "9999px",
      },
      fontSize: {
        "2xs": ["10px", { lineHeight: "1.4", letterSpacing: "0.32px" }],
        xs:     ["12px", { lineHeight: "1.33", letterSpacing: "0.32px" }],
        sm:     ["14px", { lineHeight: "1.29", letterSpacing: "0.16px" }],
        base:   ["14px", { lineHeight: "1.29", letterSpacing: "0.16px" }],
        md:     ["16px", { lineHeight: "1.50", letterSpacing: "0" }],
        lg:     ["20px", { lineHeight: "1.40", letterSpacing: "0" }],
        xl:     ["24px", { lineHeight: "1.33", letterSpacing: "0" }],
        "2xl":  ["32px", { lineHeight: "1.25", letterSpacing: "0" }],
        "3xl":  ["42px", { lineHeight: "1.19", letterSpacing: "0" }],
        "4xl":  ["48px", { lineHeight: "1.17", letterSpacing: "0" }],
      },
      transitionDuration: {
        "fast-01":     "70ms",
        "fast-02":     "110ms",
        "moderate-01": "150ms",
        "moderate-02": "240ms",
        "slow-01":     "400ms",
      },
      transitionTimingFunction: {
        "carbon-productive": "cubic-bezier(0.2, 0, 0.38, 0.9)",
        "carbon-expressive": "cubic-bezier(0.4, 0.14, 0.3, 1)",
      },
    },
  },
  plugins: [
    forms,
  ],
};
