<?php

/**
 * Unit tests for PropertyVocabularyController: the two reads a generated
 * property editor makes.
 *
 * The contract half of this change. One read answers what a property may be,
 * the other answers what each app's own form forwards and therefore leaves
 * out. A payload shape nobody tests is a contract other apps generate against
 * and then discover by breaking.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/property-vocabulary-published/specs/runtime-schema-api/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Controller\PropertyVocabularyController;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Schemas\ExtendingFormDeclaration;
use OCA\OpenRegister\Service\Schemas\PropertyVocabulary;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * PropertyVocabularyControllerTest.
 */
class PropertyVocabularyControllerTest extends TestCase {

	/**
	 * HTTP request mock.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Schema lookup mock.
	 *
	 * @var SchemaMapper&MockObject
	 */
	private SchemaMapper&MockObject $schemaMapper;

	/**
	 * The published vocabulary.
	 *
	 * @var PropertyVocabulary
	 */
	private PropertyVocabulary $vocabulary;

	/**
	 * Controller under test.
	 *
	 * @var PropertyVocabularyController
	 */
	private PropertyVocabularyController $controller;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->vocabulary = new PropertyVocabulary();

		$this->controller = new PropertyVocabularyController(
			'openregister',
			$this->request,
			$this->vocabulary,
			new ExtendingFormDeclaration($this->vocabulary),
			$this->schemaMapper
		);
	}

	public function testTheVocabularyReadAnswersTheWholeList(): void {
		$response = $this->controller->index();

		$this->assertSame(200, $response->getStatus());

		$body = $response->getData();
		$this->assertSame($this->vocabulary->types(), $body['types']);
		$this->assertSame($this->vocabulary->keys(), $body['keys']);
		$this->assertSame('x-', $body['vendorExtensionPrefix']);
		$this->assertSame(count($body['types']), $body['counts']['types']);
		$this->assertNotEmpty($body['constraints']);
		$this->assertNotEmpty($body['modifiers']);
		$this->assertNotEmpty($body['passthrough']);
	}

	public function testTheNarrowingReadListsWhatEachFormForwardsAndLeavesOut(): void {
		// A real entity, not a double. Schema resolves its getters through the
		// Nextcloud Entity magic, so a double that "adds" getSlug can only
		// pass, which is exactly the shape of test that cannot fail.
		$schema = new Schema();
		$schema->setSlug('zaak');
		$schema->setConfiguration([]);
		$schema->setProperties(
			[
				'naam' => ['type' => 'string'],
				'zaaktype' => [
					'type' => 'string',
					'x-openregister-extends-form' => [
						'app' => 'dossiq',
						'form' => 'property-definition-management',
						'map' => [
							'propertyType' => 'type',
							'label' => 'title',
							'helpText' => 'description',
							'isRequired' => 'required',
							'choices' => 'enum',
							'defaultValue' => 'default',
							'displayOrder' => 'order',
							'isSearchable' => 'facetable',
						],
					],
				],
			]
		);

		$this->schemaMapper->method('findAll')->willReturn([$schema]);

		$response = $this->controller->extendingForms();

		$this->assertSame(200, $response->getStatus());

		$body = $response->getData();
		$this->assertSame(1, $body['counts']['declarations']);
		$this->assertSame('x-openregister-extends-form', $body['annotation']);

		$declaration = $body['declarations'][0];
		$this->assertSame('zaak', $declaration['schema']);
		$this->assertSame('dossiq', $declaration['app']);
		$this->assertSame('properties/zaaktype/x-openregister-extends-form', $declaration['path']);
		$this->assertSame(8, $declaration['counts']['forwards']);
		$this->assertSame(
			count($this->vocabulary->keys()) - 8,
			$declaration['counts']['narrows']
		);
		$this->assertContains('pattern', $declaration['narrows']);
		$this->assertSame([], $declaration['errors']);
	}

	public function testAnInstanceWithNoDeclarationsAnswersAnEmptyList(): void {
		$this->schemaMapper->method('findAll')->willReturn([]);

		$body = $this->controller->extendingForms()->getData();

		$this->assertSame([], $body['declarations']);
		$this->assertSame(0, $body['counts']['declarations']);
		$this->assertSame($this->vocabulary->keys(), $body['vocabularyKeys']);
	}
}//end class
