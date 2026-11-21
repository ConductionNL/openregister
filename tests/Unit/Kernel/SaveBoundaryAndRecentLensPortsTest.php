<?php

/**
 * The object save and the object search ask Processes through Core ports, not through its classes.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Kernel;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\Core\ReadHistoryInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\SaveBoundaryInterface;
use OCA\OpenRegister\Service\Lifecycle\AutoTransitionPass;
use OCA\OpenRegister\Service\Lifecycle\AutoTransitionRunner;
use OCA\OpenRegister\Service\Object\Collaborators\ObjectHistoryServices;
use OCA\OpenRegister\Service\Object\SearchQueryHandler;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionNamedType;

/**
 * @covers \OCA\OpenRegister\Service\Object\Collaborators\ObjectHistoryServices
 * @covers \OCA\OpenRegister\Service\Lifecycle\AutoTransitionPass
 */
final class SaveBoundaryAndRecentLensPortsTest extends TestCase {

	/**
	 * The type a constructor parameter or property is declared with.
	 *
	 * @param \ReflectionParameter|\ReflectionProperty $member The parameter or property.
	 *
	 * @return string The type name.
	 */
	private static function typeOf(\ReflectionParameter|\ReflectionProperty $member): string {
		$type = $member->getType();
		self::assertInstanceOf(ReflectionNamedType::class, $type);
		self::assertTrue($type->allowsNull());
		return $type->getName();
	}//end typeOf()

	/**
	 * The history bundle and ObjectService hold the save boundary as the Core port.
	 *
	 * @return void
	 */
	public function testTheSaveBoundaryIsTypedAsTheCorePort(): void {
		$bundle = (new ReflectionClass(ObjectHistoryServices::class))->getConstructor()->getParameters();
		$byName = array_column(array_map(static fn ($p): array => [$p->getName(), $p], $bundle), 1, 0);
		$this->assertSame(SaveBoundaryInterface::class, self::typeOf($byName['autoTransitions']));
		$this->assertSame(SaveBoundaryInterface::class, self::typeOf(new \ReflectionProperty(ObjectService::class, 'autoTransitions')));
	}//end testTheSaveBoundaryIsTypedAsTheCorePort()

	/**
	 * The search query handler holds the read history as the Core port.
	 *
	 * @return void
	 */
	public function testTheRecentLensIsTypedAsTheCorePort(): void {
		$this->assertSame(ReadHistoryInterface::class, self::typeOf(new \ReflectionProperty(SearchQueryHandler::class, 'readHistory')));
	}//end testTheRecentLensIsTypedAsTheCorePort()

	/**
	 * The pass answers the port as before: a nested boundary drains nothing, and the saved entity stands when nothing applied.
	 *
	 * @return void
	 */
	public function testThePassAnswersTheBoundaryPort(): void {
		$pass = new AutoTransitionPass(
			runner: $this->createMock(AutoTransitionRunner::class),
			logger: $this->createMock(LoggerInterface::class)
		);
		$this->assertInstanceOf(SaveBoundaryInterface::class, $pass);

		$pass->enter();
		$pass->enter();
		$this->assertSame([], $pass->leave());
		$this->assertTrue($pass->isInsideBoundary());

		$saved = new ObjectEntity();
		$saved->setUuid('a');
		$this->assertSame($saved, $pass->preferAutomatic(saved: $saved, applied: []));
		$applied = new ObjectEntity();
		$this->assertSame($applied, $pass->preferAutomatic(saved: $saved, applied: ['a' => $applied]));
	}//end testThePassAnswersTheBoundaryPort()
}//end class
