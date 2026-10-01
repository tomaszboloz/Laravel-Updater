<?php

declare(strict_types=1);

return [
    'title' => 'Updates',
    'versions' => 'Application version',
    'current' => 'Installed',
    'latest' => 'Latest release',
    'state' => 'Status',
    'check' => 'Check for updates',
    'update' => 'Update now',
    'up_to_date' => 'The application is up to date',
    'available' => 'Version :version is available',
    'confirm' => 'Version :version will be installed: the site enters maintenance mode, then the code, Composer packages, migrations, npm build and caches are updated. Make sure you have a database backup.',
    'queued' => 'The update has been queued. This page refreshes automatically.',
    'release_notes' => 'What is new in :version',
    'open_release' => 'Open the release on GitHub',
    'log' => 'Update log',
    'states' => [
        'idle' => 'Idle',
        'queued' => 'Queued',
        'running' => 'Running',
        'succeeded' => 'Succeeded',
        'failed' => 'Failed',
    ],
];
