<?php

/**
 * ChangeGateListener tests (records-change-held-for-approval).
 *
 * A write that changes a gated property is stored as a `pending-<n>` draft and
 * stopped with a 202 refusal; an ungated field and an exempt group go through;
 * a gated create is drafted over a reserved uuid with no data; a second gated
 * write while one is open is refused with 409; an unreadable gate fails closed.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/records-change-held-for-approval/specs/approval-workflow/spec.md#requirement-an-approval-chain-can-hold-a-change-until-it-is-approved
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Listener;

use OCA\OpenRegister\Db\DraftVersion;
use OCA\OpenRegister\Db\DraftVersionMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Db\Schema\ConfigurationEntryRules;
use OCA\OpenRegister\Db\Schema\ConfigurationValidator;
use OCA\OpenRegister\Event\AbstractObjectEntityEvent;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Listener\ChangeGateListener;
use OCA\OpenRegister\Service\Object\DraftService;
use OCA\OpenRegister\Service\Object\PermissionCheckInterface;
use OCA\OpenRegister\Service\Task\ChangeGateDeclaration;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[CoversClass(ChangeGateListener::class)]
#[UsesClass(DraftService::class)]
#[UsesClass(ChangeGateDeclaration::class)]
#[UsesClass(ObjectEntity::class)]
#[UsesClass(Schema::class)]
#[UsesClass(DraftVersion::class)]
#[UsesClass(ObjectCreatingEvent::class)]
#[UsesClass(ObjectUpdatingEvent::class)]
#[UsesClass(AbstractObjectEntityEvent::class)]
#[UsesClass(ConfigurationValidator::class)]
#[UsesClass(ConfigurationEntryRules::class)]
class ChangeGateListenerTest extends TestCase {

	/** @var DraftVersionMapper&MockObject */
	private DraftVersionMapper $draftVersions;

	/** @var SchemaMapperInterface&MockObject */
	private SchemaMapperInterface $schemas;

	/** @var IGroupManager&MockObject */
	private IGroupManager $groups;

	/** @var LoggerInterface&MockObject */
	private LoggerInterface $logger;

	/** @var array<int, DraftVersion> The drafts the mapper was asked to insert. */
	private array $inserted = [];

	/** @var array<int, DraftVersion> The drafts already stored on the object. */
	private array $existing = [];

	protected function setUp(): void {
		$this->draftVersions = $this->createMock(DraftVersionMapper::class);
		$this->draftVersions->method('findForObject')->willReturnCallback(fn (): array => $this->existing);
		$this->draftVersions->method('findByKey')->willReturn(null);
		$this->draftVersions->method('insert')->willReturnCallback(
			function (DraftVersion $draft): DraftVersion {
				$this->inserted[] = $draft;
				return $draft;
			}
		);
		$this->schemas = $this->createMock(SchemaMapperInterface::class);
		$this->groups  = $this->createMock(IGroupManager::class);
		$this->groups->method('getUserGroupIds')->willReturn(['clerks']);
		$this->logger = $this->createMock(LoggerInterface::class);
	}

	private function listener(): ChangeGateListener {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('clerk');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new ChangeGateListener(
			$this->schemas,
			new DraftService($this->draftVersions, $this->createMock(PermissionCheckInterface::class)),
			$this->draftVersions,
			$session,
			$this->groups,
			$this->logger
		);
	}

	/**
	 * @param array<string, mixed> $chains The schema's approval chains.
	 *
	 * @return void
	 */
	private function schemaWith(array $chains): void {
		$schema = new Schema();
		$schema->setConfiguration(['x-openregister-approval-chains' => $chains]);
		$this->schemas->method('find')->willReturn($schema);
	}

	/**
	 * @param array<string, mixed> $data The object data.
	 * @param string               $uuid The object's uuid.
	 *
	 * @return ObjectEntity The object.
	 */
	private function supplier(array $data, string $uuid = 'sup-1'): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setRegister('7');
		$object->setSchema('12');
		$object->setObject($data);
		return $object;
	}

	/**
	 * @param array<string, mixed> $change The `gate.change` declaration.
	 *
	 * @return array<string, mixed> The chains.
	 */
	private static function ibanGate(array $change = ['properties' => ['iban']]): array {
		return ['bank-details' => ['gate' => ['change' => $change], 'approvers' => [['role' => 'financieel-beheer']]]];
	}

	private function update(array $old, array $new): ObjectUpdatingEvent {
		$event = new ObjectUpdatingEvent(newObject: $this->supplier($new), oldObject: $this->supplier($old));
		$this->listener()->handle($event);
		return $event;
	}

	public function testAChangeToAGatedFieldIsHeldAsAPendingChange(): void {
		$this->schemaWith(self::ibanGate());

		$event = $this->update(['name' => 'Acme', 'iban' => 'NL01OLD'], ['name' => 'Acme', 'iban' => 'NL02NEW']);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame(ChangeGateListener::CODE_PENDING, $event->getErrors()['code']);
		$this->assertSame(202, $event->getErrors()['status']);
		$this->assertSame('pending-1', $event->getErrors()['pendingChange']);
		$this->assertSame('sup-1', $event->getErrors()['object']);
		$this->assertSame('bank-details', $event->getErrors()['chain']);

		$this->assertCount(1, $this->inserted);
		$draft = $this->inserted[0];
		$this->assertSame('sup-1', $draft->getObjectUuid());
		$this->assertSame('pending-1', $draft->getDraftKey());
		$this->assertSame('bank-details', $draft->getName());
		$this->assertSame('clerk', $draft->getCreatedBy());
		$this->assertSame(['iban' => 'NL02NEW'], $draft->getDelta());
	}

	public function testTheWholeWriteIsHeldNotOnlyTheGatedField(): void {
		$this->schemaWith(self::ibanGate());

		$this->update(['phone' => '010', 'iban' => 'NL01OLD'], ['phone' => '020', 'iban' => 'NL02NEW']);

		$this->assertSame(['phone' => '020', 'iban' => 'NL02NEW'], $this->inserted[0]->getDelta());
	}

	public function testAChangeToAnUngatedFieldGoesThrough(): void {
		$this->schemaWith(self::ibanGate());

		$event = $this->update(['phone' => '010', 'iban' => 'NL01'], ['phone' => '020', 'iban' => 'NL01']);

		$this->assertFalse($event->isPropagationStopped());
		$this->assertSame([], $event->getErrors());
		$this->assertSame([], $this->inserted);
	}

	public function testAnExemptGroupWritesWithoutApproval(): void {
		$this->schemaWith(self::ibanGate(['properties' => ['iban'], 'exempt' => ['clerks']]));

		$event = $this->update(['iban' => 'NL01'], ['iban' => 'NL02']);

		$this->assertFalse($event->isPropagationStopped());
		$this->assertSame([], $this->inserted);
	}

	public function testAnEmptyPropertyListGatesAnyChange(): void {
		$this->schemaWith(self::ibanGate(['properties' => []]));

		$event = $this->update(['phone' => '010'], ['phone' => '020']);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame(['phone' => '020'], $this->inserted[0]->getDelta());
	}

	public function testAGateOnCreateOnlyLetsAnUpdateThrough(): void {
		$this->schemaWith(self::ibanGate(['properties' => ['iban'], 'on' => ['create']]));

		$event = $this->update(['iban' => 'NL01'], ['iban' => 'NL02']);

		$this->assertFalse($event->isPropagationStopped());
	}

	public function testAGatedCreateIsDraftedOverAReservedUuidWithNoData(): void {
		$this->schemaWith(self::ibanGate(['properties' => [], 'on' => ['create']]));
		$event = new ObjectCreatingEvent(object: $this->supplier(['title' => 'Aanvraag', 'amount' => 500], 'new-1'));

		$this->listener()->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame(202, $event->getErrors()['status']);
		$this->assertSame('new-1', $event->getErrors()['object']);
		$draft = $this->inserted[0];
		$this->assertSame('new-1', $draft->getObjectUuid());
		$this->assertNull($draft->getBaseVersion());
		$this->assertSame('12', $draft->getObjectSchema());
		$this->assertSame(['title' => 'Aanvraag', 'amount' => 500], $draft->getDelta());
	}

	public function testAGatedCreateWithoutAUuidReservesOne(): void {
		$this->schemaWith(self::ibanGate(['properties' => [], 'on' => ['create']]));
		$event = new ObjectCreatingEvent(object: $this->supplier(['title' => 'Aanvraag'], ''));

		$this->listener()->handle($event);

		$this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $event->getErrors()['object']);
		$this->assertSame($event->getErrors()['object'], $this->inserted[0]->getObjectUuid());
	}

	public function testAnUpdateGateDoesNotHoldACreate(): void {
		$this->schemaWith(self::ibanGate());
		$event = new ObjectCreatingEvent(object: $this->supplier(['iban' => 'NL01'], 'new-1'));

		$this->listener()->handle($event);

		$this->assertFalse($event->isPropagationStopped());
	}

	public function testASecondGatedWriteWhileOneIsOpenIsRefusedWith409(): void {
		$this->schemaWith(self::ibanGate());
		$this->existing = [$this->draft(key: 'pending-1', name: 'bank-details')];

		$event = $this->update(['iban' => 'NL01'], ['iban' => 'NL03']);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame(ChangeGateListener::CODE_OPEN, $event->getErrors()['code']);
		$this->assertSame(409, $event->getErrors()['status']);
		$this->assertSame('pending-1', $event->getErrors()['pendingChange']);
		$this->assertSame([], $this->inserted);
	}

	public function testThePendingKeyFollowsTheHighestInUse(): void {
		$this->schemaWith(self::ibanGate());
		$this->existing = [$this->draft(key: 'pending-4', name: 'other-chain'), $this->draft(key: 'summer', name: 'Summer')];

		$event = $this->update(['iban' => 'NL01'], ['iban' => 'NL02']);

		$this->assertSame('pending-5', $event->getErrors()['pendingChange']);
	}

	public function testAnUnreadableGateFailsClosed(): void {
		$this->schemaWith(['bank-details' => ['gate' => ['change' => ['on' => ['delete']]]]]);
		$this->logger->expects($this->once())->method('error');

		$event = $this->update(['phone' => '010'], ['phone' => '020']);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame(ChangeGateListener::CODE_MISCONFIGURED, $event->getErrors()['code']);
		$this->assertSame([], $this->inserted);
	}

	public function testAGateCombinedWithATransitionFailsClosed(): void {
		$this->schemaWith(['bank-details' => ['transition' => 'approve', 'gate' => ['change' => ['properties' => ['iban']]]]]);

		$event = $this->update(['iban' => 'NL01'], ['iban' => 'NL02']);

		$this->assertSame(ChangeGateListener::CODE_MISCONFIGURED, $event->getErrors()['code']);
	}

	public function testATransitionChainWithoutAChangeGateIsNotThisListenersConcern(): void {
		$this->schemaWith(['publish' => ['transition' => 'publish', 'approvers' => [['role' => 'x']]]]);

		$event = $this->update(['iban' => 'NL01'], ['iban' => 'NL02']);

		$this->assertFalse($event->isPropagationStopped());
	}

	public function testAnAlreadyStoppedWriteIsLeftAlone(): void {
		$this->schemaWith(self::ibanGate());
		$event = new ObjectUpdatingEvent(newObject: $this->supplier(['iban' => 'NL02']), oldObject: $this->supplier(['iban' => 'NL01']));
		$event->setErrors(['code' => 'other']);
		$event->stopPropagation();

		$this->listener()->handle($event);

		$this->assertSame(['code' => 'other'], $event->getErrors());
		$this->assertSame([], $this->inserted);
	}

	private function draft(string $key, string $name): DraftVersion {
		$draft = new DraftVersion();
		$draft->setDraftKey($key);
		$draft->setName($name);
		return $draft;
	}
}
