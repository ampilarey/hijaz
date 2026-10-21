# Hijaz brand

Hijaz Hajj & Umrah is a Rihla Travels brand. The logo is the one the business
already uses: a green eight-pointed star, the Kaaba, and the red wordmark. It
carries no contact details.

## Files

All in `public/images/brand/`, served as-is (no build step).

| File | Use |
| --- | --- |
| `hijaz-logo.svg` / `.png` | Primary stacked logo. Print, social, documents. |
| `hijaz-logo-horizontal.svg` / `.png` | Site header, email signatures, anything wide and short. |
| `hijaz-mark.svg` / `.png` | Star and Kaaba only. Avatars, app icons, watermarks. |
| `favicon.svg` | Browser tab icon, also exported to `public/favicon.ico`, `favicon-32.png`, `apple-touch-icon.png`, `icon-192.png`, `icon-512.png`. |

The SVGs are the masters. Text is outlined, so they need no font installed.
Regenerate everything after editing `scripts/brand/build-logo.py`:

```bash
pip install fonttools                       # once; needs Liberation Serif Bold installed
python3 scripts/brand/build-logo.py public/images/brand
node scripts/brand/render-svg.mjs public/images/brand/hijaz-logo.svg public/images/brand/hijaz-logo.png 1200 transparent
```

## Colours

Defined as Tailwind tokens in `resources/css/app.css` (`bg-brand-green`,
`text-brand-red`, and so on).

| Token | Hex | Use |
| --- | --- | --- |
| `brand-green` | `#17A24A` | Star, primary buttons, links. |
| `brand-green-dark` | `#0F7A37` | Hover states, small text on light backgrounds. |
| `brand-green-light` | `#E6F5EB` | Tinted panels. |
| `brand-red` | `#E0262B` | Wordmark, headings, accents. Not for body text. |
| `brand-red-dark` | `#B31D21` | Hover states. |
| `brand-gold` | `#D4A72C` | Kaaba band, small highlights. |
| `brand-ink` | `#1E1E1E` | Body text, Kaaba. |
| `brand-stone` | `#6A6A6A` | Secondary text. |
| `brand-sand` | `#F7F4EE` | Page background. |

## Type

- Headings: Tinos bold (`font-display`), the same design as the wordmark.
- Body: Instrument Sans (`font-sans`).

Both load from Bunny Fonts through `vite.config.js`.

## Rules

- Never add the email address or phone numbers to the logo. They belong in the
  page footer and contact page.
- Keep the star green and the wordmark red. On dark or photographic backgrounds
  place the logo on a white panel rather than recolouring it.
- Leave clear space around the logo of at least the height of the "HIJAZ"
  letters.
- Do not stretch, rotate, or add effects.
