<?php
header('Content-Type: application/json');

require_once __DIR__ . '/vendor/autoload.php';

$input = json_decode(file_get_contents('php://input'), true);

if (empty($input['title']) || empty($input['start']) || empty($input['end'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Tytuł, początek i koniec są wymagane.']);
    exit;
}

// --- USTAWIENIA ---
$radicaleUrl  = 'http://radicale:5232/';
$userName     = 'test';
$password     = 'test';
$calendarPath = 'test/moj-kalendarz/';
$timeZoneId   = 'Europe/Warsaw';

// --- KLIENT DAV ---
$client = new Sabre\DAV\Client([
    'baseUri'  => $radicaleUrl,
    'userName' => $userName,
    'password' => $password,
]);

$uid = !empty($input['uid']) ? $input['uid'] : uniqid();
$eventName = $uid . '.ics';
$eventPath = $calendarPath . $eventName;
$tz = new DateTimeZone($timeZoneId);

// --- Prawidłowa obsługa wyjątków w seriach cyklicznych ---
if (!empty($input['uid']) && !empty($input['originalStart']) && !empty($input['isRecurring'])) {
    try {
        $masterEventResponse = $client->request('GET', $eventPath);
        $vcalendar = Sabre\VObject\Reader::read($masterEventResponse['body']);
        $originalStartDate = new DateTime($input['originalStart']);

        // --- **NAJWAŻNIEJSZA ZMIANA PONIŻEJ** ---
        $exceptionExists = false;
        // 1. Sprawdź, czy wyjątek dla tej daty już istnieje
        foreach ($vcalendar->VEVENT as $existingEvent) {
            if (isset($existingEvent->{'RECURRENCE-ID'})) {
                if ($existingEvent->{'RECURRENCE-ID'}->getDateTime()->getTimestamp() === $originalStartDate->getTimestamp()) {
                    // 2. Jeśli tak, ZAKTUALIZUJ jego właściwości
                    $existingEvent->SUMMARY = $input['title'];
                    $existingEvent->DESCRIPTION = $input['description'];
                    $existingEvent->DTSTART->setDateTime(new DateTime($input['start'], $tz));
                    $existingEvent->DTEND->setDateTime(new DateTime($input['end'], $tz));
                    $exceptionExists = true;
                    break;
                }
            }
        }

        // 3. Jeśli wyjątek nie istniał, stwórz go i DODAJ do kalendarza
        if (!$exceptionExists) {
            $exceptionEvent = $vcalendar->add('VEVENT');
            $exceptionEvent->UID = $uid;
            $exceptionEvent->add('RECURRENCE-ID', $originalStartDate);
            $exceptionEvent->add('SUMMARY', $input['title']);
            if (!empty($input['description'])) $exceptionEvent->add('DESCRIPTION', $input['description']);
            $exceptionEvent->add('DTSTART', new DateTime($input['start'], $tz));
            $exceptionEvent->add('DTEND', new DateTime($input['end'], $tz));
        }

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => "Błąd podczas wczytywania serii do edycji: " . $e->getMessage()]);
        exit;
    }
} else {
    // Logika dla tworzenia NOWYCH wydarzeń lub edycji POJEDYNCZYCH
    $vcalendar = new Sabre\VObject\Component\VCalendar();


    $vevent = $vcalendar->add('VEVENT');
    $vevent->UID = $uid;
    $vevent->add('SUMMARY', $input['title']);
    if (!empty($input['description'])) $vevent->add('DESCRIPTION', $input['description']);
    $vevent->add('DTSTART', new DateTime($input['start'], $tz));
    $vevent->add('DTEND', new DateTime($input['end'], $tz));

    if (!empty($input['recurrence']) && $input['recurrence'] !== 'none') {
        $rrule = '';
        switch ($input['recurrence']) {
            case 'daily': $rrule = 'FREQ=DAILY'; break;
            case 'weekly': $rrule = 'FREQ=WEEKLY'; break;
            case 'monthly': $rrule = 'FREQ=MONTHLY'; break;
            case 'every_x_days':
                $interval = filter_var($input['interval'], FILTER_VALIDATE_INT) ?: 1;
                $rrule = "FREQ=DAILY;INTERVAL={$interval}";
                break;
        }
        if ($rrule) {
            $vevent->add('RRULE', $rrule);
        }
    }
}

$icalData = $vcalendar->serialize();

try {
    $client->request('PUT', $eventPath, $icalData, ['Content-Type' => 'text/calendar; charset=utf-8']);
    echo json_encode(['success' => true, 'uid' => $uid]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}