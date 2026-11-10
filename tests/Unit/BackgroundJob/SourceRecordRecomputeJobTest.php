<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\BackgroundJob
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\BackgroundJob;

use OCA\OpenRegister\BackgroundJob\SourceRecordRecomputeJob;
use OCA\OpenRegister\BackgroundJob\Support\ActorForwardingServices;
use OCA\OpenRegister\Service\Deferral\DeferredListenerContext;
use OCA\OpenRegister\Service\Merge\MasterRecomputeInterface;
use OCA\OpenRegister\Service\OrganisationServiceInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins which masters the deferred source-record recompute reaches.
 */
class SourceRecordRecomputeJobTest extends TestCase {

	/**
	 * Every entry with a master uuid is recomputed once; an empty or missing uuid is skipped.
	 *
	 * @return void
	 */
	public function testRecomputesEachNamedMasterAndSkipsBlankOnes(): void {
		$recompute = $this->createMock(MasterRecomputeInterface::class);
		$seen = [];
		$recompute->method('recompute')->willReturnCallback(
			function (string $masterUuid) use (&$seen): void {
				$seen[] = $masterUuid;
			}
		);

		$job = new SourceRecordRecomputeJob(
			$this->createMock(ITimeFactory::class),
			new ActorForwardingServices(
				$this->createMock(IUserSession::class),
				$this->createMock(IUserManager::class),
				$this->createMock(OrganisationServiceInterface::class),
				$this->createMock(LoggerInterface::class)
			),
			$recompute
		);

		$argument = (new DeferredListenerContext(
			userId: null,
			orgUuid: null,
			entries: [['masterUuid' => 'm-1'], ['masterUuid' => ''], ['other' => 'x'], ['masterUuid' => 'm-2']]
		))->toJobArguments();

		$method = (new \ReflectionClass($job))->getMethod('run');
		$method->invoke($job, $argument);

		$this->assertSame(['m-1', 'm-2'], $seen);
	}//end testRecomputesEachNamedMasterAndSkipsBlankOnes()
}//end class
