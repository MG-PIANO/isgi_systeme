const fs = require('fs');
const path = require('path');

const configPath = path.join(__dirname, 'tailwind.config.js');
let configContent = fs.readFileSync(configPath, 'utf8');

const colorsMatch = configContent.match(/colors:\s*\{([^}]*)\}/);
if (!colorsMatch) {
    console.error("Colors not found");
    process.exit(1);
}

const colorsString = colorsMatch[1];
const colors = {};
const regex = /"([^"]+)":\s*"([^"]+)"/g;
let match;
while ((match = regex.exec(colorsString)) !== null) {
    colors[match[1]] = match[2];
}

let cssContent = fs.readFileSync(path.join(__dirname, 'src/index.css'), 'utf8');

let lightVars = ':root {\n';
let darkVars = '.dark {\n';
let tailwindColors = '';

// Generate dark mode variants programmatically (simple inversion logic for a dark theme)
// This uses a typical material design 3 approach: invert the lightness
function invertColor(hex) {
    // Very naive inversion for demonstration. We will use a more robust dark palette.
    // Instead of perfect inversion, let's map them to typical dark mode equivalents.
    // Light backgrounds become dark (#1e1e1e, #121212)
    // Dark texts become light (#e0e0e0, #ffffff)
    // Primary/secondary are lightened slightly
    
    const r = parseInt(hex.slice(1, 3), 16);
    const g = parseInt(hex.slice(3, 5), 16);
    const b = parseInt(hex.slice(5, 7), 16);
    
    const brightness = (r * 299 + g * 587 + b * 114) / 1000;
    
    if (brightness > 200) {
        // Light background -> dark background
        return '#' + Math.floor(r * 0.1).toString(16).padStart(2, '0') + Math.floor(g * 0.12).toString(16).padStart(2, '0') + Math.floor(b * 0.15).toString(16).padStart(2, '0');
    } else if (brightness < 100) {
        // Dark text -> light text
        return '#' + Math.min(255, r + 150).toString(16).padStart(2, '0') + Math.min(255, g + 150).toString(16).padStart(2, '0') + Math.min(255, b + 150).toString(16).padStart(2, '0');
    } else {
        // Mid tones
        return hex; // keep same or slightly adjust
    }
}

// A more tailored dark palette based on MD3
const darkPalette = {
    "surface-container-low": "#1e2022",
    "secondary": "#b6c8e3",
    "outline-variant": "#434655",
    "surface-container-highest": "#333538",
    "on-secondary": "#223146",
    "surface-bright": "#383a3c",
    "on-error": "#690005",
    "on-background": "#e2e2e5",
    "tertiary-fixed-dim": "#bec6e0",
    "primary-fixed": "#dbe1ff",
    "on-primary": "#002878",
    "surface-dim": "#111415",
    "on-primary-fixed": "#00174b",
    "surface-tint": "#b4c5ff",
    "background": "#191c1e",
    "on-error-container": "#ffdad6",
    "surface-variant": "#434655",
    "tertiary-fixed": "#dae2fd",
    "on-surface": "#e2e2e5",
    "on-tertiary-fixed-variant": "#3f465c",
    "tertiary": "#b1c5ff",
    "tertiary-container": "#363e52",
    "outline": "#8d909f",
    "on-tertiary-container": "#dae2fd",
    "surface-container-lowest": "#0c0e10",
    "primary": "#b4c5ff",
    "error-container": "#93000a",
    "primary-container": "#00389c",
    "on-tertiary-fixed": "#131b2e",
    "secondary-fixed": "#d3e4fe",
    "primary-fixed-dim": "#b4c5ff",
    "secondary-container": "#38485d",
    "inverse-on-surface": "#191c1e",
    "on-secondary-fixed": "#0b1c30",
    "inverse-primary": "#004ac6",
    "surface": "#111415",
    "on-secondary-fixed-variant": "#38485d",
    "surface-container": "#1d2022",
    "on-secondary-container": "#d0e1fb",
    "secondary-fixed-dim": "#b7c8e1",
    "error": "#ffb4ab",
    "inverse-surface": "#e2e2e5",
    "on-tertiary": "#1c273c",
    "on-surface-variant": "#c3c6d7",
    "surface-container-high": "#282a2c",
    "on-primary-container": "#dbe1ff"
};

for (const [key, value] of Object.entries(colors)) {
    lightVars += `  --color-${key}: ${value};\n`;
    const darkValue = darkPalette[key] || invertColor(value);
    darkVars += `  --color-${key}: ${darkValue};\n`;
    tailwindColors += `        "${key}": "var(--color-${key})",\n`;
}

lightVars += '}\n';
darkVars += '}\n';

// Replace colors in tailwind.config.js
configContent = configContent.replace(colorsString, `\n${tailwindColors}      `);
fs.writeFileSync(configPath, configContent);

// Add vars to index.css
if (!cssContent.includes(':root {')) {
    cssContent = `${lightVars}\n${darkVars}\n${cssContent}`;
} else {
    // Just simple prepend
    cssContent = `${lightVars}\n${darkVars}\n${cssContent}`;
}
fs.writeFileSync(path.join(__dirname, 'src/index.css'), cssContent);

console.log('Successfully updated to use CSS variables for dark mode.');
