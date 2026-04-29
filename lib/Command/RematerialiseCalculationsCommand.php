<?php

/**
 * OpenRegister rematerialise-calculations command
 *
 * Re-evaluates every materialised calculation declared on a schema and
 * rewrites the persisted value. Used after a schema's calculation
 * expression changes so existing objects reflect the new shape without
 * waiting for the next user-driven save.
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
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Command;

use DateTimeInterface;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Calculation\CalculationEvaluator;
use OCA\OpenRegister\Service\ObjectService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class RematerialiseCalculationsCommand extends Command
{

    public function __construct(
        private readonly RegisterMapper $registerMapper,
        private readonly SchemaMapper $schemaMapper,
        private readonly MagicMapper $magicMapper,
        private readonly ObjectService $objectService,
        private readonly CalculationEvaluator $evaluator
    ) {
        parent::__construct();
    }//end __construct()

    protected function configure(): void
    {
        $this->setName('openregister:rematerialise-calculations')
            ->setDescription('Re-evaluate every materialised calculation on objects in a (register, schema) and persist the result.')
            ->addArgument('register', InputArgument::REQUIRED, 'Register slug, uuid or id')
            ->addArgument('schema',   InputArgument::REQUIRED, 'Schema slug, uuid or id')
            ->addOption('dry-run',    null, InputOption::VALUE_NONE, 'Report changes without saving');
    }//end configure()

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

        $calcs = $this->getCalculations($schema);
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

        $output->writeln(sprintf(
            '<info>Rematerialising %d calculation(s) on %s/%s%s</info>',
            count($materialiseNames),
            $register->getSlug() ?? $register->getId(),
            $schema->getSlug() ?? $schema->getId(),
            $dryRun ? ' (dry run)' : ''
        ));

        $entities = $this->magicMapper->findAllInRegisterSchemaTable(
            register: $register,
            schema: $schema,
            limit: 100000
        );

        $touched   = 0;
        $unchanged = 0;
        $failed    = 0;

        foreach ($entities as $entity) {
            $data = $entity->getObject() ?? [];
            $payload = $this->withSelf($data, $entity);

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
                    $output->writeln(sprintf(
                        '  <error>! %s on %s: %s</error>',
                        (string) $name,
                        (string) $entity->getUuid(),
                        $e->getMessage()
                    ));
                }
            }

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
                    $output->writeln(sprintf(
                        '  <error>save failed on %s: %s</error>',
                        (string) $entity->getUuid(),
                        $e->getMessage()
                    ));
                    $failed++;
                }
            }
        }

        $output->writeln(sprintf(
            '<info>Touched %d, unchanged %d, failed %d</info>',
            $touched,
            $unchanged,
            $failed
        ));
        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }//end execute()

    /**
     * @param array<string, mixed> $data
     */
    private function withSelf(array $data, \OCA\OpenRegister\Db\ObjectEntity $entity): array
    {
        $created = $entity->getCreated();
        $updated = $entity->getUpdated();
        $data['@self'] = [
            'id'       => $entity->getUuid(),
            'uuid'     => $entity->getUuid(),
            'register' => $entity->getRegister(),
            'schema'   => $entity->getSchema(),
            'owner'    => $entity->getOwner(),
            'created'  => $created !== null ? $created->format(DateTimeInterface::ATOM) : null,
            'updated'  => $updated !== null ? $updated->format(DateTimeInterface::ATOM) : null,
        ];
        return $data;
    }//end withSelf()

    /**
     * @return array<string, mixed>|null
     */
    private function getCalculations(Schema $schema): ?array
    {
        $config = ($schema->getConfiguration() ?? []);
        $value  = ($config['x-openregister-calculations'] ?? null);
        return is_array($value) === true ? $value : null;
    }//end getCalculations()

}//end class
