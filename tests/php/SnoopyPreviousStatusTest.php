<?php

require_once(__DIR__ . '/../../php/Snoopy.class.inc');
$GLOBALS['httpBlockPrivateNetworks'] = false;

/**
 * Callers reuse one client for a sequence of fetches, and about thirty
 * loginmgr account classes read $status after one. So a request that assigns
 * no status of its own must report no status, and not the status of the
 * request before it.
 *
 * $status is part of a response, so it is cleared where the rest of the
 * response is -- discardResponse(), which every start of a request and every
 * refusal goes through. A reply whose start-line arrived but was cut short of
 * a status code is the case that reaches the end of a read with nothing
 * assigned, so it is what these cases fetch second.
 *
 * The peers are real listeners in separate processes and each serves one
 * request, as the two hosts of a two-fetch sequence would.
 */
function snoopyPreviousStatusPeer($bytes)
{
    $worker = <<<'PHP'
$server = stream_socket_server("tcp://127.0.0.1:0", $errno, $error);
if ($server === false) {
    fwrite(STDERR, "listen: " . $error);
    exit(1);
}
echo stream_socket_get_name($server, false), "\n";
flush();
$peer = stream_socket_accept($server, 10);
if ($peer === false) exit(2);
stream_set_timeout($peer, 10);
do {
    $line = fgets($peer);
    if ($line === false) exit(3);
} while ($line !== "\r\n" && $line !== "\n");
fwrite($peer, base64_decode($argv[1]));
fclose($peer);
fclose($server);
PHP;
    $process = proc_open(
        array(PHP_BINARY, '-c', __DIR__ . '/../php-test.ini', '-r', $worker, base64_encode($bytes)),
        array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
        $pipes
    );
    if (!is_resource($process)) throw new RuntimeException('Could not start the HTTP peer');
    fclose($pipes[0]);
    stream_set_timeout($pipes[1], 10);
    $address = fgets($pipes[1]);
    if ($address === false) {
        proc_terminate($process);
        proc_close($process);
        throw new RuntimeException('The HTTP peer did not announce its address');
    }
    return array('url' => 'http://' . trim($address) . '/', 'process' => $process, 'pipes' => $pipes);
}

function snoopyPreviousStatusStop($peers)
{
    foreach ($peers as $peer) {
        proc_terminate($peer['process']);
        foreach ($peer['pipes'] as $pipe) if (is_resource($pipe)) fclose($pipe);
        proc_close($peer['process']);
    }
}

function previousStatusSame($expected, $actual, $message)
{
    if ($expected !== $actual) throw new RuntimeException($message . '; expected ' . var_export($expected, true)
        . ', got ' . var_export($actual, true));
}

function snoopyPreviousStatusClient()
{
    $client = new Snoopy();
    $client->block_private = false;
    $client->proxy_host = $client->proxy_port = '';
    $client->read_timeout = 5;
    $client->maxredirs = 0;
    return $client;
}

$tests = array();

$tests['a request that assigns no status reports none, not the one before it'] = function () {
    $peers = array();
    try {
        $peers[] = $first = snoopyPreviousStatusPeer("HTTP/1.1 200 OK\r\nX-A: 1\r\n\r\nfirst body");
        $client = snoopyPreviousStatusClient();
        previousStatusSame(true, $client->fetch($first['url']), 'The first request completes');
        previousStatusSame('200', $client->status, 'The first request reports its own status');

        // A peer that closed partway through its start-line: the reader has a
        // response_code but never reached a status code to assign.
        $peers[] = $second = snoopyPreviousStatusPeer('HTTP/1.1 200');
        $client->fetch($second['url']);
        previousStatusSame('HTTP/1.1 200', $client->response_code, 'The second reply is the one reported on');
        previousStatusSame(0, $client->status, 'A request with no status of its own reports no status');
        previousStatusSame('', $client->results, 'No part of the first response survives the second request');
    } finally {
        snoopyPreviousStatusStop($peers);
    }
};

$tests['a refused request reports its refusal, not the status before it'] = function () {
    $peers = array();
    try {
        $peers[] = $first = snoopyPreviousStatusPeer("HTTP/1.1 404 Not Found\r\n\r\nmissing");
        $client = snoopyPreviousStatusClient();
        previousStatusSame(true, $client->fetch($first['url']), 'The first request completes');
        previousStatusSame('404', $client->status, 'The first request reports its own status');
        previousStatusSame(false, $client->fetch('not-a-url'), 'The second request is refused');
        previousStatusSame(Snoopy::RESPONSE_BODY_FAILED, $client->status, 'The refusal reports refusal status');
    } finally {
        snoopyPreviousStatusStop($peers);
    }
};

$tests['a failed connect still reports the transport status it names'] = function () {
    $peers = array();
    try {
        $peers[] = $first = snoopyPreviousStatusPeer("HTTP/1.1 200 OK\r\n\r\nfirst body");
        $client = snoopyPreviousStatusClient();
        previousStatusSame(true, $client->fetch($first['url']), 'The first request completes');
        previousStatusSame('200', $client->status, 'The first request reports its own status');

        // A port nothing listens on: the connect fails, and the status it
        // reports has to be its own and not a cleared 0.
        $closed = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if ($closed === false) throw new RuntimeException('Could not reserve a port: ' . $error);
        $address = stream_socket_get_name($closed, false);
        fclose($closed);
        // fsockopen() warns on the refused connection; connect() reports it
        // through $error and $status, which is what is under test here.
        previousStatusSame(false, @$client->fetch('http://' . $address . '/'),
            'The second request fails to connect');
        previousStatusSame(Snoopy::SOCKET_CONNECT_FAILED, $client->status,
            'A failed connect reports the connect status');
        previousStatusSame('', $client->results, 'The first body does not survive a failed connect');
    } finally {
        snoopyPreviousStatusStop($peers);
    }
};

$failures = 0;
foreach ($tests as $name => $test) {
    try {
        $test();
        echo 'ok - ', $name, "\n";
    } catch (Throwable $error) {
        $failures++;
        echo 'not ok - ', $name, ': ', $error->getMessage(), "\n";
    }
}
echo count($tests) - $failures, ' passed, ', $failures, " failed\n";
exit($failures ? 1 : 0);
