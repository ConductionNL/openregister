<?php

/**
 * The related-objects list keeps to the caller's organisation boundary.
 *
 * `/uses` reads each related table through the filtered list query and falls
 * back to the rows a cross-table lookup found for any uuid that query did not
 * return. A row the fallback adds is read once more through the single-object
 * read, which applies the organisation filter.
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
 * @spec openspec/specs/linked-entity-types/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\PerformanceHandler;
use OCA\OpenRegister\Service\Object\RelationHandler;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The organisation boundary of the related-objects list.
 */
class RelationHandlerOrganisationTest extends TestCase {

	/**
	 * Run /uses for a lead that points at one object of the caller's organisation and one of another.
	 *
	 * @param bool $multitenancy The multitenancy flag the caller passes.
	 *
	 * @return string[] The uuids the list answers.
	 */
	private function uses(bool $multitenancy): array {
		$lead = $this->object(uuid: 'lead-1', schema: '2', organisation: 'org-a');
		$lead->setRelations(['ours' => 'ours-1', 'theirs' => 'theirs-1']);

		$ours = $this->object(uuid: 'ours-1', schema: '3', organisation: 'org-a');
		$theirs = $this->object(uuid: 'theirs-1', schema: '3', organisation: 'org-b');

		$magic = $this->createMock(originalClassName: MagicMapper::class);
		// The single-object read applies the organisation filter: another organisation's object is not found.
		$magic->method('find')->willReturnCallback(
			static function (string|int $identifier, ...$rest) use ($lead, $ours): ObjectEntity {
				$organisationFilter = ($rest[4] ?? true);
				return match (true) {
					$identifier === 'lead-1' => $lead,
					$identifier === 'ours-1' => $ours,
					$organisationFilter === false => throw new \LogicException('read without the organisation filter'),
					default => throw new DoesNotExistException('not found'),
				};
			}
		);
		$magic->method('findMultipleAcrossAllMagicTables')->willReturn([$ours, $theirs]);
		// The per-table query applies the organisation filter: the other organisation's object is not in its answer.
		$magic->method('findAllInRegisterSchemaTable')->willReturn([$ours]);

		$registerMapper = $this->createMock(originalClassName: RegisterMapper::class);
		$registerMapper->method('find')->willReturnCallback(
			static function ($id): Register {
				$register = new Register();
				$register->setId((int)$id);
				return $register;
			}
		);
		$schemaMapper = $this->createMock(originalClassName: SchemaMapper::class);
		$schemaMapper->method('find')->willReturnCallback(
			static function ($id): Schema {
				$schema = new Schema();
				$schema->setId((int)$id);
				return $schema;
			}
		);

		$rbac = $this->createMock(originalClassName: MagicRbacHandler::class);
		$rbac->method('isAdmin')->willReturn(false);
		$rbac->method('getCurrentUserId')->willReturn('bob');
		// A schema without authorization rules: hasPermission() admits every caller.
		$rbac->method('hasPermission')->willReturn(true);

		$handler = new RelationHandler(
			objectEntityMapper: $magic,
			schemaMapper: $schemaMapper,
			performanceHandler: $this->createMock(originalClassName: PerformanceHandler::class),
			rbacHandler: $rbac,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
			registerMapper: $registerMapper
		);

		$result = $handler->getUses(objectId: 'lead-1', _multitenancy: $multitenancy);

		return array_map(static fn (array $row): string => (string)($row['@self']['id'] ?? $row['id'] ?? ''), $result['results']);
	}//end uses()

	/**
	 * Build an object in register 1.
	 *
	 * @param string $uuid         The uuid.
	 * @param string $schema       The schema id.
	 * @param string $organisation The owning organisation.
	 *
	 * @return ObjectEntity The object.
	 */
	private function object(string $uuid, string $schema, string $organisation): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setRegister('1');
		$object->setSchema($schema);
		$object->setOrganisation($organisation);
		$object->setOwner('alice');
		return $object;
	}//end object()

	/**
	 * Another organisation's object is not in the list.
	 *
	 * @return void
	 */
	public function testAnotherOrganisationsObjectIsLeftOut(): void {
		$this->assertSame(expected: ['ours-1'], actual: $this->uses(multitenancy: true));
	}//end testAnotherOrganisationsObjectIsLeftOut()

	/**
	 * With multitenancy switched off the fallback rows are kept as they were found.
	 *
	 * @return void
	 */
	public function testWithoutMultitenancyTheFoundRowsAreKept(): void {
		$this->assertSame(expected: ['ours-1', 'theirs-1'], actual: $this->uses(multitenancy: false));
	}//end testWithoutMultitenancyTheFoundRowsAreKept()
}//end class
