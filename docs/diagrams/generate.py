"""
Draws the DJIHS Enrollment System diagrams with matplotlib.

    python3 docs/diagrams/generate.py

Writes one PNG and one SVG per diagram, plus all of them in one PDF, next to
this file. Architecture diagrams follow the C4 model (system context and
containers: one level of detail per diagram, each with a title and a key);
the enrollment flow is a swimlane activity diagram with one lane per role.
"""

from pathlib import Path
import textwrap

import matplotlib

matplotlib.use("Agg")
import matplotlib.pyplot as plt  # noqa: E402
from matplotlib.backends.backend_pdf import PdfPages  # noqa: E402
from matplotlib.patches import Ellipse, FancyArrowPatch, FancyBboxPatch, Polygon, Rectangle  # noqa: E402

OUT = Path(__file__).resolve().parent

# School green for the system itself, greys for things outside it.
GREEN_DARK = "#0a4d1c"
GREEN = "#13803a"
GREEN_SOFT = "#e7f4ea"
PERSON = "#08451a"
EXTERNAL = "#6b7280"
EXTERNAL_SOFT = "#f1f2f4"
OLD = "#9a3412"
OLD_SOFT = "#fdf0e8"
INK = "#1f2937"
MUTED = "#5b6472"
LINE = "#4b5563"
AMBER = "#b45309"
WHITE = "#ffffff"

plt.rcParams.update({"font.family": "DejaVu Sans", "font.size": 10, "svg.fonttype": "none"})


def canvas(w, h, title, subtitle):
    fig = plt.figure(figsize=(w, h))
    ax = fig.add_axes((0, 0, 1, 1))
    ax.set_xlim(0, w)
    ax.set_ylim(0, h)
    ax.axis("off")
    ax.text(0.45, h - 0.5, title, fontsize=19, fontweight="bold", color=INK, va="top")
    ax.text(0.45, h - 0.98, subtitle, fontsize=11, color=MUTED, va="top")
    return fig, ax


def wrap(text, width):
    return "\n".join(textwrap.fill(p, width) for p in text.split("\n"))


def box(ax, x, y, w, h, title, tag=None, body=None, fill=GREEN, text=WHITE, edge=None, size=10.5, wrapw=None, dashed=False, align="center", bold=True):
    """A rounded box: bold title, an optional [tag] line and wrapped body."""
    ax.add_patch(FancyBboxPatch((x, y), w, h, boxstyle="round,pad=0.02,rounding_size=0.12", facecolor=fill,
                                edgecolor=edge or fill, linewidth=1.4, linestyle="--" if dashed else "-", zorder=2))
    wrapw = wrapw or max(14, int(w * 9.5))
    lines = [(wrap(title, wrapw), {"fontweight": "bold" if bold else "normal", "fontsize": size})]
    if tag:
        lines.append((f"[{tag}]", {"fontsize": size - 2, "fontstyle": "italic"}))
    if body:
        lines.append((wrap(body, wrapw), {"fontsize": size - 1.5}))
    block = "\n".join(t for t, _ in lines)
    total = block.count("\n") + 1
    line_h = 0.19 * size / 10
    cy = y + h / 2 + (total - 1) * line_h / 2
    tx = x + w / 2 if align == "center" else x + 0.15
    for t, style in lines:
        n = t.count("\n") + 1
        ax.text(tx, cy - (n - 1) * line_h / 2, t, ha=align, va="center", color=text, zorder=3, linespacing=1.25, **style)
        cy -= n * line_h + 0.04


def person(ax, x, y, w, h, title, body=None, fill=PERSON):
    """C4 person: a box with a head above it."""
    ax.add_patch(Ellipse((x + w / 2, y + h + 0.2), 0.42, 0.42, facecolor=fill, edgecolor=fill, zorder=2))
    box(ax, x, y, w, h, title, body=body, fill=fill, size=10)


def database(ax, x, y, w, h, title, tag, body, fill=GREEN):
    """A cylinder."""
    e = 0.32
    ax.add_patch(Rectangle((x, y + e / 2), w, h - e, facecolor=fill, edgecolor=fill, zorder=2))
    ax.add_patch(Ellipse((x + w / 2, y + e / 2), w, e, facecolor=fill, edgecolor=fill, zorder=2))
    ax.add_patch(Ellipse((x + w / 2, y + h - e / 2), w, e, facecolor=fill, edgecolor=WHITE, linewidth=1.2, zorder=3))
    box(ax, x, y + 0.12, w, h - 0.45, title, tag, body, fill="none", size=10.5)


def boundary(ax, x, y, w, h, label, color=GREEN):
    ax.add_patch(FancyBboxPatch((x, y), w, h, boxstyle="round,pad=0.02,rounding_size=0.15", facecolor="none",
                                edgecolor=color, linewidth=1.6, linestyle=(0, (6, 4)), zorder=1))
    ax.text(x + 0.18, y + 0.14, label, fontsize=10.5, fontweight="bold", color=color, va="bottom", zorder=3)


def arrow(ax, a, b, label=None, dashed=False, rad=0.0, color=LINE, lx=0.0, ly=0.0, both=False, fontsize=8.8, lw=1.4):
    style = "<|-|>" if both else "-|>"
    ax.add_patch(FancyArrowPatch(a, b, arrowstyle=style, mutation_scale=13, color=color, linewidth=lw,
                                 linestyle=(0, (5, 4)) if dashed else "-", connectionstyle=f"arc3,rad={rad}", zorder=1.5,
                                 shrinkA=2, shrinkB=2))
    if label:
        mx, my = (a[0] + b[0]) / 2 + lx, (a[1] + b[1]) / 2 + ly
        ax.text(mx, my, label, fontsize=fontsize, color=INK, ha="center", va="center", zorder=4, linespacing=1.2,
                bbox=dict(boxstyle="round,pad=0.25", facecolor=WHITE, edgecolor="none", alpha=0.95))


def path(ax, points, dashed=False, color=LINE):
    """Straight segments through the points, with an arrowhead on the last one."""
    style = dict(color=color, linewidth=1.4, linestyle=(0, (5, 4)) if dashed else "-", zorder=1.5)
    for a, b in zip(points[:-2], points[1:-1]):
        ax.plot([a[0], b[0]], [a[1], b[1]], solid_capstyle="butt", **style)
    ax.add_patch(FancyArrowPatch(points[-2], points[-1], arrowstyle="-|>", mutation_scale=13, shrinkA=0, shrinkB=1,
                                 color=color, linewidth=1.4, linestyle=style["linestyle"], zorder=1.5))


def key(ax, x, y, items):
    """A legend row at the bottom: (label, color, kind)."""
    ax.text(x, y + 0.1, "Key", fontsize=9.5, fontweight="bold", color=INK, va="center")
    cx = x + 0.55
    for label, color, kind in items:
        if kind == "line":
            ax.plot([cx, cx + 0.45], [y + 0.1, y + 0.1], color=color, linewidth=1.4, linestyle=(0, (5, 4)))
        elif kind == "boundary":
            ax.add_patch(Rectangle((cx, y - 0.02), 0.45, 0.24, facecolor="none", edgecolor=color, linestyle=(0, (4, 3))))
        else:
            ax.add_patch(FancyBboxPatch((cx, y - 0.02), 0.45, 0.24, boxstyle="round,pad=0.01,rounding_size=0.05", facecolor=color, edgecolor=color))
        ax.text(cx + 0.55, y + 0.1, label, fontsize=9, color=MUTED, va="center")
        cx += 0.75 + len(label) * 0.075


def save(fig, name, pdf):
    for ext in ("png", "svg"):
        fig.savefig(OUT / f"{name}.{ext}", dpi=200 if ext == "png" else None, facecolor=WHITE)
    pdf.savefig(fig, facecolor=WHITE)
    plt.close(fig)


# 1. System context ------------------------------------------------------------
def system_context(pdf):
    fig, ax = canvas(16, 10, "1. System context: who uses the DJIHS Enrollment System",
                     "C4 level 1. The system, the people who use it, and the things outside it that it exchanges data with.")
    roles = [
        ("Administrator", "Everything, including the DepEd rates on Reports"),
        ("ICT Coordinator", "Accounts, school years, backups"),
        ("Registrar", "Encodes, reviews, imports the workbook, Reports"),
        ("Key Teacher", "Sections, strands, placing learners"),
        ("Adviser", "Encodes, requests changes, My Section, SF1"),
        ("Subject Teacher", "My Classes: learners per subject"),
    ]
    w, gap, x0 = 2.25, 0.25, 0.75
    for i, (t, b) in enumerate(roles):
        x = x0 + i * (w + gap)
        person(ax, x, 6.95, w, 1.05, t, b)
        arrow(ax, (x + w / 2, 6.95), (5.6 + (i + 0.5) * 4.8 / 6, 5.55))
    ax.text(8.0, 6.55, "Sign in with their own account; each role sees only its pages", fontsize=9.5, color=INK, ha="center",
            bbox=dict(boxstyle="round,pad=0.3", facecolor=WHITE, edgecolor="none"))

    box(ax, 5.6, 3.55, 4.8, 2.0, "DJIHS Enrollment System", "Software system",
        "Records each learner's enrollment, has it reviewed, places learners in sections, keeps an audit log, and produces DepEd forms and reports.",
        fill=GREEN, size=12, wrapw=48)

    person(ax, 0.75, 3.85, 2.6, 1.25, "Parent or guardian", "Gives the enrollment forms; is shown the privacy notice. Has no account.", fill=EXTERNAL)
    arrow(ax, (3.35, 4.45), (5.6, 4.45), "Information on paper,\nencoded by staff", dashed=True)

    ext = [
        (4.95, "School's Excel workbook", "ENROLLMENT_ENCODING sheet the school used before", "Imported (.xlsx or .csv);\nblank template downloaded"),
        (3.15, "DepEd School Form 1 (SF1)", "Official class register", "Filled in from a section's\nlearners (Excel)"),
        (1.35, "DepEd LIS", "Learner Information System", "Not connected: staff still\nencode in LIS separately"),
    ]
    for y, t, b, lab in ext:
        box(ax, 12.55, y - 0.45, 2.9, 1.15, t, "External", b, fill=EXTERNAL, size=10)
        arrow(ax, (10.4, 4.55 + (y - 3.15) * 0.45), (12.55, y + 0.12), lab, dashed=(t == "DepEd LIS"), both=(t.startswith("School")), lx=0.05, ly=0.34)

    box(ax, 5.6, 1.35, 4.8, 1.25, "Runs on Render (cloud) or a school computer", "Deployment",
        "Same software either way. Render: PostgreSQL and https. School computer: one SQLite file on the school network.",
        fill=EXTERNAL_SOFT, text=INK, edge=EXTERNAL, size=10)
    arrow(ax, (8.0, 3.55), (8.0, 2.6))

    key(ax, 0.75, 0.45, [("Person with an account", PERSON, "box"), ("The system", GREEN, "box"),
                         ("Outside the system", EXTERNAL, "box"), ("Not automated / manual", LINE, "line")])
    save(fig, "01-system-context", pdf)


# 2. Containers ------------------------------------------------------------------
def containers(pdf):
    fig, ax = canvas(16, 11, "2. Containers: how the system is built",
                     "C4 level 2. The running parts of the system and how they talk to each other.")
    person(ax, 6.6, 8.55, 2.8, 0.95, "School staff", "Six roles, in a web browser on a computer or phone")

    boundary(ax, 0.6, 2.3, 14.8, 5.75, "DJIHS Enrollment System")
    box(ax, 1.0, 5.35, 4.1, 2.15, "Web pages", "React 19 + TypeScript + Tailwind, sent by Inertia",
        "Forms, tables and charts in the browser. Green sidebar, text-size control, works on phones. No business rules: everything is checked again on the server.")
    box(ax, 6.0, 4.55, 4.6, 2.95, "Web application", "Laravel 12 on PHP 8.3",
        "Every request passes: sign-in and session · role abilities · forced password change · confidentiality agreement.\n"
        "Services: enrollment with duplicate checks · review workflow · revision requests · sectioning with seat limits · reports · import and export · access logging.",
        wrapw=50)
    database(ax, 11.6, 5.0, 3.4, 2.4, "Database", "PostgreSQL on Render · SQLite on a school computer",
             "10 tables. Unique keys and row locks stop duplicate learners and overfilled sections.")
    box(ax, 11.6, 2.75, 3.4, 1.45, "Files", "Disk", "SF1 template; imported workbooks held only until confirmed", fill=GREEN, size=10)

    arrow(ax, (8.0, 8.55), (3.05, 7.5), "Uses, over https\n(http on a school network)", lx=-0.3, ly=0.15)
    arrow(ax, (5.1, 6.45), (6.0, 6.45), "Page visits and\nform posts (JSON)", both=True, ly=0.55)
    arrow(ax, (10.6, 6.2), (11.6, 6.2), "Reads and writes\n(in transactions)", both=True, ly=0.6)
    arrow(ax, (10.6, 4.9), (11.6, 3.7), "Reads template,\nstages uploads", lx=0.05, ly=0.15)

    box(ax, 6.0, 0.75, 4.6, 1.15, "Downloads and uploads", "Excel / CSV / ZIP",
        "SF1, class lists, Reports, audit log, full backup; workbook import", fill=EXTERNAL, size=10)
    arrow(ax, (8.3, 4.55), (8.3, 1.9), both=True)

    key(ax, 0.75, 0.3, [("Person", PERSON, "box"), ("Part of the system", GREEN, "box"),
                        ("Outside the running system", EXTERNAL, "box"), ("System boundary", GREEN, "boundary")])
    save(fig, "02-containers", pdf)


# 3. Enrollment flow (swimlanes) ------------------------------------------------
def enrollment_flow(pdf):
    W, H = 20, 12.5
    fig, ax = canvas(W, H, "3. Enrollment flow: from paper forms to a learner in a section",
                     "Swimlane activity diagram. Each lane is who does the step; arrows show the order, left to right.")
    lanes = ["Parent or\nguardian", "Encoder\n(Registrar or Adviser)", "System", "Reviewer\n(Registrar, ICT\nor Adviser)", "Key Teacher", "Adviser and\nSubject Teacher"]
    top, lane_h, left = 10.9, 1.62, 0.5
    for i, name in enumerate(lanes):
        y = top - (i + 1) * lane_h
        ax.add_patch(Rectangle((left, y), W - 1.0, lane_h, facecolor=GREEN_SOFT if i % 2 else WHITE, edgecolor="#cfd8d2", linewidth=1, zorder=0))
        ax.add_patch(Rectangle((left, y), 2.1, lane_h, facecolor="#dbeee0", edgecolor="#cfd8d2", zorder=0.5))
        ax.text(left + 1.05, y + lane_h / 2, name, ha="center", va="center", fontsize=10, fontweight="bold", color=INK, linespacing=1.3)

    def ly(i):
        return top - (i + 0.5) * lane_h

    def step(i, x, w, text, fill=GREEN, txt=WHITE, h=1.15, edge=None):
        box(ax, x, ly(i) - h / 2, w, h, text, fill=fill, text=txt, size=9.5, edge=edge, wrapw=int(w * 10.5), bold=False)

    def diamond(i, cx, text, w=2.0, h=1.3):
        cy = ly(i)
        ax.add_patch(Polygon([(cx, cy + h / 2), (cx + w / 2, cy), (cx, cy - h / 2), (cx - w / 2, cy)], closed=True,
                             facecolor=WHITE, edgecolor=GREEN_DARK, linewidth=1.6, zorder=2))
        ax.text(cx, cy, text, ha="center", va="center", fontsize=8.8, color=INK, zorder=3, linespacing=1.2)

    c1, c2, c3, c4, c5 = 3.0, 6.0, 9.2, 12.8, 15.7
    w = 2.5

    # Parent: start, forms, privacy notice.
    ax.add_patch(Ellipse((2.78, ly(0)), 0.32, 0.32, facecolor=INK, zorder=2))
    step(0, c1, w, "Brings the forms (PSA certificate, report card…)", fill=EXTERNAL)
    arrow(ax, (2.94, ly(0)), (c1, ly(0)))
    step(0, c2, w, "Is shown the privacy notice", fill=EXTERNAL)
    arrow(ax, (c1 + w, ly(0)), (c2, ly(0)))

    # Encoder types the LRN; the system checks it.
    step(1, c2, w, "Types the 12-digit LRN")
    arrow(ax, (c2 + w / 2, ly(0) - 0.58), (c2 + w / 2, ly(1) + 0.58))
    diamond(2, c2 + w / 2, "Already\nenrolled this\nyear?")
    arrow(ax, (c2 + w / 2, ly(1) - 0.58), (c2 + w / 2, ly(2) + 0.65))
    step(2, c1 - 0.4, w - 0.2, "Warns at once with a link to the record. Stop.", fill=AMBER)
    arrow(ax, (c2 + w / 2 - 1.0, ly(2)), (c1 - 0.4 + w - 0.2, ly(2)), "yes", ly=0.2)

    # Fill in and submit.
    step(1, c3, w + 0.3, "Fills in learner, parents and address (or uses a returning learner's saved details); ticks the privacy box; submits", h=1.3)
    path(ax, [(c2 + w / 2 + 1.0, ly(2)), (8.75, ly(2)), (8.75, ly(1)), (c3, ly(1))])
    ax.text(8.32, ly(2) + 0.12, "no", fontsize=8.8, color=INK)

    step(2, c3, w + 0.3, "Checks the form; locks the learner's record so two staff can't save the same person", h=1.3)
    arrow(ax, (c3 + (w + 0.3) / 2, ly(1) - 0.65), (c3 + (w + 0.3) / 2, ly(2) + 0.65))
    step(2, c4, w, "Saves as Pending and writes the audit log")
    arrow(ax, (c3 + w + 0.3, ly(2)), (c4, ly(2)))

    # Review.
    diamond(3, c4 + w / 2, "Approve or\nreject? (first\ndecision wins)", w=2.2, h=1.35)
    arrow(ax, (c4 + w / 2, ly(2) - 0.58), (c4 + w / 2, ly(3) + 0.68))
    step(3, c3, w + 0.3, "Rejected with a reason", fill=AMBER, h=0.9)
    arrow(ax, (c4 + w / 2 - 1.1, ly(3) - 0.15), (c3 + w + 0.3, ly(3) - 0.15), "reject", ly=-0.25)
    path(ax, [(c3 + w + 0.3, ly(3) + 0.28), (12.35, ly(3) + 0.28), (12.35, ly(1)), (c3 + w + 0.3, ly(1))], dashed=True)
    ax.text(12.45, ly(1) - 0.32, "fixed and\nresubmitted", fontsize=8.5, color=INK, ha="left", va="top",
            bbox=dict(boxstyle="round,pad=0.2", facecolor=WHITE, edgecolor="none"))
    step(3, c5, w, "Enrolled", fill=GREEN_DARK, h=0.8)
    arrow(ax, (c4 + w / 2 + 1.1, ly(3)), (c5, ly(3)), "approve", ly=0.22)

    # Sectioning and the class lists.
    step(4, c5, w, "Places the learner in a section (seat limit checked, even with two people at once)", h=1.3)
    arrow(ax, (c5 + w / 2, ly(3) - 0.4), (c5 + w / 2, ly(4) + 0.65))
    step(5, c5, w, "Sees the class: My Section, SF1 and class lists; My Classes", h=1.2)
    arrow(ax, (c5 + w / 2, ly(4) - 0.65), (c5 + w / 2, ly(5) + 0.6))
    step(5, c3, 5.6, "Later, if needed: adviser asks for a correction with a reason → Registrar approves on Review Enrollments → the record changes (refused if someone else edited it meanwhile)",
         fill=GREEN_SOFT, txt=INK, edge=GREEN, h=1.2)
    arrow(ax, (c5, ly(5)), (c3 + 5.6, ly(5)), dashed=True)
    ax.add_patch(Ellipse((18.9, ly(5)), 0.36, 0.36, facecolor=WHITE, edgecolor=INK, linewidth=2, zorder=2))
    ax.add_patch(Ellipse((18.9, ly(5)), 0.2, 0.2, facecolor=INK, zorder=3))
    arrow(ax, (c5 + w, ly(5)), (18.72, ly(5)))
    ax.text(18.9, ly(5) - 0.38, "Reports\nupdate", ha="center", va="top", fontsize=8.5, color=INK)

    key(ax, 0.75, 0.45, [("Step in the system", GREEN, "box"), ("Outcome", GREEN_DARK, "box"), ("Stop or send back", AMBER, "box"),
                         ("Step on paper", EXTERNAL, "box"), ("Goes back, or happens later", LINE, "line")])
    save(fig, "03-enrollment-flow", pdf)


# 4. Enrollment status lifecycle --------------------------------------------------
def status_lifecycle(pdf):
    fig, ax = canvas(15, 8.5, "4. Enrollment status: every state a record can be in",
                     "State diagram. Every change is written to the audit log with who made it and when.")

    def state(x, y, name, note, fill=GREEN, w=2.7, h=1.1):
        box(ax, x, y, w, h, name, body=note, fill=fill, size=10.5, wrapw=28)

    ax.add_patch(Ellipse((0.95, 3.6), 0.34, 0.34, facecolor=INK))
    state(1.9, 3.05, "Pending", "Waiting for review", fill=AMBER)
    arrow(ax, (1.12, 3.6), (1.9, 3.6))
    ax.text(1.0, 4.0, "Submitted on\nthe form", fontsize=9, color=INK, ha="left", va="bottom")
    state(6.0, 4.55, "Enrolled", "Counted in reports; can be placed in a section", fill=GREEN_DARK)
    state(6.0, 1.15, "Rejected", "With a reason", fill=OLD)
    arrow(ax, (4.6, 3.95), (6.0, 5.0), "Approve", lx=-0.25, ly=0.2)
    arrow(ax, (4.6, 3.25), (6.0, 1.8), "Reject", lx=-0.25, ly=-0.2)
    arrow(ax, (6.0, 1.45), (3.25, 3.05), "Fixed and resubmitted", rad=-0.35, lx=-0.9, ly=-0.6)

    box(ax, 6.0, 6.25, 2.7, 0.85, "Spreadsheet import", body="Arrives Enrolled or Cancelled", fill=EXTERNAL_SOFT, text=INK, edge=EXTERNAL, size=9.5)
    arrow(ax, (7.35, 6.25), (7.35, 5.65))

    boundary(ax, 10.6, 1.1, 3.9, 6.0, "Left the school", color=EXTERNAL)
    for i, name in enumerate(["Cancelled", "Dropped", "Transferred Out"]):
        state(11.0, 5.55 - i * 1.45, name, "Reason recorded; seat freed", fill=EXTERNAL, w=3.1, h=1.05)
    arrow(ax, (8.7, 5.35), (10.6, 5.35), "Change Status\n(with a reason)", ly=0.42)
    arrow(ax, (10.6, 3.2), (8.4, 4.55), "Restore", dashed=True, rad=-0.2, lx=0.35, ly=-0.35)

    key(ax, 0.75, 0.4, [("Waiting", AMBER, "box"), ("Enrolled", GREEN_DARK, "box"), ("Rejected", OLD, "box"), ("Left the school", EXTERNAL, "box")])
    save(fig, "04-status-lifecycle", pdf)


# 5. Old system vs new ---------------------------------------------------------
def old_vs_new(pdf):
    fig, ax = canvas(16, 11.5, "5. What changed: the old system and the new one",
                     "Same school workflows and the same look; rebuilt so the rules are enforced on the server.")
    ax.text(3.9, 9.85, "Old system (HTML, CSS, JavaScript, PHP)", fontsize=13, fontweight="bold", color=OLD, ha="center")
    ax.text(12.1, 9.85, "New system (Laravel, React, TypeScript)", fontsize=13, fontweight="bold", color=GREEN_DARK, ha="center")
    rows = [
        ("Pages", "A separate HTML page per role and task (about 30)", "One app; each role gets its own menu and pages, checked on the server"),
        ("Signing in", "Login state and role kept in the browser (localStorage) and checked by JavaScript", "Server sessions; every page and action checks the role's abilities; 30-minute idle sign-out"),
        ("Business logic", "28 hand-written PHP API files", "Controllers, services and validation; tested with 145 feature tests and 43 browser tests"),
        ("Two people at once", "Not handled: duplicates and overwrites possible", "Unique keys, row locks and version checks: one save wins, the other is told why"),
        ("Database", "MySQL, 14 tables", "PostgreSQL or SQLite, 10 tables (see diagram 6)"),
        ("Personal data", "No privacy notice or access log", "Privacy notice, staff agreement, view and download log, Learner Data Record"),
    ]
    y = 8.95
    for label, old, new in rows:
        ax.text(8.0, y + 0.35, label, ha="center", va="center", fontsize=10, fontweight="bold", color=INK,
                bbox=dict(boxstyle="round,pad=0.3", facecolor="#eef0f2", edgecolor="none"))
        box(ax, 0.6, y - 0.62, 6.6, 0.85, old, fill=OLD_SOFT, text=INK, edge=OLD, size=10.5, wrapw=62, bold=False)
        box(ax, 8.8, y - 0.62, 6.6, 0.85, new, fill=GREEN_SOFT, text=INK, edge=GREEN, size=10.5, wrapw=62, bold=False)
        arrow(ax, (7.25, y - 0.2), (8.75, y - 0.2), color=GREEN)
        y -= 1.32
    ax.text(0.6, 0.95, "Added that the old system didn't have:", fontsize=10.5, fontweight="bold", color=INK)
    ax.text(0.6, 0.5, wrap("importing the school's workbook · LRN lookup while typing · adjustable text size · one Reports page with year-on-year change · "
                           "document checklist · revision requests · CSV and Excel everywhere · runs on Render or a school computer", 170),
            fontsize=9.5, color=MUTED, va="center")
    save(fig, "05-old-vs-new", pdf)


# 6. Data model ------------------------------------------------------------------
def data_model(pdf):
    fig, ax = canvas(18, 11.5, "6. Data model: the 10 tables and how they connect",
                     "Entity relationship diagram. A line marked 1 … N: one record at the 1 end has many at the N end. Arrows inside a table name the table a column points to.")

    def table(x, y, name, cols, w=3.2, old=None):
        h = 0.42 + 0.27 * len(cols)
        ax.add_patch(FancyBboxPatch((x, y), w, h, boxstyle="round,pad=0.01,rounding_size=0.08", facecolor=WHITE, edgecolor=GREEN_DARK, linewidth=1.4, zorder=2))
        ax.add_patch(FancyBboxPatch((x, y + h - 0.42), w, 0.42, boxstyle="round,pad=0.01,rounding_size=0.08", facecolor=GREEN_DARK, edgecolor=GREEN_DARK, zorder=2))
        ax.text(x + 0.15, y + h - 0.21, name, color=WHITE, fontweight="bold", fontsize=10.5, va="center", zorder=3)
        for i, c in enumerate(cols):
            ax.text(x + 0.15, y + h - 0.62 - i * 0.27, c, fontsize=8.6, color=INK, va="center", zorder=3)
        if old:
            ax.text(x + w - 0.1, y - 0.18, f"was: {old}", fontsize=7.8, color=OLD, ha="right", va="top", style="italic")
        return {"x": x, "y": y, "w": w, "h": h}

    T = {}
    T["school_years"] = table(0.5, 7.0, "school_years", ["id", "start_year  (2026 → 2026-2027)", "is_active · is_enrollment_open", "grade_10_completers"], old="academicyear")
    T["strands"] = table(0.5, 3.6, "strands", ["id", "code (ABM, HUMSS…) · track", "is_active"], old="strand")
    T["users"] = table(14.3, 6.45, "users", ["id", "username (unique)", "role · is_active", "must_change_password", "privacy_agreed_at"], old="user, employee")
    T["sections"] = table(5.0, 7.0, "sections", ["id", "school_year_id → school_years", "grade_level · strand_id → strands", "name · capacity", "adviser_id → users"], old="section")
    T["students"] = table(5.0, 1.0, "students", ["id", "lrn (unique)", "names · birthdate · sex", "religion · IP · 4Ps · disability", "address · contact number", "father · mother · guardian", "lock_version"], old="student, parentguardian")
    T["enrollments"] = table(9.6, 3.7, "enrollments", ["id", "student_id → students", "school_year_id → school_years", "  (one per learner per year)", "grade_level · strand_id", "section_id → sections", "learner_type · status", "privacy_notice_at / _by", "encoded_by · reviewed_by", "lock_version"], w=3.6, old="enrollment, sectionassignment")
    T["teacher_assignments"] = table(9.6, 8.25, "teacher_assignments", ["section_id → sections", "user_id → users", "subject_name (unique per section)"], w=3.6, old="teacherassignment")
    T["document_submissions"] = table(14.3, 3.6, "document_submissions", ["enrollment_id → enrollments", "document_type (unique per enrollment)", "is_submitted · is_verified"], w=3.4, old="documentsubmission")
    T["revision_requests"] = table(14.3, 1.25, "revision_requests", ["enrollment_id → enrollments", "requested_by · reviewed_by", "changes {field: old, new}", "status · justification"], w=3.4, old="studentrevisionrequest")
    T["audit_logs"] = table(0.5, 0.95, "audit_logs", ["user_id → users", "event (created, updated,", "  deleted, viewed, exported)", "record · old and new values"], old="auditlog")

    def edge(a, b, pa, pb, rad=0.0):
        A, B = T[a], T[b]
        pos = {"r": lambda t, f=0.5: (t["x"] + t["w"], t["y"] + t["h"] * f), "l": lambda t, f=0.5: (t["x"], t["y"] + t["h"] * f),
               "t": lambda t, f=0.5: (t["x"] + t["w"] * f, t["y"] + t["h"]), "b": lambda t, f=0.5: (t["x"] + t["w"] * f, t["y"])}
        p1, p2 = pos[pa[0]](A, pa[1]), pos[pb[0]](B, pb[1])
        ax.add_patch(FancyArrowPatch(p1, p2, arrowstyle="-", color=LINE, linewidth=1.2, connectionstyle=f"arc3,rad={rad}", zorder=1))
        ax.text(*p1, " 1", fontsize=8.5, color=GREEN_DARK, fontweight="bold", va="bottom")
        ax.text(*p2, "N ", fontsize=8.5, color=GREEN_DARK, fontweight="bold", va="bottom", ha="right" if pb[0] == "l" else "left")

    edge("school_years", "sections", ("r", 0.6), ("l", 0.7))
    edge("school_years", "enrollments", ("b", 0.8), ("l", 0.62), rad=0.08)
    edge("strands", "sections", ("r", 0.7), ("l", 0.25), rad=0.1)
    edge("strands", "enrollments", ("r", 0.3), ("l", 0.35), rad=0.05)
    edge("students", "enrollments", ("r", 0.6), ("l", 0.15), rad=0.1)
    edge("sections", "enrollments", ("r", 0.3), ("t", 0.2), rad=-0.2)
    edge("sections", "teacher_assignments", ("r", 0.8), ("l", 0.5))
    edge("users", "teacher_assignments", ("l", 0.85), ("r", 0.5))
    edge("users", "sections", ("t", 0.3), ("t", 0.75), rad=0.35)
    edge("enrollments", "document_submissions", ("r", 0.55), ("l", 0.5))
    edge("enrollments", "revision_requests", ("r", 0.2), ("l", 0.6), rad=0.1)

    ax.text(0.5, 0.2, "Grade levels are a fixed list in the code (was the gradelevel table). Learners' parents are columns on students (was parentguardian). "
                       "The section a learner is in is enrollments.section_id (was sectionassignment).\n"
            "Not drawn, to keep the picture readable: audit_logs.user_id, and the encoded_by / reviewed_by / requested_by columns, all point to users.", fontsize=9, color=MUTED, va="bottom", linespacing=1.4)
    save(fig, "06-data-model", pdf)


if __name__ == "__main__":
    with PdfPages(OUT / "DJIHS-system-diagrams.pdf") as pdf:
        for draw in (system_context, containers, enrollment_flow, status_lifecycle, old_vs_new, data_model):
            draw(pdf)
    print("Wrote", sorted(p.name for p in OUT.iterdir() if p.suffix in {".png", ".svg", ".pdf"}))
