<?php

/**
 * Per-property merge decisions and the readability refusal.
 *
 * Covers the preview that offers a choice, the execution that applies exactly
 * the approved map, the 422 refusals when the map and the preview disagree,
 * the 403 when the merger cannot read everything being merged, and the control
 * that matters most: no map behaves exactly as it did before this change.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Merge
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/specs/mdm-merge/spec.md#requirement-a-merge-preview-offers-the-choice-per-property-and-execution-applies-the-choice-req-dmd-001
 */

declare(strict_types=1);

namespace Unit\Service\Merge;

use OCA\OpenRegister\Service\PropertyAccessRequest;
use OCA\OpenRegister\Service\SchemaProperty;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\MergeDecisionException;
use OCA\OpenRegister\Exception\MergeNotFullyReadableException;
use OCA\OpenRegister\Kernel\Contracts\MergeInstruction;
use OCA\OpenRegister\Service\Merge\MergeReportingServices;
use OCA\OpenRegister\Service\Merge\MergeService;
use OCA\OpenRegister\Service\Merge\MergeSurvivorshipServices;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use OCA\OpenRegister\Service\Survivorship\SourceRecordResolver;
use OCA\OpenRegister\Service\Survivorship\SurvivorshipResolver;
use OCA\OpenRegister\Service\Survivorship\TrustTierResolver;
use OCA\OpenRegister\Tests\Support\AuditTrailEvents;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class MergeFieldDecisionTest extends TestCase {

	/**
	 * Object read/write path.
	 *
	 * @var ObjectService&MockObject
	 */
	private $objectService;

	/**
	 * Schema lookup.
	 *
	 * @var SchemaMapper&MockObject
	 */
	private $schemaMapper;

	/**
	 * Field-level security.
	 *
	 * @var PropertyRbacHandler&MockObject
	 */
	private $propertyRbac;

	/**
	 * Audit writer.
	 *
	 * @var AuditTrailMapper&MockObject
	 */
	private $auditTrailMapper;

	/**
	 * Service under test.
	 *
	 * @var MergeService
	 */
	private MergeService $service;

	/**
	 * Wire the service over mocked collaborators and the real pure resolvers.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objectService = $this->createMock(ObjectService::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->propertyRbac = $this->createMock(PropertyRbacHandler::class);
		$this->auditTrailMapper = $this->createMock(AuditTrailMapper::class);
		$logger = $this->createMock(LoggerInterface::class);

		$this->service = new MergeService(
			$this->objectService,
			$this->propertyRbac,
			new MergeSurvivorshipServices($this->schemaMapper, new SurvivorshipResolver(), new TrustTierResolver(), new SourceRecordResolver($this->objectService, $this->schemaMapper, $logger)),
			new MergeReportingServices($this->createMock(IEventDispatcher::class), $this->auditTrailMapper, $logger)
		);
	}//end setUp()

	/**
	 * A schema carrying the merge and survivorship blocks.
	 *
	 * @param bool $withPropertyAuthorization Whether a property declares an authorization block.
	 *
	 * @return Schema
	 */
	private function schemaWithConfig(bool $withPropertyAuthorization = false): Schema {
		$schema = new Schema();
		$schema->setSlug('party');
		$schema->setConfiguration(
			[
				'x-openregister-merge' => ['sourceLinkField' => 'sources', 'entityType' => 'party'],
				'x-openregister-survivorship' => [
					'sourceLinkField' => 'sources',
					'goldenRecordField' => 'goldenRecord',
					'provenanceField' => 'attributeProvenance',
					'tierOrder' => ['discard', 'bronze', 'silver', 'gold'],
					'defaultTier' => 'bronze',
					'discardTier' => 'discard',
				],
			]
		);

		$properties = ['address' => ['type' => 'string'], 'phone' => ['type' => 'string']];
		if ($withPropertyAuthorization === true) {
			$properties['medicalNote'] = ['type' => 'string', 'authorization' => ['read' => ['care-team']]];
		}

		$schema->setProperties($properties);

		return $schema;
	}//end schemaWithConfig()

	/**
	 * Two party records that disagree about the address and the phone number.
	 *
	 * @param array<string, mixed> $extraFrom Extra payload on the losing object.
	 *
	 * @return array{0: ObjectEntity, 1: ObjectEntity}
	 */
	private function buildPair(array $extraFrom = []): array {
		$from = new ObjectEntity();
		$from->setUuid('from-uuid');
		$from->setSchema('party');
		$from->setRegister('parties');
		$from->setObject(
			array_merge(
				['status' => 'active', 'address' => 'Eikenlaan 4', 'phone' => '020-1111111', 'sources' => []],
				$extraFrom
			)
		);

		$into = new ObjectEntity();
		$into->setUuid('into-uuid');
		$into->setSchema('party');
		$into->setRegister('parties');
		$into->setObject(
			['status' => 'active', 'address' => 'Eikenlaan 14', 'phone' => '020-2222222', 'sources' => []]
		);

		return [$from, $into];
	}//end buildPair()

	/**
	 * Answer both object reads, rendered and unrendered alike.
	 *
	 * @param ObjectEntity $from The losing object.
	 * @param ObjectEntity $into The surviving object.
	 *
	 * @return void
	 */
	private function stubReads(ObjectEntity $from, ObjectEntity $into): void {
		$this->schemaMapper->method('find')->willReturn($this->schemaWithConfig());
		$this->objectService->method('find')->willReturnCallback(
			static function (...$args) use ($from, $into): ?ObjectEntity {
				$id = ($args[0] ?? '');
				if ($id === 'from-uuid') {
					return $from;
				}

				if ($id === 'into-uuid') {
					return $into;
				}

				return null;
			}
		);
		$this->objectService->method('findAll')->willReturn([]);
	}//end stubReads()

	/**
	 * The preview offers, per property, what each side holds and what the
	 * resolver proposes.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/mdm-merge/spec.md#requirement-a-merge-preview-offers-the-choice-per-property-and-execution-applies-the-choice-req-dmd-001
	 */
	public function testPreviewOffersBothValuesAndAProposal(): void {
		[$from, $into] = $this->buildPair();
		$this->stubReads($from, $into);

		$this->objectService->expects($this->never())->method('saveObject');

		$preview = $this->service->previewMerge('from-uuid', 'into-uuid');

		$this->assertArrayHasKey('fieldChoices', $preview);
		$this->assertSame('Eikenlaan 4', $preview['fieldChoices']['address']['from']);
		$this->assertSame('Eikenlaan 14', $preview['fieldChoices']['address']['into']);
		$this->assertSame('Eikenlaan 14', $preview['fieldChoices']['address']['proposed']);

		// The merge machinery's own fields are not a choice a reviewer makes.
		$this->assertArrayNotHasKey('status', $preview['fieldChoices']);
		$this->assertArrayNotHasKey('sources', $preview['fieldChoices']);
		$this->assertArrayNotHasKey('goldenRecord', $preview['fieldChoices']);
	}//end testPreviewOffersBothValuesAndAProposal()

	/**
	 * The reviewer keeps the older address and the newer phone number, and the
	 * survivor holds exactly that.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/mdm-merge/spec.md#requirement-a-merge-preview-offers-the-choice-per-property-and-execution-applies-the-choice-req-dmd-001
	 */
	public function testExecutionAppliesExactlyTheApprovedMap(): void {
		[$from, $into] = $this->buildPair();
		$this->stubReads($from, $into);

		$survivorPayload = null;
		$this->objectService->method('saveObject')->willReturnCallback(
			function (...$args) use (&$survivorPayload): ObjectEntity {
				$subject = ($args[0] ?? null);
				if ($subject instanceof ObjectEntity && $subject->getUuid() === 'into-uuid') {
					$survivorPayload = $subject->getObject();
				}

				if ($subject instanceof ObjectEntity) {
					return $subject;
				}

				$row = new ObjectEntity();
				$row->setUuid('merge-op');
				$row->setObject(is_array($subject) ? $subject : []);

				return $row;
			}
		);

		$this->service->merge(instruction: new MergeInstruction(from: 'from-uuid', into: 'into-uuid', reason: 'same person, two intakes', mergedBy: 'reviewer'), decisions: ['address' => 'from', 'phone' => 'into']);

		$this->assertSame('Eikenlaan 4', $survivorPayload['address']);
		$this->assertSame('020-2222222', $survivorPayload['phone']);
	}//end testExecutionAppliesExactlyTheApprovedMap()

	/**
	 * A map that omits a property the preview offered is refused with 422
	 * naming it, and nothing is written.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/mdm-merge/spec.md#requirement-a-merge-preview-offers-the-choice-per-property-and-execution-applies-the-choice-req-dmd-001
	 */
	public function testAnIncompleteMapIsRefusedAndWritesNothing(): void {
		[$from, $into] = $this->buildPair();
		$this->stubReads($from, $into);

		$this->objectService->expects($this->never())->method('saveObject');

		try {
			$this->service->merge(instruction: new MergeInstruction(from: 'from-uuid', into: 'into-uuid', reason: 'same person', mergedBy: 'reviewer'), decisions: ['address' => 'into']);
			$this->fail('an incomplete decision map should have been refused');
		} catch (MergeDecisionException $e) {
			$this->assertSame(422, $e->getCode());
			$this->assertContains('phone', $e->getProperties());
		}
	}//end testAnIncompleteMapIsRefusedAndWritesNothing()

	/**
	 * A map that names a property the preview never offered is refused too.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/mdm-merge/spec.md#requirement-a-merge-preview-offers-the-choice-per-property-and-execution-applies-the-choice-req-dmd-001
	 */
	public function testAMapNamingAnUnofferedPropertyIsRefused(): void {
		[$from, $into] = $this->buildPair();
		$this->stubReads($from, $into);

		$this->objectService->expects($this->never())->method('saveObject');

		try {
			$this->service->merge(instruction: new MergeInstruction(from: 'from-uuid', into: 'into-uuid', reason: 'same person', mergedBy: 'reviewer'), decisions: ['address' => 'into', 'phone' => 'from', 'iban' => 'from']);
			$this->fail('a map naming an unoffered property should have been refused');
		} catch (MergeDecisionException $e) {
			$this->assertContains('iban', $e->getProperties());
		}
	}//end testAMapNamingAnUnofferedPropertyIsRefused()

	/**
	 * A decision that names neither side is refused rather than guessed at.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/mdm-merge/spec.md#requirement-a-merge-preview-offers-the-choice-per-property-and-execution-applies-the-choice-req-dmd-001
	 */
	public function testADecisionMustNameFromOrInto(): void {
		[$from, $into] = $this->buildPair();
		$this->stubReads($from, $into);

		$this->objectService->expects($this->never())->method('saveObject');

		$this->expectException(MergeDecisionException::class);
		$this->service->merge(instruction: new MergeInstruction(from: 'from-uuid', into: 'into-uuid', reason: 'same person', mergedBy: 'reviewer'), decisions: ['address' => 'Eikenlaan 4', 'phone' => 'into']);
	}//end testADecisionMustNameFromOrInto()

	/**
	 * THE CONTROL. No map behaves exactly as it did before this change: the
	 * resolver's proposal applies and no payload property is rewritten.
	 *
	 * Without this, every assertion above would be satisfied by a change that
	 * quietly rewrote payload properties on every merge in the fleet.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/mdm-merge/spec.md#requirement-a-merge-preview-offers-the-choice-per-property-and-execution-applies-the-choice-req-dmd-001
	 */
	public function testNoMapLeavesThePayloadPropertiesAlone(): void {
		[$from, $into] = $this->buildPair();
		$this->stubReads($from, $into);

		$survivorPayload = null;
		$this->objectService->method('saveObject')->willReturnCallback(
			function (...$args) use (&$survivorPayload): ObjectEntity {
				$subject = ($args[0] ?? null);
				if ($subject instanceof ObjectEntity && $subject->getUuid() === 'into-uuid') {
					$survivorPayload = $subject->getObject();
				}

				if ($subject instanceof ObjectEntity) {
					return $subject;
				}

				$row = new ObjectEntity();
				$row->setUuid('merge-op');
				$row->setObject(is_array($subject) ? $subject : []);

				return $row;
			}
		);

		$this->service->merge(instruction: new MergeInstruction(from: 'from-uuid', into: 'into-uuid', reason: 'same person', mergedBy: 'reviewer'));

		$this->assertSame('Eikenlaan 14', $survivorPayload['address']);
		$this->assertSame('020-2222222', $survivorPayload['phone']);
	}//end testNoMapLeavesThePayloadPropertiesAlone()

	/**
	 * A handler without the medical domain cannot merge two client records.
	 * The refusal names the property, never its value, and both objects carry
	 * an audit entry for the attempt.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/mdm-merge/spec.md#requirement-a-merge-is-refused-when-the-merger-cannot-read-everything-being-merged-req-dmd-002
	 */
	public function testAMergeIsRefusedWhenAPropertyCannotBeRead(): void {
		[$from, $into] = $this->buildPair(['medicalNote' => 'diagnosis on file']);
		$this->schemaMapper->method('find')->willReturn($this->schemaWithConfig(withPropertyAuthorization: true));
		$this->objectService->method('find')->willReturnCallback(
			static function (...$args) use ($from, $into): ?ObjectEntity {
				$id = ($args[0] ?? '');
				if ($id === 'from-uuid') {
					return $from;
				}

				if ($id === 'into-uuid') {
					return $into;
				}

				return null;
			}
		);
		$this->objectService->method('findAll')->willReturn([]);

		$this->propertyRbac->method('canReadProperty')->willReturnCallback(
			static fn (SchemaProperty $read, PropertyAccessRequest $request): bool => ($read->name !== 'medicalNote')
		);

		$audited = [];
		$this->auditTrailMapper->method('recordObjectEvent')->willReturnCallback(AuditTrailEvents::positional(
			static function (...$args) use (&$audited): AuditTrail {
				$subject = $args[0];
				$audited[] = [(string)$subject->getUuid(), (string)$args[1], $args[2]];

				return new AuditTrail();
			}
		));

		$this->objectService->expects($this->never())->method('saveObject');

		try {
			$this->service->merge(instruction: new MergeInstruction(from: 'from-uuid', into: 'into-uuid', reason: 'same person', mergedBy: 'handler'));
			$this->fail('a merge touching an unreadable property should have been refused');
		} catch (MergeNotFullyReadableException $e) {
			$this->assertSame(403, $e->getCode());
			$this->assertSame(['medicalNote'], $e->getProperties());
			$this->assertStringNotContainsString('diagnosis on file', $e->getMessage());
		}

		$this->assertSame(['from-uuid', 'into-uuid'], array_column($audited, 0));
		$this->assertSame(['merge.refused', 'merge.refused'], array_column($audited, 1));
		$this->assertSame(['medicalNote'], $audited[0][2]['refusedProperties']);
	}//end testAMergeIsRefusedWhenAPropertyCannotBeRead()

	/**
	 * CONTROL for the refusal above: the same schema, the same caller, and a
	 * pair that does not carry the restricted property merges normally. Without
	 * it, a guard that refused every merge would pass the test above.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/mdm-merge/spec.md#requirement-a-merge-is-refused-when-the-merger-cannot-read-everything-being-merged-req-dmd-002
	 */
	public function testAPairWithoutTheRestrictedPropertyStillMerges(): void {
		[$from, $into] = $this->buildPair();
		$this->schemaMapper->method('find')->willReturn($this->schemaWithConfig(withPropertyAuthorization: true));
		$this->objectService->method('find')->willReturnCallback(
			static function (...$args) use ($from, $into): ?ObjectEntity {
				$id = ($args[0] ?? '');
				if ($id === 'from-uuid') {
					return $from;
				}

				if ($id === 'into-uuid') {
					return $into;
				}

				return null;
			}
		);
		$this->objectService->method('findAll')->willReturn([]);
		$this->propertyRbac->method('canReadProperty')->willReturnCallback(
			static fn (SchemaProperty $read, PropertyAccessRequest $request): bool => ($read->name !== 'medicalNote')
		);

		$this->objectService->method('saveObject')->willReturnCallback(
			static function (...$args): ObjectEntity {
				$subject = ($args[0] ?? null);
				if ($subject instanceof ObjectEntity) {
					return $subject;
				}

				$row = new ObjectEntity();
				$row->setUuid('merge-op');
				$row->setObject(is_array($subject) ? $subject : []);

				return $row;
			}
		);

		$operation = $this->service->merge(instruction: new MergeInstruction(from: 'from-uuid', into: 'into-uuid', reason: 'same person', mergedBy: 'handler'));

		$this->assertSame('into-uuid', $operation['mergedIntoUuid']);
	}//end testAPairWithoutTheRestrictedPropertyStillMerges()

	/**
	 * The read rule is asked of the record's own metadata, not of a `@self` its stored data carries.
	 *
	 * The rule double admits only organisation org-a. The losing record belongs to
	 * org-b and its stored data claims org-a; the merge is refused.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/mdm-merge/spec.md#requirement-a-merge-is-refused-when-the-merger-cannot-read-everything-being-merged-req-dmd-002
	 */
	public function testTheReadRuleIsAskedOfTheRecord(): void {
		[$from, $into] = $this->buildPair(['medicalNote' => 'x', '_organisation' => 'org-a', '@self' => ['organisation' => 'org-a']]);
		$from->setOrganisation('org-b');
		$into->setOrganisation('org-a');
		$into->setObject(array_merge($into->getObject(), ['@self' => ['organisation' => 'org-a']]));
		$this->schemaMapper->method('find')->willReturn($this->schemaWithConfig(withPropertyAuthorization: true));
		$this->objectService->method('find')->willReturnCallback(
			static fn (...$args): ?ObjectEntity => ['from-uuid' => $from, 'into-uuid' => $into][($args[0] ?? '')] ?? null
		);
		$this->objectService->method('findAll')->willReturn([]);
		$this->propertyRbac->method('canReadProperty')->willReturnCallback(
			static fn (SchemaProperty $read, PropertyAccessRequest $request): bool => (($request->object['@self']['organisation'] ?? null) === 'org-a')
		);
		$this->auditTrailMapper->method('recordObjectEvent')->willReturn(new AuditTrail());
		$this->objectService->expects($this->never())->method('saveObject');

		$this->expectException(MergeNotFullyReadableException::class);
		$this->service->merge(instruction: new MergeInstruction(from: 'from-uuid', into: 'into-uuid', reason: 'same person', mergedBy: 'handler'));
	}//end testTheReadRuleIsAskedOfTheRecord()
}//end class
