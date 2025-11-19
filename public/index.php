<?php

echo "<h1>Test połączenia z PHP do Radicale</h1>";

$radicaleUrl = 'http://radicale:5232/test/'; // Używamy nazwy serwisu 'radicale' jako hosta
$username = 'test';
$password = 'test';

$ch = curl_init();

curl_setopt($ch, CURLOPT_URL, $radicaleUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_USERPWD, "$username:$password");
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PROPFIND');
curl_setopt($ch, CURLOPT_HTTPHEADER, array('Depth: 1'));

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);

curl_close($ch);

echo "<h2>Zapytanie do: " . htmlspecialchars($radicaleUrl) . "</h2>";
echo "<p>Kod odpowiedzi HTTP: <strong>" . $httpCode . "</strong></p>";

if ($error) {
    echo "<h3>Błąd cURL:</h3>";
    echo "<pre style='background-color:#f8d7da; padding:10px; border-radius:5px;'>" . htmlspecialchars($error) . "</pre>";
} else {
    echo "<h3>Odpowiedź serwera Radicale:</h3>";
    echo "<pre style='background-color:#e2e3e5; padding:10px; border-radius:5px;'>" . htmlspecialchars($response) . "</pre>";
}