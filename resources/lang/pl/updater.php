<?php

declare(strict_types=1);

return [
    'title' => 'Aktualizacje',
    'versions' => 'Wersja aplikacji',
    'current' => 'Zainstalowana',
    'latest' => 'Najnowsze wydanie',
    'state' => 'Status',
    'check' => 'Sprawdź aktualizacje',
    'update' => 'Aktualizuj teraz',
    'up_to_date' => 'Aplikacja jest aktualna',
    'available' => 'Dostępna jest wersja :version',
    'confirm' => 'Zostanie zainstalowana wersja :version: strona przejdzie w tryb serwisowy, a następnie zostaną zaktualizowane kod, pakiety Composer, migracje, build npm i cache. Upewnij się, że masz kopię zapasową bazy danych.',
    'queued' => 'Aktualizacja została dodana do kolejki. Strona odświeża się automatycznie.',
    'release_notes' => 'Co nowego w :version',
    'open_release' => 'Otwórz wydanie na GitHubie',
    'log' => 'Dziennik aktualizacji',
    'states' => [
        'idle' => 'Bezczynny',
        'queued' => 'W kolejce',
        'running' => 'W trakcie',
        'succeeded' => 'Zakończona',
        'failed' => 'Błąd',
    ],
];
