# System diagrams

| File | What it shows |
| --- | --- |
| `01-system-context` | Who uses the system and what it exchanges data with (C4 level 1) |
| `02-containers` | How it is built: web pages, web application, database, files (C4 level 2) |
| `03-enrollment-flow` | From paper forms to a learner in a section, one lane per role |
| `04-status-lifecycle` | Every status an enrollment can have, and what moves it |
| `05-old-vs-new` | What changed from the old HTML/PHP system |
| `06-data-model` | The 10 tables, how they connect, and which old tables they replace |

`DJIHS-system-diagrams.pdf` has all six, one per page. Each diagram is also a PNG (for documents and slides) and an SVG (scales without blurring).

To redraw them after the system changes, edit `generate.py`, then run:

```bash
pip install matplotlib
```

```bash
python3 docs/diagrams/generate.py
```

The architecture diagrams follow the [C4 model](https://c4model.com): one level of detail per diagram, each with a title and a key.
