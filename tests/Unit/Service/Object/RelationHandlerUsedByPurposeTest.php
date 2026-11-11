<?php

/**
 * The "used by" list keeps to the caller's declared processing purpose.
 *
 * `/used` reads every table that holds a referrer. A table whose schema asks
 * for a purpose the request does not carry is left out, as the cross-table
 * `_relations_contains` search leaves it out.
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
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/verwerkingsregister-api/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\RegisterSchema;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Audit\PurposeGuard;
use OCA\OpenRegister\Service\Object\RelationHandler;
use OCA\OpenRegister\Service\Object\RelationHandlerStores;
use OCA\OpenRegister\Service\Object\RelationLookup;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The processing-purpose filter of the "used by" list.
 */
class RelationHandlerUsedByPurposeTest extends TestCase {

	/**
	 * The schema whose reads ask for a purpose the request does not carry.
	 *
	 * @var int
	 */
	private const BOUND_SCHEMA = 4;

	/**
	 * Run /used for a target referenced from an unbound table and from a purpose-bound one.
	 *
	 * @param PurposeGuard|null $guard The doelbinding guard, null when none is wired.
	 *
	 * @return string[] The uuids the list answers.
	 */
	private function usedBy(?PurposeGuard $guard): array {
		$target = $this->object(uuid: 'target-1', schema: '2');
		$open = $this->object(uuid: 'open-1', schema: '3');
		$open->setRelations(['target' => 'target-1']);
		$bound = $this->object(uuid: 'bound-1', schema: (string)self::BOUND_SCHEMA);
		$bound->setRelations(['target' => 'target-1']);

		$magic = $this->createMock(MagicMapper::class);
		$magic->method('find')->willReturn($target);
		$magic->method('findByRelationAcrossAllMagicTables')->willReturn([$open, $bound]);
		$magic->method('findAllInRegisterSchemaTable')->willReturnCallback(
			static fn (RegisterSchema $table): array => match ($table->schema->getId()) {
				3 => [$open],
				self::BOUND_SCHEMA => [$bound],
				default => [],
			}
		);

		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('find')->willReturnCallback(
			static function ($id): Register {
				$register = new Register();
				$register->setId((int)$id);
				return $register;
			}
		);
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturnCallback(
			static function ($id): Schema {
				$schema = new Schema();
				$schema->setId((int)$id);
				return $schema;
			}
		);

		$handler = new RelationHandler(
			stores: new RelationHandlerStores(
				objects: $magic,
				schemas: $schemaMapper,
				registers: $registerMapper
			),
			rbacHandler: $this->createMock(MagicRbacHandler::class),
			logger: $this->createMock(LoggerInterface::class),
			purposeGuard: $guard
		);

		$result = $handler->getUsedBy(lookup: (new RelationLookup(objectId: 'target-1'))->filteredBy(rbac: true, multitenancy: true));

		$uuids = array_map(static fn (array $row): string => (string)($row['@self']['id'] ?? $row['id'] ?? ''), $result['results']);
		sort($uuids);
		return $uuids;
	}//end usedBy()

	/**
	 * A guard that refuses reads of the bound schema and admits every other.
	 *
	 * @return PurposeGuard The guard.
	 */
	private function guardRefusingBoundSchema(): PurposeGuard {
		$guard = $this->createMock(PurposeGuard::class);
		$guard->method('allows')->willReturnCallback(
			static fn (?Register $register, ?Schema $schema): bool => $schema?->getId() !== self::BOUND_SCHEMA
		);
		return $guard;
	}//end guardRefusingBoundSchema()

	/**
	 * A guard that admits every read.
	 *
	 * @return PurposeGuard The guard.
	 */
	private function guardAdmittingAll(): PurposeGuard {
		$guard = $this->createMock(PurposeGuard::class);
		$guard->method('allows')->willReturn(true);
		return $guard;
	}//end guardAdmittingAll()

	/**
	 * Build an object in register 1.
	 *
	 * @param string $uuid   The uuid.
	 * @param string $schema The schema id.
	 *
	 * @return ObjectEntity The object.
	 */
	private function object(string $uuid, string $schema): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setRegister('1');
		$object->setSchema($schema);
		$object->setOwner('alice');
		return $object;
	}//end object()

	/**
	 * A referrer in a table the caller's purpose does not cover is left out.
	 *
	 * @return void
	 */
	public function testReferrerOutsideTheCallersPurposeIsLeftOut(): void {
		$this->assertSame(['open-1'], $this->usedBy(guard: $this->guardRefusingBoundSchema()));
	}//end testReferrerOutsideTheCallersPurposeIsLeftOut()

	/**
	 * When the guard admits every table, every referrer is listed.
	 *
	 * @return void
	 */
	public function testReferrersWithinTheCallersPurposeAreKept(): void {
		$this->assertSame(['bound-1', 'open-1'], $this->usedBy(guard: $this->guardAdmittingAll()));
	}//end testReferrersWithinTheCallersPurposeAreKept()
}//end class
