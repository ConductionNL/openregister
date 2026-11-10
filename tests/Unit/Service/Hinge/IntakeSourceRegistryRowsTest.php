<?php

/**
 * Unit tests for the row shapes the intake-source registry accepts.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Hinge
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Hinge;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Hinge\IntakeSourceRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Rows arrive as entities or rendered arrays; both are read, anything else is ignored, and disabled sources drop out.
 *
 * @covers \OCA\OpenRegister\Service\Hinge\IntakeSourceRegistry
 */
class IntakeSourceRegistryRowsTest extends TestCase {

	/**
	 * Entity and array rows are both sources; a scalar row is not; `enabled: false` is filtered.
	 *
	 * @return void
	 */
	public function testEntityAndArrayRowsAreRead(): void {
		$entity = new ObjectEntity();
		$entity->setObject(['slug' => 'mail', 'enabled' => true]);

		$objects = $this->createMock(ObjectOperationsInterface::class);
		$objects->method('setRegister')->willReturnSelf();
		$objects->method('setSchema')->willReturnSelf();
		$objects->method('findAll')->willReturn([$entity, ['slug' => 'form', 'enabled' => false], 'garbage', ['slug' => 'api']]);

		$registry = new IntakeSourceRegistry($objects, $this->createMock(LoggerInterface::class));

		$this->assertSame(['mail', 'form', 'api'], array_column($registry->all(), 'slug'));
		$this->assertSame(['mail', 'api'], array_column($registry->enabled(), 'slug'));

	}//end testEntityAndArrayRowsAreRead()
}//end class
