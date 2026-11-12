<?php

/**
 * The write refusals are part of preparing a save, not only units of their own.
 *
 * Other tests call the guards directly; these run them through the real
 * prepareObjectForSave(), so a save that stopped asking them turns red.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\ValidationException;
use OCA\OpenRegister\Service\DateTimeNormalizer;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

/**
 * @covers \OCA\OpenRegister\Service\ObjectService
 * @uses   \OCA\OpenRegister\Db\JsonFieldsTrait
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Exception\ValidationException
 * @uses   \OCA\OpenRegister\Service\Geo\GeoJsonGeometryValidator
 * @uses   \OCA\OpenRegister\Service\Hinge\LensResolver
 * @uses   \OCA\OpenRegister\Service\Object\DraftStatusPolicy
 * @uses   \OCA\OpenRegister\Service\Object\ObjectDateValues
 * @uses   \OCA\OpenRegister\Service\Object\NotSuppliedHandler
 * @uses   \OCA\OpenRegister\Service\Object\RepeatingGroupValidator
 * @uses   \OCA\OpenRegister\Service\WritePhaseProbe
 */
class ObjectServiceSaveGuardWiringTest extends TestCase {

	/**
	 * Prepare a create of one object under a schema with a polygon and a lens.
	 *
	 * @param array<string, mixed> $object The object as it would be saved.
	 *
	 * @return array<string, mixed> The prepared object.
	 */
	private function prepare(array $object): array {
		$schema = new Schema();
		$schema->hydrate(
			[
				'slug' => 'wijk',
				'title' => 'Wijk',
				'hardValidation' => false,
				'properties' => ['area' => ['type' => 'object', 'format' => 'geo:polygon']],
				'configuration' => [
					'x-openregister-lenses' => ['besluitDatum' => ['through' => 'besluit', 'property' => 'datum']],
				],
			]
		);

		$handler = $this->createMock(SaveObject::class);
		foreach (['applyAlwaysDefaults', 'applyExpressionDefaults', 'seedLifecycleFieldOnCreate'] as $step) {
			$handler->method($step)->willReturnCallback(static fn (Schema $schema, array $data): array => $data);
		}

		$service = (new ReflectionClass(ObjectService::class))->newInstanceWithoutConstructor();
		(new ReflectionProperty(ObjectService::class, 'currentSchema'))->setValue($service, $schema);
		(new ReflectionProperty(ObjectService::class, 'logger'))->setValue($service, $this->createMock(LoggerInterface::class));
		(new ReflectionProperty(ObjectService::class, 'saveHandler'))->setValue($service, $handler);
		(new ReflectionProperty(ObjectService::class, 'dateTimeNormalizer'))->setValue($service, $this->createMock(DateTimeNormalizer::class));

		return (new ReflectionMethod(ObjectService::class, 'prepareObjectForSave'))
			->invoke($service, $object, ['uuid' => null, 'isCreate' => true]);
	}//end prepare()

	public function testAnAllowedObjectIsPrepared(): void {
		$this->assertSame(['naam' => 'Centrum'], $this->prepare(['naam' => 'Centrum']));
	}//end testAnAllowedObjectIsPrepared()

	public function testAGeometryThatDoesNotCloseIsRefused(): void {
		$open = ['type' => 'Polygon', 'coordinates' => [[[5.1, 52.0], [5.2, 52.0], [5.2, 52.1], [5.1, 52.1]]]];

		$this->expectException(ValidationException::class);
		$this->expectExceptionMessage("'area'");
		$this->prepare(['area' => $open]);
	}//end testAGeometryThatDoesNotCloseIsRefused()

	public function testAWriteToALensIsRefused(): void {
		$this->expectException(ValidationException::class);
		$this->expectExceptionMessage('besluitDatum');
		$this->prepare(['besluitDatum' => '2026-01-01']);
	}//end testAWriteToALensIsRefused()
}//end class
