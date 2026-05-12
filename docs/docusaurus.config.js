// @ts-check

/**
 * OpenRegister documentation site.
 *
 * Built on @conduction/docusaurus-preset for brand defaults (tokens,
 * theme swizzles for Navbar / Footer, four-locale i18n scaffolding,
 * KvK / BTW copyright). Site-specific overrides — locales, sidebar
 * path, mermaid theme, redocusaurus OAS routes, custom prism themes,
 * openregister-only navbar items — are passed through createConfig() opts.
 */

const { createConfig, baseFooterLinks } = require('@conduction/docusaurus-preset');

/* createConfig replaces themes wholesale when `themes:` is passed, so
   we re-include the brand theme plugin alongside @docusaurus/theme-mermaid.
   Without the brand theme entry the Navbar/Footer swizzles and
   brand.css auto-load would silently drop. */
const BRAND_THEME = require.resolve('@conduction/docusaurus-preset/theme');

const config = createConfig({
  title: 'OpenRegister',
  tagline: 'Schemas, registers, structured data objects. The typed-data backbone underneath every other Conduction app.',
  url: 'https://openregister.conduction.nl',
  baseUrl: '/',

  organizationName: 'ConductionNL',
  projectName: 'openregister',

  /* The brand preset's default i18n block (nl/en/de/fr) is replaced
     wholesale here. OpenRegister docs ship with NL + EN translation
     surfaces; keep both. */
  i18n: {
    defaultLocale: 'en',
    locales: ['en', 'nl'],
    localeConfigs: {
      en: { label: 'English' },
      nl: { label: 'Nederlands' },
    },
  },

  /* The openregister docs source lives at the repo root of `docs/`
     rather than under a `docs/` subfolder, so we override the preset's
     default `presets:` block to point `docs.path` at './' and disable
     the blog plugin. customCss carries openregister-specific CSS only —
     brand tokens and the theme swizzles are auto-loaded by the brand
     theme entry in `themes:` below.

     redocusaurus stays as a sibling preset entry so the OAS routes at
     /api and /api/clientRegister keep working. */
  presets: [
    [
      'classic',
      {
        docs: {
          path: './',
          /* docs.path: './' makes plugin-content-docs scan every file
             in docs/, which collides with plugin-content-pages's own
             scan of docs/src/pages/. Exclude src/ (pages live there)
             plus the standard node_modules bucket.

             Also excludes a handful of session-summary / dev-note
             files that contain raw `<` characters (e.g. `<= 100 chars`,
             `<2026-01-05`, `width=100%`) the MDX 3 parser rejects. They
             pre-date this preset migration and are not user-facing
             docs; leaving them out of the scan is the lowest-risk fix.
             Track properly in a follow-up MDX-cleanup PR. */
          exclude: [
            '**/node_modules/**',
            'src/**',
            'Features/faceting.md',
            'Features/endpoints.md',
            'features/workflow-operations.md',
            'development-notes/magic-mapper-auto-table-creation.md',
            'development-notes/session-summary-2026-01-05-final-extended.md',
            'development-notes/session-summary-2026-01-05-final.md',
            'development/configuration-dependencies.md',
            'development/csv-duplicate-handling.md',
            'development/magic-mapper.md',
            'user-guide/importing-data.md',
            'user/n8n-visual-guide-template.md',
            'user/n8n-workflow-configuration.md',
          ],
          sidebarPath: require.resolve('./sidebars.js'),
          editUrl: 'https://github.com/ConductionNL/openregister/tree/main/docs/',
        },
        blog: false,
        theme: {
          customCss: require.resolve('./src/css/custom.css'),
        },
      },
    ],
    [
      'redocusaurus',
      {
        specs: [
          {
            id: 'open-register',
            spec: 'static/oas/open-register.json',
            route: '/api',
          },
          {
            id: 'client-registers',
            spec: 'static/oas/clientRegisters.json',
            route: '/api/clientRegister',
          },
        ],
        theme: {
          primaryColor: '#1890ff',
        },
      },
    ],
  ],

  themes: [BRAND_THEME, '@docusaurus/theme-mermaid'],

  /* Brand navbar provides locale dropdown + GitHub by default; we
     replace items[] with openregister's own (Documentation sidebar
     link, API documentation, GitHub link, locale dropdown). */
  navbar: {
    items: [
      {
        type: 'docSidebar',
        sidebarId: 'tutorialSidebar',
        position: 'left',
        label: 'Documentation',
      },
      {
        href: '/api',
        label: 'API Documentation',
        position: 'right',
      },
      {
        href: 'https://github.com/ConductionNL/openregister',
        label: 'GitHub',
        position: 'right',
      },
      { type: 'localeDropdown', position: 'right' },
    ],
  },

  /* Per-property footer override (preset 1.2.0+): we pass `links` only,
     so the brand `style: 'dark'` and the brand KvK/BTW/IBAN/address
     copyright string both inherit unchanged. Single-column brand
     "Conduction" anchor pulled from baseFooterLinks(). */
  footer: {
    links: [
      ...baseFooterLinks().filter((column) => column.title === 'Conduction'),
    ],
  },

  /* Drop the canal-footer's boat-sinking + kade-cyclist mini-games
     on this product-page footer (preset 1.3.0+). The static skyline +
     canal decoration are kept; the interactive layer goes away. */
  minigames: false,

  /* themeConfig is shallow-merged into the preset's defaults
     (colorMode + navbar + footer). prism + mermaid land alongside. */
  themeConfig: {
    prism: {
      theme: require('prism-react-renderer/themes/github'),
      darkTheme: require('prism-react-renderer/themes/dracula'),
    },
    mermaid: {
      theme: { light: 'default', dark: 'dark' },
    },
  },
});

/* createConfig doesn't pass-through arbitrary top-level fields; assign
   markdown directly so it makes it into the final Docusaurus config. */
config.markdown = {
  mermaid: true,
};

module.exports = config;
