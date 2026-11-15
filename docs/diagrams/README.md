# System figures

Drawn in standard notations so they can go straight into a paper, report or slides.

| Figure | File | Notation |
| --- | --- | --- |
| 1. System architecture | `fig1-system-architecture` | Layered stack (users, client, server, data, hosting) with the real technology logos (see `icons/CREDITS.md`) |
| 2. Context diagram | `fig2-context-diagram` | Data flow diagram, level 0 (Gane & Sarson) |
| 3. Data flow diagram | `fig3-dfd-level-1` | Data flow diagram, level 1 (Gane & Sarson) |
| 4. Enrollment activity | `fig4-activity-enrollment` | UML 2 activity diagram with partitions (swimlanes) |
| 5. Enrollment status | `fig5-state-enrollment-status` | UML 2 state machine diagram |
| 6. Database | `fig6-erd` | Entity relationship diagram, crow's foot notation |
| 7. Old and new system | `fig7-old-vs-new` | Architecture comparison and table |

Each figure is a PNG (for documents) and an SVG (scales without blurring). `DJIHS-system-diagrams.pdf` has all seven, one per page.

To redraw them after the system changes, edit `generate.py`, then run:

```bash
pip install matplotlib pillow
```

```bash
python3 docs/diagrams/generate.py
```

Figures 5 and 7 quote counts (tests, tables). Update them in `generate.py` when those change.
