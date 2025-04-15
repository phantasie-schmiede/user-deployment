<?php
declare(strict_types=1);

/*
 * This file is part of PSB User Deployment.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace PSB\PsbUserDeployment\Command;

use Doctrine\DBAL\Exception;
use JsonException;
use PSB\PsbUserDeployment\Enum\RecordType;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use function count;
use function in_array;

/**
 * Class ExportCommand
 *
 * @package PSB\PsbUserDeployment\Command
 */
#[AsCommand(name: 'psbUserDeployment:export', description: 'This command exports file mounts, users and user groups into a JSON file which can be used as basis for further additions and optimizations.')]
class ExportCommand extends Command
{
    private const array DEFAULT_VALUES  = [
        'be_groups'      => [
            'allowed_languages'   => '',
            'availableWidgets'    => null,
            'category_perms'      => null,
            'custom_options'      => null,
            'db_mountpoints'      => null,
            'description'         => null,
            'disable_auto_hide'   => 0,
            'disable_auto_prefix' => 0,
            'explicit_allowdeny'  => null,
            'file_mountpoints'    => null,
            'file_permissions'    => null,
            'groupMods'           => null,
            'hidden'              => 0,
            'mfa_providers'       => null,
            'non_exclude_fields'  => null,
            'pagetypes_select'    => null,
            'pid'                 => 0,
            'subgroup'            => null,
            'tables_modify'       => null,
            'tables_select'       => null,
            'TSconfig'            => null,
            'workspace_perms'     => 1,
        ],
        'be_users'       => [
            'admin'             => 0,
            'allowed_languages' => '',
            'avatar'            => 0,
            'category_perms'    => null,
            'db_mountpoints'    => null,
            'description'       => null,
            'disable'           => 0,
            'endtime'           => 0,
            'file_mountpoints'  => null,
            'file_permissions'  => null,
            'lang'              => 'default',
            'mfa'               => null,
            'options'           => 3,
            'pid'               => 0,
            'starttime'         => 0,
            'TSconfig'          => null,
            'userMods'          => null,
            'usergroup'         => null,
            'workspace_id'      => 0,
            'workspace_perms'   => 1,
        ],
        'fe_groups'      => [
            'description'     => '',
            'hidden'          => 0,
            'subgroup'        => '',
            'TSconfig'        => '',
            'tx_extbase_type' => '',
        ],
        'fe_users'       => [
            'TSconfig'        => '',
            'address'         => '',
            'city'            => '',
            'company'         => '',
            'country'         => '',
            'description'     => '',
            'disable'         => 0,
            'email'           => '',
            'endtime'         => 0,
            'fax'             => '',
            'first_name'      => '',
            'image'           => '',
            'last_name'       => '',
            'middle_name'     => '',
            'name'            => '',
            'starttime'       => 0,
            'telephone'       => '',
            'title'           => '',
            'tx_extbase_type' => '',
            'www'             => '',
            'zip'             => '',
        ],
        'sys_filemounts' => [
            'description' => '',
            'hidden'      => 0,
            'pid'         => 0,
            'read_only'   => 0,
        ],
    ];
    private const array EXCLUDED_FIELDS = [
        'crdate',
        'cruser_id',
        'deleted',
        'is_online',
        'lastlogin',
        'mfa',
        'password',
        'password_reset_token',
        'sorting',
        'tstamp',
        'uc',
        'uid',
    ];
    protected SymfonyStyle $io;

    protected function configure(): void
    {
        $this->setHelp(
            'This command exports a JSON file containing the existing file mount, user and user group records in both backend and frontend.'
        )
            ->addArgument('file', InputArgument::REQUIRED, 'Provide the name of the created export file.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $configuration = [];
        $mapping = [];
        $this->io = new SymfonyStyle($input, $output);
        $fileName = $input->getArgument('file');

        $this->io->writeln('Exporting file mounts, users and user groups to ' . $fileName . '.');

        try {
            foreach (RecordType::strictlyOrderedCases() as $recordType) {
                $this->exportToConfiguration($configuration, $mapping, $recordType);
            }

            $this->exportToFile($configuration, $fileName);
            $this->io->success('The export has been successfully created.');

            return Command::SUCCESS;
        } catch (Throwable $exception) {
            $this->io->error('An error occurred during the export process: ' . $exception->getMessage());

            return Command::FAILURE;
        }
    }

    /**
     * @throws Exception
     */
    private function exportToConfiguration(array &$configuration, array &$mapping, RecordType $recordType): void
    {
        $table = $recordType->getTable();
        $configuration[$table]['_default'] = self::DEFAULT_VALUES[$table];
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()
            ->removeAll();
        $records = $queryBuilder->select('*')
            ->from($table)
            ->where(
                $queryBuilder->expr()
                    ->eq('deleted', 0)
            )
            ->executeQuery()
            ->fetchAllAssociative();
        $this->io->writeln('Exporting ' . count($records) . ' ' . $table . ' records');

        if (in_array($recordType, [
            RecordType::BackendGroup,
            RecordType::FileMount,
            RecordType::FrontendGroup,
        ], true)) {
            foreach ($records as $record) {
                $mapping[$table][$record['uid']] = $record[$recordType->getIdentifierField()];
            }
        }

        foreach ($records as $record) {
            $identifierField = $recordType->getIdentifierField();
            $identifier = $record[$identifierField];
            unset($record[$identifierField]);
            if (null !== $recordType->getGroupField()) {
                $this->replaceRelationIdentifier(
                    $mapping[$recordType->getGroupTable()],
                    $record,
                    $recordType->getGroupField()
                );
            }

            if (in_array($recordType, [
                RecordType::BackendGroup,
                RecordType::BackendUser,
            ], true)) {
                $this->replaceRelationIdentifier($mapping['sys_filemounts'], $record, 'file_mountpoints');
            }

            foreach ($record as $field => $value) {
                // Add field to configuration if it is not the default value and not in the excluded fields.
                // @formatter:off
                if ((!isset($configuration[$table]['_default'][$field]) || $value !== $configuration[$table]['_default'][$field])
                    && (null !== ($configuration[$table]['_default'][$field] ?? true) || '' !== $value)
                    && !in_array(
                        $field,
                        self::EXCLUDED_FIELDS,
                        true
                    )) {
                    // format:on
                    $configuration[$table][$identifier][$field] = $value;
                }
            }

            ksort($configuration[$table][$identifier], SORT_NATURAL | SORT_FLAG_CASE);
        }

        ksort($configuration[$table], SORT_NATURAL | SORT_FLAG_CASE);
    }

    /**
     * @throws JsonException
     */
    private function exportToFile(array $configuration, string $fileName): void
    {
        ksort($configuration, SORT_NATURAL | SORT_FLAG_CASE);
        $file = GeneralUtility::getFileAbsFileName($fileName);
        $fileContent = json_encode($configuration, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        GeneralUtility::writeFile($file, $fileContent);
    }

    private function replaceRelationIdentifier(array $tableMapping, array &$record, string $relationField): void
    {
        if (empty($record[$relationField])) {
            return;
        }

        $relations = GeneralUtility::intExplode(',', $record[$relationField]);

        array_walk($relations, static function(&$value) use ($tableMapping) {
            $value = $tableMapping[$value] ?? '';
        });

        $relations = array_filter($relations);
        sort($relations, SORT_NATURAL | SORT_FLAG_CASE);
        $record[$relationField] = $relations;
    }
}
