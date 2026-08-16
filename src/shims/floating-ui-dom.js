// Shim for @floating-ui/dom compatibility with floating-vue@1.0.0-beta.x
// floating-vue uses getScrollParents (0.x API), renamed to getOverflowAncestors in 1.x
export * from '@floating-ui/dom-actual'
export { getOverflowAncestors as getScrollParents } from '@floating-ui/dom-actual'
