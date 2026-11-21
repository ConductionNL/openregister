<?php

/**
 * Creating, changing or deleting a schema or register goes on the audit trail.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/capability-rows-verified-c/specs/enhanced-audit-trail/spec.md#requirement-changes-to-authorisation-rules-api-tokens-and-record-types-are-on-the-audit-trail
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Listener;

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Event\RegisterCreatedEvent;
use OCA\OpenRegister\Event\RegisterDeletedEvent;
use OCA\OpenRegister\Event\RegisterUpdatedEvent;
use OCA\OpenRegister\Event\SchemaCreatedEvent;
use OCA\OpenRegister\Event\SchemaDeletedEvent;
use OCA\OpenRegister\Event\SchemaUpdatedEvent;
use OCA\OpenRegister\Listener\RecordTypeAuditListener;
use OCP\EventDispatcher\Event;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Listener\RecordTypeAuditListener
 * @uses   \OCA\OpenRegister\Service\Rbac\AuditRowWriter
 * @uses   \OCA\OpenRegister\Db\AuditTrail
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Event\SchemaUpdatedEvent
 * @uses   \OCA\OpenRegister\Event\RegisterUpdatedEvent
 * @uses   \OCA\OpenRegister\Event\AbstractSchemaEvent
 * @uses   \OCA\OpenRegister\Event\AbstractRegisterEvent
 * @uses   \OCA\OpenRegister\Event\SchemaCreatedEvent
 * @uses   \OCA\OpenRegister\Event\SchemaDeletedEvent
 * @uses   \OCA\OpenRegister\Event\RegisterCreatedEvent
 * @uses   \OCA\OpenRegister\Event\RegisterDeletedEvent
 * @uses   \OCA\OpenRegister\Db\JsonFieldsTrait
 * @uses   \OCA\OpenRegister\Db\LinkedItemsTrait
 * @uses   \OCA\OpenRegister\Db\JsonArrayColumn
 * @uses   \OCA\OpenRegister\Db\Register\RegisterJson
 * @uses   \OCA\OpenRegister\Db\Register\RegisterLanguages
 * @uses   \OCA\OpenRegister\Db\Register\RegisterSchemaIds
 * @uses   \OCA\OpenRegister\Db\Register\MagicMappingConfig
 * @uses   \OCA\OpenRegister\Db\Register\ExactKeyHydration
 * @uses   \OCA\OpenRegister\Db\Schema\AuthorizationRulesValidator
 * @uses   \OCA\OpenRegister\Db\Schema\ConfigurationValidator
 * @uses   \OCA\OpenRegister\Db\Schema\DeclaredActions
 * @uses   \OCA\OpenRegister\Db\Schema\FacetBlockStamp
 * @uses   \OCA\OpenRegister\Db\Schema\InversedByNormaliser
 * @uses   \OCA\OpenRegister\Db\Schema\NotSuppliedReasons
 * @uses   \OCA\OpenRegister\Db\Schema\ObjectSourceDeclaration
 * @uses   \OCA\OpenRegister\Db\Schema\PresentationAnnotations
 * @uses   \OCA\OpenRegister\Db\Schema\PropertyAuthorization
 * @uses   \OCA\OpenRegister\Db\Schema\PropertyFacetBlock
 * @uses   \OCA\OpenRegister\Db\Schema\SchemaGrantEvaluator
 * @uses   \OCA\OpenRegister\Db\Schema\SchemaPropertyDocument
 * @uses   \OCA\OpenRegister\Db\Schema\TopLevelAnnotationFold
 * @uses   \OCA\OpenRegister\Db\Schema\VocabularyEntries
 * @uses   \OCA\OpenRegister\Service\Schemas\UniqueConstraintEvaluator
 */
final class RecordTypeAuditListenerTest extends TestCase {

	/**
	 * The rows the audit trail received.
	 *
	 * @var array<int, AuditTrail>
	 */
	private array $written = [];

	/**
	 * A schema `oude-meldingen`.
	 *
	 * @param string                    $title         Its title.
	 * @param array<string, mixed>|null $authorization Its rules.
	 *
	 * @return Schema
	 */
	private function schema(string $title = 'Oude meldingen', ?array $authorization = null): Schema {
		$schema = new Schema();
		$schema->setId(12);
		$schema->setUuid('00000000-0000-4000-8000-00000000000c');
		$schema->setSlug('oude-meldingen');
		$schema->setTitle($title);
		$schema->setAuthorization($authorization);

		return $schema;
	}//end schema()

	/**
	 * A register `meldingen`.
	 *
	 * @param string $description Its description.
	 *
	 * @return Register
	 */
	private function register(string $description = 'Alle meldingen'): Register {
		$register = new Register();
		$register->setId(3);
		$register->setUuid('00000000-0000-4000-8000-000000000003');
		$register->setSlug('meldingen');
		$register->setTitle('Meldingen');
		$register->setDescription($description);

		return $register;
	}//end register()

	/**
	 * The listener over a recording audit trail, acting as `beheerder`.
	 *
	 * @param bool                 $failing Whether the audit trail refuses the write.
	 * @param LoggerInterface|null $logger  The logger, when the test reads it.
	 *
	 * @return RecordTypeAuditListener
	 */
	private function listener(bool $failing = false, ?LoggerInterface $logger = null): RecordTypeAuditListener {
		$mapper = $this->createMock(AuditTrailMapperInterface::class);
		$mapper->method('insertAuditTrails')->willReturnCallback(
			function (array $entries) use ($failing): int {
				if ($failing === true) {
					throw new RuntimeException('database gone');
				}

				array_push($this->written, ...$entries);
				return count($entries);
			}
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('beheerder');
		$user->method('getDisplayName')->willReturn('Beheerder');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new RecordTypeAuditListener(
			mapper: $mapper,
			userSession: $session,
			logger: ($logger ?? $this->createMock(LoggerInterface::class))
		);
	}//end listener()

	/**
	 * A deleted schema writes one entry naming the actor and the schema.
	 *
	 * @return void
	 */
	public function testADeletedSchemaIsTraceable(): void {
		$this->listener()->handle(new SchemaDeletedEvent($this->schema()));

		$this->assertCount(expectedCount: 1, haystack: $this->written);
		$row = $this->written[0];
		$this->assertSame(expected: 'schema.deleted', actual: $row->getAction());
		$this->assertSame(expected: 'beheerder', actual: $row->getUser());
		$this->assertSame(expected: 12, actual: $row->getSchema());
		$this->assertSame(
			expected: ['schema' => ['id' => 12, 'uuid' => '00000000-0000-4000-8000-00000000000c', 'slug' => 'oude-meldingen', 'title' => 'Oude meldingen']],
			actual: $row->getChanged()
		);
	}//end testADeletedSchemaIsTraceable()

	/**
	 * A created register writes one entry naming the register.
	 *
	 * @return void
	 */
	public function testACreatedRegisterIsTraceable(): void {
		$this->listener()->handle(new RegisterCreatedEvent($this->register()));

		$this->assertCount(expectedCount: 1, haystack: $this->written);
		$this->assertSame(expected: 'register.created', actual: $this->written[0]->getAction());
		$this->assertSame(expected: 3, actual: $this->written[0]->getRegister());
		$this->assertSame(expected: 'meldingen', actual: $this->written[0]->getChanged()['register']['slug']);
	}//end testACreatedRegisterIsTraceable()

	/**
	 * Created schemas and deleted registers carry their own actions.
	 *
	 * @return void
	 */
	public function testEveryKindOfChangeHasItsOwnAction(): void {
		$listener = $this->listener();
		$listener->handle(new SchemaCreatedEvent($this->schema()));
		$listener->handle(new RegisterDeletedEvent($this->register()));

		$this->assertSame(
			expected: ['schema.created', 'register.deleted'],
			actual: array_map(fn (AuditTrail $row): string => $row->getAction(), $this->written)
		);
	}//end testEveryKindOfChangeHasItsOwnAction()

	/**
	 * A changed schema names the fields that changed, before and after.
	 *
	 * @return void
	 */
	public function testAChangedSchemaNamesWhatChanged(): void {
		$this->listener()->handle(new SchemaUpdatedEvent($this->schema(title: 'Meldingen (oud)'), $this->schema()));

		$this->assertCount(expectedCount: 1, haystack: $this->written);
		$this->assertSame(expected: 'schema.updated', actual: $this->written[0]->getAction());
		$this->assertSame(
			expected: ['title' => ['old' => 'Oude meldingen', 'new' => 'Meldingen (oud)']],
			actual: $this->written[0]->getChanged()['changes']
		);
	}//end testAChangedSchemaNamesWhatChanged()

	/**
	 * A changed register names the fields that changed.
	 *
	 * @return void
	 */
	public function testAChangedRegisterNamesWhatChanged(): void {
		$this->listener()->handle(new RegisterUpdatedEvent($this->register(description: 'Open meldingen'), $this->register()));

		$this->assertSame(
			expected: ['description' => ['old' => 'Alle meldingen', 'new' => 'Open meldingen']],
			actual: $this->written[0]->getChanged()['changes']
		);
	}//end testAChangedRegisterNamesWhatChanged()

	/**
	 * A change of the rules alone is left to its own entry, and a save that changes nothing writes nothing.
	 *
	 * @return void
	 */
	public function testARulesOnlyOrEmptyChangeWritesNothing(): void {
		$listener = $this->listener();
		$listener->handle(new SchemaUpdatedEvent($this->schema(authorization: ['read' => ['stagiairs']]), $this->schema(authorization: ['read' => []])));
		$listener->handle(new SchemaUpdatedEvent($this->schema(), $this->schema()));
		$listener->handle(new RegisterUpdatedEvent($this->register(), $this->register()));

		$this->assertSame(expected: [], actual: $this->written);
	}//end testARulesOnlyOrEmptyChangeWritesNothing()

	/**
	 * Any other event is ignored.
	 *
	 * @return void
	 */
	public function testAnotherEventIsIgnored(): void {
		$this->listener()->handle(new Event());

		$this->assertSame(expected: [], actual: $this->written);
	}//end testAnotherEventIsIgnored()

	/**
	 * A failed write is logged and does not throw.
	 *
	 * @return void
	 */
	public function testAFailedWriteIsLoggedNotThrown(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with($this->stringContains('Could not record a schema or register change'));

		$this->listener(failing: true, logger: $logger)->handle(new SchemaDeletedEvent($this->schema()));

		$this->assertSame(expected: [], actual: $this->written);
	}//end testAFailedWriteIsLoggedNotThrown()
}//end class
