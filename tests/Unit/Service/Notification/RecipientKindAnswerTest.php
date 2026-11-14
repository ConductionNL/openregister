<?php

/**
 * What one recipient will get for one notification kind, and which layer decided it.
 *
 * The answer composes the existing preference merge with the administrator's
 * layer above it, so the tests feed it a merged preference and assert what
 * the administrator reads: the group default named as the deciding layer, a
 * forced channel reported as forced with its reason even for a recipient who
 * switched the kind off, and an internal-only kind stripped of channels that
 * can leave the organisation.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Notification
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/notification-kinds-an-administrator-forces/specs/notificatie-engine/spec.md#requirement-an-administrator-can-read-what-one-recipient-will-get-for-one-kind-req-nkf-003
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Notification;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Notifications\Service\Notification\ForcedChannelPolicy;
use OCA\OpenRegister\Notifications\Service\Notification\NotificationPreferenceServiceInterface;
use OCA\OpenRegister\Notifications\Service\Notification\PreferenceResolution;
use OCA\OpenRegister\Notifications\Service\Notification\RecipientKindAnswer;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Notifications\Service\Notification\RecipientKindAnswer
 * @uses   \OCA\OpenRegister\Notifications\Service\Notification\ForcedChannelPolicy
 * @uses   \OCA\OpenRegister\Notifications\Service\Notification\KindPreference
 * @uses   \OCA\OpenRegister\Notifications\Service\Notification\PreferenceResolution
 * @uses   \OCA\OpenRegister\Notifications\Service\Notification\PreferenceTopic
 * @uses   \OCA\OpenRegister\Notifications\Service\Notification\RecipientAudience
 *
 * @spec openspec/changes/notification-kinds-an-administrator-forces/specs/notificatie-engine/spec.md#requirement-an-administrator-can-read-what-one-recipient-will-get-for-one-kind-req-nkf-003
 */
class RecipientKindAnswerTest extends TestCase {

	/**
	 * The preference merge double.
	 *
	 * @var NotificationPreferenceServiceInterface&MockObject
	 */
	private NotificationPreferenceServiceInterface $preferences;

	/**
	 * The resolutions the merge was asked for.
	 *
	 * @var array<int, PreferenceResolution>
	 */
	private array $asked = [];

	protected function setUp(): void {
		parent::setUp();
		$this->preferences = $this->createMock(NotificationPreferenceServiceInterface::class);
		$this->asked = [];
	}//end setUp()

	public function testTheGroupDefaultIsNamedAsTheDecidingLayer(): void {
		$this->merged(['enabled' => true, 'channels' => ['nc-notification'], 'source' => 'group-default', 'scope' => 'global', 'layers' => [['layer' => 'group-default']]]);
		$declaration = ['channels' => [['type' => 'nc-notification'], ['type' => 'email']]];

		$answer = $this->service(schemas: [$this->schema('zaak', ['assigned' => $declaration])])
			->answer(userId: 'behandelaar', schemaSlug: 'zaak', notificationKey: 'assigned', scopes: ['register:zaken']);

		$this->assertSame(
			[
				'user' => 'behandelaar',
				'schema' => 'zaak',
				'notification' => 'assigned',
				'enabled' => true,
				'channels' => ['nc-notification'],
				'layer' => 'group-default',
				'forced' => false,
				'reason' => '',
				'internalOnly' => false,
				'refusal' => '',
				'layers' => [['layer' => 'group-default']],
			],
			$answer
		);
		$this->assertCount(1, $this->asked);
		$this->assertSame($declaration, $this->asked[0]->schemaDefault);
		$this->assertSame('behandelaar', $this->asked[0]->userId);
		$this->assertSame(['zaak', 'assigned', ['register:zaken']], [$this->asked[0]->topic->schemaSlug, $this->asked[0]->topic->notificationKey, $this->asked[0]->topic->scopes]);
	}//end testTheGroupDefaultIsNamedAsTheDecidingLayer()

	public function testAForcedChannelIsReportedForcedWithItsReasonForARecipientWhoSwitchedItOff(): void {
		$this->merged(['enabled' => false, 'channels' => [], 'source' => 'user-override', 'scope' => 'global', 'layers' => []]);
		$declaration = ['forcedChannels' => ['channels' => ['email'], 'reason' => 'statutory notice']];

		$answer = $this->service(schemas: [$this->schema('besluit', ['decided' => $declaration])])
			->answer(userId: 'aanvrager', schemaSlug: 'besluit', notificationKey: 'decided');

		$this->assertTrue($answer['enabled']);
		$this->assertSame(['email'], $answer['channels']);
		$this->assertTrue($answer['forced']);
		$this->assertSame('statutory notice', $answer['reason']);
		$this->assertSame(ForcedChannelPolicy::LAYER, $answer['layer']);
	}//end testAForcedChannelIsReportedForcedWithItsReasonForARecipientWhoSwitchedItOff()

	public function testAnInternalKindKeepsOnlyChannelsThatStayInside(): void {
		$this->merged(['enabled' => true, 'channels' => ['nc-notification', 'email'], 'source' => 'schema-default', 'scope' => 'global', 'layers' => []]);

		$answer = $this->service(schemas: [$this->schema('notitie', ['added' => ['internalOnly' => true]])])
			->answer(userId: 'collega', schemaSlug: 'notitie', notificationKey: 'added');

		$this->assertTrue($answer['internalOnly']);
		$this->assertSame(['nc-notification'], $answer['channels']);
		$this->assertSame('', $answer['refusal']);
	}//end testAnInternalKindKeepsOnlyChannelsThatStayInside()

	public function testTheFirstSchemaWithTheSlugThatDeclaresTheKindIsRead(): void {
		$this->merged(['enabled' => true, 'channels' => ['email'], 'source' => 'schema-default', 'scope' => 'global', 'layers' => []]);
		$schemas = [
			$this->schema('other', ['assigned' => ['channels' => [['type' => 'webhook']]]]),
			$this->schema('zaak', []),
			$this->schema('zaak', ['assigned' => ['channels' => [['type' => 'email']]]]),
		];

		$this->service(schemas: $schemas)->answer(userId: 'u', schemaSlug: 'zaak', notificationKey: 'assigned');

		$this->assertSame(['channels' => [['type' => 'email']]], $this->asked[0]->schemaDefault);
	}//end testTheFirstSchemaWithTheSlugThatDeclaresTheKindIsRead()

	public function testAnUndeclaredKindHasNoAnswerAndNothingIsResolved(): void {
		$this->preferences->expects($this->never())->method('resolve');

		$service = $this->service(schemas: [$this->schema('zaak', ['assigned' => 'not a declaration'])]);

		$this->assertNull($service->answer(userId: 'u', schemaSlug: 'zaak', notificationKey: 'assigned'));
		$this->assertNull($service->answer(userId: 'u', schemaSlug: 'zaak', notificationKey: 'closed'));
		$this->assertNull($service->answer(userId: 'u', schemaSlug: 'besluit', notificationKey: 'assigned'));
	}//end testAnUndeclaredKindHasNoAnswerAndNothingIsResolved()

	public function testASchemaReadThatFailsIsLoggedAndHasNoAnswer(): void {
		$mapper = $this->createMock(SchemaMapperInterface::class);
		$mapper->method('findAll')->willThrowException(new RuntimeException('database gone'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with('[RecipientKindAnswer] schema enumeration failed: database gone');

		$service = new RecipientKindAnswer(schemaMapper: $mapper, preferences: $this->preferences, policy: new ForcedChannelPolicy(), logger: $logger);

		$this->assertNull($service->answer(userId: 'u', schemaSlug: 'zaak', notificationKey: 'assigned'));
	}//end testASchemaReadThatFailsIsLoggedAndHasNoAnswer()

	/**
	 * Let the merge answer with one resolved preference, recording what it was asked.
	 *
	 * @param array<string, mixed> $resolved The merged preference.
	 *
	 * @return void
	 */
	private function merged(array $resolved): void {
		$this->preferences->method('resolve')->willReturnCallback(
			function (PreferenceResolution $resolution) use ($resolved): array {
				$this->asked[] = $resolution;
				return $resolved;
			}
		);
	}//end merged()

	/**
	 * A schema double with a slug and its notification declarations.
	 *
	 * @param string               $slug          The slug.
	 * @param array<string, mixed> $notifications The `x-openregister-notifications` map.
	 *
	 * @return Schema&MockObject
	 */
	private function schema(string $slug, array $notifications): Schema {
		$schema = $this->getMockBuilder(Schema::class)->disableOriginalConstructor()->addMethods(['getSlug', 'getId'])->onlyMethods(['getConfiguration'])->getMock();
		$schema->method('getSlug')->willReturn($slug);
		$schema->method('getId')->willReturn(1);
		$schema->method('getConfiguration')->willReturn(['x-openregister-notifications' => $notifications]);

		return $schema;
	}//end schema()

	/**
	 * The service over a schema list, the merge double and the real policy.
	 *
	 * @param array<int, Schema> $schemas The schemas.
	 *
	 * @return RecipientKindAnswer
	 */
	private function service(array $schemas): RecipientKindAnswer {
		$mapper = $this->createMock(SchemaMapperInterface::class);
		$mapper->method('findAll')->willReturn($schemas);

		return new RecipientKindAnswer(
			schemaMapper: $mapper,
			preferences: $this->preferences,
			policy: new ForcedChannelPolicy(),
			logger: $this->createMock(LoggerInterface::class)
		);
	}//end service()
}//end class
