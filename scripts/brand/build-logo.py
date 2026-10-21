"""Build the Hijaz logo SVGs. Text is converted to outlines (Liberation Serif Bold,
metric-compatible with the Times New Roman Bold of the original artwork) so the
logo renders identically everywhere without a font."""
import math, sys, os
from fontTools.ttLib import TTFont
from fontTools.pens.svgPathPen import SVGPathPen

OUT = sys.argv[1]
GREEN, RED = "#17A24A", "#E0262B"
DARK_L, DARK_R, DARK_T = "#3B3B3B", "#1E1E1E", "#2C2C2C"
GOLD_L, GOLD_R = "#D4A72C", "#B88D20"
STRIPE_L, STRIPE_R = "#6A6A6A", "#4A4A4A"

font = TTFont("/usr/share/fonts/truetype/liberation/LiberationSerif-Bold.ttf")
gs = font.getGlyphSet(); cmap = font.getBestCmap(); upm = font["head"].unitsPerEm

def text_paths(text, size, x_center, baseline, tracking=0.0, fill=RED):
    s = size / upm
    glyphs = [cmap[ord(c)] for c in text]
    adv = [gs[g].width * s for g in glyphs]
    total = sum(adv) + tracking * size * (len(text) - 1)
    x = x_center - total / 2
    out = []
    for g, a, c in zip(glyphs, adv, text):
        if c != " ":
            pen = SVGPathPen(gs); gs[g].draw(pen)
            out.append(f'<path transform="translate({x:.2f} {baseline}) scale({s:.5f} {-s:.5f})" d="{pen.getCommands()}"/>')
        x += a + tracking * size
    return f'<g fill="{fill}">' + "".join(out) + "</g>"

def star_path(cx, cy, a):
    r, i = a * math.sqrt(2), a * (math.sqrt(2) - 1)
    pts = []
    for k in range(8):
        ang = k * math.pi / 4
        pts.append((cx + r * math.cos(ang), cy + r * math.sin(ang)))          # outer point
        # inner vertex between this point and the next, on the square edge
        mid = ang + math.pi / 8
        rad = math.hypot(a, i)
        pts.append((cx + rad * math.cos(mid), cy + rad * math.sin(mid)))
    return "M" + " L".join(f"{x:.2f} {y:.2f}" for x, y in pts) + " Z"

def star(cx, cy, a, sw):
    return f'<path d="{star_path(cx, cy, a)}" fill="none" stroke="{GREEN}" stroke-width="{sw}" stroke-linejoin="miter" stroke-miterlimit="6"/>'

def kaaba(cx, cy, scale=1.0):
    # local coords: front vertical edge at x=0, y=0 is the front top corner
    L, R, H = -95, 50, 105           # left extent, right extent, face height
    tl, tr = -22, -35                # rise of the top edge at the left / right extents
    def poly(pts, fill):
        return f'<polygon fill="{fill}" points="{" ".join(f"{x:.1f},{y:.1f}" for x, y in pts)}"/>'
    def band(d1, d2, fl, fr):
        return (poly([(0, d1), (L, tl + d1), (L, tl + d2), (0, d2)], fl)
              + poly([(0, d1), (R, tr + d1), (R, tr + d2), (0, d2)], fr))
    body = (poly([(0, 0), (L, tl), (L + R, tl + tr), (R, tr)], DARK_T)
          + poly([(0, 0), (L, tl), (L, tl + H), (0, H)], DARK_L)
          + poly([(0, 0), (R, tr), (R, tr + H), (0, H)], DARK_R)
          + band(14, 19, STRIPE_L, STRIPE_R)
          + band(25, 38, GOLD_L, GOLD_R))
    # centre the drawing: its bbox spans x in [L, R], y in [tl+tr, H]
    ox, oy = (L + R) / 2, ((tl + tr) + H) / 2
    return f'<g transform="translate({cx} {cy}) scale({scale}) translate({-ox} {-oy})">{body}</g>'

def svg(w, h, body, title):
    return (f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {w} {h}" width="{w}" height="{h}" role="img" aria-labelledby="t">'
            f'<title id="t">{title}</title>{body}</svg>\n')

# --- Mark: star with the Kaaba inside (square) ---
mark_body = star(300, 300, 150, 26) + kaaba(300, 300, 1.15)
open(f"{OUT}/hijaz-mark.svg", "w").write(svg(600, 600, mark_body, "Hijaz Hajj &amp; Umrah"))

# --- Stacked logo: faithful to the original, wordmark across the lower star ---
cx = 300
body = (star(cx, 270, 165, 28)
        + kaaba(cx, 205, 1.05)
        + text_paths("HIJAZ", 108, cx, 395, tracking=0.03)
        + text_paths("Hajj & Umrah", 52, cx, 452))
open(f"{OUT}/hijaz-logo.svg", "w").write(svg(600, 560, body, "Hijaz Hajj &amp; Umrah"))

# --- Horizontal lockup: mark on the left, wordmark on the right ---
body = (star(130, 130, 95, 17) + kaaba(130, 130, 0.72)
        + text_paths("HIJAZ", 104, 455, 128, tracking=0.03)
        + text_paths("Hajj & Umrah", 48, 455, 184))
open(f"{OUT}/hijaz-logo-horizontal.svg", "w").write(svg(650, 260, body, "Hijaz Hajj &amp; Umrah"))

# --- Favicon: mark only, drawn large so it reads at 16px ---
body = star(32, 32, 17, 3.2) + kaaba(32, 32, 0.135)
open(f"{OUT}/favicon.svg", "w").write(svg(64, 64, body, "Hijaz"))
print("ok")
