<?php
declare(strict_types=1);

/*
 * This file is part of PSB User Deployment.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace PSB\PsbUserDeployment\Command;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Exception;
use JsonException;
use PSB\PsbUserDeployment\Enum\RecordType;
use PSB\PsbUserDeployment\Service\PermissionService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use function in_array;
use function is_array;
use function is_string;

/**
 * Class DeployCommand
 *
 * This command imports a JSON file containing the configuration for users and user groups in both backend and frontend.
 * An example configuration can be found here: EXT:psb_user_deployment/Documentation/exampleConfiguration.json
 * The command can be executed like this:
 * ./vendor/bin/typo3 psbUserDeployment:deploy ./path/to/your/configuration.json
 *
 * It can be executed in dry-run mode by adding --dry-run or -d:
 * ./vendor/bin/typo3 psbUserDeployment:deploy /path/to/your/configuration.json --dry-run
 *
 * If data records that do not exist in the configuration are to be deleted, add --remove or -rm to the command:
 * ./vendor/bin/typo3 psbUserDeployment:deploy /path/to/your/configuration.json --remove
 *
 * Both options can be combined. The order of the options does not matter.
 *
 * You can split your configuration into several files if you provide a JSON file with the following structure:
 * {
 *     "files": [
 *         "/path/to/your/configuration1.json",
 *         "/path/to/your/configuration2.json"
 *     ]
 * }
 *
 * The command will then import all files in the given order. The files are merged into one configuration. If the same
 * record is defined in multiple files, the last definition will be used.
 *
 * The configuration considers two special keys inside each table section:
 * - "_default": This key can be used to define default values for all records of that type.
 * - "_variables": This key can be used to define variables which can be used in the configuration. The variables are
 *                 replaced by their actual values. The variables can be used in the configuration by using the variable
 *                 name as value. Make sure to choose unique variable names to avoid conflicts, e.g. by using a special
 *                 format like "@variableName".
 * Example:
 * {
 *    "be_groups": {
 *       "_variables": {
 *          "@subgroup": "subgroup1,subgroup2"
 *      },
 *     "group1": {
 *        "title": "Group 1",
 *       "subgroup": "@subgroup"
 *    }
 * }
 *
 * The command will replace "$subgroup" with "subgroup1,subgroup2".
 *
 * @package PSB\PsbUserDeployment\Command
 */
#[AsCommand(name: 'psbUserDeployment:deploy', description: 'This command creates/deletes/updates users and user groups depending on configuration.')]
class DeployCommand extends Command
{
    protected RecordType   $currentRecordType;
    protected bool         $dryRun                 = false;
    protected SymfonyStyle $io;
    protected array        $mapping                = [];
    protected array        $pageTreeAccessMapping  = [];
    protected bool         $removeAbandonedRecords = false;

    public function __construct(
        protected readonly PermissionService $permissionService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setHelp(
            'This command imports a JSON file containing the configuration for users and user groups in both backend and frontend.'
        )
            ->addArgument('file', InputArgument::REQUIRED, 'Provide the source which should be deployed.')
            ->addOption(
                'dry-run',
                'd',
                InputOption::VALUE_NONE,
                'Don\'t make changes to the database, but show number of affected records only. You can use --dry-run or -d when running this command.',
            )
            ->addOption(
                'remove',
                'x',
                InputOption::VALUE_NONE,
                'Delete records from the database which are not included in the given configuration file. You can use --remove or -x when running this command.',
            );
    }

    /**
     * @throws Exception
     * @throws JsonException
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->io = new SymfonyStyle($input, $output);
        $fileName = GeneralUtility::getFileAbsFileName($input->getArgument('file'));
        $this->dryRun = (bool)$input->getOption('dry-run');
        $this->removeAbandonedRecords = (bool)$input->getOption('remove');

        $configuration = $this->decodeConfigurationFile($fileName);

        if (!empty($configuration['files'])) {
            $configuration = $this->importConfigurationFiles($configuration);
        }

        foreach (RecordType::strictlyOrderedCases() as $recordType) {
            $tableName = $recordType->getTable();

            if (!empty($configuration[$tableName]) && is_array($configuration[$tableName])) {
                $this->deploy($configuration[$tableName], $recordType);
            }
        }

        return Command::SUCCESS;
    }

    /**
     * @throws Exception
     */
    private function countAbandonedRecords(string ...$existingIdentifiers): int
    {
        $table = $this->currentRecordType->getTable();
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()
            ->removeAll();

        return $queryBuilder->count('*')
            ->from($table)
            ->where(
                $queryBuilder->expr()
                    ->notIn(
                        $this->currentRecordType->getIdentifierField(),
                        $queryBuilder->createNamedParameter($existingIdentifiers, ArrayParameterType::STRING)
                    )
            )
            ->executeQuery()
            ->fetchOne();
    }

    /**
     * @throws JsonException
     */
    private function decodeConfigurationFile(string $fileName): array
    {
        return json_decode(file_get_contents($fileName), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @throws Exception
     */
    private function deploy(array $configuration, RecordType $recordType): void
    {
        $this->currentRecordType = $recordType;
        $createdRecords = 0;
        $updatedRecords = 0;

        // The default settings and variables have to be extracted before further processing of the array!
        $default = $this->extractValue($configuration, '_default');
        $variables = $this->extractValue($configuration, '_variables');
        $identifiers = array_keys($configuration);
        $existingRecords = $this->getExistingRecords($identifiers);
        $existingIdentifiers = array_column($existingRecords, $recordType->getIdentifierField());

        if ($this->removeAbandonedRecords) {
            if ($this->dryRun) {
                $this->io->writeln(
                    $this->countAbandonedRecords(...$identifiers) . ' ' . $recordType->getTable(
                    ) . ' records would be removed.'
                );
            } else {
                $this->io->writeln(
                    $this->softDeleteAbandonedRecords(...$identifiers) . ' ' . $recordType->getTable(
                    ) . ' records have been removed.'
                );
            }
        }

        if (in_array($recordType, [
            RecordType::BackendGroup,
            RecordType::FileMount,
            RecordType::FrontendGroup,
        ], true)) {
            $subgroupReferences = [];

            foreach ($existingRecords as $existingRecord) {
                $this->mapping[$recordType->getTable()][$existingRecord[$recordType->getIdentifierField(
                )]] = $existingRecord['uid'];
            }
        }

        foreach ($configuration as $identifier => $settings) {
            $settings = array_merge($default, $settings);

            // Replace variable references:
            array_walk($settings, static function(&$value) use ($variables) {
                if (is_string($value) && isset($variables[$value])) {
                    $value = $variables[$value];
                }
            });

            $pageTreeAccessMappingValue = $settings[PermissionService::PERMISSION_KEY] ?? null;

            if (!$this->dryRun && RecordType::BackendGroup === $recordType && null !== $pageTreeAccessMappingValue) {
                $pageUids = is_array(
                    $pageTreeAccessMappingValue
                ) ? $pageTreeAccessMappingValue : GeneralUtility::intExplode(',', (string)$pageTreeAccessMappingValue);

                foreach ($pageUids as $pageUid) {
                    $this->pageTreeAccessMapping[$pageUid] = $identifier;
                }

                unset($settings[PermissionService::PERMISSION_KEY]);
            }

            $settings[$recordType->getIdentifierField()] = $identifier;
            $settings['tstamp'] = time();

            switch ($recordType) {
                case RecordType::BackendGroup:
                case RecordType::FrontendGroup:
                    $this->prepareSubgroups($identifier, $settings, $subgroupReferences);
                    break;
                case RecordType::BackendUser:
                case RecordType::FrontendUser:
                    $this->processRelations(
                        $recordType->getGroupField(),
                        $recordType->getGroupTable(),
                        $settings
                    );
                    break;
                default:
            }

            if (in_array($recordType, [
                RecordType::BackendGroup,
                RecordType::BackendUser,
            ], true)) {
                $this->processRelations('file_mountpoints', 'sys_filemounts', $settings);
            }

            $connection = GeneralUtility::makeInstance(ConnectionPool::class)
                ->getConnectionForTable($recordType->getTable());

            if (in_array($identifier, $existingIdentifiers, true)) {
                if (!$this->dryRun) {
                    // Reactivate the record if it was soft-deleted.
                    $settings['deleted'] = 0;

                    $connection->update(
                        $recordType->getTable(),
                        $settings,
                        [$recordType->getIdentifierField() => $identifier]
                    );
                }

                $updatedRecords++;
            } else {
                if (!$this->dryRun) {
                    if (RecordType::FileMount !== $recordType) {
                        $settings['crdate'] = $settings['tstamp'];
                    }

                    $connection->insert($recordType->getTable(), $settings);

                    if (in_array($recordType, [
                        RecordType::BackendGroup,
                        RecordType::FileMount,
                        RecordType::FrontendGroup,
                    ], true)) {
                        $this->mapping[$recordType->getTable()][$identifier] = $connection->lastInsertId(
                            $recordType->getTable()
                        );
                    }
                }

                $createdRecords++;
            }
        }

        if ($this->dryRun) {
            $this->io->writeln(
                $createdRecords . ' ' . $recordType->getTable() . ' records would be created.'
            );
            $this->io->writeln(
                $updatedRecords . ' ' . $recordType->getTable() . ' records would be updated.'
            );
        } else {
            if (in_array($recordType, [
                RecordType::BackendGroup,
                RecordType::FrontendGroup,
            ], true)) {
                $this->processBackendSubgroups($existingRecords, $subgroupReferences);
            }

            if (RecordType::BackendGroup === $recordType && !empty($this->pageTreeAccessMapping)) {
                array_walk($this->pageTreeAccessMapping, function(&$value) {
                    $value = $this->mapping[RecordType::BackendGroup->getTable()][$value];
                });
                $this->permissionService->setPermissionsForAllPages($this->io, $this->pageTreeAccessMapping);
            }

            $this->io->writeln(
                $createdRecords . ' ' . $recordType->getTable() . ' records have been created.'
            );
            $this->io->writeln(
                $updatedRecords . ' ' . $recordType->getTable() . ' records have been updated.'
            );
        }
    }

    private function extractValue(array &$configuration, string $key): array
    {
        if (isset($configuration[$key])) {
            if (is_array($configuration[$key])) {
                $extractedValue = $configuration[$key];
            }

            unset($configuration[$key]);
        }

        return $extractedValue ?? [];
    }

    /**
     * @throws Exception
     */
    private function getExistingRecords(array $identifiers): array
    {
        $identifierField = $this->currentRecordType->getIdentifierField();
        $table = $this->currentRecordType->getTable();
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()
            ->removeAll();

        return $queryBuilder->select($identifierField, 'uid')
            ->from($table)
            ->where(
                $queryBuilder->expr()
                    ->in(
                        $identifierField,
                        $queryBuilder->createNamedParameter($identifiers, ArrayParameterType::STRING)
                    )
            )
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * @throws JsonException
     */
    private function importConfigurationFiles(array $configuration): array
    {
        foreach ($configuration['files'] as $fileName) {
            $configuration = array_merge(
                $configuration,
                $this->decodeConfigurationFile(GeneralUtility::getFileAbsFileName($fileName))
            );
        }

        return $configuration;
    }

    private function prepareSubgroups(string $identifier, array &$settings, array &$subgroupReferences): void
    {
        $groupField = $this->currentRecordType->getGroupField();

        if (isset($settings[$groupField])) {
            $subgroupReferences[$identifier] = $settings[$groupField];
            unset($settings[$groupField]);
        }
    }

    private function processBackendSubgroups(array $existingRecords, array $subgroupReferences): void
    {
        $table = $this->currentRecordType->getTable();

        // Add subgroup information after collecting all UIDs:
        foreach ($subgroupReferences as $identifier => $subgroupReference) {
            if (!is_array($subgroupReference)) {
                continue;
            }

            // Replace title with actual UID:
            array_walk($subgroupReference, function(&$value) {
                $value = $this->mapping[$this->currentRecordType->getGroupTable()][$value];
            });
            $connection = GeneralUtility::makeInstance(ConnectionPool::class)
                ->getConnectionForTable($table);
            $connection->update(
                $table,
                [$this->currentRecordType->getGroupField() => implode(',', $subgroupReference)],
                [$this->currentRecordType->getIdentifierField() => $identifier]
            );
        }
    }

    // This converts the identifiers to their respective UIDs.
    private function processRelations(string $relationField, string $relationTable, array &$settings): void
    {
        if (isset($settings[$relationField]) && is_array($settings[$relationField])) {
            array_walk($settings[$relationField], function(&$value) use ($relationTable) {
                $value = $this->mapping[$relationTable][$value] ?? '';
            });
            $settings[$relationField] = implode(
                ',',
                $settings[$relationField]
            );
        }
    }

    private function softDeleteAbandonedRecords(string ...$existingIdentifiers): int
    {
        $table = $this->currentRecordType->getTable();
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()
            ->removeAll();

        return $queryBuilder->update($table)
            ->set('deleted', 1)
            ->where(
                $queryBuilder->expr()
                    ->notIn(
                        $this->currentRecordType->getIdentifierField(),
                        $queryBuilder->createNamedParameter($existingIdentifiers, ArrayParameterType::STRING)
                    )
            )
            ->executeStatement();
    }
}
