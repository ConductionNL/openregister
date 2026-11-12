<?php

/**
 * Unit tests for {@see \OCA\OpenRegister\Service\Oas\OasRbacAnnotator}.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Oas
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Oas;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Service\Oas\OasRbacAnnotator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Which groups an OpenAPI operation advertises as scopes, and how it says so.
 *
 * @covers \OCA\OpenRegister\Service\Oas\OasRbacAnnotator
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Service\Rbac\EffectiveAuthorization
 * @uses   \OCA\OpenRegister\Service\Authorization\RbacGroupCollector
 */
class OasRbacAnnotatorTest extends TestCase {

	/**
	 * The register mapper mock.
	 *
	 * @var RegisterMapperInterface&MockObject
	 */
	private RegisterMapperInterface&MockObject $registers;

	/**
	 * Set up the mapper.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->registers = $this->createMock(RegisterMapperInterface::class);

	}//end setUp()

	/**
	 * A schema-like object (not a Schema entity).
	 *
	 * @param array|null $authorization The schema-level authorization.
	 * @param array|null $properties    The property definitions.
	 * @param mixed      $required      The required list.
	 *
	 * @return object
	 */
	private function schema(?array $authorization, ?array $properties=[], mixed $required=[]): object {
		return new class ($authorization, $properties, $required) {

			/**
			 * Constructor.
			 *
			 * @param array|null $authorization Authorization.
			 * @param array|null $properties    Properties.
			 * @param mixed      $required      Required.
			 */
			public function __construct(private ?array $authorization, private ?array $properties, private mixed $required) {
			}

			/**
			 * @return array|null
			 */
			public function getAuthorization(): ?array {
				return $this->authorization;
			}

			/**
			 * @return array|null
			 */
			public function getProperties(): ?array {
				return $this->properties;
			}

			/**
			 * @return integer
			 */
			public function getId(): int {
				return 9;
			}

			/**
			 * @return mixed
			 */
			public function getRequired(): mixed {
				return $this->required;
			}
		};

	}//end schema()

	/**
	 * Schema-level and property-level groups are merged per action, de-duplicated, rules read both ways.
	 *
	 * @return void
	 */
	public function testExtractSchemaGroupsMergesSchemaAndProperties(): void {
		$this->registers->expects($this->never())->method('getFirstRegisterWithSchema');
		$annotator = new OasRbacAnnotator($this->registers);

		$groups = $annotator->extractSchemaGroups(
			$this->schema(
				['read' => ['public', ['group' => 'staff'], ['nogroup' => 1], 42], 'create' => ['staff']],
				[
					'name'   => ['type' => 'string'],
					'salary' => ['authorization' => ['read' => ['hr', 'staff'], 'update' => [['group' => 'hr']]]],
					'odd'    => 'not a definition',
					'bad'    => ['authorization' => 'hr'],
				]
			)
		);

		$this->assertSame(
			[
				'createGroups' => ['staff'],
				'readGroups'   => ['public', 'staff', 'hr'],
				'updateGroups' => ['hr'],
				'deleteGroups' => [],
			],
			$groups
		);

	}//end testExtractSchemaGroupsMergesSchemaAndProperties()

	/**
	 * Without schema authorization, the register holding the schema supplies it.
	 *
	 * @return void
	 */
	public function testExtractSchemaGroupsFallsBackToRegister(): void {
		$register = new Register();
		$register->setAuthorization(['delete' => ['admin'], 'read' => ['clerks']]);
		$this->registers->expects($this->once())->method('getFirstRegisterWithSchema')->with(9)->willReturn(4);
		$this->registers->expects($this->once())->method('find')->with(4)->willReturn($register);

		$groups = (new OasRbacAnnotator($this->registers))->extractSchemaGroups($this->schema(null, null));

		$this->assertSame(['createGroups' => [], 'readGroups' => ['clerks'], 'updateGroups' => [], 'deleteGroups' => ['admin']], $groups);

	}//end testExtractSchemaGroupsFallsBackToRegister()

	/**
	 * An unreadable register means no groups at all.
	 *
	 * @return void
	 */
	public function testUnreadableRegisterYieldsNoGroups(): void {
		$this->registers->method('getFirstRegisterWithSchema')->willThrowException(new RuntimeException('db down'));

		$groups = (new OasRbacAnnotator($this->registers))->extractSchemaGroups($this->schema([]));

		$this->assertSame(['createGroups' => [], 'readGroups' => [], 'updateGroups' => [], 'deleteGroups' => []], $groups);

	}//end testUnreadableRegisterYieldsNoGroups()

	/**
	 * Admin and public get fixed descriptions; any other group is named.
	 *
	 * @return void
	 */
	public function testScopeDescription(): void {
		$annotator = new OasRbacAnnotator($this->registers);

		$this->assertSame('Full administrative access', $annotator->getScopeDescription('admin'));
		$this->assertSame('Public (unauthenticated) access', $annotator->getScopeDescription('public'));
		$this->assertSame('Access for editors group', $annotator->getScopeDescription('editors'));

	}//end testScopeDescription()

	/**
	 * Admin is put first exactly once; the operation gains scopes text, a 403 and oauth2-or-basic security.
	 *
	 * @return void
	 */
	public function testApplyRbacToOperation(): void {
		$annotator = new OasRbacAnnotator($this->registers);
		$operation = ['description' => 'List items', 'responses' => ['200' => ['description' => 'OK']]];

		$annotator->applyRbacToOperation($operation, ['staff', 'hr', 'staff']);

		$this->assertSame("List items\n\n**Required scopes:** `admin`, `staff`, `hr`", $operation['description']);
		$this->assertSame(['200' => ['description' => 'OK']], array_intersect_key($operation['responses'], ['200' => true]));
		$this->assertSame('#/components/schemas/Error', $operation['responses']['403']['content']['application/json']['schema']['$ref']);
		$this->assertStringStartsWith('Forbidden', $operation['responses']['403']['description']);
		$this->assertSame([['oauth2' => ['admin', 'staff', 'hr']], ['basicAuth' => []]], $operation['security']);

		$withAdmin = ['description' => 'x', 'responses' => []];
		$annotator->applyRbacToOperation($withAdmin, ['hr', 'admin']);
		$this->assertSame([['oauth2' => ['hr', 'admin']], ['basicAuth' => []]], $withAdmin['security']);

	}//end testApplyRbacToOperation()

	/**
	 * Only required names that were described are kept, as strings, in order.
	 *
	 * @return void
	 */
	public function testDescribableRequired(): void {
		$annotator = new OasRbacAnnotator($this->registers);

		$this->assertSame(
			['title', '2'],
			$annotator->describableRequired($this->schema(null, [], ['title', 'secret', 2]), ['title' => [], '2' => []])
		);
		$this->assertSame([], $annotator->describableRequired($this->schema(null, [], 'title'), ['title' => []]));
		$this->assertSame([], $annotator->describableRequired(new \stdClass(), ['title' => []]));

	}//end testDescribableRequired()

	/**
	 * An object that is not a Schema entity may always be described.
	 *
	 * @return void
	 */
	public function testMayDescribeNonSchema(): void {
		$this->assertTrue((new OasRbacAnnotator($this->registers))->mayDescribe($this->schema(null), 'anything'));

	}//end testMayDescribeNonSchema()
}//end class
