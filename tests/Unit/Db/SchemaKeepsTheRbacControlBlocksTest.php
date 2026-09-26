<?php

/**
 * The two RBAC declarations of round 2 survive a schema save.
 *
 * Both `rbac-inherits-to-children` and `rbac-department-role-matrix` shipped
 * with green unit suites and were unreachable over HTTP, each for its own
 * half of the same mistake: the layer a schema is SAVED through had not been
 * told the new key exists.
 *
 * - `x-openregister-hierarchy` was missing from `Schema::ANNOTATION_VOCABULARY`,
 *   so `setConfiguration()` dropped it. The save-time validator then returned
 *   early on a key that could never be there, and the expander found no edge
 *   to descend: a grant on a root stopped at the root.
 * - `matrix` was missing from the reserved-key set in
 *   `Schema::validateAuthorizationRules()`, so it was read as a CRUD verb and
 *   the save was REFUSED outright.
 *
 * Every existing test of both features hands the service its array directly
 * and never crosses `Schema`, which is exactly why neither could fail. These
 * tests cross it.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/rbac-inherits-to-children/specs/rbac-scopes/spec.md
 * @spec openspec/changes/rbac-department-role-matrix/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use Exception;
use InvalidArgumentException;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Rbac\DepartmentMatrixCompiler;
use OCA\OpenRegister\Service\Rbac\HierarchyGrantExpander;
use OCA\OpenRegister\Service\Rbac\PermissionCatalogue;
use OCA\OpenRegister\Service\Schemas\PropertyValidatorHandler;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Save-time survival of the hierarchy annotation and the department matrix.
 */
class SchemaKeepsTheRbacControlBlocksTest extends TestCase {

	private SchemaMapper $mapper;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->mapper = new SchemaMapper(
			$this->createMock(IDBConnection::class),
			$this->createMock(IEventDispatcher::class),
			$this->createMock(PropertyValidatorHandler::class),
			$this->createMock(OrganisationMapper::class),
			$this->createMock(IUserSession::class),
			$this->createMock(IGroupManager::class),
			$this->createMock(IAppConfig::class),
			$this->createMock(LoggerInterface::class)
		);
	}//end setUp()

	/**
	 * A zaak schema whose `hoofdzaak` property points back at itself.
	 *
	 * @param array<string, mixed> $hierarchy The annotation to declare.
	 *
	 * @return Schema
	 */
	private function zaakWith(array $hierarchy): Schema {
		$schema = new Schema();
		$schema->setSlug('zaak');
		$schema->setProperties(
			[
				'onderwerp' => ['type' => 'string'],
				'hoofdzaak' => ['type' => 'object', '$ref' => 'zaak'],
			]
		);
		$schema->setConfiguration([HierarchyGrantExpander::ANNOTATION => $hierarchy]);

		return $schema;
	}//end zaakWith()

	/**
	 * Drive the mapper's private save-time hierarchy validator.
	 *
	 * @param Schema $schema The schema being saved.
	 *
	 * @return void
	 */
	private function validateHierarchy(Schema $schema): void {
		$method = new ReflectionMethod(SchemaMapper::class, 'validateHierarchyAnnotation');
		$method->invoke($this->mapper, $schema);
	}//end validateHierarchy()

	/**
	 * The annotation is still on the schema after `setConfiguration()`.
	 *
	 * Asserted on the value, not on a key count: the key surviving with its
	 * block emptied would descend nothing just as thoroughly.
	 *
	 * @return void
	 */
	public function testTheHierarchyAnnotationSurvivesTheSave(): void {
		$schema = $this->zaakWith(['parent' => 'hoofdzaak', 'inheritedVerbs' => ['read']]);

		$configuration = ($schema->getConfiguration() ?? []);

		$this->assertSame(
			['parent' => 'hoofdzaak', 'inheritedVerbs' => ['read']],
			($configuration[HierarchyGrantExpander::ANNOTATION] ?? null),
			'setConfiguration() dropped x-openregister-hierarchy, so no grant can ever descend'
		);
		$this->assertSame(
			[],
			$schema->consumeDroppedAnnotationKeys(),
			'the annotation was recorded as an unknown key'
		);
	}//end testTheHierarchyAnnotationSurvivesTheSave()

	/**
	 * The save-time validator actually SEES the annotation.
	 *
	 * This is the caller-side assertion for the same defect, and it is the one
	 * that matters: a dropped annotation makes `validateHierarchyAnnotation()`
	 * return early, so a hierarchy pointing at a property the schema does not
	 * declare saved with a 201 — which is exactly what the e2e measured.
	 *
	 * @return void
	 */
	public function testAHierarchyOnAPropertyTheSchemaDoesNotDeclareIsRefusedAtSave(): void {
		$this->expectException(Exception::class);
		$this->expectExceptionMessageMatches('/not a property of this schema/');

		$this->validateHierarchy($this->zaakWith(['parent' => 'bovenliggend']));
	}//end testAHierarchyOnAPropertyTheSchemaDoesNotDeclareIsRefusedAtSave()

	/**
	 * A hierarchy naming a reference to ANOTHER schema is refused at save.
	 *
	 * The refusal the annotation exists for: a hierarchy over `behandelaar`
	 * would hand everyone who may read one case every case filed to the same
	 * person, and from that moment on it looks exactly like working
	 * inheritance.
	 *
	 * @return void
	 */
	public function testAHierarchyPointingAtAnotherSchemaIsRefusedAtSave(): void {
		$schema = new Schema();
		$schema->setSlug('zaak');
		$schema->setProperties(
			[
				'behandelaar' => ['type' => 'object', '$ref' => 'medewerker'],
			]
		);
		$schema->setConfiguration(
			[HierarchyGrantExpander::ANNOTATION => ['parent' => 'behandelaar']]
		);

		$this->expectException(Exception::class);

		$this->validateHierarchy($schema);
	}//end testAHierarchyPointingAtAnotherSchemaIsRefusedAtSave()

	/**
	 * A well-formed hierarchy saves.
	 *
	 * The control for the two refusals above: without it they would both pass
	 * on a validator that refuses everything.
	 *
	 * @return void
	 */
	public function testAWellFormedHierarchySaves(): void {
		$this->validateHierarchy($this->zaakWith(['parent' => 'hoofdzaak', 'maxDepth' => 3]));

		$this->addToAssertionCount(1);
	}//end testAWellFormedHierarchySaves()

	/**
	 * A self-reference the IMPORT rewrote to the schema's id still saves.
	 *
	 * `Configuration\ImportHandler` replaces every `$ref` with the resolved
	 * schema id, so dossiq's `case` schema ships `"$ref": "case"` and reaches
	 * this validator as `"$ref": "169"`. Comparing that to the slug refused
	 * the whole schema at import — measured on a live instance, in the log as
	 * `[ImportHandler] Failed to import schema: x-openregister-hierarchy: The
	 * parent property "parentCase" references "169" rather than this schema`.
	 *
	 * @return void
	 */
	public function testASelfReferenceSpeltAsTheSchemaIdIsAccepted(): void {
		$schema = new Schema();
		$schema->setId(169);
		$schema->setSlug('case');
		$schema->setTitle('Case');
		$schema->setProperties(
			[
				'parentCase' => ['type' => 'string', 'format' => 'uuid', '$ref' => '169'],
			]
		);
		$schema->setConfiguration(
			[
				HierarchyGrantExpander::ANNOTATION => [
					'parent' => 'parentCase',
					'parentField' => 'parentCase',
					'maxDepth' => 10,
					'inheritedVerbs' => ['read'],
				],
			]
		);

		$this->validateHierarchy($schema);

		$this->addToAssertionCount(1);
	}//end testASelfReferenceSpeltAsTheSchemaIdIsAccepted()

	/**
	 * A reference to ANOTHER schema's id is still refused.
	 *
	 * The control for the test above: accepting the id spelling must not turn
	 * the check into one that accepts any number.
	 *
	 * @return void
	 */
	public function testAReferenceToADifferentSchemaIdIsStillRefused(): void {
		$schema = new Schema();
		$schema->setId(169);
		$schema->setSlug('case');
		$schema->setProperties(
			[
				'parentCase' => ['type' => 'string', '$ref' => '170'],
			]
		);
		$schema->setConfiguration(
			[HierarchyGrantExpander::ANNOTATION => ['parent' => 'parentCase']]
		);

		$this->expectException(Exception::class);
		$this->expectExceptionMessageMatches('/references "170" rather than this schema/');

		$this->validateHierarchy($schema);
	}//end testAReferenceToADifferentSchemaIdIsStillRefused()

	/**
	 * A block declaring a department matrix is accepted by the save.
	 *
	 * @return void
	 */
	public function testADepartmentMatrixIsAcceptedByTheAuthorizationValidator(): void {
		$schema = new Schema();
		$schema->setAuthorization(
			[
				'read' => ['behandelaars'],
				DepartmentMatrixCompiler::KEY => [
					'read' => ['behandelaars' => 'afdeling'],
				],
			]
		);

		$this->assertTrue(
			$schema->validateAuthorization(),
			'a schema declaring a department matrix could not be saved at all'
		);
	}//end testADepartmentMatrixIsAcceptedByTheAuthorizationValidator()

	/**
	 * EVERY key the catalogue calls a control is accepted as a control.
	 *
	 * The durable half of the fix. `matrix` was not the only one: `deny`,
	 * `public` and the token-grant marker were all refused as unknown verbs
	 * too, because this validator kept its own three-entry copy of a list that
	 * already existed. Reading the catalogue is what keeps the fifth control
	 * key from breaking the save again.
	 *
	 * @return void
	 */
	public function testEveryControlKeyTheCatalogueNamesCanBeSaved(): void {
		$shapes = [
			'roles' => ['behandelaar' => ['afdeling-a']],
			'public' => true,
			'inheritFromPublic' => true,
			'scope' => 'private',
			'deny' => ['read' => ['ingehuurd']],
			DepartmentMatrixCompiler::KEY => ['read' => ['behandelaars' => 'afdeling']],
		];

		foreach (PermissionCatalogue::CONTROL_KEYS as $key) {
			$schema = new Schema();
			$schema->setAuthorization(
				[
					'read' => ['behandelaars'],
					$key => ($shapes[$key] ?? ['something' => true]),
				]
			);

			$this->assertTrue(
				$schema->validateAuthorization(),
				"control key '{$key}' was read as a CRUD verb and refused the save"
			);
		}
	}//end testEveryControlKeyTheCatalogueNamesCanBeSaved()

	/**
	 * The action vocabulary is still CLOSED.
	 *
	 * The counterweight to the test above, and the reason the fix reads the
	 * catalogue instead of accepting anything that is not a verb: a typo must
	 * still refuse the schema rather than save as a permission that is never
	 * granted and never errors.
	 *
	 * @return void
	 */
	public function testATypoIsStillRefusedAsAnUnknownAction(): void {
		$schema = new Schema();
		$schema->setAuthorization(['raed' => ['behandelaars']]);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessageMatches("/Invalid authorization action 'raed'/");

		$schema->validateAuthorization();
	}//end testATypoIsStillRefusedAsAnUnknownAction()
}//end class
