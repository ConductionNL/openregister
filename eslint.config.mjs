// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
//
// eslint 10 + @nextcloud/eslint-config 9 — the same stack Nextcloud's own apps
// run (nextcloud/forms is the reference). Flat config, ESM.
//
// This file is the fleet's canonical shape. Copy it verbatim into an app; the
// only parts that should ever differ are the last two blocks (app-specific
// globals and file-scoped exemptions).
//
// WHY `.mjs` AND NOT `"type": "module"` IN package.json
// -----------------------------------------------------
// `@nextcloud/eslint-config@9` is `"type": "module"`, so the config importing it
// must be ESM. forms achieves that by making the whole package ESM; these apps
// cannot — `webpack.config.js`, `vitest.config.js` and the `tests/**` CLI
// checkers are CommonJS and would stop parsing. Naming the config `.mjs` scopes
// the module system to the one file that needs it.
//
// 🔴 NODE 22 IS REQUIRED, NOT PREFERRED
// -------------------------------------
// `@nextcloud/eslint-config@9` declares `engines.node: ^22.14 || ^24 || >=26`
// and imports `findPackageJSON` from `node:module`, an API that first exists in
// 22.14. On Node 20 eslint dies before linting a single file with
// `SyntaxError: … does not provide an export named 'findPackageJSON'`, and npm
// reports the mismatch only as an EBADENGINE warning it continues past.
//
// 🔴 THE PEER DEPENDENCIES ARE LOAD-BEARING
// -----------------------------------------
// `vue-eslint-parser` is NOT a dependency of `@nextcloud/eslint-config` — it is
// a peer of the `eslint-plugin-vue@10` it bundles, so the APP must supply it,
// at `^10`. If a stale `eslint-plugin-vue@^9` / `vue-eslint-parser@^9` is left
// in devDependencies it hoists over the bundled copy, `vue/base/setup-for-vue`
// then supplies NO parser, and `typescript-eslint/base` — which also claims
// `**/*.vue` — parses every SFC as TypeScript. Every `.vue` file fails with
// `Parsing error: Expression expected`, and because eslint reports a parse
// failure as ONE finding and lints nothing else in that file, the whole Vue
// layer goes unchecked while the problem count looks small.
// `@typescript-eslint/parser` must likewise be resolvable from the top level:
// `vue-eslint-parser` requires it by name for `<script>` blocks.
//
// 🔴 AND package.json CARRIES AN `overrides` ENTRY FOR THIS (no comments are
// possible there, so it is explained here):
//
//   "overrides": { "@conduction/nextcloud-vue": { "eslint": "$eslint" } }
//
// `@conduction/nextcloud-vue` declares `eslint: "^8.56.0 || ^9.0.0"` as a peer.
// It is marked OPTIONAL, which means npm will not INSTALL it — but if eslint is
// present anyway, the version must still satisfy that range, so a fresh
// `npm install` fails with ERESOLVE against eslint 10. The peer exists only
// because nc-vue ships an eslint preset (`@conduction/nextcloud-vue/eslint`),
// which this config no longer imports, so the constraint is inert for us.
// `$eslint` pins it to whatever the root declares. Drop the override once
// nc-vue widens the range to include ^10 (ConductionNL/nextcloud-vue).
//
// ⚠️ An EXISTING lockfile hides this: `npm ci` does not re-resolve peers, so an
// app can look fine until someone adds a dependency.
//
// WHAT THIS FILE NO LONGER NEEDS
// ------------------------------
// `conductionVue3Fixes` and the `FlatCompat` bridge to the eslintrc-era
// `@nextcloud` config are GONE. That preset existed to patch a Vue-2 ruleset.
// Measured against v9's `recommended` with `--print-config`, every one of its
// jobs is already done: 21 of 21 `vue/no-deprecated-*` rules are enabled, and
// `vue/no-v-model-argument` / `vue/no-v-for-template-key` — the two rules the
// preset had to switch off because they are inverted under Vue 3 — are not
// enabled at all, so there is nothing left to disable.
import { recommended } from '@nextcloud/eslint-config'
import eslintConfigPrettier from 'eslint-config-prettier'

export default [
	...recommended,

	{
		// 🔴 SCOPED, and it must be. Flat config resolves a rule's plugin from the
		// config object the rule sits in, and v9 also lints `.json` (it ships
		// `@eslint/json`), where `jsdoc` is not registered. An unscoped `jsdoc/*`
		// override aborts the entire run with "The jsdoc plugin is not defined in
		// your configuration file" — a hard config error, not a lint finding.
		//
		// 🔴 THE `ignores` ARE PART OF THAT SCOPE, NOT AN OPINION. v9 registers the
		// jsdoc plugin ONLY inside `nextcloud/documentation/*`, and every one of
		// those blocks carries exactly this ignore list — Nextcloud does not
		// require JSDoc in tests. Referencing a `jsdoc/*` rule for a test file
		// therefore names a plugin that is not registered there, and eslint
		// refuses to run at all. Measured: with `lint: "eslint src tests"` this
		// took out ALL 12 files under tests/ while src/ was fine.
		files: ['**/*.js', '**/*.mjs', '**/*.ts', '**/*.tsx', '**/*.vue'],
		ignores: [
			'**/*.test.*',
			'**/*.spec.*',
			'**/*.cy.*',
			'**/test/**',
			'**/tests/**',
			'**/__tests__/**',
			'**/__mocks__/**',
		],
		rules: {
			// `@spec` (hydra gate-16 / gate-19 traceability) and `@visual` (the
			// visual-coverage gate) are this fleet's own JSDoc tags. v9 configures
			// `jsdoc/check-tag-names` with no `definedTags`, so without this every
			// annotation reports as an invalid tag name.
			//
			// It must be passed as RULE OPTIONS: once a preset has configured the
			// rule, it reads `definedTags` from its own options object and
			// `settings.jsdoc.definedTags` is ignored.
			'jsdoc/check-tag-names': ['error', { definedTags: ['spec', 'visual'] }],
		},
	},

	{
		// `t` and `n` are imported for translation wiring that is not always called
		// yet. For `.ts`/`.vue` v9 turns the CORE `no-unused-vars` off and drives
		// `@typescript-eslint/no-unused-vars` instead, so the pattern belongs on
		// the TS rule.
		//
		// 🔴 SCOPED for the same reason as the block above: in an unscoped object
		// this applies to plain `.js` too, where v9 has NOT registered
		// `@typescript-eslint`, and eslint then refuses to run at all with
		// "could not find plugin @typescript-eslint".
		//
		// ⚠️ The swap is per-file-type, not global: `--print-config` on a `.js`
		// file reports `@typescript-eslint/no-unused-vars: undefined` and core
		// `no-unused-vars: [2, …]`. Plain `.js` therefore gets no ignore pattern
		// from here, which is deliberate — widening it would hide dead bindings.
		files: ['**/*.ts', '**/*.tsx', '**/*.vue'],
		rules: {
			'@typescript-eslint/no-unused-vars': [
				'error',
				{
					varsIgnorePattern: '^(t|n)$',
					argsIgnorePattern: '^_',
					ignoreRestSiblings: true,
				},
			],
		},
	},

	{
		// Node-side CLI checkers under tests/ legitimately use console and
		// process.exit, and ship as plain JS with no shebang. A GLOB, not a file
		// list: an explicit list silently stopped covering every new checker.
		files: ['tests/**/*.js', 'tests/**/*.mjs', 'tests/**/*.ts'],
		rules: {
			'no-console': 'off',
			'n/no-process-exit': 'off',
			'n/hashbang': 'off',
			// `_` / `__` as a deliberate throwaway binding — `catch (_)`, a
			// discarded destructuring slot. Narrow on purpose: the pattern matches
			// UNDERSCORES ONLY, so a real name that happens to start with `_` is
			// still reported. v9 drives plain `.js` through the CORE rule (the
			// `@typescript-eslint` swap is per-file-type), so it is set here.
			'no-unused-vars': [
				'error',
				{
					// vars/caught: UNDERSCORES ONLY, so a real name that merely
					// starts with `_` is still reported.
					varsIgnorePattern: '^_+$',
					caughtErrors: 'all',
					caughtErrorsIgnorePattern: '^_+$',
					// args: leading underscore, which is what NC's own TypeScript
					// block uses (`argsIgnorePattern: '^_'`) — a positional
					// parameter often has to keep a descriptive name to document
					// the signature even when the body ignores it.
					argsIgnorePattern: '^_',
					ignoreRestSiblings: true,
				},
			],
			// Tests import devDependencies by definition; this rule is about what
			// ships in the published package, which tests/ never does.
			'n/no-unpublished-import': 'off',
		},
	},

	{
		// Test globals. Several apps keep their spec files INSIDE `src/`, which the
		// lint script scans, and neither `@nextcloud/eslint-config` nor the runner
		// declares the framework globals. Without this, `no-undef` reports every
		// `describe` / `it` / `expect` as undefined: 1203 findings in openregister
		// alone, all from 7 identifiers.
		//
		// This is describing the environment, not relaxing a rule — the same reason
		// a webpack `require.context` file declares `require`. It matters that they
		// are declared rather than suppressed: 1203 fake findings would bury any
		// REAL `no-undef` in the same app, and `no-undef` is the rule that catches
		// a genuine typo'd identifier.
		files: [
			'**/*.{test,spec}.{js,mjs,ts,tsx,vue}',
			'**/{test,tests,__tests__,__mocks__}/**/*.{js,mjs,ts,tsx,vue}',
		],
		languageOptions: {
			globals: {
				describe: 'readonly',
				it: 'readonly',
				test: 'readonly',
				expect: 'readonly',
				beforeEach: 'readonly',
				afterEach: 'readonly',
				beforeAll: 'readonly',
				afterAll: 'readonly',
				vi: 'readonly',
				jest: 'readonly',
				suite: 'readonly',
			},
		},
	},

	// eslint-config-prettier LAST OF THE PRESETS, and it has to be: it only turns
	// rules OFF, and what it turns off is everything prettier owns — including the
	// `@stylistic/*` family v9 introduces (`indent`, `quotes`, `semi`).
	//
	// Those three AGREE with @nextcloud/prettier-config (tab / single / never),
	// which is why Nextcloud ships both packages. Agreement is not the point: two
	// tools formatting the same bytes is the unfixable state this fleet already
	// hit with php-cs-fixer and PHPCS, so exactly one of them is allowed an
	// opinion and prettier is it. `prettier --check` runs as its own CI job.
	//
	// NOTE: forms additionally uses `eslint-plugin-prettier/recommended`, which
	// reports prettier violations AS eslint errors. Deliberately not adopted —
	// this fleet already runs `prettier --check` separately, and doing both means
	// one defect reported twice in two places.
	eslintConfigPrettier,

	{
		// AFTER eslint-config-prettier, because prettier's config does NOT cover
		// this rule and it has to stay off. `@stylistic/exp-list-style` rewrites a
		// wrapped expression list to put a trailing comma before the closing paren
		// (`… : v,)`), which prettier immediately reformats back — the two tools
		// fight over the same bytes forever. `nextcloud/forms` disables exactly
		// this rule in its own flat config, so switching it off is matching
		// Nextcloud's resolution rather than diverging from it.
		name: 'conduction/prettier-jurisdiction',
		rules: {
			'@stylistic/exp-list-style': 'off',
		},
	},
]
