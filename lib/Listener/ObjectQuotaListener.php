<?php

/**
 * OpenRegister ObjectQuotaListener
 *
 * Refuses a create that would take an organisation past its schema quota.
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
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Service\Quota\ObjectQuotaService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Enforces `x-openregister-quota.perOrganisation` on create.
 *
 * Only a create counts. An update never adds an object, so it is never
 * refused here. An object that belongs to no organisation is not subject to
 * a per-organisation quota.
 *
 * Refuses through the event (`setErrors` plus `stopPropagation`), the idiom
 * UniqueConstraintListener uses, which the save path turns into a refused
 * write with the structured error.
 *
 * A count that cannot be made REFUSES the create (`object-quota-unchecked`)
 * and logs an error. It allowed it before, and that is how a count that never
 * worked (live pass O10: every count failed) left the quota silently off for
 * every schema. Only a schema that declares a quota is affected, and the
 * refusal says the quota could not be checked rather than that it was reached.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/object-quota-per-organisation/specs/tenant-quotas/spec.md
 */
class ObjectQuotaListener implements IEventListener {

	/**
	 * The error code a refused create carries.
	 *
	 * @var string
	 */
	public const ERROR_CODE = 'object-quota-exceeded';

	/**
	 * The error code of a create refused because the quota could not be checked.
	 *
	 * @var string
	 */
	public const ERROR_CODE_UNCHECKED = 'object-quota-unchecked';

	/**
	 * Constructor.
	 *
	 * @param ObjectQuotaService $quotas  Reads the cap and counts.
	 * @param SchemaMapper       $schemas Resolves the object's schema.
	 * @param LoggerInterface    $logger  Logger.
	 *
	 * @spec openspec/changes/object-quota-per-organisation/specs/tenant-quotas/spec.md
	 */
	public function __construct(
		private readonly ObjectQuotaService $quotas,
		private readonly SchemaMapper $schemas,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Refuse a create past the quota.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/object-quota-per-organisation/specs/tenant-quotas/spec.md
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectCreatingEvent) === false) {
			return;
		}

		$object = $event->getObject();
		$organisation = (string)($object->getOrganisation() ?? '');
		$schemaRef = (string)($object->getSchema() ?? '');
		$registerRef = (string)($object->getRegister() ?? '');
		if ($organisation === '' || $schemaRef === '' || is_numeric($registerRef) === false) {
			return;
		}

		try {
			$schema = $this->schemas->find(id: $schemaRef, _rbac: false, _multitenancy: false);
		} catch (Throwable $failure) {
			// No schema, no quota to read: the save path refuses an unknown schema itself.
			return;
		}

		$limit = $this->quotas->limitFor(schema: $schema);
		if ($limit === null) {
			return;
		}

		try {
			$count = $this->quotas->count(
				registerId: (int)$registerRef,
				schema: $schema,
				organisationUuid: $organisation
			);
		} catch (Throwable $failure) {
			$this->logger->error(
				message: '[ObjectQuotaListener] The quota count failed, refusing the create: ' . $failure->getMessage(),
				context: ['app' => 'openregister', 'schema' => $schemaRef, 'organisation' => $organisation]
			);
			$event->setErrors(
				[
					'code' => self::ERROR_CODE_UNCHECKED,
					'message' => sprintf(
						'Schema "%s" limits how many objects one organisation holds, and that limit could not be checked just now, so nothing was created. Try again.',
						$schemaRef
					),
				]
			);
			$event->stopPropagation();
			return;
		}

		if ($count < $limit) {
			return;
		}

		$event->setErrors(
			[
				'code' => self::ERROR_CODE,
				'message' => sprintf(
					'This organisation already holds %d of at most %d objects of schema "%s".',
					$count,
					$limit,
					(string)$schema->getSlug()
				),
				'schema' => (string)$schema->getSlug(),
				'organisation' => $organisation,
				'count' => $count,
				'limit' => $limit,
			]
		);
		$event->stopPropagation();
	}//end handle()
}//end class
