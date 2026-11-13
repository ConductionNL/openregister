<?php

/**
 * OpenRegister RedactionPolicyCacheListener: drops the policy index when a policy object changes.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/redaction-policy-as-data/specs/redaction-policy/spec.md#REQ-RPD-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\RedactionPolicy\PolicyIndexCache;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * Invalidates the policy index when a termRule, detectionPattern or
 * redactionProfile object is created, updated or deleted, so the next
 * detection in this process reads the changed policy.
 *
 * Nothing is looked up while no index is built: most object writes happen
 * when no detection has run, and they then cost one property read. A schema
 * that cannot be resolved counts as a policy schema: dropping the index too
 * often costs one read, keeping a stale one could miss an always term.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/redaction-policy-as-data/specs/redaction-policy/spec.md#REQ-RPD-001
 */
final class RedactionPolicyCacheListener implements IEventListener {
	use WrittenObjectOfEvent;

	/**
	 * The schemas whose objects make up the policy.
	 *
	 * @var list<string>
	 */
	public const POLICY_SCHEMAS = ['termRule', 'detectionPattern', 'redactionProfile'];

	/**
	 * Constructor.
	 *
	 * The schema mapper is resolved when needed, not injected, as the other
	 * object listeners do: the mappers reach each other at construction time.
	 *
	 * @param PolicyIndexCache   $cache     The policy index of this process.
	 * @param ContainerInterface $container Resolves the schema mapper.
	 */
	public function __construct(
		private readonly PolicyIndexCache $cache,
		private readonly ContainerInterface $container,
	) {
	}//end __construct()

	/**
	 * Drop the index when the written object belongs to a policy schema.
	 *
	 * @param Event $event An object created, updated or deleted event.
	 *
	 * @return void
	 *
	 * @listener-placement inline cheap-bounded — a property read while no index is built, else one schema lookup;
	 *   deferred, a detection later in the same request would read the old policy.
	 *
	 * @spec openspec/changes/redaction-policy-as-data/specs/redaction-policy/spec.md#REQ-RPD-001
	 */
	public function handle(Event $event): void {
		if ($this->cache->isBuilt() === false) {
			return;
		}

		$object = $this->extractObject(event: $event);
		if ($object !== null && $this->isPolicyObject(object: $object) === true) {
			$this->cache->invalidate();
		}
	}//end handle()

	/**
	 * Whether an object belongs to one of the policy schemas.
	 *
	 * @param ObjectEntity $object The written object.
	 *
	 * @return bool True for a policy object, or when its schema cannot be resolved.
	 */
	private function isPolicyObject(ObjectEntity $object): bool {
		$reference = (string) $object->getSchema();
		if (is_numeric($reference) === false) {
			return in_array($reference, self::POLICY_SCHEMAS, true);
		}

		try {
			$slug = $this->container->get(SchemaMapperInterface::class)->find(id: (int) $reference, _rbac: false, _multitenancy: false)->getSlug();
		} catch (Throwable) {
			return true;
		}

		return in_array($slug, self::POLICY_SCHEMAS, true);
	}//end isPolicyObject()
}//end class
