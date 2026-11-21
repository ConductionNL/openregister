/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */
import { n, t } from './l10n.js'

// The object dialogs render translated text as text, so values are filled in as they are.

describe('object dialog l10n', () => {
	it('fills in a value with a quote and an ampersand unescaped', () => {
		expect(t('openregister', 'You merge {source} into {target}.', {
			source: '\'s-Hertogenbosch & Co',
			target: 'Tilburg',
		})).toBe('You merge \'s-Hertogenbosch & Co into Tilburg.')
	})

	it('fills in a plural value unescaped', () => {
		expect(n('openregister', 'Migrate {count} object', 'Migrate {count} objects', 2, {
			count: '2 \'s-Hertogenbosch & Co',
		})).toBe('Migrate 2 \'s-Hertogenbosch & Co objects')
	})
})
