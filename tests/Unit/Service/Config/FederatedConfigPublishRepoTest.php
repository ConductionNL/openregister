<?php

/**
 * Publishing keeps a repository's topics and can stay out of the user namespace.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Config
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/federated-config-sharing/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Config;

use OCA\OpenRegister\Service\Config\BrokeredPublishTarget;
use OCA\OpenRegister\Service\Config\BundleSigner;
use OCA\OpenRegister\Service\Config\ConfigPublishRequest;
use OCA\OpenRegister\Service\Config\ConfigPublishTarget;
use OCA\OpenRegister\Service\Config\FederatedConfigService;
use OCA\OpenRegister\Service\Config\FederatedConfigTransport;
use OCA\OpenRegister\Service\Config\IShareableConfigType;
use OCA\OpenRegister\Service\Config\ShareableConfigTypeRegistry;
use OCA\OpenRegister\Service\Credential\CredentialBrokerService;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[CoversClass(FederatedConfigService::class)]
#[CoversClass(ConfigPublishTarget::class)]
#[UsesClass(BrokeredPublishTarget::class)]
#[UsesClass(ConfigPublishRequest::class)]
#[UsesClass(FederatedConfigTransport::class)]
final class FederatedConfigPublishRepoTest extends TestCase {

	/**
	 * The broker calls made, as `METHOD path` => body.
	 *
	 * @var list<array{0: string, 1: string}>
	 */
	private array $calls = [];

	/**
	 * Publish one bundle against a broker that answers from $answers (`METHOD path` => answer or 'throw').
	 *
	 * @param array<string, array<string, mixed>|string> $answers The scripted answers; anything else answers 201.
	 * @param ConfigPublishTarget                        $target  The publish target.
	 *
	 * @return void
	 */
	private function publish(array $answers, ConfigPublishTarget $target): void {
		$broker = $this->createMock(CredentialBrokerService::class);
		$broker->method('request')->willReturnCallback(
			function (string $credentialId, string $appId, string $method, string $path, array $headers = [], ?string $body = null) use ($answers): array {
				$this->calls[] = [$method . ' ' . $path, (string)$body];
				$answer = ($answers[$method . ' ' . $path] ?? ['status' => 201, 'body' => '{}']);
				if ($answer === 'throw') {
					throw new \RuntimeException('down');
				}

				return $answer;
			}
		);
		$signer = $this->createMock(BundleSigner::class);
		$signer->method('sign')->willReturnArgument(0);
		$registry = $this->createMock(ShareableConfigTypeRegistry::class);
		$registry->method('get')->willReturn($this->type());

		$service = new FederatedConfigService(
			new FederatedConfigTransport($broker, $signer, $this->createMock(IClientService::class)),
			$registry,
			$this->createMock(IAppConfig::class),
			$this->createMock(LoggerInterface::class)
		);
		$service->publish('demo.type', ['x' => 1], $target, 'cred-1');
	}//end publish()

	/**
	 * A shareable type whose discovery topic is `demo-topic`.
	 *
	 * @return IShareableConfigType
	 */
	private function type(): IShareableConfigType {
		return new class implements IShareableConfigType {
			public function getId(): string {
				return 'demo.type';
			}

			public function getDisplayName(): string {
				return 'Demo';
			}

			public function getTopic(): string {
				return 'demo-topic';
			}

			public function serialise(array $selection): array {
				return ['type' => 'demo.type'];
			}

			public function deserialise(array $bundle): array {
				return [];
			}
		};
	}//end type()

	/**
	 * The body of the one call to `METHOD path`.
	 *
	 * @param string $call The call.
	 *
	 * @return string|null
	 */
	private function bodyOf(string $call): ?string {
		foreach ($this->calls as [$made, $body]) {
			if ($made === $call) {
				return $body;
			}
		}

		return null;
	}//end bodyOf()

	/**
	 * Publishing into a repository with topics writes the union, sorted, without dropping any.
	 *
	 * @return void
	 */
	public function testTheTypeTopicIsAddedToTheExistingTopics(): void {
		$this->publish(
			[
				'GET /repos/o/r' => ['status' => 200],
				'GET /repos/o/r/topics' => ['status' => 200, 'body' => '{"names":["openbuild-app","demo-topic","zeta"]}'],
			],
			new ConfigPublishTarget('o/r', 'set.json')
		);

		self::assertSame('{"names":["demo-topic","openbuild-app","zeta"]}', $this->bodyOf('PUT /repos/o/r/topics'));
	}//end testTheTypeTopicIsAddedToTheExistingTopics()

	/**
	 * Topics that cannot be read count as none: the type's topic is still set.
	 *
	 * @return void
	 */
	public function testUnreadableTopicsCountAsNone(): void {
		$this->publish(['GET /repos/o/r' => ['status' => 200], 'GET /repos/o/r/topics' => 'throw'], new ConfigPublishTarget('o/r', 'set.json'));
		self::assertSame('{"names":["demo-topic"]}', $this->bodyOf('PUT /repos/o/r/topics'));

		$this->calls = [];
		$this->publish(['GET /repos/o/r' => ['status' => 200], 'GET /repos/o/r/topics' => ['status' => 404, 'body' => '{"names":["x"]}']], new ConfigPublishTarget('o/r', 'set.json'));
		self::assertSame('{"names":["demo-topic"]}', $this->bodyOf('PUT /repos/o/r/topics'));
	}//end testUnreadableTopicsCountAsNone()

	/**
	 * By default a failed organisation create falls back to the user namespace.
	 *
	 * @return void
	 */
	public function testAFailedOrganisationCreateFallsBackToTheUserNamespace(): void {
		$this->publish(['GET /repos/o/r' => ['status' => 404], 'POST /orgs/o/repos' => ['status' => 422]], new ConfigPublishTarget('o/r', 'set.json'));

		self::assertSame('{"name":"r","private":false,"auto_init":true}', $this->bodyOf('POST /user/repos'));
	}//end testAFailedOrganisationCreateFallsBackToTheUserNamespace()

	/**
	 * A target without the fallback never creates the repository under the user namespace.
	 *
	 * @return void
	 */
	public function testATargetWithoutTheFallbackStaysInTheOrganisation(): void {
		$target = (new ConfigPublishTarget('o/r', 'set.json', 'main', true))->withoutUserNamespaceFallback();
		$this->publish(['GET /repos/o/r' => ['status' => 404], 'POST /orgs/o/repos' => ['status' => 422]], $target);

		self::assertSame('{"name":"r","private":true,"auto_init":true}', $this->bodyOf('POST /orgs/o/repos'));
		self::assertNull($this->bodyOf('POST /user/repos'));
	}//end testATargetWithoutTheFallbackStaysInTheOrganisation()

	/**
	 * Switching the fallback off returns a copy and leaves the original target as it was.
	 *
	 * @return void
	 */
	public function testSwitchingTheFallbackOffKeepsTheOriginal(): void {
		$target = new ConfigPublishTarget('o/r', 'set.json', 'dev', true);
		$strict = $target->withoutUserNamespaceFallback();

		self::assertTrue($target->allowsUserNamespaceFallback());
		self::assertFalse($strict->allowsUserNamespaceFallback());
		self::assertSame(['o/r', 'set.json', 'dev', true], [$strict->repo, $strict->path, $strict->branch, $strict->private]);
	}//end testSwitchingTheFallbackOffKeepsTheOriginal()
}//end class
