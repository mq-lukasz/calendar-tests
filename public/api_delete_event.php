<?php
header('Content-Type: application/json');

require_once __DIR__ . '/vendor/autoload.php';

$input = json_decode(file_get_contents('php://input'), true);

if (empty($input['uid'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Brak identyfikatora UID wydarzenia.']);
    exit;
}

// --- USTAWIENIA ---
$radicaleUrl  = 'http://radicale:5232/';
$userName     = 'test';
$password     = 'test';
$calendarPath = 'test/moj-kalendarz/';
// Strefa czasowa nie jest tu potrzebna, bo EXDATE dla wydarzeń czasowych operuje na UTC

// --- KLIENT DAV ---
$client = new Sabre\DAV\Client([
    'baseUri'  => $radicaleUrl,
    'userName' => $userName,
    'password' => $password,
]);

$uid = $input['uid'];
$scope = $input['scope'] ?? 'single';
$eventName = $uid . '.ics';
$eventPath = $calendarPath . $eventName;


try {
    // Scenariusz 1: Usuwamy pojedyncze wydarzenie lub całą serię (fizyczne usunięcie pliku)
    if ($scope === 'single' || $scope === 'all') {
        $client->request('DELETE', $eventPath);
    }
    // Scenariusz 2: Usuwamy tylko jedno wystąpienie z cyklu
    elseif ($scope === 'instance' && !empty($input['originalStart'])) {

        // --- **NAJWAŻNIEJSZA ZMIANA PONIŻEJ** ---

        // 1. Pobierz istniejący plik .ics z całą serią
        $masterEventResponse = $client->request('GET', $eventPath);
        $vcalendar = Sabre\VObject\Reader::read($masterEventResponse['body']);

        // Znajdź główny komponent VEVENT (ten z regułą RRULE)
        $masterVevent = null;
        foreach($vcalendar->VEVENT as $event) {
            if (isset($event->RRULE)) {
                $masterVevent = $event;
                break;
            }
        }

        if (!$masterVevent) {
            throw new Exception("Nie znaleziono głównego wydarzenia cyklicznego w pliku.");
        }

        // 2. Sprawdź, czy seria jest całodniowa
        $isAllDay = $masterVevent->DTSTART instanceof \Sabre\VObject\Property\ICalendar\Date;
        $originalStartDate = new DateTime($input['originalStart']);

        // 3. Stwórz poprawnie sformatowany EXDATE
        if ($isAllDay) {
            // Dla wydarzeń całodniowych, EXDATE to tylko data (VALUE=DATE)
            $exdateProp = $vcalendar->createProperty(
                'EXDATE',
                new DateTime($originalStartDate->format('Y-m-d'))
            );
            $exdateProp['VALUE'] = 'DATE';
            $masterVevent->add($exdateProp);
        } else {
            // Dla wydarzeń z czasem, EXDATE musi być w UTC
            $originalStartDate->setTimezone(new DateTimeZone('UTC'));
            $masterVevent->add('EXDATE', $originalStartDate);
        }

        // 4. Zapisz zaktualizowany plik .ics z powrotem na serwerze
        $icalData = $vcalendar->serialize();
        $client->request('PUT', $eventPath, $icalData, ['Content-Type' => 'text/calendar; charset=utf-8']);

    } else {
        throw new Exception("Nieprawidłowe polecenie usunięcia.");
    }

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}