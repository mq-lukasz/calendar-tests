<?php

// Krok 1: Wczytaj autoloadera Composera, aby załadować bibliotekę Sabre
require_once __DIR__ . '/vendor/autoload.php';

echo "<h1>Wydarzenia z kalendarza</h1>";

// --- USTAWIENIA ---
$radicaleUrl  = 'http://radicale:5232/';
$userName     = 'test';
$password     = 'test';
$calendarPath = 'test/moj-kalendarz/'; // UPEWNIJ SIĘ, ŻE TA NAZWA JEST POPRAWNA
$timeZoneId   = 'Europe/Warsaw';

// --- KLIENT DAV ---
$client = new Sabre\DAV\Client([
    'baseUri'  => $radicaleUrl,
    'userName' => $userName,
    'password' => $password,
]);

// --- **ZMIANA: Ustawiamy bardzo szeroki zakres dat** ---
$tz = new DateTimeZone($timeZoneId);
$startDate = new DateTime('2025-09-01 00:00:00', $tz);
$endDate   = new DateTime('2025-09-30 23:59:59', $tz);

echo "<p>Pobieram wydarzenia w okresie od <strong>" . $startDate->format('Y-m-d') . "</strong> do <strong>" . $endDate->format('Y-m-d') . "</strong></p>";

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

try {
    $rawResponse = $client->request('REPORT', $calendarPath, $query, [
        'Depth'        => '1',
        'Content-Type' => 'application/xml; charset=utf-8',
    ]);

    $parsedResponse = $client->parseMultiStatus($rawResponse['body']);

    if (empty($parsedResponse)) {
        die("<h2>Wynik:</h2><p>Nie znaleziono żadnych wydarzeń.</p>");
    }

    // --- **NOWY KROK: Zbieranie wydarzeń do tablicy przed sortowaniem** ---
    $eventsToSort = [];
    foreach ($parsedResponse as $eventUrl => $propertiesByStatus) {
        $eventProps = $propertiesByStatus[200] ?? [];
        if (isset($eventProps['{urn:ietf:params:xml:ns:caldav}calendar-data'])) {
            $icalData = $eventProps['{urn:ietf:params:xml:ns:caldav}calendar-data'];
            $vcalendar = Sabre\VObject\Reader::read($icalData);
            foreach ($vcalendar->VEVENT as $event) {
                // Dodajemy każde wydarzenie jako obiekt do naszej tablicy
                $eventsToSort[] = $event;
            }
        }
    }

    // --- **NOWY KROK: Sortowanie tablicy z wydarzeniami** ---
    usort($eventsToSort, function($a, $b) {
        return $a->DTSTART->getDateTime() <=> $b->DTSTART->getDateTime();
    });


    // --- PRZETWARZANIE POSORTOWANYCH WYNIKÓW ---
    echo "<h2>Znalezione wydarzenia:</h2>";
    echo "<ul style='list-style-type: none; padding: 0;'>";

    foreach ($eventsToSort as $event) {
        $summary = $event->SUMMARY;
        $dtstart = $event->DTSTART->getDateTime()->format('Y-m-d H:i');
        $type = isset($event->{'RECURRENCE-ID'}) ? "Wystąpienie cyklu" : "Wydarzenie pojedyncze";
        $style = isset($event->{'RECURRENCE-ID'}) ? "border-left: 5px solid #2196F3;" : "border-left: 5px solid #4CAF50;";

        echo "<li style='background-color:#f5f5f5; padding:10px; margin-bottom:10px; border-radius:5px; {$style}'>";
        echo "<strong>" . htmlspecialchars($summary) . "</strong><br>";
        echo "Data: " . $dtstart . "<br>";
        echo "<small style='color: #555;'>" . $type . "</small>";
        echo "</li>";
    }
    echo "</ul>";

} catch (Exception $e) {
    echo "<p style='color:red; font-weight:bold;'>Wystąpił błąd:</p>";
    echo "<pre>" . htmlspecialchars($e->getMessage()) . "</pre>";
}