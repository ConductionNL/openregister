<?php

/**
 * An IL10N double for the migration-pack classes: the text with its parameters filled in, as an
 * untranslated instance renders it, or with a prefix when a test asks which messages are translated.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/migration-mapping-packs/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\MigrationPack;

use OCP\IL10N;

/**
 * Hands a test an IL10N whose `t()` is `vsprintf`, optionally prefixed.
 */
trait PassThroughL10nTrait {

	/**
	 * An IL10N that renders the English text with its parameters.
	 *
	 * @param string $prefix Put before every translated message (`NL:` to prove a message went through `t()`).
	 *
	 * @return IL10N
	 */
	private function passThroughL10n(string $prefix = ''): IL10N {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array|string $parameters = []): string => $prefix . vsprintf($text, (array)$parameters)
		);

		return $l10n;
	}//end passThroughL10n()
}//end trait
