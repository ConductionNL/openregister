<?php

/**
 * OpenRegister QualityScoreOnSaveListener
 *
 * Subscribes to ObjectCreatingEvent + ObjectUpdatingEvent. When a schema
 * declares an `x-openregister-quality` annotation, computes a per-object
 * data-quality score (0-1) and patches it (plus an optional status label)
 * into the object payload before persistence. Mirrors the materialise-on-save
 * contract of {@see CalculationOnSaveListener}: runs before the write, is
 * fail-soft (logs a warning and continues on any error), and never aborts
 * the save.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use DateTimeImmutable;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Service\Quality\QualityScorer;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Materialises a declared data-quality score into the object payload on save.
 *
 * @template-implements IEventListener<ObjectCreatingEvent|ObjectUpdatingEvent>
 *
 * @spec openspec/changes/mdm-foundation/tasks.md#task-2
 */
class QualityScoreOnSaveListener implements IEventListener
{
    /**
     * Default field the score is written to when the annotation omits `field`.
     *
     * @var string
     */
    private const DEFAULT_FIELD = 'qualityScore';

    /**
     * Wire collaborators used to look up and score the schema's quality rules.
     *
     * @param SchemaMapper    $schemaMapper Schema lookup mapper.
     * @param QualityScorer   $scorer       Pure data-quality scorer.
     * @param LoggerInterface $logger       PSR logger for warnings.
     *
     * @return void
     *
     * @spec openspec/changes/mdm-foundation/tasks.md#task-2
     */
    public function __construct(
        private readonly SchemaMapper $schemaMapper,
        private readonly QualityScorer $scorer,
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * Run quality scoring before the object is persisted.
     *
     * @param Event $event Inbound dispatcher event.
     *
     * @return void
     *
     * @spec openspec/changes/mdm-foundation/tasks.md#task-2
     */
    public function handle(Event $event): void
    {
        if ($event instanceof ObjectCreatingEvent) {
            $this->process(object: $event->getObject());
            return;
        }

        if ($event instanceof ObjectUpdatingEvent) {
            $this->process(object: $event->getNewObject());
            return;
        }
    }//end handle()

    /**
     * Compute and patch the quality score onto the object data.
     *
     * @param ObjectEntity $object Object being created or updated.
     *
     * @return void
     *
     * @spec openspec/changes/mdm-foundation/tasks.md#task-2
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) The method runs the linear score-on-save
     *   pipeline (load schema, read annotation, score, patch score, optionally patch status);
     *   the guards short-circuit absent annotations and the steps share one payload, so
     *   extracting them would only scatter a strictly-sequential flow across helpers.
     * @SuppressWarnings(PHPMD.NPathComplexity)      Same rationale — sequential save-time guards.
     */
    private function process(ObjectEntity $object): void
    {
        $schema = $this->loadSchema(object: $object);
        if ($schema === null) {
            return;
        }

        $quality = $this->getQuality(schema: $schema);
        if ($quality === null) {
            return;
        }

        $rules = ($quality['rules'] ?? null);
        if (is_array($rules) === false || count($rules) === 0) {
            return;
        }

        $data    = ($object->getObject() ?? []);
        $changed = false;

        try {
            $score = $this->scorer->score(object: $data, rules: $rules, now: new DateTimeImmutable());
        } catch (Throwable $e) {
            $this->logger->warning(
                sprintf(
                    'Quality scoring failed on %s: %s',
                    (string) $object->getUuid(),
                    $e->getMessage()
                )
            );
            return;
        }

        $field = (string) ($quality['field'] ?? self::DEFAULT_FIELD);
        if ($field === '') {
            $field = self::DEFAULT_FIELD;
        }

        if (($data[$field] ?? null) !== $score) {
            $data[$field] = $score;
            $changed      = true;
        }

        $statusField = (string) ($quality['statusField'] ?? '');
        if ($statusField !== '') {
            $thresholds = ($quality['thresholds'] ?? []);
            if (is_array($thresholds) === false) {
                $thresholds = [];
            }

            $status = $this->scorer->status(score: $score, thresholds: $thresholds);
            if (($data[$statusField] ?? null) !== $status) {
                $data[$statusField] = $status;
                $changed            = true;
            }
        }

        if ($changed === true) {
            $object->setObject($data);
        }
    }//end process()

    /**
     * Look up the schema referenced by an object instance.
     *
     * @param ObjectEntity $object Object whose schema reference to resolve.
     *
     * @return Schema|null Resolved schema, or null on lookup failure.
     *
     * @spec openspec/changes/mdm-foundation/tasks.md#task-2
     */
    private function loadSchema(ObjectEntity $object): ?Schema
    {
        $ref = $object->getSchema();
        if ($ref === null || $ref === '') {
            return null;
        }

        try {
            return $this->schemaMapper->find($ref, _multitenancy: false);
        } catch (Throwable $e) {
            return null;
        }
    }//end loadSchema()

    /**
     * Read the `x-openregister-quality` configuration block.
     *
     * @param Schema $schema Schema to inspect.
     *
     * @return array<string, mixed>|null Quality config, or null when absent.
     *
     * @spec openspec/changes/mdm-foundation/tasks.md#task-2
     */
    private function getQuality(Schema $schema): ?array
    {
        $config = ($schema->getConfiguration() ?? []);
        $value  = ($config['x-openregister-quality'] ?? null);
        if (is_array($value) === true && count($value) > 0) {
            return $value;
        }

        return null;
    }//end getQuality()
}//end class
