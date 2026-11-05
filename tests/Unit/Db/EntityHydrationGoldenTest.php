<?php

/**
 * Golden test for getJsonFields() and hydrate() of the lib/Db entities.
 *
 * For each entity, records its JSON fields and, after hydrating it from data
 * that sets every field (JSON fields to an empty and a non-empty array) plus
 * an unknown key, the value every getter returns and the updated fields.
 * Re-record with UPDATE_GOLDEN=1.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Db
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db;

use DateTime;
use OCP\AppFramework\Db\Entity;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\Application
 * @covers \OCA\OpenRegister\Db\AuditTrail
 * @covers \OCA\OpenRegister\Db\BulkJob
 * @covers \OCA\OpenRegister\Db\BulkJobMember
 * @covers \OCA\OpenRegister\Db\Configuration
 * @covers \OCA\OpenRegister\Db\ConfigurationDeployment
 * @covers \OCA\OpenRegister\Db\ConfigurationDraft
 * @covers \OCA\OpenRegister\Db\ConfigurationDraftSet
 * @covers \OCA\OpenRegister\Db\ConfigurationValue
 * @covers \OCA\OpenRegister\Db\Endpoint
 * @covers \OCA\OpenRegister\Db\EndpointLog
 * @covers \OCA\OpenRegister\Db\ErasurePreview
 * @covers \OCA\OpenRegister\Db\FederatedShare
 * @covers \OCA\OpenRegister\Db\Mapping
 * @covers \OCA\OpenRegister\Db\ObjectEntity
 * @covers \OCA\OpenRegister\Db\ObjectRelation
 * @covers \OCA\OpenRegister\Db\Register
 * @covers \OCA\OpenRegister\Db\Schema
 * @covers \OCA\OpenRegister\Db\SchemaChangelog
 * @covers \OCA\OpenRegister\Db\SchemaRun
 * @covers \OCA\OpenRegister\Db\SchemaRunEntry
 * @covers \OCA\OpenRegister\Db\SearchTrail
 * @covers \OCA\OpenRegister\Db\Source
 * @covers \OCA\OpenRegister\Db\SubjectExport
 * @covers \OCA\OpenRegister\Db\SyncRecord
 * @covers \OCA\OpenRegister\Db\JsonFieldsTrait
 * @covers \OCA\OpenRegister\Db\HydrateEmptyJsonAsNullTrait
 * @covers \OCA\OpenRegister\Db\HydrateBySettersTrait
 */
class EntityHydrationGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/entity-hydration-golden.json';

	private const HYDRATED = [
		'Application', 'AuditTrail', 'BulkJob', 'FederatedShare', 'SchemaChangelog', 'SchemaRun',
		'SchemaRunEntry', 'SearchTrail', 'SyncRecord', 'BulkJobMember', 'ConfigurationDeployment',
		'ConfigurationDraft', 'ConfigurationDraftSet', 'ConfigurationValue', 'ErasurePreview',
		'ObjectRelation', 'SubjectExport', 'Endpoint', 'Mapping',
	];

	private const JSON_ONLY = ['Configuration', 'EndpointLog', 'ObjectEntity', 'Register', 'Schema', 'Source'];

	public function testEntitiesMatchGolden(): void {
		$actual = [];
		foreach (array_merge(self::HYDRATED, self::JSON_ONLY) as $short) {
			$class = 'OCA\\OpenRegister\\Db\\' . $short;
			$actual[$short]['jsonFields'] = method_exists($class, 'getJsonFields') === true ? (new $class())->getJsonFields() : 'none';
			if (in_array($short, self::HYDRATED, true) === false) {
				continue;
			}

			foreach (['empty json' => [], 'filled json' => ['k' => 'v']] as $variant => $json) {
				$actual[$short][$variant] = $this->hydrated(new $class(), $json);
			}
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$expected = json_decode((string) file_get_contents(self::FIXTURE), true);
		foreach ($expected as $name => $outcome) {
			$this->assertSame($outcome, $actual[$name] ?? null, $name);
		}

		$this->assertSame(array_keys($expected), array_keys($actual));
	}//end testEntitiesMatchGolden()

	private function hydrated(Entity $entity, array $json): array {
		$data = ['unknownKey' => 'ignored'];
		foreach ($entity->getFieldTypes() as $field => $type) {
			$data[$field] = match ($type) {
				'json'                      => $json,
				'integer', 'int'            => 7,
				'boolean', 'bool'           => true,
				'float'                     => 1.5,
				'datetime', 'date', 'time'  => new DateTime('2026-02-03 04:05:06'),
				default                     => 'text-' . $field,
			};
		}

		$out = [];
		try {
			$returned = $entity->hydrate($data);
			$out['returnsSelf'] = $returned === $entity;
		} catch (\Throwable $e) {
			$out['thrown'] = get_class($e);
		}

		foreach (array_keys($entity->getFieldTypes()) as $field) {
			try {
				$value = $entity->{'get' . ucfirst($field)}();
			} catch (\Throwable $e) {
				$value = 'thrown ' . get_class($e);
			}

			if ($value instanceof \DateTimeInterface) {
				$value = $value->format('c');
			}

			$out['values'][$field] = is_object($value) === true ? get_class($value) : $value;
		}

		$out['updated'] = array_keys($entity->getUpdatedFields());

		return $out;
	}//end hydrated()
}//end class
