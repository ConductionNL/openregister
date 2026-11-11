<?php

/**
 * Unit tests for a final-state reference that resolves to a row without a schema.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Lifecycle;

use OCA\OpenRegister\Db\MagicMapper\MagicObjectFinderInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Lifecycle\LifecycleFinalStateResolver;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A row resolved without a schema entity cannot be shown to belong to the declared schema, so the state is not final.
 *
 * @covers \OCA\OpenRegister\Service\Lifecycle\LifecycleFinalStateResolver
 */
class LifecycleFinalStateResolverSchemaTest extends TestCase {

	/**
	 * Without a schema on the resolved row the state is not final, and the mismatch is logged once.
	 *
	 * @return void
	 */
	public function testARowWithoutASchemaIsNotFinal(): void {
		$row = new ObjectEntity();
		$row->setObject(['isFinal' => true]);
		$objects = $this->createMock(MagicObjectFinderInterface::class);
		$objects->expects($this->once())
			->method('findAcrossAllSources')
			->willReturn(['object' => $row, 'schema' => null, 'register' => null]);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('warning')
			->with('[LifecycleFinalStateResolver] lifecycle value "s-1" resolves to a row outside the declared schema "statussen".');

		$resolver = new LifecycleFinalStateResolver($objects, $logger);

		$this->assertFalse($resolver->isFinalByReference(['from' => 'statussen', 'field' => 'isFinal'], 's-1'));
		$this->assertFalse($resolver->isFinalByReference(['from' => 'statussen', 'field' => 'isFinal'], 's-1'), 'memoised');

	}//end testARowWithoutASchemaIsNotFinal()
}//end class
