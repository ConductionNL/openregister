<?php

/**
 * Unit tests for content-aware existing-schema updates in ImportHandler (#2075).
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Configuration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Configuration;

use GuzzleHttp\Client;
use OCA\OpenRegister\Db\ConfigurationMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MappingMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Configuration\ImportHandler;
use OCA\OpenRegister\Service\Configuration\UploadHandler;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Locks schemaContentDiffers(): the version field is an optimisation, not the
 * source of truth. When an app adds a property (or changes an authorization
 * rule) to an existing schema WITHOUT bumping the schema `version`, the import
 * must still detect the change so the update is applied — otherwise the config
 * version advances while the schema stays stale (#2075), dropping columns and,
 * where an auth rule matches the new property, 500ing every read (#2082).
 */
class ImportHandlerSchemaContentDiffTest extends TestCase {

	private ImportHandler $handler;

	/**
	 * Build an ImportHandler with fully mocked collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->handler = new ImportHandler(
			schemaMapper:        $this->createMock(SchemaMapper::class),
			registerMapper:      $this->createMock(RegisterMapper::class),
			objectEntityMapper:  $this->createMock(MagicMapper::class),
			configurationMapper: $this->createMock(ConfigurationMapper::class),
			mappingMapper:       $this->createMock(MappingMapper::class),
			client:              $this->createMock(Client::class),
			appConfig:           $this->createMock(IAppConfig::class),
			logger:              $this->createMock(LoggerInterface::class),
			appDataPath:         '/tmp',
			uploadHandler:       $this->createMock(UploadHandler::class),
			objectService:       $this->createMock(ObjectService::class)
		);

	}//end setUp()

	/**
	 * Invoke the private content-diff method under test.
	 *
	 * @param array $data Incoming schema definition.
	 * @param Schema $existing Stored schema entity.
	 *
	 * @return bool
	 */
	private function differs(array $data, Schema $existing): bool {
		$reflection = new \ReflectionMethod(ImportHandler::class, 'schemaContentDiffers');
		$reflection->setAccessible(true);
		return (bool)$reflection->invoke($this->handler, $data, $existing);
	}//end differs()

	/**
	 * Build a Schema entity with the given content fields.
	 *
	 * @param array $properties Schema properties.
	 * @param array $required Required list.
	 * @param array $authorization Authorization block.
	 *
	 * @return Schema
	 */
	private function makeSchema(array $properties = [], array $required = [], array $authorization = []): Schema {
		$schema = new Schema();
		$schema->setProperties($properties);
		$schema->setRequired($required);
		$schema->setAuthorization($authorization);

		return $schema;
	}//end makeSchema()

	/**
	 * A property added by the incoming definition is detected as a difference.
	 *
	 * This is the regressed case: `beoordeeling` gained `status` with no
	 * version bump.
	 *
	 * @return void
	 */
	public function testAddedPropertyDiffers(): void {
		$existing = $this->makeSchema(properties: ['naam' => ['type' => 'string']]);
		$data = ['properties' => ['naam' => ['type' => 'string'], 'status' => ['type' => 'string']]];

		$this->assertTrue($this->differs(data: $data, existing: $existing));

	}//end testAddedPropertyDiffers()

	/**
	 * A changed authorization rule is detected even when properties match.
	 *
	 * @return void
	 */
	public function testChangedAuthorizationDiffers(): void {
		$existing = $this->makeSchema(
			properties: ['naam' => ['type' => 'string']],
			authorization: ['read' => ['public']]
		);
		$data = [
			'properties' => ['naam' => ['type' => 'string']],
			'authorization' => ['read' => [['group' => 'public', 'match' => ['status' => 'approved']]]],
		];

		$this->assertTrue($this->differs(data: $data, existing: $existing));

	}//end testChangedAuthorizationDiffers()

	/**
	 * A changed required list is detected.
	 *
	 * @return void
	 */
	public function testChangedRequiredDiffers(): void {
		$existing = $this->makeSchema(properties: ['naam' => []], required: ['naam']);
		$data = ['properties' => ['naam' => []], 'required' => ['naam', 'status']];

		$this->assertTrue($this->differs(data: $data, existing: $existing));

	}//end testChangedRequiredDiffers()

	/**
	 * Identical content — including reordered keys and reordered required — is
	 * NOT a difference, so the cheap skip still applies to genuine no-ops.
	 *
	 * @return void
	 */
	public function testIdenticalContentDoesNotDiffer(): void {
		$existing = $this->makeSchema(
			properties: ['naam' => ['type' => 'string'], 'status' => ['type' => 'string']],
			required: ['naam', 'status'],
			authorization: ['read' => ['public'], 'create' => ['admin']]
		);
		// Same content, keys and lists reordered.
		$data = [
			'properties' => ['status' => ['type' => 'string'], 'naam' => ['type' => 'string']],
			'required' => ['status', 'naam'],
			'authorization' => ['create' => ['admin'], 'read' => ['public']],
		];

		$this->assertFalse($this->differs(data: $data, existing: $existing));

	}//end testIdenticalContentDoesNotDiffer()

	/**
	 * Absent incoming fields equal empty stored fields (no false positive).
	 *
	 * @return void
	 */
	public function testAbsentEqualsEmpty(): void {
		$existing = $this->makeSchema(properties: ['naam' => []]);
		$data = ['properties' => ['naam' => []]];

		$this->assertFalse($this->differs(data: $data, existing: $existing));

	}//end testAbsentEqualsEmpty()

	/**
	 * The openbuild `exportJob` case, reduced.
	 *
	 * An incoming definition whose ONLY change is a top-level
	 * `x-openregister-lifecycle` block must read as different. Before this was
	 * detected, the version gate skipped the import (declared 0.1.0 against a
	 * stored 1.0.0) and the content check compared only properties/required/
	 * authorization, so the lifecycle never reached the running schema —
	 * `TransitionEngine` found no state machine, every export stayed at
	 * `status: queued` forever, and nothing logged it.
	 *
	 * @return void
	 */
	public function testDeclaredAnnotationMissingFromStoredDiffers(): void {
		$existing = $this->makeSchema(properties: ['naam' => ['type' => 'string']]);
		$existing->setConfiguration(['objectNameField' => 'naam']);

		$data = [
			'properties' => ['naam' => ['type' => 'string']],
			'x-openregister-lifecycle' => [
				'field' => 'status',
				'initial' => 'queued',
				'transitions' => ['start' => ['from' => ['queued'], 'to' => 'running']],
			],
		];

		$this->assertTrue($this->differs(data: $data, existing: $existing));

	}//end testDeclaredAnnotationMissingFromStoredDiffers()

	/**
	 * An annotation whose value changed is a difference too.
	 *
	 * @return void
	 */
	public function testChangedAnnotationValueDiffers(): void {
		$existing = $this->makeSchema();
		$existing->setConfiguration(
			[
				'x-openregister-lifecycle' => [
					'field' => 'status',
					'transitions' => ['start' => ['from' => ['queued'], 'to' => 'running']],
				],
			]
		);

		$data = [
			'x-openregister-lifecycle' => [
				'field' => 'status',
				'transitions' => ['start' => ['from' => ['queued'], 'to' => 'building']],
			],
		];

		$this->assertTrue($this->differs(data: $data, existing: $existing));

	}//end testChangedAnnotationValueDiffers()

	/**
	 * An identical annotation is NOT a difference, whichever level it is
	 * declared at.
	 *
	 * `Schema::hydrate()` folds sibling-of-properties `x-openregister-*` keys
	 * into `configuration`, so the app declares them at the top level and the
	 * stored schema keeps them nested. Both forms must compare equal or every
	 * settings load would rewrite the schema.
	 *
	 * @return void
	 */
	public function testIdenticalAnnotationDoesNotDiffer(): void {
		$lifecycle = [
			'field' => 'status',
			'initial' => 'queued',
			'transitions' => ['start' => ['from' => ['queued'], 'to' => 'running']],
		];

		$existing = $this->makeSchema();
		$existing->setConfiguration(
			['objectNameField' => 'naam', 'x-openregister-lifecycle' => $lifecycle]
		);

		$this->assertFalse(
			$this->differs(data: ['x-openregister-lifecycle' => $lifecycle], existing: $existing)
		);
		$this->assertFalse(
			$this->differs(
				data: ['configuration' => ['x-openregister-lifecycle' => $lifecycle]],
				existing: $existing
			)
		);

	}//end testIdenticalAnnotationDoesNotDiffer()

	/**
	 * A key OUTSIDE the declared vocabulary must NOT count as a difference.
	 *
	 * openbuild really declares `x-openregister-lifecycle-exception`, which is
	 * dropped on every save because it is not in the vocabulary. Comparing it
	 * would differ forever and re-import the schema on every settings load —
	 * turning a stale-schema fix into permanent write churn.
	 *
	 * @return void
	 */
	public function testUnknownAnnotationKeyDoesNotDiffer(): void {
		$existing = $this->makeSchema();
		$existing->setConfiguration(['objectNameField' => 'naam']);

		$data = ['x-openregister-lifecycle-exception' => ['whatever' => true]];

		$this->assertFalse($this->differs(data: $data, existing: $existing));

	}//end testUnknownAnnotationKeyDoesNotDiffer()

	/**
	 * A stored annotation the incoming no longer declares is NOT a difference.
	 *
	 * The stored configuration also carries keys OpenRegister maintains itself
	 * and annotations an operator may have added through the UI; treating
	 * their absence as a change would rewrite them away on the next import.
	 *
	 * @return void
	 */
	public function testStoredOnlyAnnotationDoesNotDiffer(): void {
		$existing = $this->makeSchema();
		$existing->setConfiguration(
			[
				'objectNameField' => 'naam',
				'x-openregister-mcp' => ['enabled' => true],
			]
		);

		$this->assertFalse($this->differs(data: ['properties' => []], existing: $existing));

	}//end testStoredOnlyAnnotationDoesNotDiffer()

}//end class
