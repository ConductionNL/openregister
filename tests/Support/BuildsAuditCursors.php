<?php

/**
 * Build the scoped audit list's cursor seal over an opaque test double.
 *
 * The double hands out a random token per sealed value and opens only the
 * tokens it handed out, as authenticated encryption does: a test can then
 * assert that a cursor carries no offset and that a forged one starts over.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Support;

use Exception;
use OCA\OpenRegister\Service\Audit\ReadableAuditCursor;
use OCP\Security\ICrypto;

/**
 * One builder for the cursor seal the scoped audit list tests need.
 */
trait BuildsAuditCursors {

	/**
	 * The plaintexts the double sealed, by token.
	 *
	 * @var array<string, string>
	 */
	private array $sealedCursors = [];

	/**
	 * A cursor seal whose tokens are random and open only when this double made them.
	 *
	 * @return ReadableAuditCursor The seal.
	 */
	protected function auditCursors(): ReadableAuditCursor {
		$crypto = $this->createMock(ICrypto::class);
		$crypto->method('encrypt')->willReturnCallback(
			function (string $plaintext): string {
				// Letters only, so a test can tell an offset in the token apart.
				$token = 'sealed-' . strtr(bin2hex(random_bytes(12)), '0123456789', 'ghijklmnop');
				$this->sealedCursors[$token] = $plaintext;

				return $token;
			}
		);
		$crypto->method('decrypt')->willReturnCallback(
			function (string $token): string {
				if (isset($this->sealedCursors[$token]) === false) {
					throw new Exception('HMAC does not match.');
				}

				return $this->sealedCursors[$token];
			}
		);

		return new ReadableAuditCursor($crypto);
	}//end auditCursors()
}//end trait
