<?php

require_once __DIR__ . '/vendor/autoload.php';

// --- USTAWIENIA ---
$radicaleBaseUrl = 'http://radicale:5232/';
$userName = 'test';
$password = 'test';
$calendarName = 'moj-kalendarz';
$calendarDisplayName = 'Mój główny kalendarz';

// --- KLIENT DAV ---
$client = new Sabre\DAV\Client([
    'baseUri' => $radicaleBaseUrl,
    'userName' => $userName,
    'password' => $password
]);

echo "<h1>Skrypt dodawania wydarzenia do Radicale</h1>";

// --- ETAP 1: Upewnij się, że istnieje kolekcja domowa użytkownika ---
try {
    echo "<p>Etap 1: Sprawdzam/Tworzę kolekcję domową użytkownika '/" . htmlspecialchars($userName) . "/'...</p>";
    $client->request('MKCOL', $userName . '/');
    echo "<p style='color:green;'>OK! Kolekcja domowa gotowa.</p>";
} catch (Sabre\DAV\Exception\MethodNotAllowed $e) {
    echo "<p style='color:gray;'>OK! Kolekcja domowa już istniała.</p>";
} catch (Exception $e) {
    die("<p style='color:red;'>Błąd krytyczny na Etapie 1: " . htmlspecialchars($e->getMessage()) . "</p>");
}

// --- ETAP 2: Upewnij się, że istnieje kalendarz ---
try {
    echo "<p>Etap 2: Sprawdzam/Tworzę kalendarz '" . htmlspecialchars($calendarName) . "'...</p>";
    $client->request('MKCALENDAR', $userName . '/' . $calendarName . '/', '<?xml version="1.0" encoding="utf-8" ?><C:mkcalendar xmlns:D="DAV:" xmlns:C="urn:ietf:params:xml:ns:caldav"><D:set><D:prop><D:displayname>' . $calendarDisplayName . '</D:displayname><D:resourcetype><D:collection/><C:calendar/></D:resourcetype></D:prop></D:set></C:mkcalendar>');
    echo "<p style='color:green;'>OK! Kalendarz gotowy.</p>";
} catch (Sabre\DAV\Exception\MethodNotAllowed $e) {
    echo "<p style='color:gray;'>OK! Kalendarz już istniał.</p>";
} catch (Exception $e) {
    die("<p style='color:red;'>Błąd krytyczny na Etapie 2: " . htmlspecialchars($e->getMessage()) . "</p>");
}


// --- TWORZENIE WYDARZENIA (NAJPROSTSZA WERSJA) ---

// 1. Główny komponent VCALENDAR
$vcalendar = new Sabre\VObject\Component\VCalendar();

// 2. Komponent VEVENT (wydarzenie)
$vevent = $vcalendar->add('VEVENT');

// 3. Podstawowe, wymagane pola
$vevent->add('SUMMARY', 'Wydarzenie ze strefą czasową Warszawa');

// 4. Daty z jawnym przypisaniem strefy czasowej
$tz = new DateTimeZone('Europe/Warsaw');
$vevent->add('DTSTART', new DateTime('today 14:00', $tz));
$vevent->add('DTEND', new DateTime('today 15:30', $tz));
// Biblioteka sabre/vobject sama doda wymagane pole DTSTAMP.

// 5. Serializacja do formatu .ics
$icalData = $vcalendar->serialize();

echo "<h2>Dane iCalendar do wysłania:</h2>";
echo "<pre>" . htmlspecialchars($icalData) . "</pre>";

// --- WYSYŁANIE WYDARZENIA ---
$eventName =  uniqid() . '.ics';
$eventPath = $userName . '/' . $calendarName . '/' . $eventName;

try {
    $response = $client->request('PUT', $eventPath, $icalData, [
        'Content-Type' => 'text/calendar; charset=utf-8',
    ]);

    if ($response['statusCode'] >= 200 && $response['statusCode'] < 300) {
        echo "<h2 style='color:green;'>SUKCES! Wydarzenie dodane.</h2>";
    } else {
        echo "<h2 style='color:red;'>BŁĄD! Serwer odpowiedział kodem: " . $response['statusCode'] . "</h2>";
        echo "<pre>Treść błędu: " . htmlspecialchars($response['body']) . "</pre>";
    }
} catch (Exception $e) {
    die("<p style='color:red;'>Błąd krytyczny: " . htmlspecialchars($e->getMessage()) . "</p>");
}

