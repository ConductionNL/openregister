<?php

/**
 * AggregationDeclarationCheckListener: Search's check of the aggregations a schema declares.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Search\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Search\Listener;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper\AdvisoryAnnotationChecks;
use OCA\OpenRegister\Db\SchemaMapper\AdvisoryAnnotationRule;
use OCA\OpenRegister\Db\SchemaMapper\DiscardedAnnotations;
use OCA\OpenRegister\Db\SchemaMapper\PositionedDeclarationCheck;
use OCA\OpenRegister\Db\SchemaMapper\SchemaAnnotation;
use OCA\OpenRegister\Db\SchemaMapper\SchemaDeclarationChecksEvent;
use OCA\OpenRegister\Db\SchemaMapper\SchemaDeclarationOrder;
use OCA\OpenRegister\Search\Service\SearchAnnotationValidators;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Contributes the `x-openregister-aggregations` check to the schema save, in
 * the AGGREGATIONS slot of SchemaDeclarationOrder.
 *
 * The annotation is advisory: the schema still stores objects, the aggregation
 * simply won't be runnable, so a fault is logged and recorded, never refused.
 *
 * @template-implements IEventListener<SchemaDeclarationChecksEvent>
 *
 * @spec exclude schema save plumbing moved out of SchemaMapper, not a capability of its own
 */
final class AggregationDeclarationCheckListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger Receives the warnings.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Add the aggregations check to the schema save.
	 *
	 * @param Event $event The schema declaration checks being collected.
	 *
	 * @return void
	 *
	 * @spec exclude schema save plumbing moved out of SchemaMapper, not a capability of its own
	 */
	public function handle(Event $event): void {
		if (($event instanceof SchemaDeclarationChecksEvent) === false) {
			return;
		}

		$logger = $this->logger;
		$event->add(
			check: new PositionedDeclarationCheck(
				position: SchemaDeclarationOrder::AGGREGATIONS,
				check: static fn (Schema $schema, DiscardedAnnotations $discarded) => (new AdvisoryAnnotationChecks(
					logger: $logger,
					discarded: $discarded
				))->checkContributed(schema: $schema, rule: self::rule())
			)
		);
	}//end handle()

	/**
	 * The `x-openregister-aggregations` annotation, validated by the aggregation validator.
	 *
	 * @return AdvisoryAnnotationRule
	 *
	 * @spec exclude moved unchanged from SchemaMapper, which named no spec for it
	 */
	public static function rule(): AdvisoryAnnotationRule {
		return new AdvisoryAnnotationRule(
			key: 'x-openregister-aggregations',
			consequence: 'aggregation not registered',
			// The slug and title let the validator tell a `schema` key naming
			// this schema from one naming a related schema.
			errors: static fn (SchemaAnnotation $annotation, Schema $schema): array => SearchAnnotationValidators::aggregation()->validate(
				array_merge($annotation->shape(), ['slug' => $schema->getSlug(), 'title' => $schema->getTitle()])
			)
		);
	}//end rule()
}//end class
