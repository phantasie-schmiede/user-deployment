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
namespace PSB\PsbUserDeployment\Enum;

/**
 * Class RecordTypes
 *
 * @package PSB\PsbUserDeployment\Enum
 */
enum RecordTypes: string
{
    case BACKEND_GROUP = 'BackendGroup';
    case BACKEND_USER = 'BackendUser';
    case FRONTEND_GROUP = 'FrontendGroup';
    case FRONTEND_USER = 'FrontendUser';

    public static function containsUsers(RecordTypes $recordType): bool
    {
        return in_array($recordType, [self::BACKEND_USER, self::FRONTEND_USER]);
    }

    public static function getIdentifierField(RecordTypes $recordType): string
    {
        return match ($recordType) {
            self::BACKEND_GROUP, self::FRONTEND_GROUP => 'title',
            self::BACKEND_USER, self::FRONTEND_USER => 'username',
        };
    }

    public static function getTable(RecordTypes $recordType): string
    {
        return match ($recordType) {
            self::BACKEND_GROUP => 'be_groups',
            self::BACKEND_USER => 'be_users',
            self::FRONTEND_GROUP => 'fe_groups',
            self::FRONTEND_USER => 'fe_users',
        };
    }

    /**
     * @return RecordTypes[]
     */
    public static function strictlyOrderedCases(?string $context = null): array
    {
        if ('BE' === $context) {
            return [
                0 => self::BACKEND_GROUP,
                1 => self::BACKEND_USER,
            ];
        }

        if ('FE' === $context) {
            return [
                0 => self::FRONTEND_GROUP,
                1 => self::FRONTEND_USER,
            ];
        }

        return [
            0 => self::BACKEND_GROUP,
            2 => self::FRONTEND_GROUP,
            1 => self::BACKEND_USER,
            3 => self::FRONTEND_USER,
        ];
    }
}
