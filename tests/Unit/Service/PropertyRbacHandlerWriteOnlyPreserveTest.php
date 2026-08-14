<?php

declare(strict_types=1);

/**
 * Save-side write-only preservation tests (openregister#463).
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 * @license  EUPL-1.2
 * @link     https://github.com/OpenRegister/OpenRegister
 */

namespace Unit\Service;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use OCP\IGroupManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The save-side half of the writeOnly contract (openregister#463).
 *
 * `writeOnly` strips a secret from every read. Nothing restored it on save, so the
 * natural client round-trip — GET the object, edit a field, PUT it back — re-sent a
 * body without the secret and the PUT-semantic null-fill destroyed it. These tests
 * pin the preserve rule that closes that, and in particular pin the two directions it
 * must NOT over-reach: a new value must still overwrite, and a sibling edit under the
 * same parent must still land.
 */
class PropertyRbacHandlerWriteOnlyPreserveTest extends TestCase {
	private PropertyRbacHandler $handler;
	private IUserSession&MockObject $userSession;
	private IGroupManager&MockObject $groupManager;
	private ConditionMatcher&MockObject $conditionMatcher;
	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		parent::setUp();

		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->conditionMatcher = $this->createMock(ConditionMatcher::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->handler = new PropertyRbacHandler(
			$this->userSession,
			$this->groupManager,
			$this->conditionMatcher,
			$this->logger
		);
	}

	/**
	 * A schema with a top-level write-only secret, as `credential` has.
	 */
	private function topLevelSchema(): Schema {
		$schema = new Schema();
		$schema->setProperties(
			[
				'name' => ['type' => 'string'],
				'apiToken' => ['type' => 'string', 'writeOnly' => true],
			]
		);

		return $schema;
	}

	/**
	 * A schema shaped like an OpenConnector Source: an untyped `configuration`
	 * object holding both a nested secret and ordinary operator-editable settings.
	 */
	private function nestedSchema(): Schema {
		$schema = new Schema();
		$schema->setProperties(
			[
				'name' => ['type' => 'string'],
				'configuration' => ['type' => 'object'],
			]
		);
		$schema->setConfiguration(
			[
				Schema::WRITEONLY_PATHS_ANNOTATION => [
					'configuration.authentication.client_secret',
				],
			]
		);

		return $schema;
	}

	/**
	 * Run the full preserve rule the way SaveObject::prepareObjectForUpdate() does:
	 * detect omissions against the RAW incoming payload, then restore from the stored
	 * object. Keeping both halves together here is deliberate — the contract only
	 * means anything as a pair.
	 */
	private function preserve(Schema $schema, array $incoming, array $stored): array {
		$omitted = $this->handler->collectOmittedWriteOnlyPaths(schema: $schema, incoming: $incoming);

		return $this->handler->restoreWriteOnlyValues(
			prepared: $incoming,
			stored: $stored,
			omittedPaths: $omitted
		);
	}

	// ── The core fix: an omitted secret survives ──

	public function testOmittedTopLevelWriteOnlyPropertyIsPreserved(): void {
		$stored = ['name' => 'prod', 'apiToken' => 's3cr3t'];
		$incoming = ['name' => 'prod-renamed'];

		$result = $this->preserve($this->topLevelSchema(), $incoming, $stored);

		$this->assertSame('s3cr3t', $result['apiToken'], 'An omitted write-only secret must survive the save.');
		$this->assertSame('prod-renamed', $result['name'], 'The edited field must still land.');
	}

	public function testOmittedNestedWriteOnlyPathIsPreserved(): void {
		$stored = [
			'name' => 'src',
			'configuration' => [
				'endpoint' => 'https://api.example.gov',
				'authentication' => ['username' => 'svc', 'client_secret' => 's3cr3t'],
			],
		];
		$incoming = [
			'name' => 'src',
			'configuration' => [
				'endpoint' => 'https://api.example.gov',
				'authentication' => ['username' => 'svc'],
			],
		];

		$result = $this->preserve($this->nestedSchema(), $incoming, $stored);

		$this->assertSame(
			's3cr3t',
			$result['configuration']['authentication']['client_secret'],
			'An omitted nested write-only secret must survive the save.'
		);
	}

	/**
	 * The subtle half of the nested case. Restoring the stored `configuration`
	 * wholesale would bring the secret back but silently revert the operator's edit —
	 * trading one data-loss bug for another. The preserved leaf must merge INTO the
	 * incoming sub-tree.
	 */
	public function testSiblingEditsUnderTheSameParentStillLandWhileSecretSurvives(): void {
		$stored = [
			'configuration' => [
				'endpoint' => 'https://old.example.gov',
				'authentication' => ['username' => 'old-user', 'client_secret' => 's3cr3t'],
			],
		];
		// The operator edits a sibling of the secret AND a sibling of its parent.
		$incoming = [
			'configuration' => [
				'endpoint' => 'https://new.example.gov',
				'authentication' => ['username' => 'new-user'],
			],
		];

		$result = $this->preserve($this->nestedSchema(), $incoming, $stored);

		$this->assertSame('s3cr3t', $result['configuration']['authentication']['client_secret']);
		$this->assertSame(
			'https://new.example.gov',
			$result['configuration']['endpoint'],
			'A sibling edit to configuration must not be clobbered by the preserve.'
		);
		$this->assertSame(
			'new-user',
			$result['configuration']['authentication']['username'],
			'A sibling edit alongside the secret must not be clobbered by the preserve.'
		);
	}

	// ── The #1 regression risk: secrets must stay settable ──

	public function testNewTopLevelValueStillOverwrites(): void {
		$stored = ['name' => 'prod', 'apiToken' => 'old-secret'];
		$incoming = ['name' => 'prod', 'apiToken' => 'brand-new-secret'];

		$result = $this->preserve($this->topLevelSchema(), $incoming, $stored);

		$this->assertSame(
			'brand-new-secret',
			$result['apiToken'],
			'A preserve rule that is too eager makes secrets unsettable.'
		);
	}

	public function testNewNestedValueStillOverwrites(): void {
		$stored = [
			'configuration' => ['authentication' => ['client_secret' => 'old-secret']],
		];
		$incoming = [
			'configuration' => ['authentication' => ['client_secret' => 'rotated-secret']],
		];

		$result = $this->preserve($this->nestedSchema(), $incoming, $stored);

		$this->assertSame('rotated-secret', $result['configuration']['authentication']['client_secret']);
	}

	// ── absent vs explicit null ──

	/**
	 * The deliberate asymmetry. If explicit null were preserved too, a secret could be
	 * set and rotated but never removed — a decommissioned credential you cannot delete.
	 */
	public function testExplicitNullClearsTopLevelSecret(): void {
		$stored = ['name' => 'prod', 'apiToken' => 's3cr3t'];
		$incoming = ['name' => 'prod', 'apiToken' => null];

		$result = $this->preserve($this->topLevelSchema(), $incoming, $stored);

		$this->assertArrayHasKey('apiToken', $result);
		$this->assertNull($result['apiToken'], 'An explicit null must clear the secret, not preserve it.');
	}

	public function testExplicitNullClearsNestedSecret(): void {
		$stored = [
			'configuration' => ['authentication' => ['client_secret' => 's3cr3t']],
		];
		$incoming = [
			'configuration' => ['authentication' => ['client_secret' => null]],
		];

		$result = $this->preserve($this->nestedSchema(), $incoming, $stored);

		$this->assertNull($result['configuration']['authentication']['client_secret']);
	}

	/**
	 * An empty string is a value the client deliberately sent, so it is not an omission
	 * and is passed through untouched. (SaveObject::sanitizeEmptyStringsForObjectProperties
	 * subsequently normalises it, which is what actually clears the column — this test
	 * pins only that the preserve rule keeps its hands off.)
	 */
	public function testEmptyStringIsNotTreatedAsAnOmission(): void {
		$stored = ['apiToken' => 's3cr3t'];
		$incoming = ['apiToken' => ''];

		$result = $this->preserve($this->topLevelSchema(), $incoming, $stored);

		$this->assertSame('', $result['apiToken'], 'An empty string is a sent value, not an omission.');
	}

	// ── Fail-safe / edge behaviour ──

	public function testCreatePathHasNothingToPreserveAndDoesNotCrash(): void {
		$incoming = ['name' => 'brand-new'];

		// A create has no stored object at all.
		$result = $this->preserve($this->topLevelSchema(), $incoming, []);

		$this->assertSame(['name' => 'brand-new'], $result);
		$this->assertArrayNotHasKey(
			'apiToken',
			$result,
			'A create must not invent a write-only key that was never stored.'
		);
	}

	public function testNeverStoredSecretIsNotInvented(): void {
		$stored = ['name' => 'prod'];
		$incoming = ['name' => 'prod-renamed'];

		$result = $this->preserve($this->topLevelSchema(), $incoming, $stored);

		$this->assertArrayNotHasKey('apiToken', $result);
	}

	public function testSchemaWithoutWriteOnlyDeclarationsIsUntouched(): void {
		$schema = new Schema();
		$schema->setProperties(['name' => ['type' => 'string']]);

		$incoming = ['name' => 'x'];
		$result = $this->preserve($schema, $incoming, ['name' => 'y', 'other' => 'z']);

		$this->assertSame($incoming, $result, 'Opt-in only: an undeclared schema must be passed through verbatim.');
	}

	/**
	 * The payload dropped the whole parent block. The secret still has to come back,
	 * which means creating the intermediate segment to hold it.
	 */
	public function testOmittedParentBlockStillRestoresTheSecretLeaf(): void {
		$stored = [
			'configuration' => [
				'endpoint' => 'https://api.example.gov',
				'authentication' => ['client_secret' => 's3cr3t'],
			],
		];
		$incoming = ['configuration' => ['endpoint' => 'https://api.example.gov']];

		$result = $this->preserve($this->nestedSchema(), $incoming, $stored);

		$this->assertSame('s3cr3t', $result['configuration']['authentication']['client_secret']);
	}

	/**
	 * The client replaced the sub-tree with a scalar. There is nowhere to put the leaf,
	 * and forcing one would silently discard what the client actually sent.
	 */
	public function testScalarWhereSubTreeExpectedIsLeftAlone(): void {
		$stored = ['configuration' => ['authentication' => ['client_secret' => 's3cr3t']]];
		$incoming = ['configuration' => 'disabled'];

		$result = $this->preserve($this->nestedSchema(), $incoming, $stored);

		$this->assertSame('disabled', $result['configuration']);
	}

	public function testStoredNullIsPreservedAsNullNotSkipped(): void {
		// pathExists uses array_key_exists, so a stored explicit null is a real value
		// that round-trips rather than being misread as "never set".
		$stored = ['name' => 'prod', 'apiToken' => null];
		$incoming = ['name' => 'prod-renamed'];

		$result = $this->preserve($this->topLevelSchema(), $incoming, $stored);

		$this->assertArrayHasKey('apiToken', $result);
		$this->assertNull($result['apiToken']);
	}

	public function testPreserveNeverLogsTheSecretValue(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$handler = new PropertyRbacHandler(
			$this->userSession,
			$this->groupManager,
			$this->conditionMatcher,
			$logger
		);

		$logger->expects($this->any())
			->method('debug')
			->willReturnCallback(
				function (string $message, array $context = []): void {
					$haystack = $message . json_encode($context);
					$this->assertStringNotContainsString(
						's3cr3t',
						$haystack,
						'A secret value must never reach the log.'
					);
				}
			);

		$omitted = $handler->collectOmittedWriteOnlyPaths(
			schema: $this->topLevelSchema(),
			incoming: ['name' => 'prod']
		);
		$handler->restoreWriteOnlyValues(
			prepared: ['name' => 'prod'],
			stored: ['apiToken' => 's3cr3t'],
			omittedPaths: $omitted
		);
	}

	// ── The real-world round-trip: the test that IS the bug ──

	/**
	 * openregister#463 / openconnector#245, reproduced end to end.
	 *
	 * This composes the REAL read-side strip with the new save-side preserve, exactly as
	 * the live flow does: nc-vue's generic CnFormDialog loads an object through the render
	 * boundary (which strips the secret), spreads that form data into its submit body, and
	 * PUTs it back. No client awareness of writeOnly exists anywhere in that path — which
	 * is why the platform has to be the thing that holds the line.
	 *
	 * Using stripWriteOnlyProperties() to GENERATE the payload rather than hand-writing one
	 * is the point: if the strip's notion of "what gets removed" ever drifts from the
	 * preserve's notion of "what gets carried forward", this test fails. That symmetry is
	 * the actual contract.
	 */
	public function testRenderedBodyFedBackAsUpdatePayloadDoesNotDestroyTheSecret(): void {
		$schema = $this->nestedSchema();

		$stored = [
			'name' => 'prod-source',
			'configuration' => [
				'endpoint' => 'https://api.example.gov',
				'authentication' => [
					'username' => 'svc-account',
					'client_secret' => 's3cr3t',
				],
			],
		];

		// 1. The client GETs the object. The render boundary strips the secret.
		$rendered = $this->handler->stripWriteOnlyProperties(schema: $schema, object: $stored);
		$this->assertArrayNotHasKey(
			'client_secret',
			$rendered['configuration']['authentication'],
			'Precondition: the read boundary must strip the secret.'
		);

		// 2. The operator edits one unrelated field in the form.
		$submitted = $rendered;
		$submitted['configuration']['endpoint'] = 'https://new.example.gov';

		// 3. The whole form body is PUT back — without the secret it was never shown.
		$result = $this->preserve($schema, $submitted, $stored);

		// 4. The secret must still be there.
		$this->assertSame(
			's3cr3t',
			$result['configuration']['authentication']['client_secret'],
			'The load/edit/save round-trip must not destroy the stored secret (openregister#463).'
		);
		$this->assertSame('https://new.example.gov', $result['configuration']['endpoint']);
		$this->assertSame('svc-account', $result['configuration']['authentication']['username']);
	}

	/**
	 * The same round-trip for a top-level writeOnly property — the openconnector#245
	 * shape, where a Source form's includeFields carries `apikey`/`secret`/`password`.
	 */
	public function testTopLevelRenderedBodyRoundTripDoesNotDestroyTheSecret(): void {
		$schema = $this->topLevelSchema();
		$stored = ['name' => 'prod', 'apiToken' => 's3cr3t'];

		$rendered = $this->handler->stripWriteOnlyProperties(schema: $schema, object: $stored);
		$this->assertArrayNotHasKey('apiToken', $rendered);

		$submitted = $rendered;
		$submitted['name'] = 'prod-renamed';

		$result = $this->preserve($schema, $submitted, $stored);

		$this->assertSame('s3cr3t', $result['apiToken']);
		$this->assertSame('prod-renamed', $result['name']);
	}
}
