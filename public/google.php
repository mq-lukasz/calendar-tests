<?php

// Załaduj autoload Composera
require __DIR__ . '/vendor/autoload.php';

// Użycie instrukcji 'use' dla poprawnego zaimportowania klas z przestrzeni nazw
use Google\Client;
use Google\Service\Calendar;
use Google\Service\Calendar\Event;

// 1. Zdefiniuj ścieżkę do pliku JSON klucza serwisowego
$serviceAccountKeyFile = __DIR__.'/tillio-calendar.json';

// 2. Zdefiniuj ID kalendarza
$calendarId = 'ece82b2230ef9ce7b9282ae62dc9a5d7d8b58a2a9627724a8051096b13a22b81@group.calendar.google.com';
//$calendarId = 'primary';

// 3. Ustaw zakres dostępu
$scopes = [Calendar::CALENDAR];

try {
    // Utwórz klienta Google (Używamy Google\Client)
    $client = new Client();
    $client->setApplicationName("Calendar API PHP");

    // Ustaw uwierzytelnianie na podstawie klucza konta serwisowego
    $client->setAuthConfig($serviceAccountKeyFile);
    $client->setSubject('l.goracy@tillio.pl');
    $client->setScopes($scopes);

    // Utwórz instancję serwisu Calendar (Używamy Google\Service\Calendar)
    $service = new Calendar($client);
/*
    $optParams = [
      //  'minAccessRole' => 'reader',
        'showHidden' => true,        // Pokaż też te, które użytkownik ukrył w UI
    ];

    $calendarList = $service->calendarList->listCalendarList($optParams);

    echo "## 📅 Lista dostępnych kalendarzy:\n\n";

    foreach ($calendarList->getItems() as $calendarEntry) {
        $id = $calendarEntry->getId();
        $summary = $calendarEntry->getSummary(); // Nazwa wyświetlana
        $role = $calendarEntry->getAccessRole(); // Twój poziom dostępu
        $primary = $calendarEntry->getPrimary() ? "[GŁÓWNY]" : "";

        echo "------------------------------------------------\n";
        echo "Nazwa:  $summary $primary\n";
        echo "ID:     $id\n";
        echo "Dostęp: $role\n";

        // Wyświetlenie opisu, jeśli istnieje
        if ($calendarEntry->getDescription()) {
            echo "Opis:   " . substr($calendarEntry->getDescription(), 0, 50) . "...\n";
        }
    }

    exit;*/

    // 2. Przygotowanie danych wydarzenia
    // Tworzymy obiekt wydarzenia
    $event = new Event([
        'summary' => 'Spotkanie Projektowe API',
        'location' => 'Sala Konferencyjna / Online',
        'description' => 'Omówienie integracji Google Calendar.',

        // Data i czas rozpoczęcia
        'start' => [
            'dateTime' => '2025-11-22T10:00:00', // Format ISO
            'timeZone' => 'Europe/Warsaw',
        ],

        // Data i czas zakończenia
        'end' => [
            'dateTime' => '2025-11-22T11:00:00',
            'timeZone' => 'Europe/Warsaw',
        ],

        // (Opcjonalnie) Zaproszenie gości
        'attendees' => [
            ['email' => 'l.goracy@muscode.pl'],
            ['email' => 'j@fatal.pl'],
        ],

        // (Opcjonalnie) Remindery (powiadomienia)
        'reminders' => [
            'useDefault' => false,
            'overrides' => [
                ['method' => 'email', 'minutes' => 24 * 60], // Email dzień wcześniej
                ['method' => 'popup', 'minutes' => 10],      // Powiadomienie 10 min przed
            ],
        ],
       /* 'conferenceData' => [
            'createRequest' => [
                // Unikalny ID żądania (np. losowy ciąg znaków) - zapobiega dublom
                'requestId' => 'req_' . time() . '_' . rand(1000,9999),
                'conferenceSolutionKey' => [
                    'type' => 'hangoutMeet' // To oznacza Google Meet
                ]
            ]
        ]*/
    ]);

    // 3. Wysłanie do API (INSERT)
    // $calendarId to zazwyczaj 'primary'
    $createdEvent = $service->events->insert($calendarId, $event, [
       // 'conferenceDataVersion' => 1,
        'sendUpdates' => 'all', // <--- TO WYSYŁA EMAILE DO GOŚCI
    ]);

    // 4. Sukces!
    echo "✅ Wydarzenie dodane!\n";
    echo "Link do wydarzenia: " . $createdEvent->htmlLink . "\n";
    echo "ID wydarzenia: " . $createdEvent->getId();

    $hangoutLink = $createdEvent->getHangoutLink(); // Bezpośredni link do Meet

    echo "🔗 Link do Google Meet: " . $hangoutLink . "\n";
exit;
    // Opcje zapytania
    $optParams = array(
        'maxResults' => 10,
        'orderBy' => 'startTime',
        'singleEvents' => true,
        'timeMin' => date('c', strtotime('2025-11-10 00:00:00')),
        'timeMax' => date('c', strtotime('2025-11-30 23:59:59')),
    );

    // Wywołaj metodę listEvents
    // Zwrócony obiekt jest typu Google\Service\Calendar\Events
    $events = $service->events->listEvents($calendarId, $optParams);

    echo "## 📅 Najbliższe wydarzenia z kalendarza: {$calendarId}\n";

    if (empty($events->getItems())) {
        echo "Brak nadchodzących wydarzeń.\n";
    } else {
        foreach ($events->getItems() as $event) {
            // Obiekt $event jest typu Google\Service\Calendar\Event
            $start = $event->getStart()->getDateTime();
            if (empty($start)) {
                $start = $event->getStart()->getDate();
            }

            echo "<pre>".print_r($event->getAttendees(), true)."</pre>";

            echo "* **{$event->getSummary()}** ({$start}) {$event->getHangoutLink()} | {$event->getICalUID()} | \n";
        }
    }

} catch (\Exception $e) {
    echo "Wystąpił błąd: " . $e->getMessage() . "\n";
}

?>