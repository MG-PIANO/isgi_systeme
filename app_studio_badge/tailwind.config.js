/** @type {import('tailwindcss').Config} */
export default {
  content: [
    "./index.html",
    "./src/**/*.{js,ts,jsx,tsx}",
  ],
  darkMode: "class",
  theme: {
    extend: {
      colors: {
        isgi: {
          blue: "#003087",
          dark: "#001f5c",
          light: "#0052cc",
          orange: "#FF6B00",
          amber: "#F59E0B"
        }
      }
    },
  },
  plugins: [],
}
