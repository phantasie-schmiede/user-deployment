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
 *         "_default": {
 *
 *         },
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
 *         "_default": {
 *             "company": "Doe Inc."
 *         },
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
    protected array        $frontendGroups         = [];
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
            $this->deploy($configuration['be_groups'], self::RECORD_TYPES['BACKEND_GROUP']);
        }

        if (!empty($configuration['be_users']) && is_array($configuration['be_users'])) {
            $this->deploy($configuration['be_users'], self::RECORD_TYPES['BACKEND_USER']);
        }

        if (!empty($configuration['fe_groups']) && is_array($configuration['fe_groups'])) {
            $this->deploy($configuration['fe_groups'], self::RECORD_TYPES['FRONTEND_GROUP']);
        }

        if (!empty($configuration['fe_users']) && is_array($configuration['fe_users'])) {
            $this->deploy($configuration['fe_users'], self::RECORD_TYPES['FRONTEND_USER']);
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
    private function deploy(array $configuration, string $currentRecordType): void
    {
        $this->currentRecordType = $currentRecordType;
        $createdRecords = 0;
        $updatedRecords = 0;

        // The default settings and variables have to be extracted before further processing of the array!
        $default = $this->extractValue($configuration, '_default');
        $variables = $this->extractValue($configuration, '_variables');
        $identifiers = array_keys($configuration);
        $existingRecords = $this->getExistingRecords($identifiers);
        $existingIdentifiers = array_column($existingRecords, self::IDENTIFIER_FIELDS[$this->currentRecordType]);

        if ($this->removeAbandonedRecords) {
            if ($this->dryRun) {
                $this->io->info($this->countAbandonedRecords($identifiers) . ' records would be removed.');
            } else {
                $this->io->info($this->removeAbandonedRecords($identifiers) . ' records have been removed.');
            }
        }

        // Backend group:
        // $subgroupReferences = [];

        foreach ($configuration as $identifier => $settings) {
            $settings = array_merge($default, $settings);

            // Replace variable references:
            array_walk($settings, static function(&$value) use ($variables) {
                if (isset($variables[$value])) {
                    $value = $variables[$value];
                }
            });

            $settings[self::IDENTIFIER_FIELDS[$this->currentRecordType]] = $identifier;
            $settings['tstamp'] = time();

            // Backend group:
            // if (isset($settings['subgroup'])) {
            //     $subgroupReferences[$identifier] = $settings['subgroup'];
            //     unset($settings['subgroup']);
            // }

            // Backend user:
            // if (isset($settings['usergroup'])) {
            //    array_walk($settings['usergroup'], function(&$value) {
            //         $value = $this->backendGroups[$value];
            //     });
            //     $settings['usergroup'] = implode(',', $settings['usergroup']);
            // }

            $connection = GeneralUtility::makeInstance(ConnectionPool::class)
                ->getConnectionForTable(self::TABLES[$this->currentRecordType]);

            if (in_array(
                $identifier,
                $existingIdentifiers,
                true
            )) {
                if (!$this->dryRun) {
                    $connection->update(
                        self::TABLES[$this->currentRecordType], $settings,
                        [self::IDENTIFIER_FIELDS[$this->currentRecordType] => $identifier]
                    );
                }

                $updatedRecords++;
            } else {
                if (!$this->dryRun) {
                    $settings['crdate'] = $settings['tstamp'];
                    $connection->insert(self::TABLES[$this->currentRecordType], $settings);
                }

                $createdRecords++;
                // Backend group:
                // $this->backendGroups[$identifier] = $connection->lastInsertId(self::TABLES[$this->currentRecordType]);
            }
        }

        // Backend group:
        // foreach ($existingRecords as $existingRecord) {
        //     $this->backendGroups[$existingRecord[self::IDENTIFIER_FIELDS[$this->currentRecordType]]] = $existingRecord['uid'];
        // }
        // // Add subgroup information after collecting all UIDs:
        // foreach ($subgroupReferences as $identifier => $subgroupReference) {
        //     // Replace title with actual UID:
        //     array_walk($subgroupReference, function(&$value) {
        //         $value = $this->backendGroups[$value];
        //     });
        //     $connection = GeneralUtility::makeInstance(ConnectionPool::class)
        //         ->getConnectionForTable(self::TABLES[$this->currentRecordType]);
        //     $connection->update(
        //         self::TABLES[$this->currentRecordType],
        //         ['subgroup' => implode(',', $subgroupReference)],
        //         [self::IDENTIFIER_FIELDS[$this->currentRecordType] => $identifier]
        //     );
        // }

        if ($this->dryRun) {
            $this->io->info($createdRecords . ' records would be created.');
            $this->io->info($updatedRecords . ' records would be updated.');
        } else {
            $this->io->info($createdRecords . ' records have been created.');
            $this->io->info($updatedRecords . ' records have been updated.');
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
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable(self::TABLES[$this->currentRecordType]);

        return $queryBuilder->select(self::IDENTIFIER_FIELDS[$this->currentRecordType], 'uid')
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
