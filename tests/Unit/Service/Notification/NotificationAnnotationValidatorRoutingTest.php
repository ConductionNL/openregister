<?php

declare(strict_types=1);

namespace Unit\Service\Notification;

use OCA\OpenRegister\Service\Notification\NotificationAnnotationValidator;
use PHPUnit\Framework\TestCase;

/**
 * Covers the save-time refusals for the routing dialect: the role recipient,
 * the transports block and the scope domain.
 *
 * Each of these is a rule that reaches nobody or calls nothing. Refusing them
 * at save is the only moment anybody is looking; the alternative is finding out
 * at midnight, from the absence of a message.
 */
class NotificationAnnotationValidatorRoutingTest extends TestCase {
	private NotificationAnnotationValidator $validator;

	protected function setUp(): void {
		parent::setUp();
		$this->validator = new NotificationAnnotationValidator();
	}

	/**
	 * Wrap one rule in a schema the validator will accept the shape of.
	 *
	 * @param array<string, mixed> $rule The notification rule.
	 * @param array<string, mixed>|null $authorization The schema's authorization block.
	 *
	 * @return array<string, mixed> The schema.
	 */
	private function schemaWith(array $rule, ?array $authorization = null): array {
		$schema = [
			'properties' => ['title' => ['type' => 'string']],
			'x-openregister-notifications' => ['termijn' => $rule],
		];
		if ($authorization !== null) {
			$schema['authorization'] = $authorization;
		}

		return $schema;
	}

	/**
	 * The error codes a schema produces.
	 *
	 * @param array<string, mixed> $schema The schema.
	 *
	 * @return array<int, string> The codes.
	 */
	private function codesFor(array $schema): array {
		return array_column($this->validator->validate($schema), 'code');
	}

	/**
	 * A role the schema assigns is accepted.
	 */
	public function testAnAssignedRoleIsAccepted(): void {
		$codes = $this->codesFor(
			$this->schemaWith(
				[
					'trigger' => ['type' => 'updated'],
					'channels' => ['nc-notification'],
					'recipients' => [['kind' => 'role', 'role' => 'behandelaar']],
					'subject' => 'termijn',
				],
				['roles' => ['behandelaar' => ['behandelaars']]]
			)
		);

		$this->assertSame([], $codes);
	}

	/**
	 * A role the schema does not assign is refused, naming what is declared.
	 */
	public function testAnUnassignedRoleIsRefused(): void {
		$codes = $this->codesFor(
			$this->schemaWith(
				[
					'trigger' => ['type' => 'updated'],
					'channels' => ['nc-notification'],
					'recipients' => [['kind' => 'role', 'role' => 'toezichthouder']],
					'subject' => 'termijn',
				],
				['roles' => ['behandelaar' => ['behandelaars']]]
			)
		);

		$this->assertContains('notification-recipient-role-unknown', $codes);
	}

	/**
	 * A role entry naming no role is refused.
	 */
	public function testARoleWithNoNameIsRefused(): void {
		$codes = $this->codesFor(
			$this->schemaWith(
				[
					'trigger' => ['type' => 'updated'],
					'channels' => ['nc-notification'],
					'recipients' => [['kind' => 'role']],
					'subject' => 'termijn',
				],
				['roles' => ['behandelaar' => ['behandelaars']]]
			)
		);

		$this->assertContains('notification-recipient-role-missing', $codes);
	}

	/**
	 * A schema with no role assignment at all does not refuse every role: the
	 * assignment may live on a register this validator is not given.
	 */
	public function testARoleIsAcceptedWhenTheSchemaDeclaresNoAssignment(): void {
		$codes = $this->codesFor(
			$this->schemaWith(
				[
					'trigger' => ['type' => 'updated'],
					'channels' => ['nc-notification'],
					'recipients' => [['kind' => 'role', 'role' => 'behandelaar']],
					'subject' => 'termijn',
				]
			)
		);

		$this->assertSame([], $codes);
	}

	/**
	 * An outbound transport naming no handler is refused: it is an integration
	 * nobody would notice was missing.
	 */
	public function testAnOutboundTransportWithNoHandlerIsRefused(): void {
		$codes = $this->codesFor(
			$this->schemaWith(
				[
					'trigger' => ['type' => 'updated'],
					'channels' => ['nc-notification'],
					'recipients' => [['kind' => 'users', 'users' => ['anna']]],
					'transports' => [['kind' => 'outbound']],
					'subject' => 'termijn',
				]
			)
		);

		$this->assertContains('notification-transport-no-handler', $codes);
	}

	/**
	 * A transport kind the engine does not have is refused, naming what it has.
	 */
	public function testAnUnknownTransportKindIsRefused(): void {
		$codes = $this->codesFor(
			$this->schemaWith(
				[
					'trigger' => ['type' => 'updated'],
					'channels' => ['nc-notification'],
					'recipients' => [['kind' => 'users', 'users' => ['anna']]],
					'transports' => [['kind' => 'carrier-pigeon']],
					'subject' => 'termijn',
				]
			)
		);

		$this->assertContains('notification-transport-bad-kind', $codes);
	}

	/**
	 * A well-formed outbound transport is accepted.
	 */
	public function testAWellFormedTransportIsAccepted(): void {
		$codes = $this->codesFor(
			$this->schemaWith(
				[
					'trigger' => ['type' => 'updated'],
					'channels' => ['nc-notification'],
					'recipients' => [['kind' => 'users', 'users' => ['anna']]],
					'transports' => [['kind' => 'outbound', 'handler' => 'OCA\\Dossiq\\Zgw\\ZgwTransport']],
					'subject' => 'termijn',
				]
			)
		);

		$this->assertSame([], $codes);
	}

	/**
	 * A domain that is not a non-empty string is refused, so a typo cannot
	 * quietly become a scope nobody ever pins a preference to.
	 */
	public function testAMalformedDomainIsRefused(): void {
		$codes = $this->codesFor(
			$this->schemaWith(
				[
					'trigger' => ['type' => 'updated'],
					'channels' => ['nc-notification'],
					'recipients' => [['kind' => 'users', 'users' => ['anna']]],
					'domain' => ['vergunningen'],
					'subject' => 'termijn',
				]
			)
		);

		$this->assertContains('notification-domain-malformed', $codes);
	}

	/**
	 * A declared domain is accepted.
	 */
	public function testADeclaredDomainIsAccepted(): void {
		$codes = $this->codesFor(
			$this->schemaWith(
				[
					'trigger' => ['type' => 'updated'],
					'channels' => ['nc-notification'],
					'recipients' => [['kind' => 'users', 'users' => ['anna']]],
					'domain' => 'vergunningen',
					'subject' => 'termijn',
				]
			)
		);

		$this->assertSame([], $codes);
	}
}
