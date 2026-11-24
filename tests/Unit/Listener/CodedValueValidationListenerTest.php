<?php

/**
 * CodedValueValidationListenerTest: a coded property takes its allowed values from its concept scheme at save time.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/modelling-stated-requirements/specs/skos-concept-registers/spec.md
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Listener\CodedValueValidationListener;
use OCA\OpenRegister\Service\Vocabulary\CodedPropertyDeclaration;
use OCA\OpenRegister\Service\Vocabulary\CodedPropertyDeclarationFactory;
use OCA\OpenRegister\Service\Vocabulary\CodedValueGuard;
use OCA\OpenRegister\Service\Vocabulary\ConceptHierarchy;
use OCA\OpenRegister\Service\Vocabulary\ConceptLifecycle;
use OCA\OpenRegister\Service\Vocabulary\ConceptRepository;
use OCA\OpenRegister\Service\Vocabulary\ConceptSchemeAccess;
use OCA\OpenRegister\Service\Vocabulary\ConceptShapeGuardInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * REQ-MSR-003 through the listener a write dispatches, with the real guard over a scheme
 * whose concepts are read at save time (the repository double stands in for the vocabulary register).
 *
 * @covers \OCA\OpenRegister\Listener\CodedValueValidationListener
 */
class CodedValueValidationListenerTest extends TestCase {

	private const SCHEME = 'https://identifier.overheid.nl/tooi/set/scw_woo_informatiecategorieen';

	/**
	 * The concepts of the scheme as the vocabulary register holds them now.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $concepts = [];

	private CodedValueValidationListener $listener;

	private Schema $schema;

	protected function setUp(): void {
		parent::setUp();
		$this->concepts = [
			'urn:woo:convenant' => ['uri' => 'urn:woo:convenant', 'prefLabel' => ['nl' => 'Convenant']],
			'urn:woo:jaarplan' => ['uri' => 'urn:woo:jaarplan', 'prefLabel' => ['nl' => 'Jaarplan']],
		];

		$repository = $this->createMock(ConceptRepository::class);
		$repository->method('conceptsOf')->willReturnCallback(fn (): array => $this->concepts);
		$repository->method('resolve')->willReturnCallback(fn (string $value): ?array => ($this->concepts[$value] ?? null));

		$lifecycle = new ConceptLifecycle();
		$guard = new CodedValueGuard(
			scheme: new ConceptSchemeAccess(concepts: $repository, hierarchy: new ConceptHierarchy(lifecycle: $lifecycle), lifecycle: $lifecycle),
			declarationFactory: new CodedPropertyDeclarationFactory()
		);

		$this->schema = new Schema();
		$this->schema->setId(7);
		$this->schema->setProperties(
			[
				'titel' => ['type' => 'string'],
				'categorie' => ['type' => 'string', CodedPropertyDeclaration::ANNOTATION => ['scheme' => self::SCHEME]],
			]
		);
		$schemas = $this->createMock(SchemaMapperInterface::class);
		$schemas->method('find')->willReturn($this->schema);

		$shape = $this->createMock(ConceptShapeGuardInterface::class);
		$shape->method('violations')->willReturn([]);

		// The guard fails open (the save proceeds, logged), so an accepted write
		// proves nothing unless the guard did not fail.
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('warning');

		$this->listener = new CodedValueValidationListener(
			guard: $guard,
			shapeGuard: $shape,
			schemas: $schemas,
			logger: $logger
		);
	}//end setUp()

	/**
	 * An object of the schema holding the given category.
	 *
	 * @param string $categorie The coded value.
	 *
	 * @return ObjectEntity The object.
	 */
	private function object(string $categorie): ObjectEntity {
		$object = new ObjectEntity();
		$object->setSchema('7');
		$object->setObject(['titel' => 'Besluit', 'categorie' => $categorie]);

		return $object;
	}//end object()

	public function testAValueFromTheSchemeIsAccepted(): void {
		$event = new ObjectCreatingEvent($this->object('urn:woo:convenant'));

		$this->listener->handle($event);

		$this->assertFalse($event->isPropagationStopped());
		$this->assertSame([], $event->getErrors());
	}//end testAValueFromTheSchemeIsAccepted()

	public function testAValueOutsideTheSchemeIsRefusedNamingPropertyAndScheme(): void {
		$event = new ObjectCreatingEvent($this->object('onbekend'));

		$this->listener->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$errors = $event->getErrors();
		$this->assertSame(CodedValueValidationListener::ERROR_CODE, $errors['code']);
		$this->assertArrayHasKey('categorie', $errors);
		$this->assertStringContainsString('"categorie"', $errors['categorie']);
		$this->assertStringContainsString(self::SCHEME, $errors['categorie']);
		$this->assertStringContainsString('onbekend', $errors['message']);
	}//end testAValueOutsideTheSchemeIsRefusedNamingPropertyAndScheme()

	public function testAnUpdateToAValueOutsideTheSchemeIsRefusedToo(): void {
		$event = new ObjectUpdatingEvent($this->object('onbekend'), $this->object('urn:woo:convenant'));

		$this->listener->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertArrayHasKey('categorie', $event->getErrors());
	}//end testAnUpdateToAValueOutsideTheSchemeIsRefusedToo()

	/**
	 * The allowed values are read at save time: a concept added after the schema was saved is
	 * allowed without a schema edit. The same value is refused before it is added, so the pass
	 * is about the scheme and not about the value.
	 */
	public function testAConceptAddedAfterTheSchemaWasSavedIsAccepted(): void {
		$before = new ObjectCreatingEvent($this->object('urn:woo:klachtoordeel'));
		$this->listener->handle($before);
		$this->assertTrue($before->isPropagationStopped());

		$this->concepts['urn:woo:klachtoordeel'] = ['uri' => 'urn:woo:klachtoordeel', 'prefLabel' => ['nl' => 'Klachtoordeel']];

		$after = new ObjectCreatingEvent($this->object('urn:woo:klachtoordeel'));
		$this->listener->handle($after);
		$this->assertFalse($after->isPropagationStopped());
		$this->assertSame([], $after->getErrors());
	}//end testAConceptAddedAfterTheSchemaWasSavedIsAccepted()

	public function testAValidateOnlyCallAgreesWithTheSave(): void {
		$this->assertSame([], $this->listener->check(['categorie' => 'urn:woo:jaarplan'], $this->schema, null));

		$violations = $this->listener->check(['categorie' => 'onbekend'], $this->schema, null);
		$this->assertCount(1, $violations);
		$this->assertSame('categorie', $violations[0]['property']);
		$this->assertSame('coded-value', $violations[0]['rule']);
	}//end testAValidateOnlyCallAgreesWithTheSave()
}//end class
