/**
 * One formatter config for the workspace. Prettier owns WHITESPACE; ESLint owns semantics,
 * and the two do not overlap -- there is no eslint-config-prettier here because no ESLint
 * formatting rule is enabled in the first place.
 *
 * @type {import('prettier').Config}
 */
export default {
  printWidth: 100,
  singleQuote: true,
  semi: false,
  trailingComma: 'all',
  arrowParens: 'always',
  // The repo is `* text=auto eol=lf` in .gitattributes. Saying it again here means a clone
  // on a machine with core.autocrlf=true cannot produce a file Prettier then rewrites.
  endOfLine: 'lf',
}
