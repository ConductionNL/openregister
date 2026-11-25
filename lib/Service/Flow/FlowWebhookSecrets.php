<?php

/**
 * The addresses and signing secrets of incoming-webhook flow triggers.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The node carries only its address token, which is random and names nothing
 * about the flow. The secret never enters the flow document: it is kept
 * encrypted in app config under the token, so no read or export of a flow can
 * return it. A secret is generated when a node is first saved, or when the
 * save asks for `rotateSecret`, and only then is it handed back, once.
 * Rotating replaces the stored secret, so the old one is refused at once.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-calendar-and-webhook-triggers/tasks.md#2-incoming-webhook-trigger
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Service\Flow\Nodes\TriggerWebhookNode;
use OCP\IAppConfig;
use OCP\Security\ICrypto;
use OCP\Security\ISecureRandom;

/**
 * Stamps tokens, stores secrets encrypted and finds a trigger by its token.
 *
 * @spec openspec/changes/flow-calendar-and-webhook-triggers/tasks.md#2-incoming-webhook-trigger
 */
class FlowWebhookSecrets {

	/**
	 * App id.
	 *
	 * @var string
	 */
	private const APP_ID = 'openregister';

	/**
	 * App config key prefix of a trigger's record, followed by its token.
	 *
	 * @var string
	 */
	private const KEY = 'flow_hook_';

	/**
	 * A token is this many hex characters.
	 *
	 * @var integer
	 */
	private const TOKEN_LENGTH = 32;

	/**
	 * The characters of a token.
	 *
	 * @var string
	 */
	private const HEX = '0123456789abcdef';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig    $appConfig Holds the encrypted secrets.
	 * @param ICrypto       $crypto    Encrypts and decrypts a secret.
	 * @param ISecureRandom $random    Draws tokens and secrets.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly ICrypto $crypto,
		private readonly ISecureRandom $random,
	) {
	}//end __construct()

	/**
	 * Before a save: give every webhook node a token, drop anything secret-shaped
	 * a request sent, and take the rotation asks off the document.
	 *
	 * @param Flow $flow The flow about to be saved.
	 *
	 * @return list<string> The ids of the nodes whose secret is to be rotated.
	 *
	 * @spec openspec/changes/flow-calendar-and-webhook-triggers/tasks.md#2-incoming-webhook-trigger
	 */
	public function stamp(Flow $flow): array {
		$nodes = ($flow->getNodes() ?? []);
		$rotate = [];
		$changed = false;
		$seen = [];
		foreach ($nodes as $index => $node) {
			if (self::isWebhookNode(node: $node) === false) {
				continue;
			}

			$config = (array)($node['config'] ?? []);
			if (($config['rotateSecret'] ?? false) === true) {
				$rotate[] = (string)$node['id'];
			}

			unset($config['rotateSecret'], $config['secret'], $config['secretEncrypted']);
			// A token already held by another trigger (a node copied from another
			// flow, or duplicated within this one) gets a fresh one: the address
			// belongs to the trigger it was issued to, and taking it over would
			// silently break that trigger.
			$token = (string)($config['token'] ?? '');
			if (preg_match('/^[0-9a-f]{' . self::TOKEN_LENGTH . '}$/', $token) !== 1
				|| isset($seen[$token]) === true
				|| $this->heldElsewhere(token: $token, flowId: (string)$flow->getUuid(), nodeId: (string)$node['id']) === true
			) {
				$config['token'] = $this->random->generate(self::TOKEN_LENGTH, self::HEX);
			}

			$seen[(string)$config['token']] = true;

			if ($config !== ($node['config'] ?? null)) {
				$nodes[$index]['config'] = $config;
				$changed = true;
			}
		}

		if ($changed === true) {
			$flow->setNodes($nodes);
		}

		return $rotate;
	}//end stamp()

	/**
	 * After a save: store a secret for every webhook node that has none or is
	 * being rotated, and hand back the new ones, once.
	 *
	 * @param Flow         $flow   The saved flow.
	 * @param list<string> $rotate The nodes whose secret is replaced.
	 *
	 * @return array<string, array{address: string, secret: string}> The new secrets by node id.
	 *
	 * @spec openspec/changes/flow-calendar-and-webhook-triggers/specs/flow-engine/spec.md#scenario-the-secret-is-shown-once-and-can-be-rotated
	 */
	public function provision(Flow $flow, array $rotate=[]): array {
		$issued = [];
		foreach (($flow->getNodes() ?? []) as $node) {
			if (self::isWebhookNode(node: $node) === false) {
				continue;
			}

			$nodeId = (string)$node['id'];
			$token = (string)($node['config']['token'] ?? '');
			if ($token === '') {
				continue;
			}

			// Never overwrite the record of another trigger: its address and
			// secret stay its own. stamp() re-issues such a token before the
			// save, so this only guards a save that skipped it.
			if ($this->heldElsewhere(token: $token, flowId: (string)$flow->getUuid(), nodeId: $nodeId) === true) {
				continue;
			}

			$known = $this->find(token: $token);
			$ours = ($known !== null && $known['flow'] === (string)$flow->getUuid() && $known['node'] === $nodeId);
			if ($ours === true && in_array($nodeId, $rotate, true) === false) {
				continue;
			}

			$secret = $this->random->generate(48, ISecureRandom::CHAR_ALPHANUMERIC);
			$record = ['flow' => (string)$flow->getUuid(), 'node' => $nodeId, 'secret' => $this->crypto->encrypt($secret)];
			$this->appConfig->setValueString(self::APP_ID, self::KEY . $token, (string)json_encode($record), sensitive: true);
			$issued[$nodeId] = ['address' => self::address(token: $token), 'secret' => $secret];
		}

		return $issued;
	}//end provision()

	/**
	 * The trigger a token addresses, with its secret in clear, or null.
	 *
	 * @param string $token The address token.
	 *
	 * @return array{flow: string, node: string, secret: string}|null
	 *
	 * @spec openspec/changes/flow-calendar-and-webhook-triggers/tasks.md#2-incoming-webhook-trigger
	 */
	public function find(string $token): ?array {
		if (preg_match('/^[0-9a-f]{' . self::TOKEN_LENGTH . '}$/', $token) !== 1) {
			return null;
		}

		$record = json_decode($this->appConfig->getValueString(self::APP_ID, self::KEY . $token, ''), true);
		if (is_array($record) === false || is_string($record['secret'] ?? null) === false) {
			return null;
		}

		try {
			$secret = $this->crypto->decrypt($record['secret']);
		} catch (\Throwable $e) {
			return null;
		}

		return ['flow' => (string)($record['flow'] ?? ''), 'node' => (string)($record['node'] ?? ''), 'secret' => $secret];
	}//end find()

	/**
	 * The path a token's calls are posted to.
	 *
	 * @param string $token The address token.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/flow-calendar-and-webhook-triggers/tasks.md#2-incoming-webhook-trigger
	 */
	public static function address(string $token): string {
		return '/index.php/apps/openregister/api/flows/hooks/' . $token;
	}//end address()

	/**
	 * Whether a token is stored for a trigger other than this flow's node.
	 *
	 * Reads the record's owner without decrypting its secret, so a record whose
	 * secret cannot be read still counts as held.
	 *
	 * @param string $token  The address token.
	 * @param string $flowId The uuid of the flow being saved.
	 * @param string $nodeId The id of the node carrying the token.
	 *
	 * @return bool
	 */
	private function heldElsewhere(string $token, string $flowId, string $nodeId): bool {
		$record = json_decode($this->appConfig->getValueString(self::APP_ID, self::KEY . $token, ''), true);
		if (is_array($record) === false) {
			return false;
		}

		return (string)($record['flow'] ?? '') !== $flowId || (string)($record['node'] ?? '') !== $nodeId;
	}//end heldElsewhere()

	/**
	 * Whether a node is an incoming-webhook trigger with an id.
	 *
	 * @param mixed $node A node of the flow.
	 *
	 * @return bool
	 */
	private static function isWebhookNode(mixed $node): bool {
		return is_array($node) === true
			&& ($node['type'] ?? null) === TriggerWebhookNode::TYPE
			&& trim((string)($node['id'] ?? '')) !== '';
	}//end isWebhookNode()
}//end class
