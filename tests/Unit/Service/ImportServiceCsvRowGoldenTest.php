<?php

/**
 * Golden test for ImportService::transformCsvRowToObject.
 *
 * Records the object built from each row, for an admin, a regular user and no user,
 * in fixtures/import-csv-row-golden.json. Set UPDATE_GOLDEN=1 to rewrite it.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ImportService;
use OCA\OpenRegister\Service\MigrationPack\MappingEngine;
use OCA\OpenRegister\Service\Object\ValidateObject;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Translation\TranslationCsvCodec;
use OCA\OpenRegister\Tests\Unit\Service\MigrationPack\PassThroughL10nTrait;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use Throwable;

/**
 * @covers \OCA\OpenRegister\Service\ImportService
 * @uses   \OCA\OpenRegister\Service\Import\ImportValueCaster
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Service\MigrationPack\MappingEngine
 */
class ImportServiceCsvRowGoldenTest extends TestCase {
	use PassThroughL10nTrait;

	/**
	 * Rows to transform.
	 *
	 * @return array<string, array>
	 */
	private static function rows(): array {
		return [
			'plain' => ['name' => 'Jan', 'age' => '30', 'extra' => 'x'],
			'typed' => ['age' => '7', 'score' => '1.5', 'active' => 'true', 'tags' => '["a","b"]', 'born' => '2026-01-02', 'obj' => '{"k":1}'],
			'empty cells' => ['name' => '', 'age' => null, 'extra' => '0', 'n' => 0],
			'underscore' => ['_id' => '9', '_score' => '1', 'name' => 'A'],
			'at columns' => ['@self.owner' => 'bob', '@self.published' => '2026-01-01 10:00:00', '@self.organisation' => 'org', '@other' => 'x', '@self.' => 'y', 'name' => 'B'],
			'id column' => ['id' => 'abc-1', 'name' => 'C'],
			'numeric keys' => [0 => 'zero', 1 => 'one', 'name' => 'D'],
			'at only' => ['@self.id' => 'u-1'],
		];
	}

	/**
	 * Users: [label, admin?] or null.
	 *
	 * @return array<string, array>
	 */
	public static function scenarios(): array {
		return [
			'admin' => ['admin'],
			'user' => ['user'],
			'anonymous' => ['anonymous'],
			'no admin group' => ['nogroup'],
		];
	}

	/**
	 * @dataProvider scenarios
	 */
	public function testCsvRowGolden(string $who): void {
		$groups = $this->createMock(IGroupManager::class);
		$adminGroup = $this->createMock(IGroup::class);
		$adminGroup->method('inGroup')->willReturnCallback(fn (IUser $u) => $u->getUID() === 'root');
		$groups->method('get')->willReturn($who === 'nogroup' ? null : $adminGroup);
		$codec = $this->createMock(TranslationCsvCodec::class);
		$codec->method('unflattenFromCsv')->willReturnCallback(static fn (array $row) => $row);
		$service = new ImportService(
			$this->createMock(SchemaMapper::class),
			$this->createMock(ObjectService::class),
			$this->createMock(LoggerInterface::class),
			$groups,
			$codec,
			$this->createMock(AuditTrailMapper::class),
			new MappingEngine($this->passThroughL10n()),
			$this->createMock(ValidateObject::class),
			$this->createMock(ContainerInterface::class)
		);

		$user = null;
		if ($who !== 'anonymous') {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($who === 'user' ? 'ann' : 'root');
		}

		$register = new Register();
		$this->setId($register, 1);
		$schema = new Schema();
		$this->setId($schema, 2);
		$schema->setProperties(
			[
				'name' => ['type' => 'string'],
				'age' => ['type' => 'integer'],
				'score' => ['type' => 'number'],
				'active' => ['type' => 'boolean'],
				'tags' => ['type' => 'array'],
				'born' => ['type' => 'string', 'format' => 'date'],
				'obj' => ['type' => 'object'],
			]
		);

		$actual = [];
		foreach (self::rows() as $label => $row) {
			try {
				$actual[$label] = $service->transformCsvRowToObject($row, $register, $schema, $user);
			} catch (Throwable $e) {
				$actual[$label] = ['error' => [get_class($e), $e->getMessage()]];
			}
		}

		$actual = json_decode(json_encode($actual, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION), true);
		$file = __DIR__ . '/fixtures/import-csv-row-golden.json';
		$name = $this->dataName();
		$all = is_file($file) === true ? json_decode(file_get_contents($file), true) : [];
		if (getenv('UPDATE_GOLDEN') === '1') {
			$all[$name] = $actual;
			ksort($all);
			file_put_contents($file, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION) . "\n");
		}

		$this->assertSame($all[$name], $actual);
	}

	/**
	 * Set an entity's id.
	 *
	 * @param object $entity The entity.
	 * @param int    $id     The id.
	 *
	 * @return void
	 */
	private function setId(object $entity, int $id): void {
		$prop = (new ReflectionClass($entity))->getProperty('id');
		$prop->setAccessible(true);
		$prop->setValue($entity, $id);
	}

	/**
	 * A sheet column with a numeric header is an ordinary property.
	 *
	 * @return void
	 */
	public function testASheetColumnWithANumericHeaderIsAProperty(): void {
		$service = (new \ReflectionClass(ImportService::class))->newInstanceWithoutConstructor();
		$transform = new \ReflectionMethod(ImportService::class, 'transformExcelRowToObject');

		$row = $transform->invoke($service, ['2024' => 'x', 'name' => 'y'], null, null, null);

		$this->assertSame([2024 => 'x', 'name' => 'y'], $row);
	}

	/**
	 * An importer who is not an admin sets no `@self` value, and `_` and `@` columns never become properties.
	 *
	 * @return void
	 */
	public function testASheetRowKeepsSystemColumnsOutOfTheProperties(): void {
		$service = (new \ReflectionClass(ImportService::class))->newInstanceWithoutConstructor();
		$transform = new \ReflectionMethod(ImportService::class, 'transformExcelRowToObject');
		$row = ['_hidden' => 'a', '@self.owner' => 'b', '@other' => 'c', 'name' => 'd', 'empty' => '', 'id' => 'e'];

		$this->assertSame(['name' => 'd', 'id' => 'e', '@self' => ['id' => 'e']], $transform->invoke($service, $row, null, null, null));
	}
}
