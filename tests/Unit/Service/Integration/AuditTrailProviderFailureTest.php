<?php

/**
 * Failure-path tests for the built-in AuditTrailProvider.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Integration;

use OCA\OpenRegister\Service\Audit\ObjectHistoryForReaders;
use OCA\OpenRegister\Kernel\Contracts\ObjectRef;
use OCA\OpenRegister\Service\Integration\BuiltinProviders\AuditTrailProvider;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * A history that cannot be read lists nothing; one that can is listed in full, reindexed.
 *
 * @covers \OCA\OpenRegister\Service\Integration\BuiltinProviders\AuditTrailProvider
 * @uses   \OCA\OpenRegister\Kernel\Contracts\ObjectRef
 * @uses \OCA\OpenRegister\Kernel\Contracts\ObjectRef
 */
class AuditTrailProviderFailureTest extends TestCase {

	/**
	 * A failing history read lists nothing rather than throwing into the integration panel.
	 *
	 * @return void
	 */
	public function testFailingHistoryListsNothing(): void {
		$history = $this->createMock(ObjectHistoryForReaders::class);
		$history->method('page')->willThrowException(new RuntimeException('db'));

		$provider = new AuditTrailProvider(history: $history, l10n: $this->createMock(IL10N::class));

		$this->assertSame([], $provider->list(ref: new ObjectRef(register: 'r', schema: 's', id: 'o')));
	}//end testFailingHistoryListsNothing()

	/**
	 * The whole history is asked for under the listed path, and its results come back as a list.
	 *
	 * @return void
	 */
	public function testHistoryIsAskedInFullAndReindexed(): void {
		$history = $this->createMock(ObjectHistoryForReaders::class);
		$history->expects($this->once())->method('page')
			->with(new ObjectRef(register: 'r', schema: 's', id: 'o'), ['limit' => PHP_INT_MAX])
			->willReturn(['results' => [3 => ['id' => 'b'], 7 => ['id' => 'a']]]);

		$provider = new AuditTrailProvider(history: $history, l10n: $this->createMock(IL10N::class));

		$this->assertSame([['id' => 'b'], ['id' => 'a']], $provider->list(ref: new ObjectRef(register: 'r', schema: 's', id: 'o')));
	}//end testHistoryIsAskedInFullAndReindexed()
}//end class
