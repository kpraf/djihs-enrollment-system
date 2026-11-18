"""
Draws the DJIHS Enrollment System figures with matplotlib, in standard
notations:

  Figure 1  System architecture      layered stack with technology logos
  Figure 2  Context diagram          DFD level 0, Gane & Sarson
  Figure 3  Data flow diagram        DFD level 1, Gane & Sarson
  Figure 4  Enrollment activity      UML 2 activity diagram with partitions
  Figure 5  Enrollment status        UML 2 state machine
  Figure 6  Entity relationship      crow's foot notation
  Figure 7  Old and new system       architecture and comparison table
  Figure 8  Use cases                UML 2 use case diagram

    pip install matplotlib pillow
    python3 docs/diagrams/generate.py

Writes a PNG and an SVG per figure next to this file, and all figures in
DJIHS-system-diagrams.pdf.
"""

from math import hypot
from pathlib import Path

import matplotlib

matplotlib.use("Agg")
import matplotlib.pyplot as plt  # noqa: E402
from matplotlib.backends.backend_pdf import PdfPages  # noqa: E402
from matplotlib.patches import Circle, FancyArrowPatch, FancyBboxPatch, Polygon, Rectangle  # noqa: E402

OUT = Path(__file__).resolve().parent

INK = "#111111"
LINE = "#2b2b2b"
MUTED = "#555555"
SOFT = "#f3f4f3"
TINT = "#e9f2eb"  # school green, very light: headers and tier bands only
GREEN = "#0a5a1d"
WHITE = "#ffffff"
LW = 1.1

plt.rcParams.update({
    "font.family": ["Inter", "Liberation Sans", "Arial", "DejaVu Sans"],
    "font.size": 9,
    "svg.fonttype": "none",
    "lines.solid_capstyle": "butt",
})


# --- primitives ---------------------------------------------------------------
def figure(w, h, number, caption):
    fig = plt.figure(figsize=(w, h))
    ax = fig.add_axes((0, 0, 1, 1))
    ax.set_xlim(0, w)
    ax.set_ylim(0, h)
    ax.axis("off")
    r = fig.canvas.get_renderer()
    head = ax.text(0, 0.32, f"Figure {number}. ", va="center", fontsize=10.5, fontweight="bold", color=INK)
    body = ax.text(0, 0.32, caption, va="center", fontsize=10.5, color=INK)
    to_in = lambda t: t.get_window_extent(r).width / fig.dpi  # noqa: E731
    left = (w - to_in(head) - to_in(body)) / 2
    head.set_x(left)
    body.set_x(left + to_in(head))
    return fig, ax


def rect(ax, x, y, w, h, text="", fill=WHITE, lw=LW, round_=0.0, size=9, weight="normal", color=INK, z=2, ls="-"):
    style = f"round,pad=0,rounding_size={round_}" if round_ else "square,pad=0"
    ax.add_patch(FancyBboxPatch((x, y), w, h, boxstyle=style, facecolor=fill, edgecolor=LINE, linewidth=lw, zorder=z, linestyle=ls))
    if text:
        ax.text(x + w / 2, y + h / 2, text, ha="center", va="center", fontsize=size, fontweight=weight, color=color, zorder=z + 1, linespacing=1.25)


def label(ax, x, y, text, size=8, ha="center", va="center", bg=True, color=INK, style="normal", weight="normal"):
    ax.text(x, y, text, ha=ha, va=va, fontsize=size, color=color, zorder=6, linespacing=1.2, fontstyle=style, fontweight=weight,
            bbox=dict(boxstyle="square,pad=0.12", facecolor=WHITE, edgecolor="none") if bg else None)


def line(ax, pts, arrow_end=True, arrow_start=False, dashed=False, z=1.5):
    """Orthogonal or straight polyline with filled arrowheads."""
    ls = (0, (4, 3)) if dashed else "-"
    xs, ys = zip(*pts)
    ax.plot(xs, ys, color=LINE, linewidth=LW, linestyle=ls, zorder=z)
    for on, (a, b) in ((arrow_end, (pts[-2], pts[-1])), (arrow_start, (pts[1], pts[0]))):
        if on:
            ax.add_patch(FancyArrowPatch(a, b, arrowstyle="-|>", mutation_scale=9, color=LINE, linewidth=LW, shrinkA=0, shrinkB=0, zorder=z))


def cylinder(ax, x, y, w, h, title, sub):
    e = 0.22
    ax.add_patch(Rectangle((x, y + e / 2), w, h - e, facecolor=WHITE, edgecolor="none", zorder=2))
    ax.plot([x, x], [y + e / 2, y + h - e / 2], color=LINE, lw=LW, zorder=3)
    ax.plot([x + w, x + w], [y + e / 2, y + h - e / 2], color=LINE, lw=LW, zorder=3)
    from matplotlib.patches import Arc, Ellipse
    ax.add_patch(Ellipse((x + w / 2, y + h - e / 2), w, e, facecolor=WHITE, edgecolor=LINE, lw=LW, zorder=3))
    ax.add_patch(Arc((x + w / 2, y + e / 2), w, e, theta1=180, theta2=360, edgecolor=LINE, lw=LW, zorder=3))
    ax.text(x + w / 2, y + h / 2, title, ha="center", va="center", fontsize=9.5, fontweight="bold", color=INK, zorder=4)
    ax.text(x + w / 2, y + h / 2 - 0.27, sub, ha="center", va="center", fontsize=8, color=MUTED, zorder=4)


def trim_bottom(fig, ax, w, h, new_bottom):
    """Cut empty space below the content and move the caption up with it."""
    for t in ax.texts[:2]:  # the caption pieces written by figure()
        t.set_y(new_bottom + 0.32)
    ax.set_ylim(new_bottom, h)
    fig.set_size_inches(w, h - new_bottom)


def save(fig, name, pdf):
    for ext in ("png", "svg"):
        fig.savefig(OUT / f"{name}.{ext}", dpi=220 if ext == "png" else None, facecolor=WHITE)
    pdf.savefig(fig, facecolor=WHITE)
    plt.close(fig)


# --- Figure 1: three-tier architecture with real logos ---------------------------------
ICONS = OUT / "icons"
LINE_ICONS = {"user", "lock", "shield-check", "file-spreadsheet", "monitor", "smartphone", "hard-drive"}


def logo(ax, name, cx, cy, size, dpi=220):
    """Place a logo (keeps its colours) or a line icon (drawn in ink), fitted into a size x size square."""
    from PIL import Image
    im = Image.open(ICONS / f"{name}.png").convert("RGBA")
    box = im.getbbox()
    if box:
        im = im.crop(box)
    if name in LINE_ICONS:
        alpha = im.getchannel("A")
        im = Image.new("RGBA", im.size, INK)
        im.putalpha(alpha)
    w, h = im.size
    k = size / max(w, h)
    dw, dh = w * k, h * k
    im = im.resize((max(1, round(dw * dpi)), max(1, round(dh * dpi))), Image.LANCZOS)
    ax.imshow(im, extent=[cx - dw / 2, cx + dw / 2, cy - dh / 2, cy + dh / 2], zorder=4, interpolation="none")


def architecture(pdf):
    """A layered stack, top to bottom: who uses it, the browser, the server, the data, and where it runs.
    Every layer uses the same five-column grid, and every logo sits in the same square."""
    W = 16.0
    L, R, lab = 0.4, 15.6, 2.35       # band edges; label column width
    c0 = L + lab + 0.3                # content starts here
    col = (R - 0.3 - c0) / 5          # five equal columns
    X = [c0 + i * col for i in range(5)]
    SQ = 0.46                          # logo square
    GAP = 0.42                         # space between layers
    heights = {"users": 0.9, "client": 1.0, "server": 2.3, "data": 1.0, "runs": 0.75}
    H = 0.75 + sum(heights.values()) + GAP * 4 + 0.35
    fig, ax = figure(W, H, 1, "System architecture of the DJIHS Enrollment System")

    y = H - 0.3
    tops = {}
    for k, h in heights.items():
        tops[k] = (y - h, h)
        y -= h + GAP

    def band(key, name, sub, dashed=False):
        y0, h = tops[key]
        ls = (0, (5, 3)) if dashed else "-"
        ax.add_patch(Rectangle((L, y0), R - L, h, facecolor=WHITE, edgecolor=LINE, lw=LW, ls=ls, zorder=1))
        ax.add_patch(Rectangle((L, y0), lab, h, facecolor=TINT if not dashed else WHITE, edgecolor=LINE, lw=LW, ls=ls, zorder=1.2))
        ax.text(L + 0.2, y0 + h / 2 + 0.12, name, fontsize=10.5, fontweight="bold", va="center")
        ax.text(L + 0.2, y0 + h / 2 - 0.14, sub, fontsize=8, va="center", color=MUTED)
        return y0, h

    def item(x, y, icons, name, note=None):
        """One or two logos in fixed squares, then the name and note at a fixed offset."""
        for i, n in enumerate(icons):
            logo(ax, n, x + SQ / 2 + i * (SQ + 0.06), y, SQ)
        tx = x + len(icons) * (SQ + 0.06) + 0.1
        ax.text(tx, y + (0.1 if note else 0), name, fontsize=9.4, fontweight="bold", va="center")
        if note:
            ax.text(tx, y - 0.15, note, fontsize=7.9, color=MUTED, va="center")

    def link(upper, lower, text):
        ya = tops[upper][0]
        yb = tops[lower][0] + tops[lower][1]
        ax.annotate("", xy=(X[0] + SQ / 2, yb), xytext=(X[0] + SQ / 2, ya), arrowprops=dict(arrowstyle="<|-|>", color=INK, lw=1.3, mutation_scale=11, shrinkA=0, shrinkB=0))
        ax.text(X[0] + SQ / 2 + 0.15, (ya + yb) / 2, text, fontsize=8.2, va="center")

    # Users: the six roles fill columns 1-4 evenly; devices take column 5.
    y0, h = band("users", "Users", "six roles, own accounts")
    cy = y0 + h / 2
    roles = ["Administrator", "ICT Coordinator", "Registrar", "Key Teacher", "Adviser", "Subject Teacher"]
    widths = [0.3 + len(r) * 0.073 for r in roles]
    span = X[4] - 0.35 - X[0]
    gap = (span - sum(widths)) / (len(roles) - 1)
    x = X[0]
    for r, wdt in zip(roles, widths):
        ax.add_patch(FancyBboxPatch((x, cy - 0.17), wdt, 0.34, boxstyle="round,pad=0,rounding_size=0.17", facecolor=WHITE, edgecolor="#9a9a9a", lw=0.9, zorder=2))
        ax.text(x + wdt / 2, cy, r, fontsize=8.4, ha="center", va="center", zorder=3)
        x += wdt + gap
    item(X[4], cy, ["monitor", "smartphone"], "Any device", "computer, phone, tablet")

    # Client
    y0, h = band("client", "Client", "runs in the browser")
    cy = y0 + h / 2
    for x, (icons, name, note) in zip(X, [(["react"], "React 19", "user interface"), (["typescript"], "TypeScript", "typed code"),
                                          (["tailwindcss"], "Tailwind CSS", "styling"), (["inertia"], "Inertia.js", "pages from the server"),
                                          (["chartdotjs"], "Chart.js", "report charts")]):
        item(x, cy, icons, name, note)

    # Server: stack, then the request pipeline, then the modules, all on the grid.
    y0, h = band("server", "Server", "rules and business logic")
    t = y0 + h
    r1, cap1, r2, cap2, r3 = t - 0.45, t - 0.98, t - 1.28, t - 1.76, t - 2.03
    item(X[0], r1, ["apache"], "Apache", "web server")
    item(X[1], r1, ["php"], "PHP 8.3", "runtime")
    item(X[2], r1, ["laravel"], "Laravel 12", "framework")
    item(X[3], r1, ["lock"], "Access control", "roles, sessions")
    item(X[4], r1, ["shield-check"], "Data privacy", "agreement, access log")
    ax.text(X[0], cap1, "Every request passes, in order", fontsize=7.9, color=MUTED, va="center")
    steps = ["Signed in?", "Role allowed?", "Password changed?", "Agreement accepted?", "Run the action", "Write audit log"]
    sw = (X[4] + col - 0.3 - X[0] - 0.2 * 5) / 6
    for i, st in enumerate(steps):
        sx = X[0] + i * (sw + 0.2)
        ax.add_patch(FancyBboxPatch((sx, r2 - 0.17), sw, 0.34, boxstyle="round,pad=0,rounding_size=0.05", facecolor=TINT if i >= 4 else WHITE, edgecolor=LINE, lw=0.9, zorder=2))
        ax.text(sx + sw / 2, r2, st, fontsize=8.2, ha="center", va="center", zorder=3)
        if i < 5:
            ax.annotate("", xy=(sx + sw + 0.2, r2), xytext=(sx + sw, r2), arrowprops=dict(arrowstyle="-|>", color=LINE, lw=1.0, mutation_scale=8, shrinkA=0, shrinkB=0))
    ax.text(X[0], cap2, "Modules", fontsize=7.9, color=MUTED, va="center")
    ax.text(X[0], r3, "Enrollment  ·  Review and revisions  ·  Documents  ·  Sections and placement  ·  Reports and DepEd forms  ·  "
            "Import and export  ·  Accounts and school years  ·  Audit log", fontsize=8.4, va="center")

    # Data
    y0, h = band("data", "Data", "kept on the server")
    cy = y0 + h / 2
    item(X[0], cy, ["postgresql"], "PostgreSQL", "on Render")
    item(X[1], cy, ["sqlite"], "SQLite", "on a school computer")
    item(X[2], cy, ["hard-drive"], "File storage", "SF1 template, uploads")
    ax.text(X[3], cy + 0.1, "10 tables", fontsize=9.4, fontweight="bold", va="center")
    ax.text(X[3], cy - 0.15, "unique keys and row locks stop duplicate learners and overfilled sections", fontsize=7.9, color=MUTED, va="center")

    # Where it runs
    y0, h = band("runs", "Runs on", "either option", dashed=True)
    cy = y0 + h / 2
    item(X[0], cy, ["render", "docker"], "Render", "Docker image, managed PostgreSQL, HTTPS")
    item(X[2], cy, ["monitor"], "School computer", "composer run start, school network")
    ax.text(X[4], cy, "Same code; only the settings differ.", fontsize=7.9, color=MUTED, style="italic", va="center")

    link("users", "client", "uses")
    link("client", "server", "HTTPS · page data as JSON")
    link("server", "data", "SQL · file reads and writes")
    link("data", "runs", "hosted on")
    save(fig, "fig1-system-architecture", pdf)


# --- DFD shapes (Gane & Sarson) ------------------------------------------------------
def process(ax, x, y, w, h, number, name, size=8.6):
    ax.add_patch(FancyBboxPatch((x, y), w, h, boxstyle="round,pad=0,rounding_size=0.12", facecolor=WHITE, edgecolor=LINE, lw=LW, zorder=2))
    ax.plot([x, x + w], [y + h - 0.3, y + h - 0.3], color=LINE, lw=LW, zorder=3)
    ax.text(x + w / 2, y + h - 0.15, number, ha="center", va="center", fontsize=8.5, fontweight="bold", zorder=3)
    ax.text(x + w / 2, y + (h - 0.3) / 2, name, ha="center", va="center", fontsize=size, zorder=3, linespacing=1.25)


def entity(ax, x, y, w, h, name):
    ax.add_patch(Rectangle((x + 0.05, y - 0.05), w, h, facecolor="#d9d9d9", edgecolor="none", zorder=1.8))
    ax.add_patch(Rectangle((x, y), w, h, facecolor=WHITE, edgecolor=LINE, lw=LW, zorder=2))
    ax.text(x + w / 2, y + h / 2, name, ha="center", va="center", fontsize=8.6, fontweight="bold", zorder=3, linespacing=1.2)


def store(ax, x, y, w, h, sid, name):
    ax.add_patch(Rectangle((x, y), w, h, facecolor=WHITE, edgecolor="none", zorder=2))
    ax.plot([x + w, x, x, x + w], [y + h, y + h, y, y], color=LINE, lw=LW, zorder=3)
    ax.plot([x + 0.42, x + 0.42], [y, y + h], color=LINE, lw=LW, zorder=3)
    ax.text(x + 0.21, y + h / 2, sid, ha="center", va="center", fontsize=8.5, fontweight="bold", zorder=3)
    ax.text(x + 0.55, y + h / 2, name, ha="left", va="center", fontsize=8.6, zorder=3)


def flow(ax, a, b, text=None, at=0.5, dx=0.0, dy=0.0, ha="center"):
    line(ax, [a, b])
    if text:
        label(ax, a[0] + (b[0] - a[0]) * at + dx, a[1] + (b[1] - a[1]) * at + dy, text, size=7.8, ha=ha)


# --- Figure 2: context diagram ------------------------------------------------------
def context(pdf):
    fig, ax = figure(13.33, 7.5, 2, "Context diagram (DFD level 0) of the DJIHS Enrollment System")
    px, py, pw, ph = 5.37, 2.75, 2.6, 2.3
    process(ax, px, py, pw, ph, "0", "DJIHS\nEnrollment System", size=10.5)
    ew, eh = 1.85, 0.7

    def h_pair(name, x, y, inflow, outflow, left_side):
        """Entity beside the process; flows run horizontally."""
        entity(ax, x, y, ew, eh, name)
        near = x + ew if left_side else x
        far = px if left_side else px + pw
        mid = (near + far) / 2
        if inflow:
            yy = y + eh - 0.2
            line(ax, [(near, yy), (far, yy)])
            label(ax, mid, yy + 0.13, inflow, size=7.9, va="bottom", bg=False)
        if outflow:
            yy = y + 0.2 if inflow else y + eh / 2
            line(ax, [(far, yy), (near, yy)])
            label(ax, mid, yy - 0.13, outflow, size=7.9, va="top", bg=False)

    h_pair("Registrar", 0.6, 4.05, "learner details, review\ndecisions, school workbook", "pending list, reports", True)
    h_pair("Adviser", 0.6, 2.9, "learner details,\nchange requests", "class list, SF1", True)
    h_pair("Key Teacher", 10.9, 4.05, "sections, strands,\nplacements", "unplaced learners", False)
    h_pair("Subject Teacher", 10.9, 2.9, None, "class lists", False)

    def v_pair(name, x, inflow, outflow, lx_in, lx_out, y_in, y_out):
        """Entity above the process; flows run vertically."""
        entity(ax, x, 6.1, ew, eh, name)
        if inflow:
            line(ax, [(lx_in, 6.1), (lx_in, py + ph)])
            label(ax, lx_in - 0.1, y_in, inflow, size=7.9, ha="right", bg=False)
        if outflow:
            line(ax, [(lx_out, py + ph), (lx_out, 6.1)])
            label(ax, lx_out + 0.1, y_out, outflow, size=7.9, ha="left", bg=False)

    v_pair("ICT Coordinator", 4.7, "accounts,\nschool years", "audit log,\nbackup", 5.6, 5.95, 5.6, 5.3)
    v_pair("Administrator", 6.95, "accounts,\ndecisions", "DepEd\nindicators", 7.4, 7.75, 5.75, 5.6)

    entity(ax, 5.74, 0.95, ew, eh, "DepEd")
    line(ax, [(6.67, py), (6.67, 1.65)])
    label(ax, 6.77, 2.2, "SF1, enrollment reports", size=7.9, ha="left", bg=False)
    save(fig, "fig2-context-diagram", pdf)


# --- Figure 3: level 1 DFD -------------------------------------------------------------
def dfd_level1(pdf):
    fig, ax = figure(13.33, 8.5, 3, "Data flow diagram (level 1) of the DJIHS Enrollment System")
    pw, ph = 1.9, 1.0
    ew, eh = 1.7, 0.66
    sx, sw, sh = 5.6, 2.15, 0.5

    stores = {"D1": ("Students", 7.1), "D2": ("Enrollments", 5.75), "D3": ("Sections", 4.4), "D4": ("Users", 3.05), "D5": ("School years", 1.85)}
    for sid, (name, y) in stores.items():
        store(ax, sx, y, sw, sh, sid, name)

    def sy(sid, f=0.5):
        return stores[sid][1] + sh * f

    # Left: processes that write learners and enrollments.
    L = {"1.0": ("Encode\nenrollment", 6.8), "6.0": ("Import\nworkbook", 5.0), "2.0": ("Review\nenrollment", 3.3)}
    # Right: sections, reports, administration.
    Rp = {"4.0": ("Generate reports\nand forms", 6.2), "3.0": ("Manage\nsections", 4.55), "5.0": ("Manage accounts\nand school years", 2.15)}
    lx, rx = 3.05, 8.45
    for n, (name, y) in L.items():
        process(ax, lx, y, pw, ph, n, name)
    for n, (name, y) in Rp.items():
        process(ax, rx, y, pw, ph, n, name)

    def lp(n, f):  # point on the right edge of a left-side process
        return (lx + pw, L[n][1] + ph * f)

    def rp(n, f):  # point on the left edge of a right-side process
        return (rx, Rp[n][1] + ph * f)

    def sl(sid, f=0.5):  # left (closed) edge of a store
        return (sx, sy(sid, f))

    def sr(sid, f=0.5):  # right (open) end of a store
        return (sx + sw, sy(sid, f))

    # Writes go into the store, reads come out of it; "both" has two heads.
    line(ax, [lp("1.0", 0.7), sl("D1", 0.5)], arrow_start=True)
    line(ax, [lp("1.0", 0.35), sl("D2", 0.75)])
    line(ax, [lp("6.0", 0.75), sl("D1", 0.2)])
    line(ax, [lp("6.0", 0.45), sl("D2", 0.4)])
    line(ax, [lp("2.0", 0.7), sl("D2", 0.1)], arrow_start=True)

    line(ax, [sr("D1"), rp("4.0", 0.75)])
    line(ax, [sr("D2", 0.75), rp("4.0", 0.45)])
    line(ax, [sr("D3", 0.85), rp("4.0", 0.15)])
    line(ax, [rp("3.0", 0.85), sr("D2", 0.25)])
    line(ax, [rp("3.0", 0.45), sr("D3", 0.3)], arrow_start=True)
    line(ax, [rp("5.0", 0.75), sr("D4")], arrow_start=True)
    line(ax, [rp("5.0", 0.3), sr("D5")], arrow_start=True)

    # External entities.
    def ent_left(name, n, text_in, text_out=None):
        y = L[n][1] + (ph - eh) / 2
        entity(ax, 0.3, y, ew, eh, name)
        yy = y + eh / 2 + (0.13 if text_out else 0)
        line(ax, [(0.3 + ew, yy), (lx, yy)])
        label(ax, (0.3 + ew + lx) / 2, yy + 0.09, text_in, size=7.6, va="bottom", bg=False)
        if text_out:
            line(ax, [(lx, yy - 0.26), (0.3 + ew, yy - 0.26)])
            label(ax, (0.3 + ew + lx) / 2, yy - 0.35, text_out, size=7.6, va="top", bg=False)

    def ent_right(name, n, text, inbound):
        y = Rp[n][1] + (ph - eh) / 2
        entity(ax, 11.35, y, ew, eh, name)
        yy = y + eh / 2
        pts = [(11.35, yy), (rx + pw, yy)]
        line(ax, pts if inbound else pts[::-1])
        label(ax, (rx + pw + 11.35) / 2, yy + 0.09, text, size=7.6, va="bottom", bg=False)

    ent_left("Encoder\n(Registrar, Adviser)", "1.0", "enrollment\ndetails")
    ent_left("Registrar", "6.0", "workbook file")
    ent_left("Reviewer\n(Registrar,\nICT, Adviser)", "2.0", "decision", "pending list")
    ent_right("DepEd", "4.0", "SF1", False)
    entity(ax, rx + (pw - ew) / 2, 7.65, ew, 0.55, "All staff")
    line(ax, [(rx + pw / 2, 6.2 + ph), (rx + pw / 2, 7.65)])
    label(ax, rx + pw / 2 + 0.1, 7.43, "reports, class lists", size=7.6, ha="left", bg=False)
    ent_right("Key Teacher", "3.0", "sections,\nplacements", True)
    ent_right("ICT Coordinator,\nAdministrator", "5.0", "accounts,\nschool years", True)

    store(ax, sx, 0.95, sw, sh, "D6", "Audit log")
    ax.text(sx + sw / 2, 0.82, "written by every process (lines not drawn)", ha="center", va="top", fontsize=7.4, color=MUTED, style="italic")
    save(fig, "fig3-dfd-level-1", pdf)


# --- UML activity shapes -----------------------------------------------------------
def action(ax, cx, cy, text, w=1.55, h=0.42):
    ax.add_patch(FancyBboxPatch((cx - w / 2, cy - h / 2), w, h, boxstyle="round,pad=0,rounding_size=0.13", facecolor=WHITE, edgecolor=LINE, lw=LW, zorder=2))
    ax.text(cx, cy, text, ha="center", va="center", fontsize=8.2, zorder=3, linespacing=1.15)


def decision(ax, cx, cy, s=0.24):
    ax.add_patch(Polygon([(cx, cy + s), (cx + s, cy), (cx, cy - s), (cx - s, cy)], closed=True, facecolor=WHITE, edgecolor=LINE, lw=LW, zorder=2))


def start(ax, cx, cy):
    ax.add_patch(Circle((cx, cy), 0.11, facecolor=INK, zorder=2))


def end(ax, cx, cy):
    ax.add_patch(Circle((cx, cy), 0.13, facecolor=WHITE, edgecolor=INK, lw=1.3, zorder=2))
    ax.add_patch(Circle((cx, cy), 0.075, facecolor=INK, zorder=3))


# --- Figure 4: activity diagram -------------------------------------------------------
def activity(pdf):
    fig, ax = figure(8.5, 11, 4, "Activity diagram: encoding and approving an enrollment")
    lanes = ["Encoder\n(Registrar or Adviser)", "System", "Key Teacher", "Reviewer\n(Registrar, ICT or Adviser)"]
    x0, lw_, top, bottom = 0.45, 1.9, 10.55, 0.95
    for i, name in enumerate(lanes):
        x = x0 + i * lw_
        ax.add_patch(Rectangle((x, bottom), lw_, top - bottom, facecolor=WHITE, edgecolor=LINE, lw=LW, zorder=0))
        ax.add_patch(Rectangle((x, top - 0.55), lw_, 0.55, facecolor=TINT, edgecolor=LINE, lw=LW, zorder=0.5))
        ax.text(x + lw_ / 2, top - 0.275, name, ha="center", va="center", fontsize=8.6, fontweight="bold", zorder=1, linespacing=1.15)
    E, S, K, R = (x0 + lw_ * (i + 0.5) for i in range(4))
    hw, h1, h2 = 0.775, 0.21, 0.24  # half width; half height of one- and two-line actions
    g = 7.4  # guard text size

    start(ax, E, 9.75)
    action(ax, E, 9.2, "Enter LRN")
    line(ax, [(E, 9.64), (E, 9.2 + h1)])
    action(ax, S, 9.2, "Look up LRN")
    line(ax, [(E + hw, 9.2), (S - hw, 9.2)])
    decision(ax, S, 8.5)
    line(ax, [(S, 9.2 - h1), (S, 8.74)])
    label(ax, S + 0.08, 8.1, "[enrolled\nthis year]", size=g, ha="left", bg=False)
    action(ax, S + 0.0, 7.65, "Show existing\nrecord", h=0.48)
    line(ax, [(S, 8.26), (S, 7.65 + h2)])
    end(ax, S, 7.05)
    line(ax, [(S, 7.65 - h2), (S, 7.18)])

    line(ax, [(S - 0.24, 8.5), (E, 8.5), (E, 7.65 + h2)])
    label(ax, E + 0.75, 8.6, "[else]", size=g, bg=False)
    action(ax, E, 7.65, "Fill in learner\ndetails", h=0.48)
    action(ax, E, 6.95, "Confirm privacy\nnotice given", h=0.48)
    line(ax, [(E, 7.65 - h2), (E, 6.95 + h2)])
    decision(ax, E, 6.3)  # merge: first submission or resubmission
    line(ax, [(E, 6.95 - h2), (E, 6.54)])
    action(ax, E, 5.7, "Submit form")
    line(ax, [(E, 6.06), (E, 5.7 + h1)])

    action(ax, S, 5.7, "Validate and\nlock record", h=0.48)
    line(ax, [(E + hw, 5.7), (S - hw, 5.7)])
    action(ax, S, 5.0, "Save as Pending")
    line(ax, [(S, 5.7 - h2), (S, 5.0 + h1)])
    action(ax, R, 5.0, "Review\nenrollment", h=0.48)
    line(ax, [(S + hw, 5.0), (R - hw, 5.0)])
    decision(ax, R, 4.3)
    line(ax, [(R, 5.0 - h2), (R, 4.54)])

    action(ax, S, 4.3, "Set status to\nEnrolled", h=0.48)
    line(ax, [(R - 0.24, 4.3), (S + hw, 4.3)])
    label(ax, R - 0.62, 4.42, "[approve]", size=g, bg=False)
    action(ax, R, 3.55, "Return with\nreason", h=0.48)
    line(ax, [(R, 4.06), (R, 3.55 + h2)])
    label(ax, R + 0.08, 3.9, "[reject]", size=g, ha="left", bg=False)

    action(ax, K, 3.45, "Assign to\nsection", h=0.48)
    line(ax, [(S, 4.3 - h2), (S, 3.88), (K, 3.88), (K, 3.45 + h2)])
    decision(ax, S, 2.85)
    line(ax, [(K - hw, 3.3), (S, 3.3), (S, 3.09)])
    line(ax, [(S + 0.24, 2.85), (K, 2.85), (K, 3.45 - h2)])
    label(ax, K - 0.45, 2.73, "[section full]", size=g, bg=False)
    action(ax, S, 1.95, "Save placement")
    line(ax, [(S, 2.61), (S, 1.95 + h1)])
    label(ax, S + 0.08, 2.47, "[seat free]", size=g, ha="left", bg=False)
    end(ax, S, 1.35)
    line(ax, [(S, 1.95 - h1), (S, 1.48)])

    action(ax, E, 2.3, "Correct\ndetails", h=0.48)
    line(ax, [(R, 3.55 - h2), (R, 2.3), (E + hw, 2.3)])
    line(ax, [(E - hw, 2.3), (x0 + 0.14, 2.3), (x0 + 0.14, 6.3), (E - 0.24, 6.3)])
    label(ax, x0 + 0.14, 4.3, "resubmit", size=g, ha="center")
    save(fig, "fig4-activity-enrollment", pdf)


# --- Figure 5: state machine -------------------------------------------------------------
def states(pdf):
    fig, ax = figure(13.33, 7.4, 5, "State machine diagram of an enrollment's status")
    sw, sh = 1.75, 0.62

    def state(cx, cy, name, w=sw):
        ax.add_patch(FancyBboxPatch((cx - w / 2, cy - sh / 2), w, sh, boxstyle="round,pad=0,rounding_size=0.18", facecolor=WHITE, edgecolor=LINE, lw=LW, zorder=2))
        ax.text(cx, cy, name, ha="center", va="center", fontsize=9.5, fontweight="bold", zorder=3)

    P, En, Rj = (2.7, 3.8), (6.3, 5.3), (6.3, 2.2)
    start(ax, 0.9, P[1])
    state(*P, "Pending")
    line(ax, [(1.01, P[1]), (P[0] - sw / 2, P[1])])
    label(ax, 1.4, P[1] + 0.17, "submit", size=8, bg=False)

    state(*En, "Enrolled")
    state(*Rj, "Rejected")
    line(ax, [(P[0] + sw / 2, P[1] + 0.15), (4.45, P[1] + 0.15), (4.45, En[1]), (En[0] - sw / 2, En[1])])
    label(ax, 4.55, 4.6, "approve", size=8, ha="left", bg=False)
    line(ax, [(P[0] + sw / 2, P[1] - 0.15), (4.45, P[1] - 0.15), (4.45, Rj[1] + 0.12), (Rj[0] - sw / 2, Rj[1] + 0.12)])
    label(ax, 4.55, 2.95, "reject [reason given]", size=8, ha="left", bg=False)
    line(ax, [(Rj[0], Rj[1] - sh / 2), (Rj[0], 1.35), (P[0], 1.35), (P[0], P[1] - sh / 2)])
    label(ax, 4.4, 1.22, "resubmit", size=8, va="top", bg=False)

    start(ax, En[0] - 0.45, 6.55)
    line(ax, [(En[0] - 0.45, 6.44), (En[0] - 0.45, En[1] + sh / 2)])
    label(ax, En[0] - 0.55, 6.0, "import\nfrom workbook", size=8, ha="right", bg=False)

    # Composite state: the learner has left the school.
    cx0, cy0, cw, ch = 9.25, 1.4, 3.6, 4.75
    ax.add_patch(FancyBboxPatch((cx0, cy0), cw, ch, boxstyle="round,pad=0,rounding_size=0.25", facecolor=WHITE, edgecolor=LINE, lw=LW, zorder=1))
    ax.plot([cx0, cx0 + cw], [cy0 + ch - 0.5, cy0 + ch - 0.5], color=LINE, lw=LW, zorder=1.5)
    ax.text(cx0 + cw / 2, cy0 + ch - 0.25, "Left the school", ha="center", va="center", fontsize=9.5, fontweight="bold")
    subs = [("Cancelled", 4.95, "cancel"), ("Dropped", 3.75, "drop"), ("Transferred Out", 2.55, "transfer out")]
    bus = [8.35, 7.95, 7.55]
    for i, ((name, y, ev), bx) in enumerate(zip(subs, bus)):
        state(cx0 + cw / 2 + 0.25, y, name, w=2.3)
        ys = En[1] + 0.18 - 0.18 * i
        line(ax, [(En[0] + sw / 2, ys), (bx, ys), (bx, y), (cx0 + cw / 2 + 0.25 - 1.15, y)])
        label(ax, cx0 - 0.08, y + 0.08, f"{ev}\n[reason given]", size=7.4, ha="right", va="bottom", bg=False)
    # Restore leaves the composite state, whichever substate it is in.
    line(ax, [(cx0 + cw / 2, cy0 + ch), (cx0 + cw / 2, 6.75), (En[0] + 0.45, 6.75), (En[0] + 0.45, En[1] + sh / 2)])
    label(ax, 9.0, 6.88, "restore", size=8, va="bottom", bg=False)
    ax.text(6.66, 0.72, "Every transition is written to the audit log with the user and the time.", ha="center", fontsize=8, color=MUTED, style="italic")
    save(fig, "fig5-state-enrollment-status", pdf)


# --- Figure 6: ERD, crow's foot ---------------------------------------------------------------
ROW = 0.235
HEAD = 0.34


def table(ax, x, y_top, name, cols, w=2.75):
    h = HEAD + ROW * len(cols) + 0.06
    y = y_top - h
    ax.add_patch(Rectangle((x, y), w, h, facecolor=WHITE, edgecolor=LINE, lw=LW, zorder=2))
    ax.add_patch(Rectangle((x, y_top - HEAD), w, HEAD, facecolor=TINT, edgecolor=LINE, lw=LW, zorder=2))
    ax.text(x + w / 2, y_top - HEAD / 2, name, ha="center", va="center", fontsize=9, fontweight="bold", zorder=3)
    ax.plot([x + 0.38, x + 0.38], [y, y_top - HEAD], color=LINE, lw=0.6, zorder=3)
    for i, (key, col, typ) in enumerate(cols):
        cy = y_top - HEAD - ROW * (i + 0.5) - 0.03
        ax.text(x + 0.19, cy, key, ha="center", va="center", fontsize=6.8, fontweight="bold", color=GREEN, zorder=3)
        ax.text(x + 0.47, cy, col, ha="left", va="center", fontsize=7.7, zorder=3, fontstyle="italic" if col.startswith("+") else "normal",
                color=MUTED if col.startswith("+") else INK)
        ax.text(x + w - 0.1, cy, typ, ha="right", va="center", fontsize=7.2, color=MUTED, zorder=3)
    return {"x": x, "y": y, "w": w, "h": h, "top": y_top}


def crow_end(ax, p, toward, kind):
    """Draw a crow's foot end at point p on the entity edge. toward = unit vector from p out along the line."""
    ux, uy = toward
    nx, ny = -uy, ux
    def seg(d0, d1, off0=0.0, off1=0.0):
        ax.plot([p[0] + ux * d0 + nx * off0, p[0] + ux * d1 + nx * off1], [p[1] + uy * d0 + ny * off0, p[1] + uy * d1 + ny * off1], color=LINE, lw=LW, zorder=4)
    many = kind.endswith("many")
    if many:
        seg(0.0, 0.2, 0.1, 0.0)
        seg(0.0, 0.2, -0.1, 0.0)
    bar_at = 0.28 if many else 0.12
    if kind in ("one", "one_many"):
        seg(bar_at, bar_at, 0.09, -0.09)
    if kind == "one":
        seg(0.2, 0.2, 0.09, -0.09)
    if kind.startswith("zero"):
        c = 0.36 if many else 0.3
        ax.add_patch(Circle((p[0] + ux * c, p[1] + uy * c), 0.065, facecolor=WHITE, edgecolor=LINE, lw=LW, zorder=5))
        if kind == "zero_one":
            seg(0.14, 0.14, 0.09, -0.09)


def relate(ax, pts, a_kind, b_kind):
    ax.plot(*zip(*pts), color=LINE, lw=LW, zorder=1.5)
    def unit(p, q):
        d = hypot(q[0] - p[0], q[1] - p[1])
        return ((q[0] - p[0]) / d, (q[1] - p[1]) / d)
    crow_end(ax, pts[0], unit(pts[0], pts[1]), a_kind)
    crow_end(ax, pts[-1], unit(pts[-1], pts[-2]), b_kind)


def hop(ax, x, y):
    """A small bridge where a horizontal line crosses a vertical one."""
    from matplotlib.patches import Arc
    ax.add_patch(Rectangle((x - 0.09, y - 0.03), 0.18, 0.06, facecolor=WHITE, edgecolor="none", zorder=1.6))
    ax.add_patch(Arc((x, y), 0.18, 0.18, theta1=0, theta2=180, edgecolor=LINE, lw=LW, zorder=1.7))
    ax.plot([x, x], [y - 0.04, y + 0.04], color=LINE, lw=LW, zorder=1.65)


def erd(pdf):
    fig, ax = figure(16, 10.2, 6, "Entity relationship diagram of the DJIHS Enrollment System database")
    T = {}
    T["school_years"] = table(ax, 0.4, 9.7, "school_years", [("PK", "id", "bigint"), ("UQ", "start_year", "smallint"), ("", "is_active", "boolean"),
                                                            ("", "is_enrollment_open", "boolean"), ("", "is_archived", "boolean"), ("", "grade_10_completers", "smallint")])
    T["sections"] = table(ax, 4.3, 9.7, "sections", [("PK", "id", "bigint"), ("FK", "school_year_id", "bigint"), ("", "grade_level", "tinyint"), ("FK", "strand_id", "bigint"),
                                                    ("", "name", "varchar"), ("", "capacity", "smallint"), ("FK", "adviser_id", "bigint"), ("", "is_active", "boolean")])
    T["teacher_assignments"] = table(ax, 8.4, 9.7, "teacher_assignments", [("PK", "id", "bigint"), ("FK", "section_id", "bigint"), ("FK", "user_id", "bigint"),
                                                                          ("", "subject_code", "varchar"), ("", "subject_name", "varchar")])
    T["users"] = table(ax, 12.6, 9.7, "users", [("PK", "id", "bigint"), ("UQ", "username", "varchar"), ("", "first_name", "varchar"), ("", "last_name", "varchar"),
                                               ("", "role", "varchar"), ("", "is_active", "boolean"), ("", "must_change_password", "boolean"), ("", "privacy_agreed_at", "timestamp")])
    T["strands"] = table(ax, 0.4, 7.05, "strands", [("PK", "id", "bigint"), ("UQ", "code", "varchar"), ("", "name", "varchar"), ("", "track", "varchar"), ("", "is_active", "boolean")])
    T["students"] = table(ax, 4.3, 6.05, "students", [("PK", "id", "bigint"), ("UQ", "lrn", "varchar(12)"), ("", "last_name", "varchar"), ("", "first_name", "varchar"),
                                                    ("", "middle_name", "varchar"), ("", "birthdate", "date"), ("", "sex", "varchar"), ("", "is_indigenous", "boolean"),
                                                    ("", "is_4ps_beneficiary", "boolean"), ("", "is_pwd", "boolean"), ("", "contact_number", "varchar"),
                                                    ("", "+ address, parents, guardian", ""), ("", "lock_version", "integer")])
    T["enrollments"] = table(ax, 8.4, 6.75, "enrollments", [("PK", "id", "bigint"), ("FK", "student_id", "bigint"), ("FK", "school_year_id", "bigint"), ("", "grade_level", "tinyint"),
                                                          ("FK", "strand_id", "bigint"), ("FK", "section_id", "bigint"), ("", "learner_type", "varchar"), ("", "status", "varchar"),
                                                          ("", "privacy_notice_at", "timestamp"), ("FK", "encoded_by", "bigint"), ("FK", "reviewed_by", "bigint"), ("", "lock_version", "integer")], w=2.9)
    T["document_submissions"] = table(ax, 12.6, 6.75, "document_submissions", [("PK", "id", "bigint"), ("FK", "enrollment_id", "bigint"), ("", "document_type", "varchar"),
                                                                             ("", "is_submitted", "boolean"), ("", "is_verified", "boolean")])
    T["revision_requests"] = table(ax, 12.6, 4.6, "revision_requests", [("PK", "id", "bigint"), ("FK", "enrollment_id", "bigint"), ("FK", "requested_by", "bigint"),
                                                                       ("", "changes", "json"), ("", "status", "varchar"), ("FK", "reviewed_by", "bigint")])
    T["audit_logs"] = table(ax, 0.4, 4.75, "audit_logs", [("PK", "id", "bigint"), ("FK", "user_id", "bigint"), ("", "event", "varchar"), ("", "auditable_type", "varchar"),
                                                       ("", "auditable_id", "bigint"), ("", "old_values", "json"), ("", "new_values", "json"), ("", "created_at", "timestamp")])

    def right(n): return T[n]["x"] + T[n]["w"]
    def left(n): return T[n]["x"]
    def top(n): return T[n]["top"]
    def bottom(n): return T[n]["y"]

    # school_years 1 ── N sections
    relate(ax, [(right("school_years"), 9.1), (left("sections"), 9.1)], "one", "zero_many")
    # school_years 1 ── N enrollments: down the gap below sections, then across.
    relate(ax, [(2.6, bottom("school_years")), (2.6, 7.3), (8.0, 7.3), (8.0, 6.3), (left("enrollments"), 6.3)], "one", "zero_many")
    # strands 0..1 ── N sections (junior high sections have no strand)
    relate(ax, [(right("strands"), 6.75), (3.75, 6.75), (3.75, 7.75), (left("sections"), 7.75)], "zero_one", "zero_many")
    hop(ax, 3.75, 7.3)
    # strands 0..1 ── N enrollments
    relate(ax, [(right("strands"), 6.3), (4.0, 6.3), (4.0, 6.55), (7.75, 6.55), (7.75, 5.9), (left("enrollments"), 5.9)], "zero_one", "zero_many")
    # students 1 ── 1..N enrollments
    relate(ax, [(right("students"), 4.9), (left("enrollments"), 4.9)], "one", "one_many")
    # sections 0..1 ── N enrollments
    relate(ax, [(right("sections"), 7.9), (8.15, 7.9), (8.15, 7.05), (8.95, 7.05), (8.95, top("enrollments"))], "zero_one", "zero_many")
    # sections 1 ── N teacher_assignments; users 1 ── N teacher_assignments
    relate(ax, [(right("sections"), 9.1), (left("teacher_assignments"), 9.1)], "one", "zero_many")
    relate(ax, [(left("users"), 9.1), (right("teacher_assignments"), 9.1)], "one", "zero_many")
    # users 0..1 ── N sections (adviser), over the top
    relate(ax, [(13.9, top("users")), (13.9, 9.98), (5.65, 9.98), (5.65, top("sections"))], "zero_one", "zero_many")
    # enrollments 1 ── N document_submissions, revision_requests
    relate(ax, [(right("enrollments"), 6.2), (left("document_submissions"), 6.2)], "one", "zero_many")
    relate(ax, [(right("enrollments"), 4.0), (left("revision_requests"), 4.0)], "one", "zero_many")
    # users 0..1 ── N audit_logs, around the right and bottom edges
    relate(ax, [(right("users"), 8.0), (15.75, 8.0), (15.75, 1.45), (1.75, 1.45), (1.75, bottom("audit_logs"))], "zero_one", "zero_many")

    lx, ly = 0.45, 1.05
    ax.text(lx, ly, "Notation:", fontsize=8, fontweight="bold", va="center")
    for i, (k, t) in enumerate([("one", "exactly one"), ("zero_one", "zero or one"), ("one_many", "one or many"), ("zero_many", "zero or many")]):
        x = lx + 1.0 + i * 2.15
        ax.plot([x, x + 0.7], [ly, ly], color=LINE, lw=LW)
        crow_end(ax, (x + 0.7, ly), (-1, 0), k)
        ax.text(x + 0.85, ly, t, va="center", fontsize=7.8)
    ax.text(9.6, ly, "PK primary key · FK foreign key · UQ unique", va="center", fontsize=7.8)
    ax.text(0.45, 0.68, "One enrollment per learner per school year (unique student_id + school_year_id). Not drawn: encoded_by, reviewed_by, requested_by and "
            "document updated_by, which also point to users.", fontsize=7.6, color=MUTED, style="italic", va="center")
    save(fig, "fig6-erd", pdf)


# --- Figure 7: old and new -----------------------------------------------------------------
def old_vs_new(pdf):
    fig, ax = figure(13.33, 9.0, 7, "Architecture of the old and new systems")

    def stack(x0, title, rows):
        ax.text(x0 + 2.9, 8.45, title, ha="center", fontsize=10.5, fontweight="bold", color=INK)
        for i, (tier, body) in enumerate(rows):
            y = 7.35 - i * 1.05
            ax.add_patch(Rectangle((x0, y), 1.45, 0.8, facecolor=SOFT, edgecolor=LINE, lw=LW, zorder=2))
            ax.text(x0 + 0.72, y + 0.4, tier, ha="center", va="center", fontsize=8, fontweight="bold", color=GREEN, zorder=3, linespacing=1.15)
            ax.add_patch(Rectangle((x0 + 1.45, y), 4.35, 0.8, facecolor=WHITE, edgecolor=LINE, lw=LW, zorder=2))
            ax.text(x0 + 1.6, y + 0.4, body, ha="left", va="center", fontsize=8.2, zorder=3, linespacing=1.3)
            if i:
                line(ax, [(x0 + 3.62, y + 1.05), (x0 + 3.62, y + 0.8)], arrow_start=True)

    stack(0.45, "Old system", [("Presentation", "About 30 static HTML pages, one set per role\nJavaScript fetch calls; login state kept in localStorage"),
                               ("Application", "28 separate PHP API files\nOnly 3 check the server session"),
                               ("Data", "MySQL, 14 tables")])
    stack(7.05, "New system", [("Presentation", "One React and TypeScript app, pages sent by Inertia\nMenus and pages per role"),
                               ("Application", "Laravel 12: middleware checks every request;\ncontrollers, services and validation"),
                               ("Data", "PostgreSQL or SQLite, 10 tables\nunique keys, row locks, version checks")])
    ax.annotate("", xy=(7.0, 6.3), xytext=(6.3, 6.3), arrowprops=dict(arrowstyle="-|>", color=LINE, lw=1.4, mutation_scale=12))

    rows = [("Aspect", "Old system", "New system"),
            ("Access control", "Checked in the browser", "Checked on the server for every page and action"),
            ("Two users at once", "Duplicates and overwrites possible", "One save wins; the other is told why"),
            ("Validation", "Spread across each API file", "Shared server-side rules for every form"),
            ("Testing", "None automated", "145 feature tests, 43 browser tests"),
            ("Data privacy", "No notice or access log", "Privacy notice, staff agreement, access log"),
            ("Deployment", "Local XAMPP-style server", "Render (cloud) or a school computer")]
    cw = [2.3, 4.3, 5.8]
    x0, y0, rh = 0.45, 3.85, 0.42
    for r, cells in enumerate(rows):
        x = x0
        for c, text in enumerate(cells):
            ax.add_patch(Rectangle((x, y0 - r * rh), cw[c], rh, facecolor=TINT if r == 0 else (SOFT if c == 0 else WHITE), edgecolor=LINE, lw=0.8, zorder=2))
            ax.text(x + 0.12, y0 - r * rh + rh / 2, text, va="center", fontsize=8.4, fontweight="bold" if r == 0 or c == 0 else "normal", zorder=3)
            x += cw[c]
    save(fig, "fig7-old-vs-new", pdf)


# --- Figure 8: use cases ----------------------------------------------------------------
def use_cases(pdf):
    """UML 2 use case diagram, checked against every route in routes/web.php, the
    ability each one needs (app/Enums/Role.php), and which pages show the button.

    Teachers and office staff stand on the left, the Administrator on the right.
    «include» is behaviour that always happens; «extend» is optional and points
    back at the use case it extends. Lines end at the left or right tip of their
    use case, and the «include»/«extend» circles are placed in the gaps between
    the Administrator's lines, so no line passes through a circle.
    """
    from math import hypot

    w, h = 13.2, 21.5
    fig, ax = figure(w, h, 8, "Use case diagram of the DJIHS Enrollment System")
    D, step = 1.06, 1.13
    R = D / 2
    top = h - 1.5
    row = lambda n: top - (n - 1) * step  # noqa: E731

    MX, SX = 4.45, 10.45            # main use cases; included and extending ones
    LAX, RAX = 1.25, 12.35          # actor columns, left and right
    bx0, bx1 = 2.4, 11.35
    by1, by0 = h - 0.35, row(17) - R - 0.4
    ax.add_patch(Rectangle((bx0, by0), bx1 - bx0, by1 - by0, facecolor=WHITE, edgecolor=LINE, linewidth=LW * 1.2, zorder=1))
    ax.text((bx0 + bx1) / 2, by1 - 0.32, "DJIHS Enrollment System", ha="center", va="center", fontsize=13, fontweight="bold", color=INK)

    def circle(x, y, text):
        ax.add_patch(Circle((x, y), R, facecolor=WHITE, edgecolor=LINE, linewidth=LW * 1.1, zorder=3))
        ax.text(x, y, text, ha="center", va="center", fontsize=8.3, color=INK, zorder=4, linespacing=1.1)

    main = [
        ("login", "Log In"),
        ("own", "View Own\nSection or\nClasses"),
        ("export", "Export SF1,\nSF8 and\nClass Lists"),
        ("sections", "Manage\nSections\nand Strands"),
        ("search", "Search and\nView\nLearners"),
        ("encode", "Encode New\nEnrollment"),
        ("review", "Review\nEnrollments"),
        ("update", "Update\nLearner\nRecord"),
        ("docs", "Track\nSubmitted\nDocuments"),
        ("approve", "Approve\nChange\nRequests"),
        ("leave", "Record\nDropout or\nTransfer Out"),
        ("import", "Import\nLearners\n(Excel, CSV)"),
        ("users", "Manage\nUser\nAccounts"),
        ("audit", "View\nAudit Log"),
        ("reports", "View Reports\nand\nAnalytics"),
        ("years", "Manage\nSchool\nYears"),
        ("backup", "Back Up\nData"),
    ]
    MY = {key: row(n + 1) for n, (key, _) in enumerate(main)}
    for key, text in main:
        circle(MX, MY[key], text)

    def actor(x, y, name):
        r = 0.14
        ax.add_patch(Circle((x, y + 0.44), r, facecolor=WHITE, edgecolor=LINE, linewidth=LW * 1.25, zorder=4))
        for xs, ys in (([x, x], [y + 0.30, y - 0.06]), ([x - 0.24, x + 0.24], [y + 0.19, y + 0.19]),
                       ([x, x - 0.19], [y - 0.06, y - 0.38]), ([x, x + 0.19], [y - 0.06, y - 0.38])):
            ax.plot(xs, ys, color=LINE, linewidth=LW * 1.25, zorder=4, solid_capstyle="round")
        ax.text(x, y - 0.56, name, ha="center", va="center", fontsize=10, color=INK, zorder=4)

    # Who can do what: the ability each route needs, and only where a page offers it.
    # (Registrar and ICT Coordinator may download section forms, but no page shows them the button.)
    left = [
        ("Subject\nTeacher", 2.0, ["login", "own", "export"]),
        ("Key Teacher", 4.3, ["login", "export", "sections", "search"]),
        ("Adviser", 6.6, ["login", "own", "export", "search", "encode", "review", "update", "docs"]),
        ("Registrar", 10.1, ["login", "search", "encode", "review", "update", "docs", "approve", "leave",
                             "import", "users", "audit", "reports"]),
        ("ICT\nCoordinator", 13.6, ["login", "search", "encode", "review", "update", "docs", "approve", "leave",
                                    "import", "users", "audit", "years", "backup"]),
    ]
    for name, at, uses in left:
        y = row(at)
        actor(LAX, y, name)
        for u in uses:
            ax.plot([LAX + 0.28, MX - R], [y + 0.17, MY[u]], color=LINE, linewidth=LW * 0.8, zorder=1.5)

    # The Administrator can do everything except the adviser's and subject teacher's own lists.
    admin_uses = [k for k, _ in main if k != "own"]

    # (base, text, kind): the included or extending use case beside its base.
    side = [
        ("login", "Verify\nAccount\nand Role", "include"),
        ("login", "Accept\nConfidentiality\nAgreement", "extend"),
        ("export", "Log the\nData Export", "include"),
        ("sections", "Assign\nLearners to\nSections", "extend"),
        ("search", "Print\nLearner\nData Record", "extend"),
        ("encode", "Check for\nDuplicate\nLearner", "include"),
        ("review", "Approve\nor Reject", "include"),
        ("update", "Send as\nChange\nRequest", "extend"),
        ("import", "Check File\nBefore\nImporting", "include"),
        ("users", "Assign\nRole", "include"),
        ("reports", "Export\nReport", "extend"),
        ("years", "Open or\nClose\nEnrollment", "extend"),
    ]

    def seg_dist(p, a, b):
        (px, py), (ax_, ay), (bx, by) = p, a, b
        dx, dy = bx - ax_, by - ay
        t = max(0.0, min(1.0, ((px - ax_) * dx + (py - ay) * dy) / (dx * dx + dy * dy)))
        return hypot(px - (ax_ + t * dx), py - (ay + t * dy))

    def place(admin_y):
        """Put each side circle as near its base as it can go without touching an
        Administrator line or another side circle. Returns the positions and the
        total distance moved."""
        anchor = (RAX - 0.28, admin_y + 0.17)
        lines = [(anchor, (MX + R, MY[u])) for u in admin_uses]
        placed, moved = [], 0.0
        first_row = {}
        for base, _, _ in side:
            first_row[base] = first_row.get(base, 0) + 1
        seen = {}
        for base, _, _ in side:
            seen[base] = seen.get(base, 0) + 1
            want = MY[base] - (seen[base] - 1) * step  # a second circle for the same base sits one row lower
            want = MY[base] if seen[base] == 1 else MY[base] - step
            best = None
            for k in range(0, 400):
                for y in ((want - k * 0.01, want + k * 0.01) if k else (want,)):
                    if not (by0 + R + 0.1 <= y <= by1 - 0.75 - R):
                        continue
                    if any(seg_dist((SX, y), a, b) < R + 0.07 for a, b in lines):
                        continue
                    if any(abs(y - q) < D + 0.1 for q in placed):
                        continue
                    best = y
                    break
                if best is not None:
                    break
            if best is None:
                return None, float("inf")
            placed.append(best)
            moved += abs(best - want)
        return placed, moved

    # Try heights for the Administrator; prefer one near the middle (shorter lines)
    # that still lets every side circle stay close to its use case.
    best_y, best, best_cost = None, None, float("inf")
    for k in range(0, 121):
        y = row(7) - k * 0.05
        ys, moved = place(y)
        cost = moved + 0.35 * abs(y - row(9))
        if ys and cost < best_cost:
            best_y, best, best_cost = y, ys, cost
    ys = best

    actor(RAX, best_y, "Administrator")
    for u in admin_uses:
        ax.plot([RAX - 0.28, MX + R], [best_y + 0.17, MY[u]], color=LINE, linewidth=LW * 0.8, zorder=1.5)

    for (base, text, kind), y in zip(side, ys):
        circle(SX, y, text)
        bx, by = MX, MY[base]
        d = hypot(SX - bx, y - by)
        ux, uy = (SX - bx) / d, (y - by) / d
        on_base, on_side = (bx + ux * R, by + uy * R), (SX - ux * R, y - uy * R)
        p0, p1 = (on_base, on_side) if kind == "include" else (on_side, on_base)
        ax.add_patch(FancyArrowPatch(p0, p1, arrowstyle="->,head_length=6,head_width=3.5", linestyle=(0, (4, 3)),
                                     color=LINE, linewidth=LW, shrinkA=0, shrinkB=0, zorder=2))
        t = 0.32  # label near the base end, clear of the Administrator's lines
        lx, ly = on_base[0] + (on_side[0] - on_base[0]) * t, on_base[1] + (on_side[1] - on_base[1]) * t
        label(ax, lx, ly + 0.13, f"«{kind}»", size=7.6)

    save(fig, "fig8-use-case", pdf)


if __name__ == "__main__":
    with PdfPages(OUT / "DJIHS-system-diagrams.pdf") as pdf:
        for draw in (architecture, context, dfd_level1, activity, states, erd, old_vs_new, use_cases):
            draw(pdf)
    print("Wrote", sorted(p.name for p in OUT.iterdir() if p.suffix in {".png", ".svg", ".pdf"}))
