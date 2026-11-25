<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Service\Flow;

use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Service\Flow\FlowWebhookSecrets;
use OCP\IAppConfig;
use OCP\Security\ICrypto;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;

/**
 * Webhook addresses and secrets: a token on save, the secret kept out of the
 * flow document, handed out once, and replaced on rotation.
 *
 * @spec openspec/changes/flow-calendar-and-webhook-triggers/tasks.md#2-incoming-webhook-trigger
 */
class FlowWebhookSecretsTest extends TestCase {

	/**
	 * In-memory app config.
	 *
	 * @var array<string, string>
	 */
	private array $config = [];

	/**
	 * How many values the random source handed out.
	 *
	 * @var integer
	 */
	private int $draws = 0;

	/**
	 * The service over in-memory config, a reversible "encryption" and a counting random source.
	 *
	 * @return FlowWebhookSecrets
	 */
	private function secrets(): FlowWebhookSecrets {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(fn (string $app, string $key, string $default=''): string => ($this->config[$key] ?? $default));
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->config[$key] = $value;
				return true;
			}
		);
		$crypto = $this->createMock(ICrypto::class);
		$crypto->method('encrypt')->willReturnCallback(static fn (string $plain): string => 'enc:' . strrev($plain));
		$crypto->method('decrypt')->willReturnCallback(static fn (string $cipher): string => strrev(substr($cipher, 4)));
		$random = $this->createMock(ISecureRandom::class);
		$random->method('generate')->willReturnCallback(
			function (int $length, string $chars): string {
				$this->draws++;
				return substr(str_repeat(substr($chars, $this->draws % strlen($chars), 1) . 'a', $length), 0, $length);
			}
		);

		return new FlowWebhookSecrets(appConfig: $appConfig, crypto: $crypto, random: $random);
	}//end secrets()

	/**
	 * A flow with one webhook trigger.
	 *
	 * @param array<string, mixed> $config The trigger's config.
	 *
	 * @return Flow
	 */
	private static function flow(array $config): Flow {
		$flow = new Flow();
		$flow->setUuid('flow-1');
		$flow->setNodes([['id' => 'hook', 'type' => 'openregister.trigger-webhook', 'config' => $config], ['id' => 'stop', 'type' => 'end']]);

		return $flow;
	}//end flow()

	public function testASaveGivesTheNodeATokenAndHandsTheSecretOutOnce(): void {
		$secrets = $this->secrets();
		$flow = self::flow(config: ['runAs' => 'betalingen', 'secret' => 'sent-by-a-client']);

		$this->assertSame([], $secrets->stamp(flow: $flow));
		$config = $flow->getNodes()[0]['config'];
		$this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $config['token']);
		$this->assertArrayNotHasKey('secret', $config, 'nothing secret is kept in the document');

		$issued = $secrets->provision(flow: $flow);
		$this->assertSame('/index.php/apps/openregister/api/flows/hooks/' . $config['token'], $issued['hook']['address']);
		$this->assertSame(48, strlen($issued['hook']['secret']));
		$this->assertStringNotContainsString($issued['hook']['secret'], (string)json_encode($flow->jsonSerialize()), 'a read of the flow never returns the secret');
		$this->assertStringNotContainsString($issued['hook']['secret'], implode('', $this->config), 'it is stored encrypted');

		$this->assertSame(['flow' => 'flow-1', 'node' => 'hook', 'secret' => $issued['hook']['secret']], $secrets->find(token: $config['token']));
		$this->assertSame([], $secrets->provision(flow: $flow), 'a second save hands out nothing');
	}//end testASaveGivesTheNodeATokenAndHandsTheSecretOutOnce()

	public function testAnExistingTokenIsKept(): void {
		$flow = self::flow(config: ['runAs' => 'betalingen', 'token' => str_repeat('ab', 16)]);

		$this->secrets()->stamp(flow: $flow);

		$this->assertSame(str_repeat('ab', 16), $flow->getNodes()[0]['config']['token']);
	}//end testAnExistingTokenIsKept()

	public function testRotationReplacesTheSecretAndTakesTheAskOffTheDocument(): void {
		$secrets = $this->secrets();
		$flow = self::flow(config: ['runAs' => 'betalingen', 'token' => str_repeat('ab', 16)]);
		$secrets->stamp(flow: $flow);
		$first = $secrets->provision(flow: $flow)['hook']['secret'];

		$again = self::flow(config: ['runAs' => 'betalingen', 'token' => str_repeat('ab', 16), 'rotateSecret' => true]);
		$rotate = $secrets->stamp(flow: $again);
		$this->assertSame(['hook'], $rotate);
		$this->assertArrayNotHasKey('rotateSecret', $again->getNodes()[0]['config']);

		$second = $secrets->provision(flow: $again, rotate: $rotate)['hook']['secret'];
		$this->assertNotSame($first, $second);
		$this->assertSame($second, $secrets->find(token: str_repeat('ab', 16))['secret'], 'the old secret is no longer the one checked');
	}//end testRotationReplacesTheSecretAndTakesTheAskOffTheDocument()

	public function testATokenCopiedFromAnotherFlowIsReissuedAndTheOriginalKeepsItsTrigger(): void {
		$secrets = $this->secrets();
		$token = str_repeat('ab', 16);
		$original = self::flow(config: ['runAs' => 'betalingen', 'token' => $token]);
		$secrets->stamp(flow: $original);
		$originalSecret = $secrets->provision(flow: $original)['hook']['secret'];

		$copy = self::flow(config: ['runAs' => 'betalingen', 'token' => $token]);
		$copy->setUuid('flow-2');
		$secrets->stamp(flow: $copy);
		$copyToken = $copy->getNodes()[0]['config']['token'];
		$this->assertNotSame($token, $copyToken, 'a token held by another flow is not taken over');
		$this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $copyToken);

		$issued = $secrets->provision(flow: $copy);
		$this->assertSame('/index.php/apps/openregister/api/flows/hooks/' . $copyToken, $issued['hook']['address']);
		$this->assertSame(['flow' => 'flow-1', 'node' => 'hook', 'secret' => $originalSecret], $secrets->find(token: $token), 'the original trigger keeps its address and secret');
		$this->assertSame('flow-2', $secrets->find(token: $copyToken)['flow']);
	}//end testATokenCopiedFromAnotherFlowIsReissuedAndTheOriginalKeepsItsTrigger()

	public function testATokenDuplicatedWithinOneFlowIsReissued(): void {
		$secrets = $this->secrets();
		$token = str_repeat('ab', 16);
		$flow = new Flow();
		$flow->setUuid('flow-1');
		$flow->setNodes([
			['id' => 'hook', 'type' => 'openregister.trigger-webhook', 'config' => ['token' => $token]],
			['id' => 'hook-copy', 'type' => 'openregister.trigger-webhook', 'config' => ['token' => $token]],
		]);

		$secrets->stamp(flow: $flow);
		$nodes = $flow->getNodes();
		$this->assertSame($token, $nodes[0]['config']['token']);
		$this->assertNotSame($token, $nodes[1]['config']['token']);

		$issued = $secrets->provision(flow: $flow);
		$this->assertSame('hook', $secrets->find(token: $token)['node']);
		$this->assertSame('hook-copy', $secrets->find(token: $nodes[1]['config']['token'])['node']);
		$this->assertCount(2, $issued);
	}//end testATokenDuplicatedWithinOneFlowIsReissued()

	public function testProvisionNeverOverwritesAnotherTriggersRecord(): void {
		$secrets = $this->secrets();
		$token = str_repeat('ab', 16);
		$original = self::flow(config: ['token' => $token]);
		$secrets->stamp(flow: $original);
		$originalSecret = $secrets->provision(flow: $original)['hook']['secret'];

		$foreign = self::flow(config: ['token' => $token]);
		$foreign->setUuid('flow-2');

		$this->assertSame([], $secrets->provision(flow: $foreign, rotate: ['hook']));
		$this->assertSame($originalSecret, $secrets->find(token: $token)['secret']);
		$this->assertSame('flow-1', $secrets->find(token: $token)['flow']);
	}//end testProvisionNeverOverwritesAnotherTriggersRecord()

	public function testATokenThatIsNotOursOrMalformedFindsNothing(): void {
		$secrets = $this->secrets();

		$this->assertNull($secrets->find(token: str_repeat('cd', 16)));
		$this->assertNull($secrets->find(token: '../../etc'));
	}//end testATokenThatIsNotOursOrMalformedFindsNothing()
}//end class
