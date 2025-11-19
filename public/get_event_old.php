<?php

// Krok 1: Wczytaj autoloadera Composera, aby załadować bibliotekę Sabre
require_once __DIR__ . '/vendor/autoload.php';

echo "<h1>Wydarzenia z ostatniego miesiąca</h1>";

// --- USTAWIENIA POŁĄCZENIA ---
$radicaleBaseUrl = 'http://radicale:5232/';
$userName = 'test';
$password = 'test';
$calendarName = 'moj-kalendarz'; // Upewnij się, że ten kalendarz istnieje

// Pełna ścieżka do kalendarza
$calendarUrl = $radicaleBaseUrl . $userName . '/' . $calendarName . '/';

// --- KLIENT DAV ---
$client = new Sabre\DAV\Client([
    'baseUri' => $calendarUrl,
    'userName' => $userName,
    'password' => $password
]);

// Krok 1: Zdefiniuj zakres dat
$endDate = new DateTime("2025-11-30 00:00:00");
$startDate = new DateTime("2025-05-30 00:00:00");
//$startDate->modify('-3 month');


// Krok 2: Zbuduj uniwersalne zapytanie z "expand"
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
            <C:comp-filter name="VEVENT" />
        </C:comp-filter>
    </C:filter>
</C:calendar-query>
XML;

try {
    $rawResponse = $client->request('REPORT', '', $query, ['Depth' => '1', 'Content-Type' => 'application/xml; charset=utf-8']);
    echo "<pre>".print_r($rawResponse, true)."</pre>"; exit;
    $parsedResponse = $client->parseMultiStatus($rawResponse['body']);

    if (empty($parsedResponse)) {
        echo "<p>Nie znaleziono żadnych wydarzeń w tym okresie.</p>";
        exit;
    }

    echo "<h2>Znalezione wydarzenia:</h2>";
    echo "<ul style='list-style-type: none; padding: 0;'>";

    // Krok 3: Przetwórz odpowiedź, rozróżniając typ wydarzenia
    foreach ($parsedResponse as $eventUrl => $propertiesByStatus) {
        $eventProps = $propertiesByStatus[200] ?? [];

        if (isset($eventProps['{urn:ietf:params:xml:ns:caldav}calendar-data'])) {
            $icalData = $eventProps['{urn:ietf:params:xml:ns:caldav}calendar-data'];
            $vcalendar = Sabre\VObject\Reader::read($icalData);

            foreach ($vcalendar->VEVENT as $event) {
                $summary = $event->SUMMARY;
                $dtstart = $event->DTSTART->getDateTime()->format('Y-m-d H:i');

                // **TUTAJ JEST KLUCZOWA ZMIANA**
                // Sprawdzamy, czy wydarzenie ma RECURRENCE-ID. Jeśli tak, to jest to wystąpienie cyklu.
                if (isset($event->{'RECURRENCE-ID'})) {
                    $type = "Wystąpienie cyklu";
                    $style = "border-left: 5px solid #2196F3;"; // Niebieski
                } else {
                    $type = "Wydarzenie pojedyncze";
                    $style = "border-left: 5px solid #4CAF50;"; // Zielony
                }

                echo "<li style='background-color:#f5f5f5; padding:10px; margin-bottom:10px; border-radius:5px; {$style}'>";
                echo "<strong>" . htmlspecialchars($summary) . "</strong><br>";
                echo "Data: " . $dtstart . "<br>";
                echo "<small style='color: #555;'>" . $type . "</small>";
                echo "</li>";
            }
        }
    }

    echo "</ul>";

} catch (Sabre\DAV\Exception $e) {
    echo "<p style='color:red; font-weight:bold;'>Wystąpił błąd:</p>";
    echo "<pre>" . htmlspecialchars($e->getMessage()) . "</pre>";
}