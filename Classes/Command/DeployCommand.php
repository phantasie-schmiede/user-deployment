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

/**
 * Class DeployCommand
 *
 * This command imports a JSON file containing the configuration for users and user groups in both backend and frontend.
 * Example:
 * {
 *     "be_groups: {
 *         "_variables": {
 *
 *         },
 *         "Advanced editor": {
 *             "subgroup": ["basic"]
 *         }
 *         "Basic editor": {
 *         }
 *     },
 *     "be_users": {
 *         "jadoe": {
 *             "groups": ["basic"],
 *             "name": "Jane Doe"
 *         },
 *         "jodoe": {
 *             "groups": ["advanced"],
 *             "name": "John Doe"
 *         }
 *     }
 * }
 *
 * @package PSB\PsbUserDeployment\Command
 */
#[AsCommand(name: 'psbUserDeployment:deploy', description: 'This command creates/deletes/updates users and user groups depending on configuration.')]
class DeployCommand extends Command
{
    protected const IDENTIFIER_FIELDS = [
        self::RECORD_TYPES['BACKEND_GROUP']  => 'title',
        self::RECORD_TYPES['BACKEND_USER']   => 'username',
        self::RECORD_TYPES['FRONTEND_GROUP'] => 'title',
        self::RECORD_TYPES['FRONTEND_USER']  => 'username',
    ];
    protected const RECORD_TYPES      = [
        'BACKEND_GROUP'  => 'BackendGroup',
        'BACKEND_USER'   => 'BackendUser',
        'FRONTEND_GROUP' => 'FrontendendGroup',
        'FRONTEND_USER'  => 'FrontendendUser',
    ];
    protected const TABLES            = [
        self::RECORD_TYPES['BACKEND_GROUP']  => 'be_groups',
        self::RECORD_TYPES['BACKEND_USER']   => 'be_users',
        self::RECORD_TYPES['FRONTEND_GROUP'] => 'fe_groups',
        self::RECORD_TYPES['FRONTEND_USER']  => 'fe_users',
    ];

    protected array        $backendGroups          = [];
    protected string       $currentRecordType      = '';
    protected bool         $dryRun                 = false;
    protected SymfonyStyle $io;
    protected bool         $removeAbandonedRecords = false;

    protected function configure(): void
    {
        $this->setHelp('This command does nothing. It always succeeds.')
            ->addArgument(
                'file',
                InputArgument::REQUIRED,
                'Provide the source which should be deployed.',
            )
            ->addOption(
                'dry-run',
                'd',
                InputOption::VALUE_NONE,
                'Don\'t make changes to the database, but show number of affected records only. You can use --dry-run or -d when running this command.',
            )
            ->addOption(
                'remove',
                'rm',
                InputOption::VALUE_NONE,
                'Delete records from the database which are not included in the given configuration file. You can use --remove or -rm when running this command.',
            );
    }

    /**
     * @throws Exception
     * @throws JsonException
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->io = new SymfonyStyle($input, $output);
        $fileName = $input->getArgument('file');
        $this->dryRun = (bool)$input->getOption('dry-run');
        $this->removeAbandonedRecords = (bool)$input->getOption('remove');

        $configuration = $this->decodeConfigurationFile($fileName);

        if (!empty($configuration['files'])) {
            $configuration = $this->importConfigurationFiles($configuration);
        }

        if (!empty($configuration['be_groups']) && is_array($configuration['be_groups'])) {
            $this->deployBackendGroups($configuration['be_groups']);
        }

        if (!empty($configuration['be_users']) && is_array($configuration['be_users'])) {
            $this->deployBackendUsers($configuration['be_users']);
        }

        if (!empty($configuration['fe_groups']) && is_array($configuration['fe_groups'])) {
            $this->deployFrontendGroups($configuration['fe_groups']);
        }

        if (!empty($configuration['fe_users']) && is_array($configuration['fe_users'])) {
            $this->deployFrontendUsers($configuration['fe_users']);
        }

        return Command::SUCCESS;
    }

    /**
     * @throws Exception
     */
    private function countAbandonedRecords(array $groupNames): int
    {
        $table = self::TABLES[$this->currentRecordType];
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable($table);

        return $queryBuilder->select('COUNT(*)')
            ->from($table)
            ->where(
                $queryBuilder->expr()
                    ->notIn(
                        self::IDENTIFIER_FIELDS[$this->currentRecordType],
                        $queryBuilder->createNamedParameter(
                            $groupNames,
                            ArrayParameterType::STRING
                        )
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
        return json_decode($fileName, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @throws Exception
     */
    private function deployBackendGroups(array $backendGroupsConfiguration): void
    {
        $this->currentRecordType = self::RECORD_TYPES['BACKEND_GROUP'];
        $variables = $this->extractVariables($backendGroupsConfiguration);
        $identifiers = array_keys($backendGroupsConfiguration);
        $this->backendGroups = array_fill_keys($identifiers, 0);
        $subgroupReferences = [];

        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable(self::TABLES[$this->currentRecordType]);
        $existingRecords = $queryBuilder->select(self::IDENTIFIER_FIELDS[$this->currentRecordType], 'uid')
            ->from(self::TABLES[$this->currentRecordType])
            ->where(
                $queryBuilder->expr()
                    ->in(
                        self::IDENTIFIER_FIELDS[$this->currentRecordType],
                        $queryBuilder->createNamedParameter(
                            $identifiers,
                            ArrayParameterType::STRING
                        )
                    )
            )
            ->executeQuery()
            ->fetchAllAssociative();

        foreach ($existingRecords as $existingRecord) {
            $this->backendGroups[$existingRecord[self::IDENTIFIER_FIELDS[$this->currentRecordType]]] = $existingRecord['uid'];
        }

        if ($this->removeAbandonedRecords) {
            if ($this->dryRun) {
                $this->io->info($this->countAbandonedRecords($identifiers) . ' records would be removed.');
            } else {
                $this->io->info($this->removeAbandonedRecords($identifiers) . ' records have been removed.');
            }
        }

        foreach ($backendGroupsConfiguration as $identifier => $settings) {
            // Replace variable references:
            array_walk($settings, static function(&$value) use ($variables) {
                if (isset($variables[$value])) {
                    $value = $variables[$value];
                }
            });

            $settings[self::IDENTIFIER_FIELDS[$this->currentRecordType]] = $identifier;
            $settings['tstamp'] = time();

            if (isset($settings['subgroup'])) {
                $subgroupReferences[$identifier] = $settings['subgroup'];
                unset($settings['subgroup']);
            }

            $connection = GeneralUtility::makeInstance(ConnectionPool::class)
                ->getConnectionForTable(self::TABLES[$this->currentRecordType]);

            if (0 < $this->backendGroups[$identifier]) {
                // Group already exists in database
                $connection->update(
                    'be_groups', $settings, [self::IDENTIFIER_FIELDS[$this->currentRecordType] => $identifier]
                );
            } else {
                $settings['crdate'] = $settings['tstamp'];
                $connection->insert(self::TABLES[$this->currentRecordType], $settings);
                $this->backendGroups[$identifier] = $connection->lastInsertId(self::TABLES[$this->currentRecordType]);
            }
        }

        // Add subgroup information after collecting all UIDs:
        foreach ($subgroupReferences as $identifier => $subgroupReference) {
            // Replace title with actual UID:
            array_walk($subgroupReference, function(&$value) {
                $value = $this->backendGroups[$value];
            });

            $connection = GeneralUtility::makeInstance(ConnectionPool::class)
                ->getConnectionForTable(self::TABLES[$this->currentRecordType]);
            $connection->update(
                self::TABLES[$this->currentRecordType],
                ['subgroup' => implode(',', $subgroupReference)],
                [self::IDENTIFIER_FIELDS[$this->currentRecordType] => $identifier]
            );
        }
    }

    /**
     * @throws Exception
     */
    private function deployBackendUsers(array $backendUsersConfiguration): void
    {
        $this->currentRecordType = self::RECORD_TYPES['BACKEND_USER'];
        $variables = $this->extractVariables($backendUsersConfiguration);
        $identifiers = array_keys($backendUsersConfiguration);

        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable(self::TABLES[$this->currentRecordType]);
        $existingRecords = $queryBuilder->select(self::IDENTIFIER_FIELDS[$this->currentRecordType])
            ->from(self::TABLES[$this->currentRecordType])
            ->where(
                $queryBuilder->expr()
                    ->in(
                        self::IDENTIFIER_FIELDS[$this->currentRecordType],
                        $queryBuilder->createNamedParameter(
                            $identifiers,
                            ArrayParameterType::STRING
                        )
                    )
            )
            ->executeQuery()
            ->fetchAllAssociative();

        if ($this->removeAbandonedRecords) {
            if ($this->dryRun) {
                $this->io->info($this->countAbandonedRecords($identifiers) . ' records would be removed.');
            } else {
                $this->io->info($this->removeAbandonedRecords($identifiers) . ' records have been removed.');
            }
        }

        foreach ($backendUsersConfiguration as $identifier => $settings) {
            // Replace variable references:
            array_walk($settings, static function(&$value) use ($variables) {
                if (isset($variables[$value])) {
                    $value = $variables[$value];
                }
            });

            $settings[self::IDENTIFIER_FIELDS[$this->currentRecordType]] = $identifier;
            $settings['tstamp'] = time();

            if (isset($settings['usergroup'])) {
                array_walk($settings['usergroup'], function(&$value) {
                    $value = $this->backendGroups[$value];
                });
                $settings['usergroup'] = implode(',', $settings['usergroup']);
            }

            $connection = GeneralUtility::makeInstance(ConnectionPool::class)
                ->getConnectionForTable(self::TABLES[$this->currentRecordType]);

            if (in_array($identifier, $existingRecords, true)) {
                // User already exists in database.
                $connection->update(
                    self::TABLES[$this->currentRecordType], $settings,
                    [self::IDENTIFIER_FIELDS[$this->currentRecordType] => $identifier]
                );
            } else {
                $settings['crdate'] = $settings['tstamp'];
                $connection->insert(self::TABLES[$this->currentRecordType], $settings);
            }
        }
    }

    private function extractVariables(array &$tableConfiguration): array
    {
        if (isset($tableConfiguration['_variables'])) {
            if (is_array($tableConfiguration['_variables'])) {
                $variables = $tableConfiguration['_variables'];
            }

            unset($tableConfiguration['_variables']);
        }

        return $variables ?? [];
    }

    /**
     * @throws JsonException
     */
    private function importConfigurationFiles(array $configuration): array
    {
        foreach ($configuration['files'] as $fileName) {
            array_merge($configuration, $this->decodeConfigurationFile($fileName));
        }

        return $configuration;
    }

    private function removeAbandonedRecords(array $existingIdentifiers): int
    {
        $table = self::TABLES[$this->currentRecordType];
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable($table);

        return $queryBuilder->delete($table)
            ->where(
                $queryBuilder->expr()
                    ->notIn(
                        self::IDENTIFIER_FIELDS[$this->currentRecordType],
                        $queryBuilder->createNamedParameter(
                            $existingIdentifiers,
                            ArrayParameterType::STRING
                        )
                    )
            )
            ->executeStatement();
    }
}
