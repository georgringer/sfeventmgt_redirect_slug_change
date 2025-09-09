<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Automated redirects for EXT:sf_event_mgt records',
    'description' => 'Generate a redirect if the slug of a sf_event_mgt event changes',
    'category' => 'be',
    'author' => 'Georg Ringer',
    'author_email' => 'mail@ringer.it',
    'state' => 'stable',
    'clearCacheOnLoad' => true,
    'version' => '1.0.0',
    'constraints' => [
        'depends' => [
            'typo3' => '13.4.0-13.4.99',
            'redirects' => '13.4.0-13.4.99',
            'sf_event_mgt' => '8.0.0-8.9.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
