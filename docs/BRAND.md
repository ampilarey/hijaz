# Hijaz brand

Hijaz Hajj & Umrah is its own company. It is not part of Rihla; the website is
only hosted on the `hijaz.rihla.mv` subdomain. Nothing a visitor sees should
mention Rihla.

## Contact details

Recorded in `config/hijaz.php` for use on the site later. They are not shown
anywhere yet, and they never go in the logo.

| | |
| --- | --- |
| Email | hijazumra@gmail.com |
| Phone | 977 5123 (+960 9775123) |
| Phone | 990 6869 (+960 9906869) |

## Logo

The logo is an exact redraw of the company's existing artwork, with the email
and phone lines removed. The original is kept at
`docs/brand/original-logo.png` (contact lines cropped off) as the reference.

- **Star:** two equal squares, one turned 45 degrees, drawn as one green outline.
  The bottom of the star is hidden behind the lettering, so the two side bars
  end in a slanted cut.
- **Kaaba:** flat front view with a peaked roof, two thin light stripes running
  parallel to the roof, and a base that rises slightly to the centre.
- **Lettering:** "HIJAZ" over "Hajj & Umrah" in a bold Times-style serif, red.

Files, all in `public/images/brand/`:

| File | Use |
| --- | --- |
| `hijaz-logo.svg` / `.png` | Primary logo, transparent background. Website, documents, print. |
| `hijaz-logo-on-blue.svg` / `.png` | The logo on its original light blue background. Social media, sharing previews. |
| `hijaz-mark.svg` / `.png` | Star and Kaaba only, with the star closed. Profile pictures, app icons. |
| `favicon.svg` | Browser tab icon. Also exported as `public/favicon.ico`, `favicon-32.png`, `apple-touch-icon.png`, `icon-192.png`, `icon-512.png`. |

The SVGs are the masters; use them wherever possible. The PNGs are 1200 px wide
(the mark is 1024 px square).

### Regenerating

`scripts/brand/build-logo.py` draws everything from measurements of the
original, in the original's pixel coordinates, so a render can be laid over
`docs/brand/original-logo.png` to check it. It needs `fonttools` and FreeSerif
Bold (`fonts-freefont-ttf`).

```bash
python3 scripts/brand/build-logo.py public/images/brand
node scripts/brand/render-svg.mjs public/images/brand/hijaz-logo.svg public/images/brand/hijaz-logo.png 1200 transparent
```

## Colours

Sampled from the original logo. Defined as Tailwind tokens in
`resources/css/app.css`, so classes like `bg-brand-green` and `text-brand-red`
work everywhere.

| Token | Hex | Use |
| --- | --- | --- |
| `brand-green` | `#01A85A` | The star. Large shapes and icons. Too light for small text. |
| `brand-green-dark` | `#017A42` | Buttons with white text, links, small green text. |
| `brand-green-light` | `#E6F6EE` | Tinted panels. |
| `brand-red` | `#EC3237` | The wordmark. Large headings only. |
| `brand-red-dark` | `#C81E24` | Small red text. |
| `brand-ink` | `#353334` | The Kaaba. Body text. |
| `brand-muted` | `#5E5C5D` | Secondary text. |
| `brand-sky` | `#C7D9F1` | The logo's original background. Feature panels. |
| `brand-sky-light` | `#F1F6FC` | Page background. |

The logo green and red are lighter than accessible small text allows, which is
why the darker shades exist. Use the logo colours for the logo, shapes, and
headings of 24 px and up.

## Type

- **Headings:** Tinos bold (`font-display`), the Times design the wordmark uses.
- **Body:** Instrument Sans (`font-sans`).

Both load from Bunny Fonts through `vite.config.js`.

## Rules

- Never put the email address or phone numbers in the logo.
- Never mention Rihla on the site.
- Keep the star green and the lettering red. On dark backgrounds or photos,
  place the logo on a white or light blue panel instead of recolouring it.
- Leave clear space around the logo of at least the height of "HIJAZ".
- Do not stretch, rotate, or add effects.
