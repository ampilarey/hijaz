"""Build the Hijaz logo files from the original artwork.

The original (docs/brand/original-logo.png, the logo as the business supplied
it, with the contact lines cropped off) is the reference. Everything is drawn
in its pixel coordinates so the output can be laid over it and compared:

- The star and the Kaaba are geometric, so they are rebuilt from measurements
  of the original as exact shapes.
- The lettering is redrawn from a Times-style font, with every letter scaled
  into the exact box it occupies in the original.

Usage: python3 scripts/brand/build-logo.py <output dir>
Needs: pip install fonttools, and FreeSerif Bold (Debian/Ubuntu: fonts-freefont-ttf)
"""
import math
import os
import sys

from fontTools.pens.boundsPen import BoundsPen
from fontTools.pens.svgPathPen import SVGPathPen
from fontTools.pens.transformPen import TransformPen
from fontTools.ttLib import TTFont

OUT = sys.argv[1]

# Colours sampled from the original (median of solid areas).
GREEN = "#01A85A"
RED = "#EC3237"
KAABA = "#353334"
BACKGROUND = "#C7D9F1"

# ---------------------------------------------------------------- star
# Two equal squares, one turned 45 degrees, drawn as a single outline.
# Measured: centre (401.5, 204.75), square half-side 120.25, stroke 26.
CX, CY, A, W = 401.5, 204.75, 120.25, 26.0


def star_points(a):
    """The 16 vertices of the star outline whose square half-side is a,
    clockwise from the top point."""
    p, d = a * math.sqrt(2), a * (math.sqrt(2) - 1)
    q = [(0, -p), (d, -a), (a, -a), (a, -d), (p, 0), (a, d), (a, a), (d, a),
         (0, p), (-d, a), (-a, a), (-a, d), (-p, 0), (-a, -d), (-a, -a), (-d, -a)]
    return [(CX + x, CY + y) for x, y in q]


def star_shape():
    """The star as it appears in the original: the bottom is hidden behind the
    lettering, so each side bar ends in a slanted cut and the star is open
    between them."""
    outer, inner = star_points(A), star_points(A - W)
    # Outer contour from the bottom of the right bar, clockwise over the top,
    # to the bottom of the left bar: vertices 5 (right, below the right point)
    # back round through 0 (top) to 11 (left, below the left point).
    right_out, right_in = (CX + A, 323.6), (CX + A - W, 337.8)
    left_out, left_in = (CX - A, 323.6), (CX - A + W, 337.8)
    pts = [right_in, right_out]
    pts += [outer[i] for i in (5, 4, 3, 2, 1, 0, 15, 14, 13, 12, 11)]
    pts += [left_out, left_in]
    pts += [inner[i] for i in (11, 12, 13, 14, 15, 0, 1, 2, 3, 4, 5)]
    return "M" + " L".join(f"{x:.2f} {y:.2f}" for x, y in pts) + " Z"


# ---------------------------------------------------------------- Kaaba
# A front view: the roof peaks in the middle, two thin gaps run parallel to
# the roof line, and the base rises slightly to the centre.
K_LEFT, K_RIGHT, K_MID = 330.5, 471.5, 401.0
ROOF_TOP, ROOF_SLOPE = 127.4, 0.277          # y = top + slope * |x - mid|
BASE_MID, BASE_SLOPE = 232.5, 0.0993
BANDS = [(0, 12.5), (19.2, 30.3), (36.6, None)]   # dark bands, offsets below the roof line


def kaaba_shape():
    half = K_RIGHT - K_MID

    def chevron(offset):  # left, middle, right points of a line parallel to the roof
        return [(K_LEFT, ROOF_TOP + ROOF_SLOPE * half + offset),
                (K_MID, ROOF_TOP + offset),
                (K_RIGHT, ROOF_TOP + ROOF_SLOPE * half + offset)]

    base = [(K_RIGHT, BASE_MID + BASE_SLOPE * half), (K_MID, BASE_MID),
            (K_LEFT, BASE_MID + BASE_SLOPE * half)]
    paths = []
    for top, bottom in BANDS:
        lower = base if bottom is None else list(reversed(chevron(bottom)))
        pts = chevron(top) + lower
        paths.append("M" + " L".join(f"{x:.2f} {y:.2f}" for x, y in pts) + " Z")
    return "".join(paths)


# ---------------------------------------------------------------- lettering
# The original is set in a bold Times-style serif. Tracing it from a 37 px high
# JPEG gives lumpy letters, so each letter is drawn from FreeSerif Bold (a
# Times design) and scaled into the exact box that letter fills in the
# original. Of 18 serif fonts tried this way it overlapped the original best.
FONT = os.environ.get("HIJAZ_LOGO_FONT", "/usr/share/fonts/truetype/freefont/FreeSerifBold.ttf")

# Ink box of every letter in the original: x0, x1, y0, y1 in its pixels.
LINES = [
    ("HIJAZ", [(315, 355, 258, 296), (360, 378, 258, 296), (381, 406, 258, 297),
               (411, 449, 258, 296), (453, 488, 258, 296)]),
    ("Hajj&Umrah", [(314, 331, 309, 329), (335, 345, 315, 329), (347, 354, 309, 335),
                    (356, 363, 309, 335), (375, 394, 309, 329), (406, 421, 309, 329),
                    (426, 446, 315, 329), (450, 459, 316, 329), (462, 473, 315, 329),
                    (476, 488, 309, 329)]),
]


def lettering_shape():
    font = TTFont(FONT)
    glyphs, cmap = font.getGlyphSet(), font.getBestCmap()
    out = []
    for text, boxes in LINES:
        for ch, (x0, x1, y0, y1) in zip(text, boxes):
            glyph = glyphs[cmap[ord(ch)]]
            bounds = BoundsPen(glyphs)
            glyph.draw(bounds)
            gx0, gy0, gx1, gy1 = bounds.bounds
            # Pixel x0 is partly covered, so the ink edge sits inside it.
            left, right, top, bottom = x0 + 0.4, x1 + 0.6, y0 + 0.4, y1 + 0.6
            sx, sy = (right - left) / (gx1 - gx0), (bottom - top) / (gy1 - gy0)
            pen = SVGPathPen(glyphs)
            glyph.draw(TransformPen(pen, (sx, 0, 0, -sy, left - gx0 * sx, top + gy1 * sy)))
            out.append(pen.getCommands())
    return " ".join(out)


# ---------------------------------------------------------------- output
def svg(view, body, title, background=None):
    vx, vy, vw, vh = view
    bg = f'<rect x="{vx}" y="{vy}" width="{vw}" height="{vh}" fill="{BACKGROUND}"/>' if background else ""
    return (f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="{vx} {vy} {vw} {vh}" '
            f'width="{vw * 2:g}" height="{vh * 2:g}" role="img" aria-labelledby="t">'
            f'<title id="t">{title}</title>{bg}{body}</svg>\n')


star = f'<path fill="{GREEN}" d="{star_shape()}"/>'
kaaba = f'<path fill="{KAABA}" d="{kaaba_shape()}"/>'
letters = f'<path fill="{RED}" d="{lettering_shape()}"/>'

LOGO_VIEW = (216, 20, 371, 334)     # the star plus an even margin
TITLE = "Hijaz Hajj &amp; Umrah"
files = {
    "hijaz-logo.svg": svg(LOGO_VIEW, star + kaaba + letters, TITLE),
    "hijaz-logo-on-blue.svg": svg(LOGO_VIEW, star + kaaba + letters, TITLE, background=True),
}

# Mark: the star and Kaaba without lettering. With the text gone the star is
# drawn whole, so it reads as a closed shape at small sizes.
full_star = (f'<path fill="{GREEN}" fill-rule="evenodd" d="'
             + "M" + " L".join(f"{x:.2f} {y:.2f}" for x, y in star_points(A)) + " Z "
             + "M" + " L".join(f"{x:.2f} {y:.2f}" for x, y in star_points(A - W)) + ' Z"/>')
# Move the Kaaba down to the centre of the closed star.
k_centre = ((ROOF_TOP + BASE_MID + BASE_SLOPE * (K_RIGHT - K_MID)) / 2)
mark_body = full_star + f'<g transform="translate(0 {CY - k_centre:.2f})">{kaaba}</g>'
MARK_VIEW = (CX - 185, CY - 185, 370, 370)
files["hijaz-mark.svg"] = svg(MARK_VIEW, mark_body, TITLE)
files["favicon.svg"] = svg(MARK_VIEW, mark_body, "Hijaz")

for name, content in files.items():
    with open(os.path.join(OUT, name), "w") as f:
        f.write(content)
print("wrote", ", ".join(files))
