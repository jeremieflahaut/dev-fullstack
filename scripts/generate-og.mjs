/**
 * Génère public/og-default.png (1200×630) et public/apple-touch-icon.png (180×180).
 *
 * Nécessite JetBrains Mono installée dans le conteneur (paquet Alpine) :
 *   docker compose run --rm -u 0 node sh -c \
 *     "apk add --no-cache fontconfig font-jetbrains-mono >/dev/null \
 *      && node scripts/generate-og.mjs \
 *      && chown $(id -u):$(id -g) public/og-default.png public/apple-touch-icon.png"
 */
import sharp from 'sharp';

const fg = '#ece8e1';
const muted = '#9a948a';
const accent = '#ff5a41';
const bg = '#0d0c0b';

const og = `<svg width="1200" height="630" viewBox="0 0 1200 630" xmlns="http://www.w3.org/2000/svg">
  <defs>
    <pattern id="grid" width="44" height="44" patternUnits="userSpaceOnUse">
      <path d="M44 0H0V44" fill="none" stroke="${fg}" stroke-opacity="0.06" stroke-width="1"/>
    </pattern>
    <radialGradient id="glow" cx="50%" cy="0%" r="80%">
      <stop offset="0%" stop-color="${accent}" stop-opacity="0.2"/>
      <stop offset="100%" stop-color="${accent}" stop-opacity="0"/>
    </radialGradient>
  </defs>

  <rect width="1200" height="630" fill="${bg}"/>
  <rect width="1200" height="630" fill="url(#grid)"/>
  <rect width="1200" height="630" fill="url(#glow)"/>

  <!-- pastilles de fenêtre terminal -->
  <circle cx="72" cy="72" r="9" fill="#ff5f57"/>
  <circle cx="102" cy="72" r="9" fill="#febc2e"/>
  <circle cx="132" cy="72" r="9" fill="#28c840"/>

  <!-- chevron ❯ en tracé (le glyphe n'existe pas dans la police) -->
  <path d="M72 266 L112 306 L72 346" fill="none" stroke="${accent}" stroke-width="17" stroke-linecap="round" stroke-linejoin="round"/>

  <text x="140" y="338" font-family="JetBrains Mono, monospace" font-size="94" font-weight="700" fill="${fg}">dev-fullstack<tspan fill="${muted}">_</tspan></text>

  <text x="142" y="414" font-family="JetBrains Mono, monospace" font-size="33" fill="${muted}">Jérémie Flahaut — développeur web fullstack</text>

  <text x="72" y="556" font-family="JetBrains Mono, monospace" font-size="26" fill="${muted}">laravel · php · docker</text>
  <text x="1128" y="556" text-anchor="end" font-family="JetBrains Mono, monospace" font-size="26" fill="${accent}">www.dev-fullstack.net</text>

  <rect x="0.5" y="0.5" width="1199" height="629" fill="none" stroke="${fg}" stroke-opacity="0.1"/>
</svg>`;

await sharp(Buffer.from(og)).png().toFile('public/og-default.png');
console.log('✔ public/og-default.png (1200×630)');

await sharp('public/favicon.svg', { density: 300 })
  .resize(180, 180)
  .flatten({ background: bg })
  .png()
  .toFile('public/apple-touch-icon.png');
console.log('✔ public/apple-touch-icon.png (180×180)');
