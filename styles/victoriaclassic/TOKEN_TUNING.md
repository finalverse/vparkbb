# VictoriaClassic Token Tuning Guide

Edit:

`~/VictoriaPark/victoriapark/styles/victoriaclassic/theme/vp_tokens.css`

After changing tokens, purge phpBB cache.

## Recommended Starting Defaults

```css
--vp-bg: #cfdae6;
--vp-panel: #f7fbff;
--vp-border: #9fb5cc;
--vp-text: #1e2f42;
--vp-muted: #61748a;
--vp-link: #0f4f8d;
--vp-link-hover: #09355f;
--vp-accent: #2f6ea3;
--vp-row-a: #f8fbff;
--vp-row-b: #edf4fb;
--vp-font-base: "Tahoma", "Verdana", "Microsoft YaHei", sans-serif;
--vp-line-height: 1.35;
--vp-container-width: 1240px;
--vp-rail-width: 292px;
```

## What Each Token Controls

- `--vp-bg`: page background outside the main container.
- `--vp-panel`: panel/list/post background.
- `--vp-border`: grid and panel borders.
- `--vp-text`: default text color.
- `--vp-muted`: secondary text (meta, helper copy).
- `--vp-link`: default link color.
- `--vp-link-hover`: link hover/focus color.
- `--vp-accent`: active pagination and emphasized controls.
- `--vp-row-a`: zebra stripe color A.
- `--vp-row-b`: zebra stripe color B.
- `--vp-font-base`: global base font stack.
- `--vp-line-height`: baseline text density.
- `--vp-container-width`: fixed desktop content width.
- `--vp-rail-width`: desktop right-rail width.

## Fast Tuning Tips

- Make layout denser:
  - keep `--vp-line-height` between `1.25` and `1.35`.
- Make style more "classic blue":
  - darken `--vp-link` and `--vp-accent`.
- Increase readability:
  - raise contrast between `--vp-text` and `--vp-panel`.
- Give more space to topics vs rail:
  - reduce `--vp-rail-width` by 20-40px.
