import '@testing-library/dom'

/**
 * jsdom does not implement layout, so `getComputedStyle` returns declared values and
 * nothing resolved. That is why the four computed-style clauses -- a visible focus ring, a
 * measured 44px target, resolved contrast, and prefers-reduced-motion actually suppressing
 * motion -- are Playwright's and not this project's. R-118, and 8a's share of it closes in
 * the browser suite rather than here.
 *
 * What jsdom CAN prove is structure, roles, attributes, direction and the DOM a component
 * produces -- which is most of what §12's accessibility bar is about.
 */
