<?php

/**
 * ExtendInputNode: the config refusals, single and list references, nested
 * fields, URL references, the fields left alone, the read as the acting
 * identity, and the unresolvable reference that must fail the step rather than
 * leave the raw reference in place.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow\Nodes
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-parity-mapping-and-webhooks/specs/flow-rule-parity/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow\Nodes;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Flow\FlowItemOptions;
use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\FlowNodeCall;
use OCA\OpenRegister\Service\Flow\IFlowNodeTaxonomy;
use OCA\OpenRegister\Service\Flow\Nodes\ExtendInputNode;
use OCA\OpenRegister\Service\Flow\Nodes\FlowNodePresentation;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\WorkflowEngine\IManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use UnexpectedValueException;

/**
 * @covers \OCA\OpenRegister\Service\Flow\Nodes\ExtendInputNode
 * @uses \OCA\OpenRegister\Service\Flow\FieldAssignment
 * @uses \OCA\OpenRegister\Service\Flow\FlowFieldPath
 * @uses \OCA\OpenRegister\Service\Flow\FlowItemShape
 * @uses \OCA\OpenRegister\Service\Flow\FlowItems
 * @uses \OCA\OpenRegister\Service\Flow\FlowItemOptions
 * @uses \OCA\OpenRegister\Service\Flow\FlowNodeCall
 * @uses \OCA\OpenRegister\Service\Flow\Nodes\FlowNodePresentation
 * @uses \OCA\OpenRegister\Service\Flow\Nodes\ObjectNodeBase
 */
class ExtendInputNodeTest extends TestCase {

	private const OWNER = '0a1b2c3d-0000-4000-8000-000000000001';

	private const DOC_A = '0a1b2c3d-0000-4000-8000-00000000000a';

	private const DOC_B = '0a1b2c3d-0000-4000-8000-00000000000b';

	private ObjectOperationsInterface&MockObject $objects;

	private ExtendInputNode $node;

	/**
	 * Who each runAs() call ran as, in order.
	 *
	 * @var list<string>
	 */
	private array $actedAs = [];

	/**
	 * The identifiers each findSilent() call was asked for, in order.
	 *
	 * @var list<string>
	 */
	private array $read = [];

	protected function setUp(): void {
		parent::setUp();
		$this->objects = $this->createMock(ObjectOperationsInterface::class);
		$this->objects->method('runAs')->willReturnCallback(
			function (IUser $user, callable $operation) {
				$this->actedAs[] = $user->getUID();
				return $operation();
			}
		);

		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(
			function (string $uid): ?IUser {
				if ($uid === 'nobody') {
					return null;
				}

				$user = $this->createMock(IUser::class);
				$user->method('getUID')->willReturn($uid);
				$user->method('isEnabled')->willReturn($uid !== 'gone');
				return $user;
			}
		);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('imagePath')->willReturnCallback(static fn (string $app, string $image): string => '/' . $app . '/img/' . $image);

		$this->node = new ExtendInputNode(
			objects: $this->objects,
			userManager: $users,
			presentation: new FlowNodePresentation(l10n: $l10n, urls: $urls)
		);
	}//end setUp()

	/**
	 * Make findSilent() answer the known objects and refuse the rest.
	 *
	 * @return void
	 */
	private function knownObjects(): void {
		$this->objects->method('findSilent')->willReturnCallback(
			function (string $id): ObjectEntity {
				$this->read[] = $id;
				if (in_array($id, [self::OWNER, self::DOC_A, self::DOC_B], true) === false) {
					throw new DoesNotExistException('Object not found');
				}

				$entity = $this->createMock(ObjectEntity::class);
				$entity->method('jsonSerialize')->willReturn(['id' => $id, 'name' => 'object ' . substr($id, -1)]);
				return $entity;
			}
		);
	}//end knownObjects()

	private function call(array $config, array $json, string $runAs = 'alice'): FlowNodeCall {
		return new FlowNodeCall(
			items: [FlowItems::item(json: $json, options: new FlowItemOptions(binary: ['file' => 'x']))],
			config: $config,
			context: ['runAs' => $runAs]
		);
	}//end call()

	public function testMetadataNamesTheParityNode(): void {
		$this->assertSame('openregister.extend-input', $this->node->getId());
		$this->assertSame('Extend references', $this->node->getDisplayName());
		$this->assertSame('/core/img/actions/external.svg', $this->node->getIcon());
		$this->assertSame(['properties'], $this->node->configKeys());
		$this->assertSame(IFlowNodeTaxonomy::KIND_SERVICE_TASK, $this->node->getKind());
		$this->assertSame(IFlowNodeTaxonomy::CATEGORY_OBJECTS, $this->node->getCategory());
		$this->assertTrue($this->node->isAvailableForScope(IManager::SCOPE_USER));
		$this->assertTrue($this->node->isAvailableForScope(IManager::SCOPE_ADMIN));
	}

	public function testTheFormOffersExactlyTheConfigKeysWithTheFieldsRequired(): void {
		$form = $this->node->configForm();

		$this->assertSame($this->node->configKeys(), array_column($form, 'key'));
		$this->assertTrue($form[0]['required']);
	}

	public function testAStepNamingNoFieldIsRefusedAtSave(): void {
		foreach ([[], ['properties' => ' , '], ['properties' => []], ['properties' => 7]] as $config) {
			try {
				$this->node->validateConfig($config);
				$this->fail('accepted ' . json_encode($config));
			} catch (UnexpectedValueException $e) {
				$this->assertSame('An extend-references step needs the fields to extend.', $e->getMessage());
			}
		}
	}

	public function testANonTextFieldIsRefusedAtSave(): void {
		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessage("An extend-references step's fields must be text.");
		$this->node->validateConfig(['properties' => ['owner', ['x']]]);
	}

	public function testAReferenceIsReplacedByItsObjectReadAsTheActingIdentity(): void {
		$this->knownObjects();

		$out = $this->node->execute($this->call(['properties' => 'owner'], ['title' => 'T', 'owner' => self::OWNER]));

		$this->assertCount(1, $out);
		$this->assertSame(['id' => self::OWNER, 'name' => 'object 1'], $out[0][FlowItems::JSON]['owner']);
		$this->assertSame('T', $out[0][FlowItems::JSON]['title'], 'the item keeps its other fields');
		$this->assertSame(['file' => 'x'], $out[0][FlowItems::BINARY]);
		$this->assertSame(['alice'], $this->actedAs);
	}

	public function testTheReadIsALookupThatWritesNoAuditRow(): void {
		$this->objects->expects($this->never())->method('find');
		$this->knownObjects();

		$this->node->execute($this->call(['properties' => ['owner']], ['owner' => self::OWNER]));

		$this->assertSame([self::OWNER], $this->read);
	}

	public function testAListOfReferencesBecomesTheListOfObjectsInOrder(): void {
		$this->knownObjects();

		$out = $this->node->execute($this->call(['properties' => ['documents']], ['documents' => [self::DOC_B, self::DOC_A]]));

		$this->assertSame(
			[['id' => self::DOC_B, 'name' => 'object b'], ['id' => self::DOC_A, 'name' => 'object a']],
			$out[0][FlowItems::JSON]['documents']
		);
	}

	public function testANestedFieldAndAUrlReferenceAreExtended(): void {
		$this->knownObjects();

		$out = $this->node->execute(
			$this->call(
				['properties' => 'case.owner, link'],
				['case' => ['owner' => self::OWNER, 'status' => 'open'], 'link' => 'https://example.org/api/objects/r/s/' . self::DOC_A . '/']
			)
		);

		$json = $out[0][FlowItems::JSON];
		$this->assertSame(['id' => self::OWNER, 'name' => 'object 1'], $json['case']['owner']);
		$this->assertSame('open', $json['case']['status']);
		$this->assertSame(['id' => self::DOC_A, 'name' => 'object a'], $json['link']);
		$this->assertSame([self::OWNER, self::DOC_A], $this->read);
	}

	public function testFieldsHoldingNoReferenceAreLeftAlone(): void {
		$this->objects->expects($this->never())->method('findSilent');

		$json = ['empty' => '', 'none' => null, 'object' => ['id' => 'x', 'name' => 'already'], 'mixed' => ['a', ['b']]];
		$out  = $this->node->execute($this->call(['properties' => ['missing', 'empty', 'none', 'object', 'mixed', 'deep.path']], $json));

		$this->assertSame($json, $out[0][FlowItems::JSON]);
	}

	public function testAReferenceThatDoesNotResolveFailsTheStep(): void {
		$this->knownObjects();

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('The extend-references step could not read object "nope" in field "documents": Object not found');
		$this->node->execute($this->call(['properties' => ['documents']], ['documents' => [self::DOC_A, 'nope']]));
	}

	public function testARunWithoutAnActingIdentityIsRefused(): void {
		$this->objects->expects($this->never())->method('findSilent');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('no acting identity');
		$this->node->execute($this->call(['properties' => 'owner'], ['owner' => self::OWNER], runAs: ''));
	}

	public function testADisabledOrUnknownIdentityIsRefused(): void {
		$this->objects->expects($this->never())->method('findSilent');

		foreach (['gone', 'nobody'] as $uid) {
			try {
				$this->node->execute($this->call(['properties' => 'owner'], ['owner' => self::OWNER], runAs: $uid));
				$this->fail('the read ran as ' . $uid);
			} catch (RuntimeException $e) {
				$this->assertStringContainsString('"' . $uid . '"', $e->getMessage());
			}
		}
	}

	public function testAnEmptyBranchReadsNothing(): void {
		$this->objects->expects($this->never())->method('runAs');

		$this->assertSame([], $this->node->execute(new FlowNodeCall(items: [], config: [], context: [])));
	}
}
