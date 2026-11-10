<?php

/**
 * CascadingHandler::createRelatedObject: how one nested object is created under its parent.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Service\Object\CascadeTarget;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\Object\CascadingHandler;
use OCA\OpenRegister\Service\Object\SaveObjectInterface;
use OCA\OpenRegister\Service\Object\UtilityHandler;
use OCP\IURLGenerator;
use ReflectionProperty;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Tests\Unit\Service\Object\Support\SaveObjectArgs;
use OCA\OpenRegister\Service\Object\ObjectSaveOptions;

/**
 * The save handler as the cascade sees it: the interface plus the URL generator it reads by reflection.
 */
abstract class CascadeSaveHandlerDouble implements SaveObjectInterface {

	/**
	 * The URL generator the cascade reads to render the schema.
	 *
	 * @var IURLGenerator|null
	 */
	protected $urlGenerator = null;
}//end class

/**
 * Each branch of one cascade create.
 *
 * @covers \OCA\OpenRegister\Service\Object\CascadingHandler
 * @covers \OCA\OpenRegister\Service\Object\CascadeTarget
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Db\Schema\ConfigurationEntryRules
 * @uses \OCA\OpenRegister\Db\Schema\ConfigurationValidator
 * @uses \OCA\OpenRegister\Exception\NotAuthorizedException
 * @uses \OCA\OpenRegister\Db\Schema\SchemaPropertyDocument
 */
class CascadingHandlerCreateRelatedTest extends TestCase {

	/**
	 * The save handler.
	 *
	 * @var SaveObjectInterface&MockObject
	 */
	private SaveObjectInterface $save;

	/**
	 * The schema mapper.
	 *
	 * @var SchemaMapperInterface&MockObject
	 */
	private SchemaMapperInterface $schemas;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * The target schema "Address".
	 *
	 * @var Schema
	 */
	private Schema $address;

	/**
	 * Build the boundaries with one schema, slug "Address".
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->save = $this->createMock(SaveObjectInterface::class);
		$this->schemas = $this->createMock(SchemaMapperInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$other = new Schema();
		$other->setSlug('person');
		$this->address = new Schema();
		$this->address->setSlug('Address');
		$this->schemas->method('findAll')->willReturn([$other, $this->address]);
	}//end setUp()

	/**
	 * The handler under test.
	 *
	 * @return CascadingHandler
	 */
	private function handler(): CascadingHandler {
		return new CascadingHandler($this->save, $this->schemas, $this->createMock(UtilityHandler::class), $this->logger);
	}//end handler()

	/**
	 * The schema is found by slug case-insensitively; the child gets the parent uuid on its inverse property, loses its identity, and is tracked.
	 *
	 * @return void
	 */
	public function testTheChildIsCreatedUnderItsParentAndTracked(): void {
		$created = new ObjectEntity();
		$created->setUuid('child-1');
		$this->save->expects($this->once())->method('assertMayCascadeCreate')->with($this->identicalTo($this->address), '4');
		$this->save->expects($this->once())->method('saveObject')
			->with(
				['street' => 'Main', 'owner' => 'parent-1', '@self' => ['register' => 4]],
				$this->callback(fn (ObjectSaveOptions $options): bool => $options->target->register === 4 && $options->target->schema === $this->address && $options->uuid === null),
				true,
				true
			)
			->willReturn($created);
		$this->save->expects($this->once())->method('trackCreatedSubObject')->with('child-1', $this->isType('array'));

		$uuid = $this->handler()->createRelatedObject(objectData: ['id' => 'x', 'street' => 'Main', '@self' => ['id' => 'x', 'uuid' => 'y', 'register' => 4]], target: (new CascadeTarget(parentUuid: 'parent-1', register: 4))->with(definition: ['$ref' => '#/components/schemas/address', 'inversedBy' => 'owner']));

		$this->assertSame('child-1', $uuid);
	}//end testTheChildIsCreatedUnderItsParentAndTracked()

	/**
	 * Without a register and without an inverse property the child is saved as given; a child without a uuid is not tracked.
	 *
	 * @return void
	 */
	public function testWithoutRegisterOrInverseTheChildIsSavedAsGiven(): void {
		$this->save->expects($this->once())->method('assertMayCascadeCreate')->with($this->identicalTo($this->address), null);
		$this->save->expects($this->once())->method('saveObject')
			->with(['street' => 'Main'], $this->callback(fn (ObjectSaveOptions $options): bool => $options->target->register === null && $options->target->schema === $this->address))
			->willReturn(new ObjectEntity());
		$this->save->expects($this->never())->method('trackCreatedSubObject');

		$this->assertNull(
			$this->handler()->createRelatedObject(objectData: ['street' => 'Main'], target: (new CascadeTarget(parentUuid: 'parent-1', register: null))->with(definition: ['$ref' => '#/components/schemas/address', 'inversedBy' => '']))
		);
	}//end testWithoutRegisterOrInverseTheChildIsSavedAsGiven()

	/**
	 * A reference that is missing, not a component reference, ends in a slash, or names no known schema creates nothing.
	 *
	 * @return void
	 */
	public function testAnUnresolvableTargetCreatesNothing(): void {
		$this->save->expects($this->never())->method('saveObject');
		$handler = $this->handler();
		$answers = [];
		foreach ([[], ['$ref' => ''], ['$ref' => 'address'], ['$ref' => '#/components/schemas/'], ['$ref' => '#/components/schemas/unknown']] as $definition) {
			$answers[] = $handler->createRelatedObject(objectData: ['a' => 1], target: (new CascadeTarget(parentUuid: 'parent-1', register: 4))->with(definition: $definition));
		}

		$this->assertSame([null, null, null, null, null], $answers);
	}//end testAnUnresolvableTargetCreatesNothing()

	/**
	 * A refused cascade create is rethrown; any other failure is logged and answers null.
	 *
	 * @return void
	 */
	public function testARefusalEscapesAndOtherFailuresAreLogged(): void {
		$this->save->method('saveObject')->willThrowException(new RuntimeException('constraint'));
		$this->logger->expects($this->once())->method('error')
			->with('[CascadingHandler] Failed to create related object: constraint', $this->isType('array'));
		$definition = ['$ref' => '#/components/schemas/address'];

		$this->assertNull($this->handler()->createRelatedObject(objectData: ['a' => 1], target: (new CascadeTarget(parentUuid: 'parent-1', register: 4))->with(definition: $definition)));

		$save = $this->createMock(SaveObjectInterface::class);
		$save->method('assertMayCascadeCreate')->willThrowException(new NotAuthorizedException('no create right'));
		$save->expects($this->never())->method('saveObject');
		$this->save = $save;
		$this->expectException(NotAuthorizedException::class);
		$this->handler()->createRelatedObject(objectData: ['a' => 1], target: (new CascadeTarget(parentUuid: 'parent-1', register: 4))->with(definition: $definition));
	}//end testARefusalEscapesAndOtherFailuresAreLogged()

	/**
	 * A schema without inversedBy properties (here: one the save handler cannot render) leaves the object and uuid as given.
	 *
	 * @return void
	 */
	public function testNoInverseRelationsLeavesTheObjectAlone(): void {
		$this->save->expects($this->never())->method('saveObject');

		$this->assertSame(
			[['a' => ['b' => 1]], 'u-1'],
			$this->handler()->handlePreValidationCascading(['a' => ['b' => 1]], $this->address, 'u-1', 4)
		);
	}//end testNoInverseRelationsLeavesTheObjectAlone()
	/**
	 * A save handler double carrying a URL generator, so the schema can be rendered.
	 *
	 * @return SaveObjectInterface&MockObject
	 */
	private function renderingSave(): SaveObjectInterface {
		$save = $this->createMock(CascadeSaveHandlerDouble::class);
		(new ReflectionProperty(CascadeSaveHandlerDouble::class, 'urlGenerator'))->setValue($save, $this->createMock(IURLGenerator::class));

		return $save;
	}//end renderingSave()

	/**
	 * Before validation: nested objects under inversedBy properties are created and replaced by their uuids; uuids, empty and plain values stay.
	 *
	 * @return void
	 */
	public function testPreValidationCascadeReplacesNestedObjectsWithUuids(): void {
		$parent = new Schema();
		$parent->setProperties(
			[
				'addresses' => ['type' => 'array', 'items' => ['type' => 'object', '$ref' => '#/components/schemas/address', 'inversedBy' => 'owner']],
				'home' => ['type' => 'object', '$ref' => '#/components/schemas/address', 'inversedBy' => 'resident'],
				'work' => ['type' => 'object', '$ref' => '#/components/schemas/address', 'inversedBy' => 'worker'],
				'empty' => ['type' => 'object', 'inversedBy' => 'x'],
				'name' => ['type' => 'string'],
			]
		);
		$uuid = '0b0e9f3c-1d2a-4c5b-8e6f-7a8b9c0d1e2f';
		$utility = $this->createMock(UtilityHandler::class);
		$utility->method('isUuid')->willReturnCallback(fn (mixed $value): bool => $value === $uuid);
		$save = $this->renderingSave();
		$saved = [];
		$save->method('saveObject')->willReturnCallback(
			SaveObjectArgs::callback(callback: function ($register, $schema, array $data) use (&$saved): ObjectEntity {
				$saved[] = $data;
				$created = new ObjectEntity();
				$created->setUuid('new-' . count($saved));
				return $created;
			})
		);
		$handler = new CascadingHandler($save, $this->schemas, $utility, $this->logger);

		[$object, $parentUuid] = $handler->handlePreValidationCascading(
			['addresses' => [['street' => 'A'], $uuid, 'not-a-uuid', 7], 'home' => ['street' => 'B'], 'work' => $uuid, 'empty' => [], 'name' => 'Ann'],
			$parent,
			null,
			4
		);

		$this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $parentUuid, 'a parent without uuid gets one before its children point at it');
		$this->assertSame(['new-1', $uuid], $object['addresses'], 'a created item and a uuid item stay, anything else is dropped');
		$this->assertSame('new-2', $object['home']);
		$this->assertSame($uuid, $object['work']);
		$this->assertSame([], $object['empty']);
		$this->assertSame('Ann', $object['name']);
		$this->assertSame([['street' => 'A', 'owner' => $parentUuid], ['street' => 'B', 'resident' => $parentUuid]], $saved);
	}//end testPreValidationCascadeReplacesNestedObjectsWithUuids()

	/**
	 * A failed child create keeps the nested data as it was, in a list and on its own.
	 *
	 * @return void
	 */
	public function testAFailedChildCreateKeepsTheNestedData(): void {
		$parent = new Schema();
		$parent->setProperties(
			[
				'addresses' => ['type' => 'array', 'items' => ['type' => 'object', '$ref' => '#/components/schemas/address', 'inversedBy' => 'owner']],
				'home' => ['type' => 'object', '$ref' => '#/components/schemas/address', 'inversedBy' => 'resident'],
			]
		);
		$save = $this->renderingSave();
		$save->method('saveObject')->willThrowException(new RuntimeException('invalid'));
		$this->logger->expects($this->exactly(2))->method('error');
		$handler = new CascadingHandler($save, $this->schemas, $this->createMock(UtilityHandler::class), $this->logger);

		[$object, $parentUuid] = $handler->handlePreValidationCascading(
			['addresses' => [['street' => 'A']], 'home' => ['street' => 'B']],
			$parent,
			'p-1',
			null
		);

		$this->assertSame('p-1', $parentUuid);
		$this->assertSame(['addresses' => [['street' => 'A']], 'home' => ['street' => 'B']], $object);
	}//end testAFailedChildCreateKeepsTheNestedData()
}//end class
