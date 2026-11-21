<?php
/**
 * Contributes the process declaration checks to the schema save.
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

use Closure;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper\AdministeredValidationCheck;
use OCA\OpenRegister\Db\SchemaMapper\AdvisoryAnnotationChecks;
use OCA\OpenRegister\Db\SchemaMapper\AdvisoryAnnotationRule;
use OCA\OpenRegister\Db\SchemaMapper\CalculationAnnotationCheck;
use OCA\OpenRegister\Db\SchemaMapper\DeclaredActionCheck;
use OCA\OpenRegister\Db\SchemaMapper\DiscardedAnnotations;
use OCA\OpenRegister\Db\SchemaMapper\LifecycleAnnotationCheck;
use OCA\OpenRegister\Db\SchemaMapper\NamedConditionCheck;
use OCA\OpenRegister\Db\SchemaMapper\PositionedDeclarationCheck;
use OCA\OpenRegister\Db\SchemaMapper\ProcessDeclarationRefusals;
use OCA\OpenRegister\Db\SchemaMapper\ReferenceLoopCheck;
use OCA\OpenRegister\Db\SchemaMapper\RelativeTimeCalendarCheck;
use OCA\OpenRegister\Db\SchemaMapper\SchemaAnnotation;
use OCA\OpenRegister\Db\SchemaMapper\SchemaDeclarationChecksEvent;
use OCA\OpenRegister\Db\SchemaMapper\SchemaDeclarationOrder;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionValidatorInterface;
use OCA\OpenRegister\Service\Party\PartyAnnotationValidator;
use OCA\OpenRegister\Service\Rules\RelativeTimeEvaluation;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Adds the lifecycle, action, calculation, rule, administered-validation, relative-time, reversibility and review checks.
 *
 * Each runs in its slot of SchemaDeclarationOrder, so the schema save keeps
 * the order it had when the mapper ran these checks itself.
 *
 * @template-implements IEventListener<SchemaDeclarationChecksEvent>
 *
 * @spec openspec/specs/runtime-schema-api/spec.md
 */
final class SchemaDeclarationChecksListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface    $logger    Logger the checks report discarded annotations to.
	 * @param ContainerInterface $container Resolves the action validator on first use, which breaks the construction cycle through the flow services.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
		private readonly ContainerInterface $container,
	) {
	}//end __construct()

	/**
	 * Contribute the checks.
	 *
	 * @param Event $event The collection event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/runtime-schema-api/spec.md
	 */
	public function handle(Event $event): void {
		if (($event instanceof SchemaDeclarationChecksEvent) === false) {
			return;
		}

		foreach ($this->checks() as $position => $check) {
			$event->add(check: new PositionedDeclarationCheck(position: $position, check: $check));
		}
	}//end handle()

	/**
	 * The checks by slot, each taking the schema and the discarded annotations.
	 *
	 * @return array<int, Closure>
	 */
	private function checks(): array {
		$logger    = $this->logger;
		$container = $this->container;
		$refusals  = new ProcessDeclarationRefusals();
		return [
			SchemaDeclarationOrder::LIFECYCLE => static fn (Schema $schema, DiscardedAnnotations $discarded) => (new LifecycleAnnotationCheck(
				logger: $logger,
				discarded: $discarded
			))->check(schema: $schema),
			SchemaDeclarationOrder::DECLARED_ACTIONS => static fn (Schema $schema) => (new DeclaredActionCheck(
				objectActions: static fn (): ?ObjectActionValidatorInterface => $container->get(ObjectActionValidatorInterface::class),
				logger: $logger
			))->check(schema: $schema),
			SchemaDeclarationOrder::CALCULATIONS => static fn (Schema $schema, DiscardedAnnotations $discarded) => (new CalculationAnnotationCheck(
				logger: $logger,
				discarded: $discarded
			))->check(schema: $schema),
			SchemaDeclarationOrder::DEPENDENT_VALUES => static fn (Schema $schema) => $refusals->checkDependentValues(schema: $schema),
			SchemaDeclarationOrder::REFERENCE_LOOPS => static fn (Schema $schema) => (new ReferenceLoopCheck())->check(schema: $schema),
			SchemaDeclarationOrder::NAMED_CONDITIONS => static fn (Schema $schema) => (new NamedConditionCheck())->check(schema: $schema),
			SchemaDeclarationOrder::ADMINISTERED_VALIDATIONS => static fn (Schema $schema) => (new AdministeredValidationCheck())->check(schema: $schema),
			SchemaDeclarationOrder::RELATIVE_TIME_CALENDARS => static fn (Schema $schema) => (new RelativeTimeCalendarCheck(
				time: self::relativeTime(container: $container)
			))->check(schema: $schema),
			SchemaDeclarationOrder::REVERSIBILITY => static fn (Schema $schema) => $refusals->checkReversibility(schema: $schema),
			SchemaDeclarationOrder::REVIEW => static fn (Schema $schema) => $refusals->checkReview(schema: $schema),
			SchemaDeclarationOrder::PARTY => static fn (Schema $schema, DiscardedAnnotations $discarded) => (new AdvisoryAnnotationChecks(
				logger: $logger,
				discarded: $discarded
			))->checkContributed(schema: $schema, rule: self::partyRule()),
		];
	}//end checks()

	/**
	 * The `$age` evaluation, resolved on first use; none when the container cannot build it, which skips the calendar check.
	 *
	 * @param ContainerInterface $container The container.
	 *
	 * @return Closure|null
	 *
	 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/flow-engine/spec.md
	 */
	private static function relativeTime(ContainerInterface $container): ?Closure {
		if ($container->has(RelativeTimeEvaluation::class) === false) {
			return null;
		}

		return static fn (): RelativeTimeEvaluation => $container->get(RelativeTimeEvaluation::class);
	}//end relativeTime()

	/**
	 * The `x-openregister-party` annotation: advisory, validated by the party validator.
	 *
	 * It is warned about rather than dropped: the failure a dropped declaration
	 * causes is a party schema that reads as an ordinary one, and the operator
	 * needs the field name to fix it.
	 *
	 * @return AdvisoryAnnotationRule
	 *
	 * @spec openspec/specs/party-model/spec.md#requirement-a-party-without-an-account-carries-its-own-fields-and-is-reachable-req-prm-002
	 */
	public static function partyRule(): AdvisoryAnnotationRule {
		return new AdvisoryAnnotationRule(
			key: 'x-openregister-party',
			consequence: 'the schema is not treated as a party schema',
			errors: static fn (SchemaAnnotation $annotation): array => (new PartyAnnotationValidator())->validate($annotation->shape())
		);
	}//end partyRule()
}//end class
