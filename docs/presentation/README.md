# Presentation assets

## `System_Architecture.pptx`

Eleven architecture slides for the capstone defense deck, drawn from Chapter IV
(Technical Background) of the manuscript and from the implemented system in this
repository.

| # | Slide | Source |
|---|-------|--------|
| 1 | Title | manuscript title page |
| 2 | Centralized, LAN-only client–server system | §4.1.1 System Overview |
| 3 | Network architecture (Figure 3) | §4.2 Network Architecture |
| 4 | Consolidated system architecture diagram | see below |
| 5 | Five application layers (Figure 4) | §4.3 Application System Architecture, `docs/Architecture.md` |
| 6 | Security kernel — the middleware pipeline | §4.3.3, `app/Http/Middleware` |
| 7 | Real-time intrusion alert workflow | §4.4.3, `app/Services/Security` |
| 8 | Path of a leave application (data flow) | §4.9.2, `app/Services/Leave` |
| 9 | Security control mapping (Tables 5 & 6) | §4.3.4, §4.4.4 |
| 10 | Technical framework — four tiers (Figure 10) | §4.9.5 |
| 11 | What the architecture delivers | §4.7, Chapter III evaluation |

Speaker notes are attached to every slide.

## `architecture.svg` / `architecture.png`

The consolidated system architecture diagram — one picture of the whole system:
authorized clients on the LAN, switch and firewall, the centralized server with
its five layers, the data tier on the same host, and the real-time intrusion
alert path running back to the System Administrator. Two flows are colour-coded:
the navy request path (left to right) and the red alert path (bottom, right to
left). It is slide 4 of the deck, and is also suitable as a manuscript figure.

Regenerate it with:

```bash
python3 docs/presentation/build_architecture_diagram.py   # writes architecture.svg
# then rasterize at 2x with any SVG renderer, e.g.
rsvg-convert -z 2 architecture.svg -o architecture.png
```

The SVG is the source of truth; the PNG (3800 x 2080, 2x) is what the deck embeds.

## Regenerating the deck

The deck is generated, not hand-edited — edit the script and rebuild so the
`.pptx` and its source stay in step:

```bash
npm install pptxgenjs
node docs/presentation/build_architecture_deck.js docs/presentation/System_Architecture.pptx
```

Palette: navy `1E2761`, ice blue `CADCFC`, alert `C43D2B`. Headers Cambria,
body Calibri — both ship with Office, so the deck renders the same on any
presentation machine.
