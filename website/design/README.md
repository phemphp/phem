# Design system

The files of this folder are a copy of the "Livewire Blue" design system
(https://claude.ai/artifact/BwjFRBWvrSAzZTi1htmXSL): `tokens.json`, `components/bundle.css` and
`components/bundle.js`. Do not change them here. Copy them again when the design system changes.

`scripts/tokens.mjs` writes `src/styles/tokens.css` from `tokens.json` before each build. The
components of `src/components/` write the markup of the design system (the `lw-` classes).
