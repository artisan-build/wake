<?php

declare(strict_types=1);

use Illuminate\Support\Sleep;

require __DIR__.'/../../vendor/autoload.php';

$server = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
if ($server === false) {
    exit(1);
}

echo stream_socket_get_name($server, false).PHP_EOL;
flush();
$connection = stream_socket_accept($server, 5);
if ($connection !== false) {
    while (! str_contains($request = ($request ?? '').fgets($connection), "\r\n\r\n")) {
    }
    Sleep::sleep(1);
    fwrite($connection, "HTTP/1.1 202 Accepted\r\nContent-Type: application/json\r\nConnection: close\r\n\r\n{}");
    fclose($connection);
}
fclose($server);
