### Dutch Government Accessibility & Design Standards

When implementing frontend code for Dutch municipalities, these are legally required:

#### WCAG 2.1 AA — Legally Required (Digitoegankelijk / EN 301 549)

Since 2018, all Dutch government digital services must meet WCAG 2.1 level AA (50 success criteria) via the Besluit digitale toegankelijkheid overheid. The European Accessibility Act (effective June 2025, enforced by ACM) broadened this further. This is NOT optional.

**Key facts:**
- Automated tools (axe-core, Lighthouse) catch only 30-40% of issues — manual testing is essential
- Audits follow WCAG-EM methodology and are valid for 3 years
- Each service must publish a toegankelijkheidsverklaring (Status A = full compliance, B = partial, C = non-compliant)
- More than 80% of the 50 criteria require manual checking

**Perceivable:**
- All images have meaningful `alt` text (or `alt=""` for decorative)
- Color is never the only means of conveying information
- Contrast ratio minimum 4.5:1 for normal text, 3:1 for large text
- Text can be resized to 200% without loss of content
- All form fields have visible labels (not just placeholders)

**Operable:**
- ALL functionality accessible via keyboard (Tab, Enter, Space, Escape, Arrow keys)
- No keyboard traps — user can always navigate away
- Focus indicators visible on all interactive elements (use `outline`, never `outline: none`)
- Skip links for main content navigation
- No time-based interactions without user control

**Understandable:**
- Language set on `<html lang="nl">` (or appropriate language)
- Error messages identify the field and suggest correction
- Form validation happens on submit, not only on blur
- Consistent navigation patterns across pages

**Robust:**
- Valid HTML (proper nesting, closing tags)
- ARIA attributes used correctly (prefer native HTML elements over ARIA)
- Custom components announce their role, state, and value to assistive tech
- Works with screen readers (NVDA, VoiceOver, JAWS)

**Implementation patterns:**
```vue
<!-- CORRECT: Accessible button with label -->
<NcButton
    :aria-label="t('openregister', 'Add new register')"
    @click="addRegister">
    <template #icon>
        <Plus :size="20" />
    </template>
    {{ t('openregister', 'Add') }}
</NcButton>

<!-- CORRECT: Form field with visible label and error -->
<label for="register-name">{{ t('openregister', 'Name') }}</label>
<NcTextField
    id="register-name"
    :value="name"
    :error="nameError"
    :helper-text="nameError || ''"
    @update:value="updateName" />

<!-- CORRECT: Table with caption and headers -->
<table>
    <caption class="sr-only">{{ t('openregister', 'List of registers') }}</caption>
    <thead>
        <tr>
            <th scope="col">{{ t('openregister', 'Name') }}</th>
            <th scope="col">{{ t('openregister', 'Objects') }}</th>
        </tr>
    </thead>
</table>

<!-- CORRECT: Loading state announced to screen readers -->
<div v-if="loading" role="status" aria-live="polite">
    <NcLoadingIcon :size="20" />
    <span class="sr-only">{{ t('openregister', 'Loading...') }}</span>
</div>
```

**Screen reader only utility class:**
```css
.sr-only {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    white-space: nowrap;
    border: 0;
}
```

#### NL Design System — Government Theming Standard

All Conduction apps support the NL Design System via the nldesign Nextcloud app. This enables municipalities to apply their own design tokens (Rijkshuisstijl, Utrecht, Amsterdam, Den Haag, Rotterdam).

**Rules for NL Design System compatibility:**
- NEVER hardcode colors — always use CSS custom properties
- Use Nextcloud CSS variables: `var(--color-main-text)`, `var(--color-primary)`, etc.
- NL Design tokens override Nextcloud variables when nldesign is active
- Test with at least two different token sets (e.g., Rijkshuisstijl + Utrecht)
- All spacing, typography, and border-radius should use variables where available
- Support both light and dark mode

**Token categories to use:**
```css
/* Colors */
var(--color-main-text)
var(--color-primary)
var(--color-primary-text)
var(--color-background-dark)
var(--color-error)
var(--color-warning)
var(--color-success)

/* Typography — from NL Design tokens when available */
var(--font-face)
var(--font-size)

/* Spacing */
var(--default-grid-baseline)  /* 4px base unit in Nextcloud */
```

#### Multi-Language Support

Dutch municipalities serve diverse populations. Translation readiness is mandatory:
- All strings through `t('appname', 'text')` — no exceptions
- Support RTL layouts (some resident populations require it)
- Date/time formatting via `Intl.DateTimeFormat` or `@nextcloud/moment`
- Number formatting via `Intl.NumberFormat` (Dutch uses comma as decimal separator)
