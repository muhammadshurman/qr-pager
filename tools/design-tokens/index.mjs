/**
 * The ESLint half of the no-hard-coded-values rule. The CSS half is ./bin/scan-css.mjs.
 * Neither carries the pattern list: both read ./patterns.mjs (see its header for why).
 *
 * WHAT THIS HALF SEES THAT A CSS LINTER CANNOT: a value inside a STRING. `className="p-[13px]"`,
 * `style={{ fontSize: 13 }}` and a CSS chunk in a template literal are all TypeScript as far
 * as any tool is concerned, and all three put a literal on the screen.
 */
import { colourValueIsAcceptable, patternsFor, valueIsAcceptable } from './patterns.mjs'

const JS_PATTERNS = patternsFor('js')

/** Report every match of one regex pattern in one piece of text. */
function reportText(context, node, text, pattern, offset = 0) {
  if (!pattern.regex) return
  // A fresh lastIndex per call: a /g regex shared across nodes would skip matches.
  const regex = new RegExp(pattern.regex.source, pattern.regex.flags)
  let match
  while ((match = regex.exec(text)) !== null) {
    if (pattern.valueGuard && valueIsAcceptable(match[2] ?? '')) continue
    context.report({
      node,
      message: pattern.message.replace('{{match}}', match[0].trim()),
      data: { match: match[0].trim() },
    })
    if (match[0] === '') regex.lastIndex += 1
    void offset
  }
}

/** The style-object half: a property whose KEY is a style property and whose value is a literal. */
function checkStyleProperty(context, property, pattern) {
  const key =
    property.key.type === 'Identifier'
      ? property.key.name
      : property.key.type === 'Literal'
        ? String(property.key.value)
        : null
  if (key === null || !pattern.styleProperties.includes(key)) return

  // A colour property is judged by an ALLOW-LIST and a magnitude property by "is there a
  // number with a unit in it". Using one judgement for both is what let `color: 'white'`
  // through: it carries no digit, so the magnitude check accepted it.
  const acceptable = pattern.colourGuard === true ? colourValueIsAcceptable : valueIsAcceptable

  const value = property.value
  // A number is always a magnitude: React turns `fontSize: 13` into `font-size: 13px`.
  if (value.type === 'Literal' && typeof value.value === 'number' && value.value !== 0) {
    context.report({
      node: value,
      message: pattern.message.replace('{{match}}', `${key}: ${String(value.value)}`),
    })
    return
  }
  if (value.type === 'Literal' && typeof value.value === 'string') {
    if (!acceptable(value.value)) {
      context.report({
        node: value,
        message: pattern.message.replace('{{match}}', `${key}: ${value.value}`),
      })
    }
  }
}

function makeRule(ruleId) {
  const mine = JS_PATTERNS.filter((p) => p.rule === ruleId)
  const textPatterns = mine.filter((p) => p.regex)
  const stylePatterns = mine.filter((p) => p.styleProperties)

  return {
    meta: {
      type: 'problem',
      docs: {
        description: `§12: no hard-coded values. ${ruleId}.`,
        recommended: true,
      },
      schema: [],
    },
    create(context) {
      const visitors = {}

      if (textPatterns.length > 0) {
        const scan = (node, text) => {
          for (const pattern of textPatterns) reportText(context, node, text, pattern)
        }
        visitors.Literal = (node) => {
          if (typeof node.value === 'string') scan(node, node.value)
        }
        // A template literal's static chunks. The expressions inside it are visited as
        // their own nodes, so nothing is checked twice and nothing is skipped.
        visitors.TemplateElement = (node) => {
          if (typeof node.value?.raw === 'string') scan(node, node.value.raw)
        }
        // JSXText so a literal pasted into markup is caught too.
        visitors.JSXText = (node) => {
          if (typeof node.value === 'string') scan(node, node.value)
        }
      }

      if (stylePatterns.length > 0) {
        visitors.Property = (node) => {
          for (const pattern of stylePatterns) checkStyleProperty(context, node, pattern)
        }
      }

      return visitors
    },
  }
}

const rules = {
  'no-raw-color': makeRule('no-raw-color'),
  'no-raw-spacing': makeRule('no-raw-spacing'),
  'no-raw-font-size': makeRule('no-raw-font-size'),
}

const plugin = {
  meta: { name: '@qrpager/eslint-plugin-design-tokens', version: '0.0.0' },
  rules,
  configs: {},
}

// All three at `error`. There is no `warn` tier: `§12` says "no hard-coded hex, spacing or
// font size in any component", and a warning is a value that ships.
plugin.configs.recommended = {
  plugins: { '@qrpager/design-tokens': plugin },
  rules: {
    '@qrpager/design-tokens/no-raw-color': 'error',
    '@qrpager/design-tokens/no-raw-spacing': 'error',
    '@qrpager/design-tokens/no-raw-font-size': 'error',
  },
}

export default plugin
export { rules }
