<?php

$EM_CONF[$_EXTKEY] = [
    'author'           => 'Daniel Ablass',
    'author_email'     => 'dn@phantasie-schmiede.de',
    'category'         => 'misc',
    'clearCacheOnLoad' => true,
    'constraints'      => [
        'conflicts' => [],
        'depends'   => [
            'php'   => '8.3',
            'typo3' => '13.4.0-13.4.99',
        ],
        'suggests'  => [],
    ],
    'description'      => 'Versioning and deployment of users, user groups and their privileges',
    'state'            => 'alpha',
    'title'            => 'PSbits | User Deployment',
    'version'          => '0.0.0',
];
