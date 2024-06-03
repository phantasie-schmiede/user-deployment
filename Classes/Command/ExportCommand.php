<?php
declare(strict_types=1);

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */
namespace PSB\PsbUserDeployment\Command;

use Exception;
use PSB\PsbUserDeployment\Enum\RecordTypes;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

#[AsCommand(name: 'psbUserDeployment:export', description: 'Exports the user and group configuration to a JSON file.')]
class ExportCommand extends Command
{
    // For each affected table, we define the fields that we want to export.
    protected const RELEVANT_FIELDS = [
        'be_users'  => [
            'username',
            'password',
            'admin',
            'usergroup',
            'lang',
            'email',
            'realName',
            // 'uc', do we need this? disabled for readability of JSON.
            'file_permissions',
            'TSconfig', // This is only used for two entries?
            'mfa',
        ],
        'be_groups' => [
            'title',
            'non_exclude_fields',
            'explicit_allowdeny',
            'allowed_languages',
            'db_mountpoints',
            'pagetypes_select',
            'tables_select',
            'tables_modify',
            'groupMods',
            'file_mountpoints',
            'file_permissions',
            'description',
            'TSconfig',
            'subgroup',
            'workspace_perms',
            'category_perms',
            'disable_auto_prefix',
            'disable_auto_hide',
            'availableWidgets',
        ],
    ];
    protected string $context = '';
    /**
     * @var array<string, array<string, array<string, mixed>>>
     */
    protected array        $data         = [];
    protected SymfonyStyle $io;
    protected string       $storage_path = '';

    protected function configure(): void
    {
        $this->setHelp('This command exports the TYPO3 Tables be_users, be_groups, fe_users and fe_groups to a JSON file.')
            ->addArgument('path', InputArgument::REQUIRED,
                'The relative path to a directory in which the generated configuration File should be stored.')
            ->addArgument('context', InputArgument::OPTIONAL,
                'The context for which Users and Groups should be exported. Enter "BE" for Backend or "FE" for Frontend (not implemented yet). Leave this empty to export both.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->io = new SymfonyStyle($input, $output);
        $this->storage_path = $input->getArgument('path');
        $this->context = $input->getArgument('context');

        if ('BE' !== $this->context) {
            throw new RuntimeException('Only Backend export is implemented at the moment.');
        }

        $this->io->writeln('Exporting users and user groups to ' . $this->storage_path . 'export.json');

        try {
            foreach (RecordTypes::strictlyOrderedCases() as $recordType) {
                $this->export($recordType);
            }

            $this->postProcess();

            file_put_contents($this->storage_path . 'export.json',
                json_encode($this->data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

            return Command::SUCCESS;
        } catch (Throwable) {
            $this->io->error('An error occurred during the export process.');

            return Command::FAILURE;
        }
    }

    private function export(RecordTypes $recordType): void
    {
        $this->io->writeln('Exporting ' . $recordType->value . ' records');

        $this->fillDefaultValues($recordType);

        // Grab values from database
        $queryBuilder = $this->getQueryBuilder($recordType);

        $queryBuilder = $this->getQueryBuilder($recordType)
            ->select(...self::RELEVANT_FIELDS[RecordTypes::getTable($recordType)])
            ->from(RecordTypes::getTable($recordType))
            ->where($queryBuilder->expr()->eq('deleted', 0));

        $data = $queryBuilder->executeQuery()->fetchAllAssociative();

        $this->io->writeln('Exporting ' . count($data) . ' ' . $recordType->value . ' records');

        foreach ($data as $record) {
            $this->processData($recordType, $record);
        }
    }

    private function fillDefaultValues(RecordTypes $recordType): void
    {
        $this->data[$recordType->value]['_default'] = match ($recordType) {
            RecordTypes::BACKEND_GROUP => [
                'non_exclude_fields'  => '',
                'explicit_allowdeny'  => '',
                'allowed_languages'   => '',
                'db_mountpoints'      => '',
                'pagetypes_select'    => '',
                'tables_select'       => '',
                'tables_modify'       => '',
                'groupMods'           => '',
                'file_mountpoints'    => '',
                'file_permissions'    => '',
                'description'         => '',
                'TSconfig'            => '',
                'subgroup'            => '',
                'workspace_perms'     => 0,
                'category_perms'      => '',
                'disable_auto_prefix' => '',
                'disable_auto_hide'   => 0,
                'availableWidgets'    => 0,
            ],
            RecordTypes::BACKEND_USER => [
                'admin'            => 0,
                'lang'             => 'default',
                'file_permissions' => '',
                'TSconfig'         => '',
                'mfa'              => null,
            ],
            RecordTypes::FRONTEND_GROUP, RecordTypes::FRONTEND_USER => throw new Exception('To be implemented'),
        };
    }

    private function getQueryBuilder(RecordTypes $recordType): QueryBuilder
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable(RecordTypes::getTable($recordType));

        $queryBuilder->getRestrictions()->removeAll();

        return $queryBuilder;
    }

    private function isDefaultValue(RecordTypes $recordType, string $key, mixed $value): bool
    {
        if (!array_key_exists($key, $this->data[$recordType->value]['_default'])) {
            return false;
        }

        if (is_scalar($this->data[$recordType->value]['_default'][$key]) && is_scalar($value)) {
            return (string)$this->data[$recordType->value]['_default'][$key] === (string)$value;
        }

        return $this->data[$recordType->value]['_default'][$key] === $value;
    }

    private function postProcess(): void
    {
        // @TODO: What do we do, if one of the referenced records is deleted?
        // For now, we default to removing the reference

        // Find backend Groups by title and uid!
        $backendGroupsByTitleAndUid = [];
        $queryBuilder = $this->getQueryBuilder(RecordTypes::BACKEND_GROUP);
        $queryBuilder->select('uid', 'title')
            ->from(RecordTypes::getTable(RecordTypes::BACKEND_GROUP))
            ->where($queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0)));

        foreach ($queryBuilder->executeQuery()->fetchAllAssociative() as $row) {
            $backendGroupsByTitleAndUid[$row['uid']] = $row['title'];
        }

        // Replace Subgroup references in BackendGroup (comma separated uids) with an array of titles.
        $this->resolveCommaSeparatedReferences(RecordTypes::BACKEND_GROUP, 'subgroup', $backendGroupsByTitleAndUid);

        // Replace usergroup references in BackendUser (comma separated uids) with an array of titles.
        $this->resolveCommaSeparatedReferences(RecordTypes::BACKEND_USER, 'usergroup', $backendGroupsByTitleAndUid);
    }

    /**
     * @param RecordTypes          $recordType
     * @param array<string, mixed> $data
     *
     * @return void
     */
    private function processData(RecordTypes $recordType, array $data): void
    {
        $identifierField = RecordTypes::getIdentifierField($recordType);
        $cleanData = array_filter($data, static fn($key) => $identifierField !== $key, ARRAY_FILTER_USE_KEY);

        // Remove values that are the same as their default values.
        foreach ($cleanData as $key => $value) {
            // Convert to string, because we do not care for the difference between empty string and null.
            if ($this->isDefaultValue($recordType, $key, $value)) {
                unset($cleanData[$key]);
            }
        }

        // Write data into memory storage under the correct identifiers
        $this->data[$recordType->value][$data[$identifierField]] = $cleanData;
    }

    /**
     * @param RecordTypes        $recordType
     * @param string             $fieldname
     * @param array<int, string> $uidToTitleMap
     *
     * @return void
     */
    private function resolveCommaSeparatedReferences(
        RecordTypes $recordType,
        string $fieldname,
        array $uidToTitleMap
    ): void {
        foreach ($this->data[$recordType->value] as $title => $record) {
            if (in_array($title, ['_default', '_variables'])) {
                continue;
            }

            if (!array_key_exists($fieldname, $record)) {
                continue;
            }

            if (empty($record[$fieldname]) || !is_string($record[$fieldname])) {
                $this->data[RecordTypes::BACKEND_USER->value][$title][$fieldname] = [];
                continue;
            }

            $uidList = explode(',', $record[$fieldname]);
            $this->data[$recordType->value][$title][$fieldname] = [];

            foreach ($uidList as $number => $usergroupUid) {
                if (!array_key_exists($usergroupUid, $uidToTitleMap)) {
                    $this->io->warning('Usergroup ' . $usergroupUid . ' of user ' . $title . ' does not exist.');
                    unset($uidList[$number]);

                    continue;
                }

                $this->data[$recordType->value][$title][$fieldname][] = $uidToTitleMap[$usergroupUid];
            }
        }
    }
}
