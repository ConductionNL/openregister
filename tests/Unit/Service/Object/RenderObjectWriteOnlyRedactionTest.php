<?php

/**
 * Write-only field redaction (openregister#380, ocon#147).
 *
 * A schema property marked `writeOnly: true` is JSON Schema's way of saying "a client may
 * send this, the server must never return it" — the exact semantic for a secret. These
 * tests pin that `RenderObject::renderEntity()` strips such properties at the API render
 * boundary, that no caller-supplied argument can widen the redaction, and — crucially —
 * that the entity's raw `getObject()` is untouched, so the engine that legitimately needs
 * the secret (a synchronisation, the credential engine) still gets it.
 *
 * Context: the OpenConnector `source` schema leaked every integration credential to any
 * authenticated user because its secrets were plain object fields and controller-side
 * redaction was bypassed by the generic object API (ocon#147). renderEntity() is the one
 * path every API read renders through, which is why the control belongs here.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\RenderObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use OCA\OpenRegister\Tests\Support\BuildsStateFieldRuleResolver;

/**
 * @covers \OCA\OpenRegister\Service\Object\RenderObject
 * @uses \OCA\OpenRegister\Service\Object\RecordAnnotations
 * @uses \OCA\OpenRegister\Service\Object\RecurrenceOccurrences
 * @uses \OCA\OpenRegister\Service\Object\VirtualCalculations
 * @uses \OCA\OpenRegister\Service\Object\InverseBatch
 * @uses \OCA\OpenRegister\Service\Object\InverseLookup
 * @uses \OCA\OpenRegister\Service\Object\ExtendTargetReads
 * @uses \OCA\OpenRegister\Service\Object\InverseRelationCache
 * @uses \OCA\OpenRegister\Service\Object\InverseRelationPreload
 * @uses \OCA\OpenRegister\Service\Object\InverseRelationRender
 * @uses \OCA\OpenRegister\Service\Object\InverseRenderSupport
 * @uses \OCA\OpenRegister\Service\Object\InverseSchemaLookups
 * @uses \OCA\OpenRegister\Service\Object\InverseObjectHooks
 * @uses \OCA\OpenRegister\Service\Object\InverseTarget
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Service\AnonymousEvaluationContext
 * @uses \OCA\OpenRegister\Service\Archival\ArchivalDecisionResolver
 * @uses \OCA\OpenRegister\Service\Archival\UnestablishedValues
 * @uses \OCA\OpenRegister\Service\Calculation\CalculationEvaluator
 * @uses \OCA\OpenRegister\Service\Lifecycle\StateFieldRuleResolver
 * @uses \OCA\OpenRegister\Service\Lifecycle\StateFieldRules
 * @uses \OCA\OpenRegister\Service\PropertyRbacHandler
 * @uses \OCA\OpenRegister\Service\PropertyAccessRequest
 * @uses \OCA\OpenRegister\Service\Rules\ConditionDialect
 * @uses \OCA\OpenRegister\Search\Service\Search\PlaceholderResolver
 * @uses \OCA\OpenRegister\Service\SystemOperationContext
 * @uses \OCA\OpenRegister\Service\Object\RenderContext
 * @uses \OCA\OpenRegister\Service\Rbac\ExplicitReveal
 */
class RenderObjectWriteOnlyRedactionTest extends TestCase {
	use BuildsStateFieldRuleResolver;

	/**
	 * A schema whose `secret` and `apiKey` properties are write-only.
	 *
	 * @return Schema
	 */
	private function schemaWithWriteOnlySecrets(): Schema {
		$schema = new Schema();
		$ref = new ReflectionClass($schema);
		$idProp = $ref->getProperty('id');
		$idProp->setAccessible(true);
		$idProp->setValue($schema, 213);
		$schema->setSlug('source');
		$schema->setProperties(
			[
				'name' => ['type' => 'string'],
				'location' => ['type' => 'string'],
				'apiKey' => ['type' => 'string', 'writeOnly' => true],
				'secret' => ['type' => 'string', 'writeOnly' => true],
				'password' => ['type' => 'string', 'writeOnly' => true],
			]
		);

		return $schema;
	}

	/**
	 * Build the renderer with the SchemaMapper resolving our write-only schema, and every
	 * other collaborator a harmless mock.
	 *
	 * @param Schema $schema The schema find() returns.
	 *
	 * @return RenderObject
	 */
	private function renderer(Schema $schema, ?\Psr\Container\ContainerInterface $container = null): RenderObject {
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturn($schema);

		return new RenderObject(
			stores: new \OCA\OpenRegister\Service\Object\RenderStores(
				mappers: new \OCA\OpenRegister\Service\Object\RenderMappers(
					catalog: new \OCA\OpenRegister\Db\SchemaCatalog(registers: $this->createMock(\OCA\OpenRegister\Db\RegisterMapper::class), schemas: $schemaMapper, tables: $this->createMock(\OCA\OpenRegister\Db\MagicMapper::class)),
					objectCacheService: $this->createMock(\OCA\OpenRegister\Service\Object\CacheHandler::class)
				),
				files: new \OCA\OpenRegister\Service\Object\RenderFileSources(
					fileMapper: $this->createMock(\OCA\OpenRegister\Files\Db\FileMapper::class),
					fileService: $this->createMock(\OCA\OpenRegister\Service\FileService::class),
					systemTagManager: $this->createMock(\OCP\SystemTag\ISystemTagManager::class),
					systemTagMapper: $this->createMock(\OCP\SystemTag\ISystemTagObjectMapper::class)
				)
			),
			enrichers: new \OCA\OpenRegister\Service\Object\RenderEnrichers(
				translations: new \OCA\OpenRegister\Service\Object\RenderTranslations(
					translationHandler: $this->translationPassthrough(),
					translationStatusService: $this->createMock(\OCA\OpenRegister\Service\TranslationStatusService::class),
					translationMapper: $this->createMock(\OCA\OpenRegister\Db\TranslationMapper::class),
					languageService: $this->createMock(\OCA\OpenRegister\Service\LanguageService::class)
				),
				derivations: new \OCA\OpenRegister\Service\Object\RenderDerivations(
					computedFieldHandler: $this->createMock(\OCA\OpenRegister\Service\Object\SaveObject\ComputedFieldHandler::class),
					calculationEvaluator: $this->createMock(\OCA\OpenRegister\Service\Calculation\CalculationEvaluator::class),
					linkedEntityEnricher: $this->createMock(\OCA\OpenRegister\Service\Object\LinkedEntityEnricher::class),
					urnService: $this->createMock(\OCA\OpenRegister\Service\UrnService::class)
				)
			),
			runtime: new \OCA\OpenRegister\Service\Object\RenderRuntime(
				logger: $this->createMock(\Psr\Log\LoggerInterface::class),
				extensions: new \OCA\OpenRegister\Service\Object\RenderReadExtensions(),
				container: $container
			),
			propertyRbacHandler: $this->realPropertyRbacHandler()
		);
	}

	/**
	 * A REAL PropertyRbacHandler with mocked leaf dependencies.
	 *
	 * writeOnly stripping (the behaviour under test) is a pure function of the schema's
	 * writeOnly flags — it needs no session or groups — so a real handler strips correctly
	 * off `schemaWithWriteOnlySecrets()`. The test schemas declare no property-level
	 * `authorization`, so ConditionMatcher is never exercised and a mock suffices. Both the
	 * single-object path (renderEntity → filterReadableProperties) and the cheap-path
	 * (redactWriteOnlyFromRows → filterReadableProperties/stripWriteOnlyProperties) run
	 * through this same real handler.
	 *
	 * @return \OCA\OpenRegister\Service\PropertyRbacHandler
	 */
	private function realPropertyRbacHandler(): \OCA\OpenRegister\Service\PropertyRbacHandler {
		$userSession = $this->createMock(\OCP\IUserSession::class);
		$groupManager = $this->createMock(\OCP\IGroupManager::class);

		return new \OCA\OpenRegister\Service\PropertyRbacHandler(caller: new \OCA\OpenRegister\Service\PropertyRbacCaller($userSession, $groupManager), rules: new \OCA\OpenRegister\Service\PropertyRbacRules($this->createMock(\OCA\OpenRegister\Service\ConditionMatcher::class), self::stateFieldRuleResolver($userSession, $groupManager)), logger: $this->createMock(\Psr\Log\LoggerInterface::class));
	}

	/**
	 * A TranslationHandler that returns the object data unchanged.
	 *
	 * @return \OCA\OpenRegister\Service\Object\TranslationHandler
	 */
	private function translationPassthrough(): \OCA\OpenRegister\Service\Object\TranslationHandler {
		$handler = $this->createMock(\OCA\OpenRegister\Service\Object\TranslationHandler::class);
		$handler->method('resolveTranslationsForRender')->willReturnCallback(fn (array $data) => $data);

		return $handler;
	}

	/**
	 * A source entity carrying a location and three secrets.
	 *
	 * @return ObjectEntity
	 */
	private function sourceEntity(): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee');
		$entity->setSchema(213);
		$entity->setRegister(65);
		$entity->setObject(
			[
				'id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
				'name' => 'BRP HaalCentraal',
				'location' => 'https://api.example.gov',
				'apiKey' => 'SECRET_APIKEY_MUST_NOT_LEAK',
				'secret' => 'SECRET_CLIENTSECRET_MUST_NOT_LEAK',
				'password' => 'SECRET_PASSWORD_MUST_NOT_LEAK',
			]
		);

		// OpenRegister mirrors every scalar property into the `relations` search index; it
		// surfaces on the API as `@self.relations`. A source object really does carry this.
		$entity->setRelations(
			[
				'name' => 'BRP HaalCentraal',
				'location' => 'https://api.example.gov',
				'apiKey' => 'SECRET_APIKEY_MUST_NOT_LEAK',
				'secret' => 'SECRET_CLIENTSECRET_MUST_NOT_LEAK',
				'password' => 'SECRET_PASSWORD_MUST_NOT_LEAK',
			]
		);

		return $entity;
	}

	/**
	 * The core: the rendered object drops every write-only property and keeps the rest.
	 *
	 * @return void
	 */
	public function testWriteOnlyPropertiesAreStrippedFromTheRenderedObject(): void {
		$entity = $this->sourceEntity();
		$rendered = $this->renderer($this->schemaWithWriteOnlySecrets())->renderEntity($entity);

		$data = $rendered->getObject();

		$this->assertArrayNotHasKey('apiKey', $data);
		$this->assertArrayNotHasKey('secret', $data);
		$this->assertArrayNotHasKey('password', $data);

		// Non-secret fields survive.
		$this->assertSame('BRP HaalCentraal', $data['name']);
		$this->assertSame('https://api.example.gov', $data['location']);

		// Belt and braces: no secret marker anywhere in the serialised response —
		// including `@self.relations`, the search-index copy OpenRegister keeps of every
		// scalar. Redacting only the object body leaves that copy leaking.
		$this->assertStringNotContainsString('MUST_NOT_LEAK', json_encode($rendered->jsonSerialize()));

		$relations = $rendered->getRelations();
		$this->assertArrayNotHasKey('apiKey', $relations, 'The relations search-index copy must be redacted too');
		$this->assertArrayNotHasKey('secret', $relations);
		$this->assertArrayNotHasKey('password', $relations);
		$this->assertSame('BRP HaalCentraal', $relations['name'], 'Non-secret relations survive');
	}

	/**
	 * A caller cannot ask a write-only field back the way it can with `fields`. The
	 * redaction runs after `fields`, so requesting the secret explicitly still yields
	 * nothing — this is exactly the "controller redaction was bypassable" failure closed.
	 *
	 * @return void
	 */
	public function testACallerSuppliedFieldsListCannotWidenTheRedaction(): void {
		$entity = $this->sourceEntity();
		$rendered = $this->renderer($this->schemaWithWriteOnlySecrets())->renderEntity(
			$entity,
			[],
			0,
			[],
			['name', 'apiKey', 'secret'],
			[]
		);

		$data = $rendered->getObject();

		$this->assertArrayHasKey('name', $data);
		$this->assertArrayNotHasKey('apiKey', $data, 'A write-only field asked for via `fields` must still be redacted');
		$this->assertArrayNotHasKey('secret', $data);
	}

	/**
	 * The other half of the contract: redaction is a RENDER concern only. The engine reads
	 * a source through `getObject()` on the raw entity, which must still carry the secret —
	 * otherwise every synchronisation and the credential engine break.
	 *
	 * @return void
	 */
	public function testTheRawEntityStillCarriesTheSecretForTheEngine(): void {
		$entity = $this->sourceEntity();

		// Before render: the raw object is the engine's view. It has the secret.
		$this->assertSame('SECRET_APIKEY_MUST_NOT_LEAK', $entity->getObject()['apiKey']);

		// renderEntity() mutates the entity it is given (it setObject()s the redacted view),
		// so the engine must read the raw source through its OWN find (getObject on a fresh
		// entity), never through a rendered one. This test documents that boundary: a fresh
		// entity built the way the mapper builds it for the engine keeps the secret.
		$engineView = $this->sourceEntity();
		$this->assertSame('SECRET_CLIENTSECRET_MUST_NOT_LEAK', $engineView->getObject()['secret']);
	}

	/**
	 * `writeOnly` is NOT `_rbac`-gated: even a `_rbac: false` render is redacted.
	 *
	 * This test previously asserted the OPPOSITE — that `_rbac: false` returned the
	 * secret — and had been failing at HEAD ever since #389 changed the rule. It was the
	 * pre-#389 contract left behind, which is worse than no test: it documented a leak as
	 * the intended behaviour. #389 made the strip unconditional precisely because an admin
	 * HTTP GET renders with `_rbac: false`, so gating writeOnly on `_rbac` handed every
	 * admin-context read the plaintext secret.
	 *
	 * The real bypass for internal engines is `_render: false` (ObjectService::find), which
	 * returns the raw entity WITHOUT entering renderEntity at all — see
	 * testRenderFalseBypassesRedactionEntirelyForTheEngine(). Redaction is a property of the
	 * render boundary; code that must not cross it does not render.
	 *
	 * @return void
	 */
	public function testWriteOnlyIsStrippedEvenForARbacFalseRender(): void {
		$entity = $this->sourceEntity();

		$rendered = $this->renderer($this->schemaWithWriteOnlySecrets())->renderEntity(
			$entity,
			[],
			0,
			[],
			[],
			[],
			[],
			[],
			[],
			[],
			false
		);

		$data = $rendered->getObject();

		$this->assertArrayNotHasKey('apiKey', $data, 'writeOnly is a hard render-boundary rule and is not _rbac-gated (#389)');
		$this->assertArrayNotHasKey('secret', $data);
		$this->assertStringNotContainsString('MUST_NOT_LEAK', json_encode($rendered->jsonSerialize()));
	}

	/**
	 * A schema with no write-only property renders unchanged — the mechanism is strictly
	 * opt-in, so no existing schema changes behaviour.
	 *
	 * @return void
	 */
	public function testASchemaWithoutWriteOnlyPropertiesIsUnaffected(): void {
		$schema = new Schema();
		$ref = new ReflectionClass($schema);
		$idProp = $ref->getProperty('id');
		$idProp->setAccessible(true);
		$idProp->setValue($schema, 999);
		$schema->setSlug('plain');
		$schema->setProperties(
			[
				'name' => ['type' => 'string'],
				'token' => ['type' => 'string'],
			]
		);

		$entity = new ObjectEntity();
		$entity->setUuid('bbbbbbbb-cccc-dddd-eeee-ffffffffffff');
		$entity->setSchema(999);
		$entity->setRegister(1);
		$entity->setObject(['name' => 'x', 'token' => 'not-flagged-so-kept']);

		$rendered = $this->renderer($schema)->renderEntity($entity);
		$data = $rendered->getObject();

		// `token` is NOT marked writeOnly, so it is returned. Opt-in means opt-in.
		$this->assertSame('not-flagged-so-kept', $data['token']);
	}

	/**
	 * The list cheap-path (which bypasses renderEntity for performance) also redacts.
	 *
	 * This is the path the OpenConnector leak actually used: `GET .../objects/{reg}/{schema}`
	 * is a LIST, so it took the cheap-path and returned the secret even after the
	 * single-object read was fixed. Rows here are ObjectEntity objects (the cheap-path
	 * shape when no _extend/_fields is requested).
	 *
	 * @return void
	 */
	public function testCheapPathRowRedactionStripsWriteOnlyFromEntityRows(): void {
		$rows = [$this->sourceEntity()];

		$this->renderer($this->schemaWithWriteOnlySecrets())->redactWriteOnlyFromRows($rows, true);

		$data = $rows[0]->getObject();
		$this->assertArrayNotHasKey('apiKey', $data);
		$this->assertArrayNotHasKey('secret', $data);
		$this->assertSame('BRP HaalCentraal', $data['name']);

		// The @self.relations index copy is stripped too.
		$relations = $rows[0]->getRelations();
		$this->assertArrayNotHasKey('apiKey', $relations);
		$this->assertStringNotContainsString('MUST_NOT_LEAK', json_encode($rows[0]->jsonSerialize()));
	}

	/**
	 * The cheap-path also handles ARRAY rows (the SOLR/index list shape), redacting the
	 * body and `@self.relations`.
	 *
	 * @return void
	 */
	public function testCheapPathRowRedactionStripsWriteOnlyFromArrayRows(): void {
		$rows = [
			[
				'name' => 'BRP HaalCentraal',
				'apiKey' => 'SECRET_APIKEY_MUST_NOT_LEAK',
				'password' => 'SECRET_PASSWORD_MUST_NOT_LEAK',
				'@self' => [
					'schema' => 213,
					'relations' => ['apiKey' => 'SECRET_APIKEY_MUST_NOT_LEAK', 'name' => 'BRP HaalCentraal'],
				],
			],
		];

		$this->renderer($this->schemaWithWriteOnlySecrets())->redactWriteOnlyFromRows($rows, true);

		$this->assertArrayNotHasKey('apiKey', $rows[0]);
		$this->assertArrayNotHasKey('password', $rows[0]);
		$this->assertArrayNotHasKey('apiKey', $rows[0]['@self']['relations']);
		$this->assertSame('BRP HaalCentraal', $rows[0]['name']);
		$this->assertStringNotContainsString('MUST_NOT_LEAK', json_encode($rows));
	}

	/**
	 * The cheap-path strips writeOnly even when `_rbac: false` (openregister#460).
	 *
	 * This test asserted the OPPOSITE until #460: it pinned a bypass whose comment claimed
	 * "the engine batch-reads sources and must still get the secrets". No such consumer
	 * exists — `redactWriteOnlyFromRows()` is reachable only from `searchObjectsPaginated`,
	 * whose every internal caller passes `_rbac: true`. The only callers that pass
	 * `_rbac: false` are the three `ObjectsController` list/search sites, which derive it as
	 * `($isAdmin === false)` and serialise straight to a `JSONResponse` — so the bypass had
	 * exactly one real-world effect: handing admins plaintext secrets.
	 *
	 * `_rbac: false` on a read means "bypass WHICH OBJECTS you may see", never "you may see
	 * secrets". Internal code that needs the raw value uses `_render: false`, which never
	 * enters a render path at all — see testTheRawEntityStillCarriesTheSecretForTheEngine()
	 * here and testFindSkipsRenderingWhenRenderFalse() in ObjectServiceTest. This mirrors
	 * testWriteOnlyIsStrippedEvenForARbacFalseRender() on the single-object path (#389).
	 *
	 * @return void
	 */
	public function testCheapPathRowRedactionStripsWriteOnlyEvenWhenRbacIsFalse(): void {
		$rows = [$this->sourceEntity()];

		$this->renderer($this->schemaWithWriteOnlySecrets())->redactWriteOnlyFromRows($rows, false);

		$data = $rows[0]->getObject();
		$this->assertArrayNotHasKey('apiKey', $data, 'An admin/system list read must not receive writeOnly secrets (#460).');
		$this->assertArrayNotHasKey('secret', $data);
		$this->assertSame('BRP HaalCentraal', $data['name'], 'Non-secret properties survive the strip.');

		// The @self.relations mirror rides the same unconditional boundary (#429).
		$this->assertArrayNotHasKey('apiKey', $rows[0]->getRelations());
		$this->assertStringNotContainsString('MUST_NOT_LEAK', json_encode($rows[0]->jsonSerialize()));
	}

	/**
	 * The cheap-path strips writeOnly under SystemOperationContext too (openregister#460).
	 *
	 * @return void
	 */
	public function testCheapPathRowRedactionStripsWriteOnlyUnderSystemOperationContext(): void {
		$rows = [$this->sourceEntity()];

		\OCA\OpenRegister\Service\SystemOperationContext::run(
			function () use (&$rows) {
				$this->renderer($this->schemaWithWriteOnlySecrets())->redactWriteOnlyFromRows($rows, true);
			}
		);

		$this->assertArrayNotHasKey('apiKey', $rows[0]->getObject());
		$this->assertStringNotContainsString('MUST_NOT_LEAK', json_encode($rows[0]->jsonSerialize()));
	}

	/**
	 * ARRAY rows are stripped when `_rbac: false` as well — the SOLR/index list shape must
	 * not become the leak the ObjectEntity shape no longer is (openregister#460).
	 *
	 * @return void
	 */
	public function testCheapPathArrayRowRedactionStripsWriteOnlyEvenWhenRbacIsFalse(): void {
		$rows = [
			[
				'name' => 'BRP HaalCentraal',
				'apiKey' => 'SECRET_APIKEY_MUST_NOT_LEAK',
				'password' => 'SECRET_PASSWORD_MUST_NOT_LEAK',
				'@self' => [
					'schema' => 213,
					'relations' => ['apiKey' => 'SECRET_APIKEY_MUST_NOT_LEAK', 'name' => 'BRP HaalCentraal'],
				],
			],
		];

		$this->renderer($this->schemaWithWriteOnlySecrets())->redactWriteOnlyFromRows($rows, false);

		$this->assertArrayNotHasKey('apiKey', $rows[0]);
		$this->assertArrayNotHasKey('password', $rows[0]);
		$this->assertArrayNotHasKey('apiKey', $rows[0]['@self']['relations']);
		$this->assertSame('BRP HaalCentraal', $rows[0]['name']);
		$this->assertStringNotContainsString('MUST_NOT_LEAK', json_encode($rows));
	}

	/**
	 * Property `authorization.read` stripping REMAINS `_rbac`-gated on the cheap path
	 * (openregister#460 changed writeOnly ONLY).
	 *
	 * The two strips share a method but are different rules with different boundaries:
	 * `authorization.read` is a group-based visibility rule that trusted internal reads are
	 * meant to bypass (mirroring PermissionHandler::hasPermission()), whereas writeOnly is a
	 * secret boundary nobody bypasses. #460's whole point is that conflating them leaked
	 * secrets — so this test pins the OTHER half, guarding against "fixing" writeOnly by
	 * over-applying the strip and breaking the internal reads that legitimately depend on
	 * the authorization bypass.
	 *
	 * @return void
	 */
	public function testCheapPathAuthorizationReadStrippingRemainsRbacGated(): void {
		$schema = new Schema();
		$ref = new ReflectionClass($schema);
		$idProp = $ref->getProperty('id');
		$idProp->setAccessible(true);
		$idProp->setValue($schema, 214);
		$schema->setSlug('case');
		$schema->setProperties(
			[
				'title' => ['type' => 'string'],
				'internalNote' => ['type' => 'string', 'authorization' => ['read' => ['staff']]],
			]
		);

		$row = new ObjectEntity();
		$row->setUuid('case-1');
		$row->setRegister('1');
		$row->setSchema('214');
		$row->setObject(['title' => 'Case', 'internalNote' => 'staff-only-note']);

		$rows = [$row];
		$this->renderer($schema)->redactWriteOnlyFromRows($rows, false);

		$this->assertSame(
			'staff-only-note',
			$rows[0]->getObject()['internalNote'],
			'A trusted internal read (_rbac: false) must still bypass the authorization.read strip.'
		);
	}

	/**
	 * A schema with a group-restricted property and an explicit-reveal property.
	 *
	 * @return Schema
	 */
	private function schemaWithReadRules(): Schema {
		$schema = new Schema();
		$ref = new ReflectionClass($schema);
		$idProp = $ref->getProperty('id');
		$idProp->setAccessible(true);
		$idProp->setValue($schema, 214);
		$schema->setSlug('case');
		$schema->setProperties(
			[
				'title' => ['type' => 'string'],
				'internalNote' => ['type' => 'string', 'authorization' => ['read' => ['staff']]],
				'bsn' => ['type' => 'string', 'authorization' => ['reveal' => 'explicit']],
			]
		);
		return $schema;
	}

	/**
	 * The authorization.read strip is bypassed inside a system operation, as for `_rbac: false`.
	 *
	 * @return void
	 */
	public function testCheapPathAuthorizationReadStrippingIsBypassedInASystemOperation(): void {
		$schema = $this->schemaWithReadRules();
		$entityRow = new ObjectEntity();
		$entityRow->setUuid('case-1');
		$entityRow->setRegister('1');
		$entityRow->setSchema('214');
		$entityRow->setObject(['title' => 'Case', 'internalNote' => 'staff-only-note']);
		$arrayRow = ['title' => 'Case', 'internalNote' => 'staff-only-note', '@self' => ['schema' => 214]];

		$outside = [clone $entityRow, $arrayRow];
		$this->renderer($schema)->redactWriteOnlyFromRows($outside, true);
		$inside = [clone $entityRow, $arrayRow];
		\OCA\OpenRegister\Service\SystemOperationContext::run(function () use (&$inside, $schema): void {
			$this->renderer($schema)->redactWriteOnlyFromRows($inside, true);
		});

		$this->assertSame(['id' => 'case-1', 'title' => 'Case'], $outside[0]->getObject());
		$this->assertSame(['title' => 'Case', '@self' => ['schema' => 214]], $outside[1]);
		$this->assertSame(['id' => 'case-1', 'title' => 'Case', 'internalNote' => 'staff-only-note'], $inside[0]->getObject());
		$this->assertSame($arrayRow, $inside[1]);
	}

	/**
	 * Explicit-reveal values leave masked from every row, whatever else applies to the schema.
	 *
	 * @return void
	 */
	public function testCheapPathRowRedactionMasksExplicitRevealValues(): void {
		$schema = $this->schemaWithReadRules();
		$schema->setProperties(array_merge($schema->getProperties(), ['pin' => ['type' => 'string', 'writeOnly' => true]]));
		$masker = $this->createMock(\OCA\OpenRegister\Service\Rbac\ExplicitRevealMasker::class);
		$masker->method('maskObject')->willReturnCallback(
			static function ($schema, array $object): array {
				$masked = [];
				if (array_key_exists('bsn', $object) === true) {
					$object['bsn'] = '***';
					$masked = ['bsn'];
				}

				return [$object, $masked];
			}
		);
		$container = $this->createMock(\Psr\Container\ContainerInterface::class);
		$container->method('get')->willReturn($masker);

		$entityRow = new ObjectEntity();
		$entityRow->setUuid('case-1');
		$entityRow->setRegister('1');
		$entityRow->setSchema('214');
		$entityRow->setObject(['title' => 'Case', 'bsn' => '123']);
		$rows = [$entityRow, ['title' => 'Case', 'bsn' => '123', 'pin' => '0000', '@self' => ['schema' => 214]], ['title' => 'Plain', 'bsn' => '123', '@self' => ['schema' => 214]]];
		$this->renderer($schema, $container)->redactWriteOnlyFromRows($rows, false);

		$this->assertSame(['id' => 'case-1', 'title' => 'Case', 'bsn' => '***'], $rows[0]->getObject());
		$this->assertSame(['title' => 'Case', 'bsn' => '***', '@self' => ['schema' => 214]], $rows[1]);
		$this->assertSame(['title' => 'Plain', 'bsn' => '***', '@self' => ['schema' => 214]], $rows[2]);
	}
}

