<?php

/**
 * OpenRegister rematerialise-calculations command
 *
 * Re-evaluates every materialised calculation declared on a schema and
 * rewrites the persisted value. Used after a schema's calculation
 * expression changes so existing objects reflect the new shape without
 * waiting for the next user-driven save.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Command
 * @package  OCA\OpenRegister\Command
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/retrofit-2026-05-24-2b-command-repair-middleware/tasks.md#task-2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Command;

use DateTimeInterface;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Calculation\AggregateReferenceResolver;
use OCA\OpenRegister\Service\Calculation\CalculationEvaluator;
use OCA\OpenRegister\Service\Calculation\ReferenceResolver;
use OCA\OpenRegister\Service\ObjectService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Re-evaluate every materialised calculation declared on a (register, schema).
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class RematerialiseCalculationsCommand extends Command
{
    /**
     * Wire the mappers, evaluator, and object service used by the command.
     *
     * @param RegisterMapper             $registerMapper Register lookup mapper.
     * @param SchemaMapper               $schemaMapper   Schema lookup mapper.
     * @param MagicMapper                $magicMapper    Magic table mapper for objects.
     * @param ObjectService              $objectService  Object persistence service.
     * @param CalculationEvaluator       $evaluator      Expression evaluator.
     * @param ReferenceResolver          $references     Cross-object reference pre-resolver.
     * @param AggregateReferenceResolver $aggregates     Aggregate-reference pre-resolver.
     *
     * @return void
     *
     * @spec openspec/changes/retrofit-2026-05-24-2b-command-repair-middleware/tasks.md#task-2
     * @spec openspec/changes/calc-engine-reference-lookup/tasks.md#task-2
     * @spec openspec/changes/calc-engine-aggregate-reference/tasks.md#task-2
     */
    public function __construct(
        private readonly RegisterMapper $registerMapper,
        private readonly SchemaMapper $schemaMapper,
        private readonly MagicMapper $magicMapper,
        private readonly ObjectService $objectService,
        private readonly CalculationEvaluator $evaluator,
        private readonly ReferenceResolver $references,
        private readonly AggregateReferenceResolver $aggregates
    ) {
        parent::__construct();
    }//end __construct()

    /**
     * Define command name, description, and arguments.
     *
     * @return void
     *
     * @spec openspec/changes/retrofit-2026-05-24-2b-command-repair-middleware/tasks.md#task-2
     */
    protected function configure(): void
    {
        $this->setName(name: 'openregister:rematerialise-calculations')
            ->setDescription(
                'Re-evaluate every materialised calculation on objects in a (register, schema) and persist the result.'
            )
            ->addArgument('register', InputArgument::REQUIRED, 'Register slug, uuid or id')
            ->addArgument('schema',   InputArgument::REQUIRED, 'Schema slug, uuid or id')
            ->addOption('dry-run',    null, InputOption::VALUE_NONE, 'Report changes without saving');
    }//end configure()

    /**
     * Iterate every object and re-materialise all declared calculations.
     *
     * @param InputInterface  $input  Console input.
     * @param OutputInterface $output Console output stream.
     *
     * @return int Symfony command exit code.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     *
     * @spec openspec/changes/retrofit-2026-05-24-2b-command-repair-middleware/tasks.md#task-2
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $registerRef = (string) $input->getArgument('register');
        $schemaRef   = (string) $input->getArgument('schema');
        $dryRun      = (bool) $input->getOption('dry-run');

        try {
            $register = $this->registerMapper->find($registerRef, _multitenancy: false);
            $schema   = $this->schemaMapper->find($schemaRef, _multitenancy: false);
        } catch (\Throwable $e) {
            $output->writeln('<error>'.$e->getMessage().'</error>');
            return Command::FAILURE;
        }

        $calcs = $this->getCalculations(schema: $schema);
        if ($calcs === null || count($calcs) === 0) {
            $output->writeln('<comment>Schema declares no x-openregister-calculations — nothing to do.</comment>');
            return Command::SUCCESS;
        }

        $materialiseNames = [];
        foreach ($calcs as $name => $spec) {
            if (is_array($spec) === true && ($spec['materialise'] ?? false) === true) {
                $materialiseNames[] = (string) $name;
            }
        }

        if (count($materialiseNames) === 0) {
            $output->writeln('<comment>No materialised calculations declared — nothing to do.</comment>');
            return Command::SUCCESS;
        }

        $dryRunLabel = '';
        if ($dryRun === true) {
            $dryRunLabel = ' (dry run)';
        }

        $output->writeln(
                sprintf(
            '<info>Rematerialising %d calculation(s) on %s/%s%s</info>',
            count($materialiseNames),
            $register->getSlug() ?? $register->getId(),
            $schema->getSlug() ?? $schema->getId(),
            $dryRunLabel
        )
                );

        $entities = $this->magicMapper->findAllInRegisterSchemaTable(
            register: $register,
            schema: $schema,
            limit: 100000
        );

        // Declared cross-object references are pre-resolved per object so the
        // recompute path refreshes references the same way the save path does.
        $referenceSpecs = ($schema->getConfiguration()['x-openregister-references'] ?? null);
        if (is_array($referenceSpecs) === false || count($referenceSpecs) === 0) {
            $referenceSpecs = null;
        }

        // Declared aggregate-references are pre-resolved per object so the
        // recompute path refreshes save-time aggregate snapshots the same way
        // the save path does.
        $aggregateSpecs = ($schema->getConfiguration()['x-openregister-aggregate-refs'] ?? null);
        if (is_array($aggregateSpecs) === false || count($aggregateSpecs) === 0) {
            $aggregateSpecs = null;
        }

        $touched   = 0;
        $unchanged = 0;
        $failed    = 0;

        foreach ($entities as $entity) {
            $data    = $entity->getObject() ?? [];
            $payload = $this->withSelf(data: $data, entity: $entity);

            if ($referenceSpecs !== null) {
                $payload['@ref'] = $this->references->resolveAll(
                    payload: $payload,
                    references: $referenceSpecs,
                    register: $entity->getRegister()
                );
            }

            if ($aggregateSpecs !== null) {
                $payload['@aggregate'] = $this->aggregates->resolveAll(
                    payload: $payload,
                    aggregates: $aggregateSpecs,
                    registerRef: $entity->getRegister()
                );
            }

            $changed = false;
            foreach ($calcs as $name => $spec) {
                if (is_array($spec) === false || ($spec['materialise'] ?? false) !== true) {
                    continue;
                }

                try {
                    $value = $this->evaluator->evaluate($payload, $spec['expression'] ?? null);
                    if ($value instanceof DateTimeInterface) {
                        $value = $value->format(DateTimeInterface::ATOM);
                    }

                    if (($data[(string) $name] ?? null) !== $value) {
                        $data[(string) $name] = $value;
                        $changed = true;
                    }
                } catch (\Throwable $e) {
                    $failed++;
                    $output->writeln(
                            sprintf(
                        '  <error>! %s on %s: %s</error>',
                        (string) $name,
                        (string) $entity->getUuid(),
                        $e->getMessage()
                    )
                            );
                }//end try
            }//end foreach

            if ($changed === false) {
                $unchanged++;
                continue;
            }

            $touched++;
            if ($dryRun === false) {
                try {
                    $this->objectService->saveObject(
                        object: $data,
                        register: $entity->getRegister(),
                        schema: $entity->getSchema(),
                        uuid: $entity->getUuid()
                    );
                } catch (\Throwable $e) {
                    $output->writeln(
                            sprintf(
                        '  <error>save failed on %s: %s</error>',
                        (string) $entity->getUuid(),
                        $e->getMessage()
                    )
                            );
                    $failed++;
                }
            }
        }//end foreach

        $output->writeln(
                sprintf(
            '<info>Touched %d, unchanged %d, failed %d</info>',
            $touched,
            $unchanged,
            $failed
        )
                );
        $exitCode = Command::SUCCESS;
        if ($failed > 0) {
            $exitCode = Command::FAILURE;
        }

        return $exitCode;
    }//end execute()

    /**
     * Inject the synthetic `@self` metadata into an evaluation payload.
     *
     * @param array<string, mixed>              $data   Object data.
     * @param \OCA\OpenRegister\Db\ObjectEntity $entity Object entity providing metadata.
     *
     * @return array<string, mixed> Payload with `@self` injected.
     *
     * @spec openspec/changes/retrofit-2026-05-24-2b-command-repair-middleware/tasks.md#task-2
     */
    private function withSelf(array $data, \OCA\OpenRegister\Db\ObjectEntity $entity): array
    {
        $created          = $entity->getCreated();
        $updated          = $entity->getUpdated();
        $createdFormatted = null;
        if ($created !== null) {
            $createdFormatted = $created->format(DateTimeInterface::ATOM);
        }

        $updatedFormatted = null;
        if ($updated !== null) {
            $updatedFormatted = $updated->format(DateTimeInterface::ATOM);
        }

        $data['@self'] = [
            'id'       => $entity->getUuid(),
            'uuid'     => $entity->getUuid(),
            'register' => $entity->getRegister(),
            'schema'   => $entity->getSchema(),
            'owner'    => $entity->getOwner(),
            'created'  => $createdFormatted,
            'updated'  => $updatedFormatted,
        ];
        return $data;
    }//end withSelf()

    /**
     * Read the `x-openregister-calculations` configuration block.
     *
     * @param Schema $schema Schema to inspect.
     *
     * @return array<string, mixed>|null Calculations map, or null when absent.
     *
     * @spec openspec/changes/retrofit-2026-05-24-2b-command-repair-middleware/tasks.md#task-2
     */
    private function getCalculations(Schema $schema): ?array
    {
        $config = ($schema->getConfiguration() ?? []);
        $value  = ($config['x-openregister-calculations'] ?? null);
        if (is_array($value) === true) {
            return $value;
        }

        return null;
    }//end getCalculations()
}//end class
