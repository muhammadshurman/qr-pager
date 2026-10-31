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
| `--ready-ink` | `--ready-field` | `#F2F4F1` | `#0A1F17` | **15.54** | 7.0 | 7.10 | ✅ | the page text |
| `--ready-signal` | `--ready-field` | `#3DDC97` | `#0A1F17` | **9.72** | 7.0 | 7.10 | ✅ | the order number |
| `--ready-field` | `--ready-signal` | `#0A1F17` | `#3DDC97` | **9.72** | 7.0 | 7.10 | ✅ | the acknowledge button |

## Light

| fg | bg | fg hex | bg hex | ratio | bar | target | margin over bar | verdict |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `ink` | `paper` | `#10231B` | `#F2F4F1` | **14.85** | 7.0 | 7.10 | +7.85 | ✅ |
| `ink` | `surface` | `#10231B` | `#FFFFFF` | **16.42** | 7.0 | 7.10 | +9.42 | ✅ |
| `ink` | `surface-2` | `#10231B` | `#E7EBE6` | **13.63** | 7.0 | 7.10 | +6.63 | ✅ |
| `ink-mid` | `paper` | `#5A6A62` | `#F2F4F1` | **5.16** | 4.5 | 4.60 | +0.66 | ✅ |
| `ink-mid` | `surface` | `#5A6A62` | `#FFFFFF` | **5.71** | 4.5 | 4.60 | +1.21 | ✅ |
| `ink-mid` | `surface-2` | `#5A6A62` | `#E7EBE6` | **4.74** | 4.5 | 4.60 | +0.24 | ✅ |
| `alert-ink` | `paper` | `#96351A` | `#F2F4F1` | **6.73** | 4.5 | 4.60 | +2.23 | ✅ |
| `alert-ink` | `surface` | `#96351A` | `#FFFFFF` | **7.44** | 4.5 | 4.60 | +2.94 | ✅ |
| `alert-ink` | `surface-2` | `#96351A` | `#E7EBE6` | **6.17** | 4.5 | 4.60 | +1.67 | ✅ |
| `brand` | `paper` | `#0B6B4F` | `#F2F4F1` | **5.87** | 4.5 | 4.60 | +1.37 | ✅ |
| `brand` | `surface` | `#0B6B4F` | `#FFFFFF` | **6.50** | 4.5 | 4.60 | +2.00 | ✅ |
| `brand` | `surface-2` | `#0B6B4F` | `#E7EBE6` | **5.39** | 4.5 | 4.60 | +0.89 | ✅ |
| `border-strong` | `paper` | `#77847C` | `#F2F4F1` | **3.53** | 3.0 | 3.10 | +0.53 | ✅ |
| `border-strong` | `surface` | `#77847C` | `#FFFFFF` | **3.90** | 3.0 | 3.10 | +0.90 | ✅ |
| `border-strong` | `surface-2` | `#77847C` | `#E7EBE6` | **3.24** | 3.0 | 3.10 | +0.24 | ✅ |
| `hairline` | `paper` | `#D3DAD2` | `#F2F4F1` | **1.28** | — | — | — | no bar — decorative |
| `hairline` | `surface` | `#D3DAD2` | `#FFFFFF` | **1.42** | — | — | — | no bar — decorative |
| `hairline` | `surface-2` | `#D3DAD2` | `#E7EBE6` | **1.18** | — | — | — | no bar — decorative |
| `surface` | `brand` | `#FFFFFF` | `#0B6B4F` | **6.50** | 4.5 | 4.60 | +2.00 | ✅ |
| `surface` | `alert-ink` | `#FFFFFF` | `#96351A` | **7.44** | 4.5 | 4.60 | +2.94 | ✅ |
| `surface` | `alert-fill` | `#FFFFFF` | `#E4572E` | **3.68** | 3.0 | 3.10 | +0.68 | ✅ *(not rendered — bound stated only)* |

## Dark

| fg | bg | fg hex | bg hex | ratio | bar | target | margin over bar | verdict |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `ink` | `paper` | `#F2F4F1` | `#0A1F17` | **15.54** | 7.0 | 7.10 | +8.54 | ✅ |
| `ink` | `surface` | `#F2F4F1` | `#1D3028` | **12.60** | 7.0 | 7.10 | +5.60 | ✅ |
| `ink` | `surface-2` | `#F2F4F1` | `#2A3D36` | **10.43** | 7.0 | 7.10 | +3.43 | ✅ |
| `ink-mid` | `paper` | `#9DA7A1` | `#0A1F17` | **6.94** | 4.5 | 4.60 | +2.44 | ✅ |
| `ink-mid` | `surface` | `#9DA7A1` | `#1D3028` | **5.62** | 4.5 | 4.60 | +1.12 | ✅ |
| `ink-mid` | `surface-2` | `#9DA7A1` | `#2A3D36` | **4.65** | 4.5 | 4.60 | +0.15 | ✅ |
| `alert-ink` | `paper` | `#E99176` | `#0A1F17` | **7.18** | 4.5 | 4.60 | +2.68 | ✅ |
| `alert-ink` | `surface` | `#E99176` | `#1D3028` | **5.82** | 4.5 | 4.60 | +1.32 | ✅ |
| `alert-ink` | `surface-2` | `#E99176` | `#2A3D36` | **4.82** | 4.5 | 4.60 | +0.32 | ✅ |
| `brand` | `paper` | `#3DDC97` | `#0A1F17` | **9.72** | 4.5 | 4.60 | +5.22 | ✅ |
| `brand` | `surface` | `#3DDC97` | `#1D3028` | **7.89** | 4.5 | 4.60 | +3.39 | ✅ |
| `brand` | `surface-2` | `#3DDC97` | `#2A3D36` | **6.53** | 4.5 | 4.60 | +2.03 | ✅ |
| `border-strong` | `paper` | `#7E8A84` | `#0A1F17` | **4.79** | 3.0 | 3.10 | +1.79 | ✅ |
| `border-strong` | `surface` | `#7E8A84` | `#1D3028` | **3.88** | 3.0 | 3.10 | +0.88 | ✅ |
| `border-strong` | `surface-2` | `#7E8A84` | `#2A3D36` | **3.21** | 3.0 | 3.10 | +0.21 | ✅ |
| `hairline` | `paper` | `#42524B` | `#0A1F17` | **2.07** | — | — | — | no bar — decorative |
| `hairline` | `surface` | `#42524B` | `#1D3028` | **1.68** | — | — | — | no bar — decorative |
| `hairline` | `surface-2` | `#42524B` | `#2A3D36` | **1.39** | — | — | — | no bar — decorative |
| `surface` | `brand` | `#1D3028` | `#3DDC97` | **7.89** | 4.5 | 4.60 | +3.39 | ✅ |
| `surface` | `alert-ink` | `#1D3028` | `#E99176` | **5.82** | 4.5 | 4.60 | +1.32 | ✅ |
| `surface` | `alert-fill` | `#1D3028` | `#E4572E` | **3.78** | 3.0 | 3.10 | +0.78 | ✅ *(not rendered — bound stated only)* |

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
