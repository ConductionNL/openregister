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
 * @spec openspec/specs/rbac-scopes/spec.md
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
use OCA\OpenRegister\Service\Rbac\ExplicitReveal;
use OCA\OpenRegister\Service\Rbac\HierarchyGrantExpander;
use OCA\OpenRegister\Service\Rbac\PermissionCatalogue;
use OCA\OpenRegister\Service\Rbac\RevealCollector;
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
 * @uses \OCA\OpenRegister\Db\SchemaMapper\AdvisoryAnnotationChecks
 * @uses \OCA\OpenRegister\Db\SchemaMapper\SchemaAnnotation
 * @uses \OCA\OpenRegister\Db\SchemaMapper\AnnotationFaults
 * @uses \OCA\OpenRegister\Db\SchemaMapper\RecordAnnotationRefusals
 * @uses \OCA\OpenRegister\Db\SchemaMapper\ExchangeAnnotationRefusals
 * @uses \OCA\OpenRegister\Db\SchemaMapper\PropertyDeclarationRefusals
 * @uses \OCA\OpenRegister\Db\SchemaMapper\LifecycleAnnotationCheck
 * @uses \OCA\OpenRegister\Db\SchemaMapper\CalculationAnnotationCheck
 * @uses \OCA\OpenRegister\Db\SchemaMapper\RelationDeclarationCheck
 * @uses \OCA\OpenRegister\Db\SchemaMapper\DeclaredActionCheck
 * @uses \OCA\OpenRegister\Db\SchemaMapper\ReferenceNormaliser
 * @uses \OCA\OpenRegister\Db\SchemaMapper\SchemaDefaults
 * @uses \OCA\OpenRegister\Db\SchemaMapper\ConfigurationFields
 * @uses \OCA\OpenRegister\Db\SchemaMapper\FacetConfiguration
 * @uses \OCA\OpenRegister\Db\SchemaMapper\SchemaComposition
 * @uses \OCA\OpenRegister\Db\SchemaMapper\ParentReference
 * @uses \OCA\OpenRegister\Db\SchemaMapper\PropertyMerge
 * @uses \OCA\OpenRegister\Db\SchemaMapper\PropertyDelta
 * @uses \OCA\OpenRegister\Db\SchemaMapper\ExtendedByReads
 * @uses \OCA\OpenRegister\Db\SchemaMapper\AccessDeclarationChecks
 * @uses \OCA\OpenRegister\Db\SchemaMapper\SubjectDeclarationRefusals
 * @uses \OCA\OpenRegister\Db\SchemaMapper\AuthorizationBlockCheck
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
		$method = new ReflectionMethod(SchemaMapper::class, 'checkAccessDeclarations');
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
	 * A property that audits its reveals can be saved.
	 *
	 * `audit: true` on a property's block is the sensitive-field-reveal-audit
	 * flag RevealCollector reads. It was missing from the control keys, so it
	 * was read as a verb and every schema declaring it was refused with
	 * "Invalid authorization action 'audit'". Seen live 2026-09-29: learniq's
	 * LearnerProfile (personalNumber, the BSN) could not be imported at all.
	 *
	 * @return void
	 */
	public function testAPropertyThatAuditsItsRevealsCanBeSaved(): void {
		$this->assertContains(RevealCollector::AUDIT_KEY, PermissionCatalogue::CONTROL_KEYS);

		$schema = new Schema();
		$schema->setProperties(
			[
				'personalNumber' => [
					'type' => 'string',
					'authorization' => [
						'read' => ['administration-managers'],
						'update' => ['administration-managers'],
						RevealCollector::AUDIT_KEY => true,
					],
				],
			]
		);

		$this->assertTrue(
			$schema->validateAuthorization(),
			'a property declaring authorization.audit could not be saved at all'
		);
	}//end testAPropertyThatAuditsItsRevealsCanBeSaved()

	/**
	 * A property shown masked until it is revealed can be saved.
	 *
	 * `reveal: explicit` is the flag ExplicitReveal reads. Missing from the
	 * control keys, it was read as a verb and the schema was refused with
	 * "Invalid authorization action 'reveal'" (dossiq's brpPerson on import).
	 *
	 * @return void
	 */
	public function testAPropertyRevealedExplicitlyCanBeSaved(): void {
		$this->assertContains(ExplicitReveal::KEY, PermissionCatalogue::CONTROL_KEYS);

		$schema = new Schema();
		$schema->setProperties(
			[
				'bsn' => [
					'type' => 'string',
					'authorization' => [
						'read' => ['brp-raadplegers'],
						ExplicitReveal::KEY => ExplicitReveal::EXPLICIT,
					],
				],
			]
		);

		$this->assertTrue($schema->validateAuthorization());
		$this->assertSame(
			ExplicitReveal::EXPLICIT,
			$schema->getPropertyAuthorization('bsn')[ExplicitReveal::KEY],
			'the reveal flag did not survive on the property'
		);
	}//end testAPropertyRevealedExplicitlyCanBeSaved()

	/**
	 * A reveal value other than `explicit` is refused, naming the key and the property.
	 *
	 * The runtime only masks on `explicit`, so a misspelt value would save as a
	 * property that is never masked.
	 *
	 * @return void
	 */
	public function testARevealValueOtherThanExplicitIsRefused(): void {
		$schema = new Schema();
		$schema->setProperties(
			[
				'bsn' => [
					'type' => 'string',
					'authorization' => [
						'read' => ['brp-raadplegers'],
						ExplicitReveal::KEY => 'explicitly',
					],
				],
			]
		);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage("Authorization key 'reveal' in property 'bsn' must be \"explicit\"");

		$schema->validateAuthorization();
	}//end testARevealValueOtherThanExplicitIsRefused()

	/**
	 * Reveal values that are not exactly `explicit` are refused.
	 *
	 * @return array<string, array{mixed}>
	 */
	public static function wrongRevealValues(): array {
		return [
			'true' => [true],
			'other case' => ['Explicit'],
		];
	}//end wrongRevealValues()

	/**
	 * A reveal value that only looks like `explicit` is refused.
	 *
	 * @param mixed $value The reveal value.
	 *
	 * @return void
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('wrongRevealValues')]
	public function testARevealValueThatIsNotExactlyExplicitIsRefused(mixed $value): void {
		$schema = new Schema();
		$schema->setProperties(
			[
				'bsn' => [
					'type' => 'string',
					'authorization' => ['read' => ['brp-raadplegers'], ExplicitReveal::KEY => $value],
				],
			]
		);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage("Authorization key 'reveal' in property 'bsn' must be \"explicit\"");

		$schema->validateAuthorization();
	}//end testARevealValueThatIsNotExactlyExplicitIsRefused()

	/**
	 * Only the declared action leaves the deny block; an undeclared one beside it is refused.
	 *
	 * @return void
	 */
	public function testAnUndeclaredActionInTheDenyIsStillRefused(): void {
		$schema = $this->caseDeclaring(authorization: [
			'read' => ['behandelaars'],
			'deny' => ['finish-request' => ['ingehuurd'], 'suspend-request' => ['ingehuurd']],
		]);

		$this->expectException(Exception::class);
		$this->expectExceptionMessageMatches('/names "suspend-request", which no app declares/');

		$this->validateDeny($schema);
	}//end testAnUndeclaredActionInTheDenyIsStillRefused()

	/**
	 * Declaring an action named `deny` does not take the deny block out of the check.
	 *
	 * @return void
	 */
	public function testDeclaringAnActionNamedDenyKeepsTheDenyBlockChecked(): void {
		$schema = new Schema();
		$schema->setSlug('case');
		$schema->setConfiguration(
			['x-openregister-action' => ['deny' => ['name' => 'Deny', 'description' => 'Refuse the request.']]]
		);
		$schema->setAuthorization(['read' => ['behandelaars'], 'deny' => ['raed' => ['ingehuurd']]]);

		$this->assertSame([], $schema->declaredActionNames(), 'a control key was accepted as a declared action');

		$this->expectException(Exception::class);
		$this->expectExceptionMessageMatches('/names "raed", which no app declares/');

		$this->validateDeny($schema);
	}//end testDeclaringAnActionNamedDenyKeepsTheDenyBlockChecked()

	/**
	 * A canonical verb declared again is not a declared action.
	 *
	 * @return void
	 */
	public function testACanonicalVerbIsNotADeclaredAction(): void {
		$schema = new Schema();
		$schema->setConfiguration(
			[
				'x-openregister-action' => [
					'read' => ['name' => 'Read', 'description' => 'Open the record.'],
					'finish-request' => ['name' => 'Finish', 'description' => 'Close the request.'],
				],
			]
		);

		$this->assertSame(['finish-request'], $schema->declaredActionNames());
	}//end testACanonicalVerbIsNotADeclaredAction()

	/**
	 * A schema may grant the actions it declares itself.
	 *
	 * The save-time catalogue check knew only the canonical verbs and the ones
	 * apps publish, so a schema granting its own `x-openregister-action` was
	 * refused with "which no app declares" (dossiq's case macros), and every
	 * later write of that block failed.
	 *
	 * @return void
	 */
	public function testASchemaMayGrantTheActionsItDeclares(): void {
		$schema = $this->caseDeclaring(authorization: [
			'read' => ['behandelaars'],
			'readGrants' => ['behandelaars'],
			'finish-request' => ['behandelaars'],
			'deny' => ['finish-request' => ['ingehuurd']],
		]);

		$this->validateDeny($schema);

		$this->assertSame(
			['behandelaars'],
			$schema->getAuthorization()['readGrants'],
			'the readGrants rule did not survive the save'
		);
	}//end testASchemaMayGrantTheActionsItDeclares()

	/**
	 * An action the schema does not declare is still refused by the catalogue check.
	 *
	 * The control for the test above: accepting declared actions must not turn
	 * the check into one that accepts any name.
	 *
	 * @return void
	 */
	public function testAnUndeclaredActionIsStillRefusedByTheCatalogueCheck(): void {
		$schema = $this->caseDeclaring(authorization: [
			'read' => ['behandelaars'],
			'finish-request' => ['behandelaars'],
			'suspend-request' => ['behandelaars'],
		]);

		$this->expectException(Exception::class);
		$this->expectExceptionMessageMatches('/names "suspend-request", which no app declares/');

		$this->validateDeny($schema);
	}//end testAnUndeclaredActionIsStillRefusedByTheCatalogueCheck()

	/**
	 * The authorization keys survive an import, an export and an update.
	 *
	 * Each step goes through the schema's own save checks, so a key that one
	 * of them refuses or drops fails here rather than on an instance.
	 *
	 * @return void
	 */
	public function testTheAuthorizationKeysSurviveImportExportAndUpdate(): void {
		$authorization = [
			'read' => [['group' => 'authenticated', 'match' => ['assignee' => '$userId']]],
			'readGrants' => [['group' => 'authenticated', 'match' => ['assignee' => '$userId']], 'background-service'],
			'finish-request' => ['behandelaars'],
		];
		$bsn = [
			'read' => ['brp-raadplegers'],
			ExplicitReveal::KEY => ExplicitReveal::EXPLICIT,
		];
		$definition = [
			'slug' => 'case',
			'title' => 'Case',
			'configuration' => [
				'x-openregister-action' => [
					'finish-request' => ['name' => 'Finish the request', 'description' => 'Close the request with a decision.'],
				],
			],
			'authorization' => $authorization,
			'properties' => [
				'assignee' => ['type' => 'string'],
				'bsn' => ['type' => 'string', 'authorization' => $bsn],
			],
		];

		$imported = (new Schema())->hydrate(object: $definition);
		$this->save($imported);

		$reimported = (new Schema())->hydrate(object: $imported->jsonSerialize());
		$this->save($reimported);

		$updated = $reimported->hydrate(object: ['title' => 'Case, renamed']);
		$this->save($updated);

		foreach (['import' => $imported, 'export and re-import' => $reimported, 'update' => $updated] as $step => $schema) {
			$this->assertSame($authorization, $schema->getAuthorization(), "the schema block changed on {$step}");
			$this->assertSame($bsn, $schema->getPropertyAuthorization('bsn'), "the bsn block changed on {$step}");
		}
	}//end testTheAuthorizationKeysSurviveImportExportAndUpdate()

	/**
	 * Run the mapper's save-time checks the way insert() and update() do.
	 *
	 * @param Schema $schema The schema being saved.
	 *
	 * @return void
	 */
	private function save(Schema $schema): void {
		$schema->validateAuthorization();
		$method = new ReflectionMethod(SchemaMapper::class, 'cleanObject');
		$method->invoke($this->mapper, $schema);
	}//end save()

	/**
	 * A case schema that declares `finish-request` and carries the given block.
	 *
	 * @param array<string, mixed> $authorization The authorization block.
	 *
	 * @return Schema
	 */
	private function caseDeclaring(array $authorization): Schema {
		$schema = new Schema();
		$schema->setSlug('case');
		$schema->setConfiguration(
			[
				'x-openregister-action' => [
					'finish-request' => ['name' => 'Finish the request', 'description' => 'Close the request with a decision.'],
				],
			]
		);
		$schema->setAuthorization($authorization);

		return $schema;
	}//end caseDeclaring()

	/**
	 * Drive the mapper's private save-time catalogue and deny check.
	 *
	 * @param Schema $schema The schema being saved.
	 *
	 * @return void
	 */
	private function validateDeny(Schema $schema): void {
		$method = new ReflectionMethod(SchemaMapper::class, 'checkAccessDeclarations');
		$method->invoke($this->mapper, $schema);
	}//end validateDeny()

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
			ExplicitReveal::KEY => ExplicitReveal::EXPLICIT,
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
	 * Every canonical verb the catalogue publishes is a valid action in a block.
	 *
	 * The permission matrix writes any of the nine canonical verbs into a
	 * schema's authorization. This validator accepted four of them, so an
	 * administrator who narrowed `export` on a schema made that schema fail
	 * every later import of its app: the fragment never named the verb, the
	 * stored block did, and the merged block is what gets validated. Measured
	 * 2026-09-27: ~280 schemas of 17 apps carried `export`; pipelinq's `lead`
	 * was the first one seen refused. Reading the catalogue is the fix, so the
	 * tenth verb added there tomorrow does not break the import again.
	 *
	 * @return void
	 */
	public function testEveryCanonicalVerbTheCatalogueNamesCanBeSaved(): void {
		// Positive control: the verb that broke the import is in the list this test walks.
		$this->assertArrayHasKey('export', PermissionCatalogue::CANONICAL);

		foreach (array_keys(PermissionCatalogue::CANONICAL) as $verb) {
			$schema = new Schema();
			$schema->setAuthorization(
				[
					'read' => ['behandelaars'],
					$verb => ['behandelaars'],
				]
			);

			$this->assertTrue(
				$schema->validateAuthorization(),
				"canonical verb '{$verb}' was refused as an unknown action"
			);
		}
	}//end testEveryCanonicalVerbTheCatalogueNamesCanBeSaved()

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
