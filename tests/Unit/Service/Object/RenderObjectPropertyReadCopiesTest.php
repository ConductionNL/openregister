<?php

/**
 * A property read rule holds on every copy of the property, not only the body.
 *
 * Found live on the Rotterdam stack (lane oc-pub): stackiq properties ruled
 * `authorization.read: ["authenticated"]` were stripped from the object body
 * for an anonymous caller, but their values still came back through
 * `@self.relations` (the reference mirror) and `@self.description` (copied
 * from `longDescription` by `objectDescriptionField`). These tests render
 * through the real RenderObject and the real PropertyRbacHandler, as an
 * anonymous caller and, as the control, as a signed-in one.
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
 *
 * @spec openspec/specs/row-field-level-security/spec.md#requirement-a-property-read-rule-holds-on-every-route-that-returns-its-value
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\RenderObject;
use OCA\OpenRegister\Tests\Support\BuildsStateFieldRuleResolver;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class RenderObjectPropertyReadCopiesTest extends TestCase {
	use BuildsStateFieldRuleResolver;

	private const CONTACT = 'fac88a28-f252-4e8e-b9ee-ed986affede6';

	/** The session user: null is an anonymous caller. */
	private ?IUser $user = null;

	private function schema(): Schema {
		$schema = new Schema();
		$ref = new ReflectionClass($schema);
		$idProp = $ref->getProperty('id');
		$idProp->setAccessible(true);
		$idProp->setValue($schema, 119);
		$schema->setSlug('module');
		$schema->setProperties(
			[
				'name' => ['type' => 'string'],
				'contactPerson' => ['type' => 'string', 'format' => 'uuid', 'authorization' => ['read' => ['authenticated']]],
				'dpiaDocumentRef' => ['type' => 'string', 'authorization' => ['read' => ['authenticated']]],
				'longDescription' => ['type' => 'string', 'authorization' => ['read' => ['authenticated']]],
			]
		);
		$schema->setConfiguration(['objectDescriptionField' => 'longDescription', 'objectNameField' => 'name']);

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
	private function renderer(Schema $schema): RenderObject {
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturn($schema);

		return new RenderObject(
			$this->createMock(\OCA\OpenRegister\Db\FileMapper::class),
			$this->createMock(\OCA\OpenRegister\Db\MagicMapper::class),
			$this->createMock(\OCA\OpenRegister\Db\RegisterMapper::class),
			$schemaMapper,
			$this->createMock(\OCP\SystemTag\ISystemTagManager::class),
			$this->createMock(\OCP\SystemTag\ISystemTagObjectMapper::class),
			$this->createMock(\OCA\OpenRegister\Service\Object\CacheHandler::class),
			$this->createMock(\OCA\OpenRegister\Service\Object\CacheHandler::class),
			$this->realPropertyRbacHandler(),
			$this->createMock(\Psr\Log\LoggerInterface::class),
			$this->createMock(\OCA\OpenRegister\Service\FileService::class),
			$this->createMock(\OCA\OpenRegister\Service\Object\SaveObject\ComputedFieldHandler::class),
			$this->translationPassthrough(),
			$this->createMock(\OCA\OpenRegister\Service\Object\LinkedEntityEnricher::class),
			$this->createMock(\OCA\OpenRegister\Service\Calculation\CalculationEvaluator::class),
			$this->createMock(\OCA\OpenRegister\Service\UrnService::class),
			$this->createMock(\OCA\OpenRegister\Service\TranslationStatusService::class),
			$this->createMock(\OCA\OpenRegister\Db\TranslationMapper::class),
			$this->createMock(\OCA\OpenRegister\Service\LanguageService::class)
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
		$userSession->method('getUser')->willReturn($this->user);
		$groupManager = $this->createMock(\OCP\IGroupManager::class);

		return new \OCA\OpenRegister\Service\PropertyRbacHandler(
			$userSession,
			$groupManager,
			$this->createMock(\OCA\OpenRegister\Service\ConditionMatcher::class),
			$this->createMock(\Psr\Log\LoggerInterface::class),
			self::stateFieldRuleResolver($userSession, $groupManager)
		);
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

	private function entity(): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('55f182bf-cf12-421a-a24c-56f13c582d47');
		$entity->setSchema(119);
		$entity->setRegister(21);
		$entity->setName('Zaaksysteem');
		$entity->setDescription('LONG-SECRET');
		$entity->setObject(
			[
				'name' => 'Zaaksysteem',
				'contactPerson' => self::CONTACT,
				'dpiaDocumentRef' => 'DPIA-SECRET-123',
				'longDescription' => 'LONG-SECRET',
			]
		);
		$entity->setRelations(
			[
				'contactPerson' => self::CONTACT,
				'dpiaDocumentRef' => 'DPIA-SECRET-123',
				'members.0' => 'not-governed',
			]
		);

		return $entity;
	}

	private function signIn(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->user = $user;
	}

	/**
	 * The measured leak, single-object path: nothing a rule withholds survives anywhere in the response.
	 */
	public function testAnAnonymousRenderCarriesNoWithheldValueAnywhere(): void {
		$rendered = $this->renderer($this->schema())->renderEntity($this->entity());
		$json = json_encode($rendered->jsonSerialize());

		$this->assertStringNotContainsString(self::CONTACT, $json, '@self.relations kept the contact person');
		$this->assertStringNotContainsString('DPIA-SECRET-123', $json, '@self.relations kept the document reference');
		$this->assertStringNotContainsString('LONG-SECRET', $json, '@self.description kept the long description');
		$this->assertSame('Zaaksysteem', $rendered->jsonSerialize()['name'], 'a readable property stays');
		$this->assertSame('not-governed', $rendered->getRelations()['members.0'], 'an ungoverned relation stays');
	}

	/**
	 * The control: a caller the rule admits still gets every copy.
	 */
	public function testASignedInRenderKeepsTheCopies(): void {
		$this->signIn();
		$rendered = $this->renderer($this->schema())->renderEntity($this->entity());

		$this->assertSame(self::CONTACT, $rendered->getRelations()['contactPerson']);
		$this->assertSame('LONG-SECRET', $rendered->getDescription());
	}

	/**
	 * The list path (entity rows).
	 */
	public function testAnAnonymousEntityRowCarriesNoWithheldValue(): void {
		$rows = [$this->entity()];
		$this->renderer($this->schema())->redactWriteOnlyFromRows($rows);
		$json = json_encode($rows[0]->jsonSerialize());

		$this->assertStringNotContainsString(self::CONTACT, $json);
		$this->assertStringNotContainsString('DPIA-SECRET-123', $json);
		$this->assertStringNotContainsString('LONG-SECRET', $json);
	}

	/**
	 * The list path (array rows).
	 */
	public function testAnAnonymousArrayRowCarriesNoWithheldValue(): void {
		$rows = [
			[
				'name' => 'Zaaksysteem',
				'contactPerson' => self::CONTACT,
				'longDescription' => 'LONG-SECRET',
				'@self' => [
					'schema' => 119,
					'name' => 'Zaaksysteem',
					'description' => 'LONG-SECRET',
					'relations' => ['contactPerson' => self::CONTACT, 'members.0' => 'not-governed'],
				],
			],
		];
		$this->renderer($this->schema())->redactWriteOnlyFromRows($rows);
		$json = json_encode($rows[0]);

		$this->assertStringNotContainsString(self::CONTACT, $json);
		$this->assertStringNotContainsString('LONG-SECRET', $json);
		$this->assertSame(['members.0' => 'not-governed'], $rows[0]['@self']['relations']);
		$this->assertSame('Zaaksysteem', $rows[0]['@self']['name']);
	}
}
