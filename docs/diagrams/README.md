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
| 8. Use cases | `fig8-use-case` | UML 2 use case diagram: actors, use cases as circles, «include» and «extend». Checked against every route and the role abilities in `app/Enums/Role.php` |
| 9. Deployment | `fig9-deployment` | UML 2 deployment diagram with the real logos: the live site on Render (browser, Docker container, Apache and PHP, Laravel, PostgreSQL, deploys from GitHub) and the one-school-computer setup with SQLite. Drawn from `Dockerfile`, `docker/start.sh`, `docker/apache.conf` and `render.yaml` |

Each figure is a PNG (for documents) and an SVG (scales without blurring). `DJIHS-system-diagrams.pdf` has all nine, one per page.

To redraw them after the system changes, edit `generate.py`, then run:

```bash
pip install matplotlib pillow
```

```bash
python3 docs/diagrams/generate.py
```

Figures 5 and 7 quote counts (tests, tables). Update them in `generate.py` when those change.

Figure 8 follows UML 2.5 use case notation: «include» is behaviour that always happens and points at the included use case; «extend» is optional and points back at the use case it extends. Each line was checked against the route's ability and whether a page shows the action to that role (for example, the Registrar and ICT Coordinator may download section forms, but no page offers it, so they have no line to that use case). The everyday pages every user has (dashboard, own password, text size) are left out. If the roles in `app/Enums/Role.php` change, update the lists in `use_cases()` in `generate.py`.
