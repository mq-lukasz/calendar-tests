<?php

header('Content-Type: application/json');

require_once __DIR__ . '/vendor/autoload.php';

$year = filter_input(INPUT_GET, 'year', FILTER_VALIDATE_INT) ?: date('Y');
$month = filter_input(INPUT_GET, 'month', FILTER_VALIDATE_INT) ?: date('m');

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

// --- OKRES DO POBRANIA ---
$tz = new DateTimeZone($timeZoneId);
$startDate = new DateTime("{$year}-{$month}-01 00:00:00", $tz);
$endDate   = new DateTime($startDate->format('Y-m-t 23:59:59'), $tz);

// --- ZAPYTANIE CALDAV ---
$query = <<<XML
<C:calendar-query xmlns:D="DAV:" xmlns:C="urn:ietf:params:xml:ns:caldav">
    <D:prop>
        <D:getetag/>
        <C:calendar-data>
            <C:expand start="{$startDate->format('Ymd\THis\Z')}" end="{$endDate->format('Ymd\THis\Z')}"/>
        </C:calendar-data>
    </D:prop>
    <C:filter>
        <C:comp-filter name="VCALENDAR">
            <C:comp-filter name="VEVENT">
                <C:time-range start="{$startDate->format('Ymd\THis\Z')}" end="{$endDate->format('Ymd\THis\Z')}"/>
            </C:comp-filter>
        </C:comp-filter>
    </C:filter>
</C:calendar-query>
XML;

$eventsOutput = [];
$masterEventCache = []; // Zmieniamy cache, aby przechowywał cały główny VEVENT

try {
    $rawResponse = $client->request('REPORT', $calendarPath, $query, ['Depth' => '1', 'Content-Type' => 'application/xml; charset=utf-8']);
    $parsedResponse = $client->parseMultiStatus($rawResponse['body']);

    if (!empty($parsedResponse)) {
        foreach ($parsedResponse as $eventUrl => $propertiesByStatus) {
            $eventProps = $propertiesByStatus[200] ?? [];
            if (isset($eventProps['{urn:ietf:params:xml:ns:caldav}calendar-data'])) {
                $icalData = $eventProps['{urn:ietf:params:xml:ns:caldav}calendar-data'];
                $vcalendar = Sabre\VObject\Reader::read($icalData);

                foreach ($vcalendar->VEVENT as $instance) { // Zmieniamy nazwę zmiennej na 'instance' dla czytelności
                    $uid = (string)$instance->UID;
                    $isRecurring = isset($instance->{'RECURRENCE-ID'}) || isset($instance->RRULE);
                    $masterEvent = null;

                    // Jeśli to wydarzenie cykliczne, potrzebujemy jego "szablonu"
                    if ($isRecurring) {
                        if (!isset($masterEventCache[$uid])) {
                            // Pobieramy główny plik .ics, jeśli nie ma go w cache
                            $masterEventResponse = $client->request('GET', $calendarPath . $uid . '.ics');
                            if ($masterEventResponse['statusCode'] === 200) {
                                $masterVcal = Sabre\VObject\Reader::read($masterEventResponse['body']);
                                $masterEventCache[$uid] = $masterVcal->VEVENT; // Zapisujemy w cache cały VEVENT
                            }
                        }
                        $masterEvent = $masterEventCache[$uid] ?? null;
                    }

                    // Logika łączenia danych: dane z instancji mają priorytet nad danymi z szablonu
                    $summary = isset($instance->SUMMARY) ? (string)$instance->SUMMARY : ($masterEvent && isset($masterEvent->SUMMARY) ? (string)$masterEvent->SUMMARY : '');
                    $description = isset($instance->DESCRIPTION) ? (string)$instance->DESCRIPTION : ($masterEvent && isset($masterEvent->DESCRIPTION) ? (string)$masterEvent->DESCRIPTION : '');
                    $rrule = $masterEvent && isset($masterEvent->RRULE) ? (string)$masterEvent->RRULE : null;

                    $isAllDay = $instance->DTSTART instanceof \Sabre\VObject\Property\ICalendar\Date;
                    $dtstart = $instance->DTSTART->getDateTime();
                    //$dtend = $isAllDay ? $instance->DTEND->getDateTime()->modify('-1 day') : $instance->DTEND->getDateTime();


                    // --- **KLUCZOWA POPRAWKA PONIŻEJ** ---
                    // Sprawdzamy, czy istnieje DTEND. Jeśli nie, sprawdzamy DURATION.
                    if (isset($instance->DTEND)) {
                        if ($isAllDay) {
                            $dtend = $instance->DTEND->getDateTime()->modify('-1 day');
                        } else {
                            $dtend = $instance->DTEND->getDateTime();
                        }
                    } elseif (isset($instance->DURATION)) {
                        // Klonujemy datę startu i dodajemy do niej czas trwania
                        $dtend = clone $dtstart;
                        $duration = Sabre\VObject\DateTimeParser::parseDuration($instance->DURATION);
                        $dtend->add($duration);
                    } else {
                        // Sytuacja awaryjna - jeśli nie ma ani DTEND, ani DURATION
                        // Ustawiamy datę końca taką samą jak datę startu
                        $dtend = clone $dtstart;
                    }

                    $dtstart->setTimezone($tz);
                    $dtend->setTimezone($tz);

                    $eventsOutput[] = [
                        'uid' => $uid,
                        'summary' => $summary,
                        'description' => $description,
                        'start' => $dtstart->format('c'),
                        'end' => $dtend->format('c'),
                        'day' => (int)$dtstart->format('j'),
                        'isRecurring' => $isRecurring,
                        'rrule' => $rrule,
                        'isAllDay' => $isAllDay
                    ];
                }
            }
        }
    }
} catch (Exception $e) {
    http_response_code(500);
    $eventsOutput = ['error' => $e->getMessage()];
}

usort($eventsOutput, function($a, $b) {
    return strtotime($a['start']) <=> strtotime($b['start']);
});

echo json_encode($eventsOutput);