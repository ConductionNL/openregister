<?php

/**
 * Unit test for DeferredEntryObjectResolver's lookup: an entry's object is
 * found unscoped and unrendered, and an object that is gone or soft-deleted
 * resolves to nothing.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Deferral
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Deferral;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Deferral\DeferredEntryObjectResolver;
use OCA\OpenRegister\Tests\Unit\Support\FindCallShape;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Service\Deferral\DeferredEntryObjectResolver
 * @uses   \OCA\OpenRegister\Service\WriteCause
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 */
class DeferredEntryObjectResolverLookupTest extends TestCase {

	/**
	 * The resolver over a store whose find() answers the given objects in turn.
	 *
	 * @param array<int, ObjectEntity|null> $answers What find() returns, call by call.
	 *
	 * @return DeferredEntryObjectResolver
	 */
	private function resolver(array $answers): DeferredEntryObjectResolver {
		$objects = $this->createMock(ObjectOperationsInterface::class);
		$objects->expects($this->exactly(count($answers)))->method('find')
			->with(...FindCallShape::findArgs('u-1', [], false, 'zaken', null, false, false, false))
			->willReturnOnConsecutiveCalls(...$answers);
		return new DeferredEntryObjectResolver(objectService: $objects, logger: $this->createMock(LoggerInterface::class));
	}//end resolver()

	/**
	 * A live object is resolved; one that is not there, or is soft-deleted, is not.
	 *
	 * @return void
	 */
	public function testOnlyALiveObjectResolves(): void {
		$live    = new ObjectEntity();
		$deleted = new ObjectEntity();
		$deleted->setDeleted(['deleted' => '2026-11-01T00:00:00+00:00']);
		$resolver = $this->resolver(answers: [$live, null, $deleted]);
		$entry    = ['uuid' => 'u-1', 'register' => 'zaken', 'schema' => ''];

		$this->assertSame($live, $resolver->resolve(entry: $entry));
		$this->assertNull($resolver->resolve(entry: $entry));
		$this->assertNull($resolver->resolve(entry: $entry));
	}//end testOnlyALiveObjectResolves()
}//end class
