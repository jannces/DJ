# ADR-010: dompdf + maatwebsite/excel for exports
**Status:** Accepted
**Context:** Reports must export to PDF, XLSX, CSV; CSC Form 6 must print as PDF.
**Decision:** barryvdh/laravel-dompdf renders Blade → PDF (Form 6 replica + reports); maatwebsite/excel (PhpSpreadsheet) provides XLSX/CSV from shared export classes.
**Consequences:** + One dataset definition per report feeds all three formats. − dompdf CSS subset → dedicated print stylesheets.
