<!--
GENERATED FILE -- DO NOT EDIT.

Emitted from packages/tokens/src/source.ts by packages/tokens/scripts/generate.mjs.
`pnpm tokens:check` byte-diffs it against what the source produces right now.

Quote THIS FILE rather than re-deriving a figure. Three published figures were wrong
because they were typed by hand and rounded up against a minimum bar.
-->

# Contrast — the canonical table

| | |
| --- | --- |
| Formula | WCAG 2.1 relative luminance |
| Rounding | **truncated at two decimals (floored), never rounded up** |
| Why | every threshold is a MINIMUM, so flooring can only ever understate compliance; rounding up can overstate it, and a raw 4.4996 presented as `4.50` against a 4.5 bar reads as a pass and is not one |
| Mix colour space | `srgb-8bit-component-linear-round-half-up` |
| Bars | AAA text 7.0 · AA text 4.5 · AA large text and non-text 3.0 |
| Headroom | **+0.10** over the bar. A value clearing a minimum by 0.01 moves with the mix implementation and the rounding convention, so every derived role is solved against `bar + headroom` |
| Pairs | **42** across 2 themes, plus 3 order-ready |
| Failures | **0** |

## The order-ready screen — outside theming (D4)

One set of figures and not two: these resolve with no theme argument, so there is no second
declaration that could differ.

| fg | bg | fg hex | bg hex | ratio | bar | target | verdict | what |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `--ready-ink` | `--ready-field` | `#FCFCFA` | `#0A1F17` | **16.73** | 7.0 | 7.10 | ✅ | the page text |
| `--ready-signal` | `--ready-field` | `#18C880` | `#0A1F17` | **7.87** | 7.0 | 7.10 | ✅ | the order number |
| `--ready-field` | `--ready-signal` | `#0A1F17` | `#18C880` | **7.87** | 7.0 | 7.10 | ✅ | the acknowledge button |

## Light

| fg | bg | fg hex | bg hex | ratio | bar | target | margin over bar | verdict |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `ink` | `paper` | `#191C22` | `#FCFCFA` | **16.61** | 7.0 | 7.10 | +9.61 | ✅ |
| `ink` | `surface` | `#191C22` | `#FFFFFF` | **17.06** | 7.0 | 7.10 | +10.06 | ✅ |
| `ink` | `surface-2` | `#191C22` | `#F6F6F2` | **15.75** | 7.0 | 7.10 | +8.75 | ✅ |
| `ink-mid` | `paper` | `#6B6F7A` | `#FCFCFA` | **4.89** | 4.5 | 4.60 | +0.39 | ✅ |
| `ink-mid` | `surface` | `#6B6F7A` | `#FFFFFF` | **5.02** | 4.5 | 4.60 | +0.52 | ✅ |
| `ink-mid` | `surface-2` | `#6B6F7A` | `#F6F6F2` | **4.63** | 4.5 | 4.60 | +0.13 | ✅ |
| `alert-ink` | `paper` | `#8C2F27` | `#FCFCFA` | **8.01** | 4.5 | 4.60 | +3.51 | ✅ |
| `alert-ink` | `surface` | `#8C2F27` | `#FFFFFF` | **8.23** | 4.5 | 4.60 | +3.73 | ✅ |
| `alert-ink` | `surface-2` | `#8C2F27` | `#F6F6F2` | **7.59** | 4.5 | 4.60 | +3.09 | ✅ |
| `brand` | `paper` | `#191C22` | `#FCFCFA` | **16.61** | 4.5 | 4.60 | +12.11 | ✅ |
| `brand` | `surface` | `#191C22` | `#FFFFFF` | **17.06** | 4.5 | 4.60 | +12.56 | ✅ |
| `brand` | `surface-2` | `#191C22` | `#F6F6F2` | **15.75** | 4.5 | 4.60 | +11.25 | ✅ |
| `border-strong` | `paper` | `#828382` | `#FCFCFA` | **3.70** | 3.0 | 3.10 | +0.70 | ✅ |
| `border-strong` | `surface` | `#828382` | `#FFFFFF` | **3.80** | 3.0 | 3.10 | +0.80 | ✅ |
| `border-strong` | `surface-2` | `#828382` | `#F6F6F2` | **3.51** | 3.0 | 3.10 | +0.51 | ✅ |
| `hairline` | `paper` | `#DFDFD7` | `#FCFCFA` | **1.30** | — | — | — | no bar — decorative |
| `hairline` | `surface` | `#DFDFD7` | `#FFFFFF` | **1.33** | — | — | — | no bar — decorative |
| `hairline` | `surface-2` | `#DFDFD7` | `#F6F6F2` | **1.23** | — | — | — | no bar — decorative |
| `surface` | `brand` | `#FFFFFF` | `#191C22` | **17.06** | 4.5 | 4.60 | +12.56 | ✅ |
| `surface` | `alert-ink` | `#FFFFFF` | `#8C2F27` | **8.23** | 4.5 | 4.60 | +3.73 | ✅ |
| `surface` | `alert-fill` | `#FFFFFF` | `#8C2F27` | **8.23** | 3.0 | 3.10 | +5.23 | ✅ *(not rendered — bound stated only)* |

## Dark

| fg | bg | fg hex | bg hex | ratio | bar | target | margin over bar | verdict |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `ink` | `paper` | `#FCFCFA` | `#0A1F17` | **16.73** | 7.0 | 7.10 | +9.73 | ✅ |
| `ink` | `surface` | `#FCFCFA` | `#1D3129` | **13.41** | 7.0 | 7.10 | +6.41 | ✅ |
| `ink` | `surface-2` | `#FCFCFA` | `#2C3E37` | **11.04** | 7.0 | 7.10 | +4.04 | ✅ |
| `ink-mid` | `paper` | `#ABADB2` | `#0A1F17` | **7.65** | 4.5 | 4.60 | +3.15 | ✅ |
| `ink-mid` | `surface` | `#ABADB2` | `#1D3129` | **6.13** | 4.5 | 4.60 | +1.63 | ✅ |
| `ink-mid` | `surface-2` | `#ABADB2` | `#2C3E37` | **5.05** | 4.5 | 4.60 | +0.55 | ✅ |
| `alert-ink` | `paper` | `#CCA49F` | `#0A1F17` | **7.67** | 4.5 | 4.60 | +3.17 | ✅ |
| `alert-ink` | `surface` | `#CCA49F` | `#1D3129` | **6.15** | 4.5 | 4.60 | +1.65 | ✅ |
| `alert-ink` | `surface-2` | `#CCA49F` | `#2C3E37` | **5.06** | 4.5 | 4.60 | +0.56 | ✅ |
| `brand` | `paper` | `#FCFCFA` | `#0A1F17` | **16.73** | 4.5 | 4.60 | +12.23 | ✅ |
| `brand` | `surface` | `#FCFCFA` | `#1D3129` | **13.41** | 4.5 | 4.60 | +8.91 | ✅ |
| `brand` | `surface-2` | `#FCFCFA` | `#2C3E37` | **11.04** | 4.5 | 4.60 | +6.54 | ✅ |
| `border-strong` | `paper` | `#838E89` | `#0A1F17` | **5.07** | 3.0 | 3.10 | +2.07 | ✅ |
| `border-strong` | `surface` | `#838E89` | `#1D3129` | **4.06** | 3.0 | 3.10 | +1.06 | ✅ |
| `border-strong` | `surface-2` | `#838E89` | `#2C3E37` | **3.34** | 3.0 | 3.10 | +0.34 | ✅ |
| `hairline` | `paper` | `#44544D` | `#0A1F17` | **2.14** | — | — | — | no bar — decorative |
| `hairline` | `surface` | `#44544D` | `#1D3129` | **1.72** | — | — | — | no bar — decorative |
| `hairline` | `surface-2` | `#44544D` | `#2C3E37` | **1.41** | — | — | — | no bar — decorative |
| `surface` | `brand` | `#1D3129` | `#FCFCFA` | **13.41** | 4.5 | 4.60 | +8.91 | ✅ |
| `surface` | `alert-ink` | `#1D3129` | `#CCA49F` | **6.15** | 4.5 | 4.60 | +1.65 | ✅ |
| `surface` | `alert-fill` | `#1D3129` | `#B37771` | **3.80** | 3.0 | 3.10 | +0.80 | ✅ *(not rendered — bound stated only)* |

## What each foreground role is

- `ink` — body and headings (text-aaa)
- `ink-mid` — secondary text only (text-aa)
- `alert-ink` — alert and validation text (text-aa)
- `brand` — quiet button label and link text (text-aa)
- `border-strong` — an interactive control's only boundary (non-text)
- `hairline` — decorative separation only — never a control boundary

### Text on a fill

A role-against-background table has no row for this shape, and that is where the danger
button's defect hid: its label is `--size-16`, so its bar is AA's 4.5 and not large
text's 3.0. `surface` on `alert-fill` is 3.68 — a pass for a 24px label and a failure
for this one — so the button's fill is `alert-ink`. Both tokens are approved; only the
pairing changed, and no new value was invented.

- `surface` on `brand` — primary button label on its fill
- `surface` on `alert-ink` — danger button label on its fill
- `surface` on `alert-fill` — alert-fill carries TEXT only at >= 24px — the approval’s own bound, stated so a future use cannot rediscover the danger button’s defect
