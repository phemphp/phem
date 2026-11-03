# Design system

The files of this folder are the "Phem Docs" design system of the website: `tokens.json`,
`bundle.css` and `bundle.js`. Change the design system here.

`scripts/tokens.mjs` writes `src/styles/tokens.css` from `tokens.json` before each build. The
components of `src/components/` write the markup of the design system (the `ph-` classes).
