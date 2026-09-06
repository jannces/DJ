# Presentation assets

## `System_Architecture.pptx`

Ten architecture slides for the capstone defense deck, drawn from Chapter IV
(Technical Background) of the manuscript and from the implemented system in this
repository.

| # | Slide | Source |
|---|-------|--------|
| 1 | Title | manuscript title page |
| 2 | Centralized, LAN-only client–server system | §4.1.1 System Overview |
| 3 | Network architecture (Figure 3) | §4.2 Network Architecture |
| 4 | Five application layers (Figure 4) | §4.3 Application System Architecture, `docs/Architecture.md` |
| 5 | Security kernel — the middleware pipeline | §4.3.3, `app/Http/Middleware` |
| 6 | Real-time intrusion alert workflow | §4.4.3, `app/Services/Security` |
| 7 | Path of a leave application (data flow) | §4.9.2, `app/Services/Leave` |
| 8 | Security control mapping (Tables 5 & 6) | §4.3.4, §4.4.4 |
| 9 | Technical framework — four tiers (Figure 10) | §4.9.5 |
| 10 | What the architecture delivers | §4.7, Chapter III evaluation |

Speaker notes are attached to every slide.

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
