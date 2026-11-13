<?php

/**
 * FlowNodeRightsGuard: a powerful step needs a right to be put in a flow.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
 * @spec openspec/changes/flow-powerful-steps-need-a-right/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use OCP\IAppConfig;
use OCP\IUser;
use Throwable;

/**
 * Resolves the right a step needs and refuses an author who lacks it.
 *
 * The administrator's map (app config `flow_node_rights`) wins over a node's
 * own declaration, so an administrator can restrict a step another app ships
 * without waiting for that app, and can lift a built-in node's right with
 * `null`. The check runs on every save path; the palette's `locked` is advice.
 *
 * Administrators pass, as they do for every action right: FlowAccess::may()
 * answers yes for them.
 */
class FlowNodeRightsGuard implements FlowNodeRightsGuardInterface {

	/**
	 * The app id the map is stored under.
	 *
	 * @var string
	 */
	private const APP_ID = 'openregister';

	/**
	 * The app config key holding the administrator's map.
	 *
	 * @var string
	 */
	public const CONFIG_KEY = 'flow_node_rights';

	/**
	 * Constructor.
	 *
	 * @param FlowNodeRegistryInterface $nodes     The registered step types.
	 * @param FlowAccessInterface       $access    Whether a user holds a right.
	 * @param IAppConfig                $appConfig Holds the administrator's map.
	 */
	public function __construct(
		private readonly FlowNodeRegistryInterface $nodes,
		private readonly FlowAccessInterface $access,
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * The right a step of this type and configuration needs, or null.
	 *
	 * @param string               $type   The step type.
	 * @param array<string, mixed> $config The step's configuration.
	 *
	 * @return string|null The right, or null when it needs none.
	 *
	 * @spec openspec/changes/flow-powerful-steps-need-a-right/specs/flow-engine/spec.md#requirement-a-flow-step-type-can-require-a-named-right
	 */
	public function rightFor(string $type, array $config): ?string {
		$map = $this->adminMap();
		if (array_key_exists($type, $map) === true) {
			return $map[$type];
		}

		$node = $this->node(type: $type);
		if (($node instanceof IFlowNodeRequiresRight) === false) {
			return null;
		}

		return $node->requiredRight(config: $config);
	}//end rightFor()

	/**
	 * Refuse unless the user holds the right of every step in the new and the stored document.
	 *
	 * The stored document counts because editing a powerful flow one could not
	 * have built is how the restriction would otherwise be walked round.
	 *
	 * @param IUser                     $user     The author.
	 * @param array<string, mixed>      $document The document being saved.
	 * @param array<string, mixed>|null $stored   The stored document, or null.
	 *
	 * @return void
	 *
	 * @throws FlowNodeRightRefused For the first step whose right the user lacks.
	 *
	 * @spec openspec/changes/flow-powerful-steps-need-a-right/specs/flow-engine/spec.md#requirement-a-flow-with-a-restricted-step-is-saved-only-by-someone-who-holds-its-right
	 */
	public function assertMayAuthor(IUser $user, array $document, ?array $stored=null): void {
		$held = [];
		foreach ([$document, ($stored ?? [])] as $doc) {
			foreach ($this->steps(document: $doc) as $step) {
				$right = $this->rightFor(type: $step['type'], config: $step['config']);
				if ($right === null) {
					continue;
				}

				$held[$right] = ($held[$right] ?? $this->access->may(user: $user, action: $right));
				if ($held[$right] === false) {
					throw new FlowNodeRightRefused(nodeType: $step['type'], step: $step['label'], right: $right);
				}
			}
		}
	}//end assertMayAuthor()

	/**
	 * The palette with each entry's `requiresRight` and, for this user, `locked`.
	 *
	 * `requiresRight` is the right the step needs as placed (with no
	 * configuration); a step whose right depends on its configuration is still
	 * checked when the flow is saved.
	 *
	 * @param IUser                            $user    The caller.
	 * @param array<int, array<string, mixed>> $palette The registry's palette.
	 *
	 * @return array<int, array<string, mixed>> The palette.
	 *
	 * @spec openspec/changes/flow-powerful-steps-need-a-right/specs/flow-engine/spec.md#requirement-a-flow-step-type-can-require-a-named-right
	 */
	public function annotatePalette(IUser $user, array $palette): array {
		$map = $this->adminMap();
		foreach ($palette as $index => $entry) {
			$type  = (string) ($entry['id'] ?? '');
			$right = ($entry['requiresRight'] ?? null);
			if (array_key_exists($type, $map) === true) {
				$right = $map[$type];
			}

			$palette[$index]['requiresRight'] = $right;
			if (is_string($right) === true && $this->access->may(user: $user, action: $right) === false) {
				$palette[$index]['locked'] = true;
			}
		}

		return $palette;
	}//end annotatePalette()

	/**
	 * Every node right in use, with its description and the node types that require it.
	 *
	 * The rights the registered nodes declare, plus the rights the administrator's map names.
	 *
	 * @return array<string, array{description: string, nodeTypes: array<int, string>}> Keyed by right.
	 *
	 * @spec openspec/changes/flow-powerful-steps-need-a-right/specs/flow-engine/spec.md#requirement-the-action-rights-are-administered-and-published
	 */
	public function nodeRights(): array {
		$map    = $this->adminMap();
		$rights = [];
		foreach ($this->nodes->palette() as $entry) {
			$type = (string) ($entry['id'] ?? '');
			$node = $this->node(type: $type);
			if (($node instanceof IFlowNodeRequiresRight) === false || array_key_exists($type, $map) === true) {
				continue;
			}

			foreach ($node->declaredRights() as $right => $description) {
				$rights = self::withRight(rights: $rights, right: (string) $right, description: (string) $description, type: $type);
			}
		}

		foreach ($map as $type => $right) {
			if ($right !== null) {
				$description = sprintf('Use the step "%s" in a flow (set by an administrator).', $type);
				$rights      = self::withRight(rights: $rights, right: $right, description: $description, type: (string) $type);
			}
		}

		ksort($rights);
		return $rights;
	}//end nodeRights()

	/**
	 * The administrator's map of node type to right (null lifts a node's own right).
	 *
	 * A malformed stored map is read as empty, so the nodes' own declarations
	 * apply: the built-in rights cannot be lifted by a broken value.
	 *
	 * @return array<string, string|null>
	 *
	 * @spec openspec/changes/flow-powerful-steps-need-a-right/specs/flow-engine/spec.md#requirement-a-flow-step-type-can-require-a-named-right
	 */
	public function adminMap(): array {
		$decoded = json_decode($this->appConfig->getValueString(self::APP_ID, self::CONFIG_KEY, '{}'), true);
		if (is_array($decoded) === false) {
			return [];
		}

		$map = [];
		foreach ($decoded as $type => $right) {
			if (is_string($type) === true && $type !== '' && ($right === null || (is_string($right) === true && $right !== ''))) {
				$map[$type] = $right;
			}
		}

		return $map;
	}//end adminMap()

	/**
	 * Store the administrator's map of node type to right.
	 *
	 * @param array<string, string|null> $map The map.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-powerful-steps-need-a-right/specs/flow-engine/spec.md#requirement-a-flow-step-type-can-require-a-named-right
	 */
	public function setAdminMap(array $map): void {
		$this->appConfig->setValueString(self::APP_ID, self::CONFIG_KEY, (string) json_encode((object) $map, JSON_UNESCAPED_SLASHES));
	}//end setAdminMap()

	/**
	 * The typed steps of a document, on its nodes and (older shape) its edges.
	 *
	 * @param array<string, mixed> $document The document.
	 *
	 * @return array<int, array{type: string, config: array<string, mixed>, label: string}>
	 */
	private function steps(array $document): array {
		$steps = [];
		foreach (['nodes', 'edges'] as $list) {
			if (is_array($document[$list] ?? null) === false) {
				continue;
			}

			foreach ($document[$list] as $index => $entry) {
				$type = '';
				if (is_array($entry) === true) {
					$type = trim((string) ($entry['type'] ?? ''));
				}

				if ($type === '') {
					continue;
				}

				$config = ($entry['config'] ?? []);
				if (is_array($config) === false) {
					$config = [];
				}

				$steps[] = [
					'type' => $type,
					'config' => $config,
					'label' => (string) ($entry['label'] ?? $entry['name'] ?? $entry['id'] ?? $index),
				];
			}
		}

		return $steps;
	}//end steps()

	/**
	 * The registered node of a type, or null when none is.
	 *
	 * @param string $type The step type.
	 *
	 * @return IFlowNode|null
	 */
	private function node(string $type): ?IFlowNode {
		try {
			return $this->nodes->get(type: $type);
		} catch (Throwable $e) {
			return null;
		}
	}//end node()

	/**
	 * The rights list with one more node type requiring a right.
	 *
	 * @param array<string, array{description: string, nodeTypes: array<int, string>}> $rights      The list so far.
	 * @param string                                                                   $right       The right.
	 * @param string                                                                   $description Its description.
	 * @param string                                                                   $type        The node type.
	 *
	 * @return array<string, array{description: string, nodeTypes: array<int, string>}>
	 */
	private static function withRight(array $rights, string $right, string $description, string $type): array {
		$rights[$right] ??= ['description' => $description, 'nodeTypes' => []];
		if (in_array($type, $rights[$right]['nodeTypes'], true) === false) {
			$rights[$right]['nodeTypes'][] = $type;
		}

		return $rights;
	}//end withRight()
}//end class
