<?php

require_once(__DIR__ . '/../../php/Snoopy.class.inc');
$GLOBALS['httpBlockPrivateNetworks'] = false;

/**
 * RFC 9112 section 4: the first line of a response message is its status
 * line. A reply that has none is either an HTTP/0.9 body, where the response
 * was the body and nothing else, or an HTTP/1.x reply cut off before its
 * start-line arrived; the bytes are the same either way, so the two cannot be
 * told apart. Snoopy sends HTTP/1.0 request lines and never an HTTP/0.9
 * request, so it has no reason to expect an HTTP/0.9 answer -- RFC 9112
 * appendix C.1 notes that most traffic that looks like HTTP/0.9 is in fact
 * badly formed HTTP/1.x -- and a port answering with something that is not
 * HTTP at all reaches this same path.
 *
 * Both transports are driven against one real listener in a separate process,
 * the socket reader directly and curl as the class invokes it, because the
 * point is that they answer alike: the socket reader used to return true with
 * no status, publish the bytes as headers and hand back whatever followed a
 * blank line as a body, while curl refused the same reply outright.
 */
function snoopyStatusLineReply($bytes, $transport)
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
    $socket = false;
    try {
        fclose($pipes[0]);
        stream_set_timeout($pipes[1], 10);
        $address = fgets($pipes[1]);
        if ($address === false) throw new RuntimeException('The HTTP peer did not announce its address');
        $address = trim($address);
        $client = new Snoopy();
        $client->block_private = false;
        $client->proxy_host = $client->proxy_port = '';
        $client->read_timeout = 5;
        $client->maxredirs = 0;
        list($client->host, $client->port) = explode(':', $address);
        if ($transport === 'socket') {
            $socket = stream_socket_client('tcp://' . $address, $errno, $error, 10);
            if ($socket === false) throw new RuntimeException('Could not connect to the HTTP peer: ' . $error);
            $fetched = $client->_httprequest('/', $socket, 'http://' . $address . '/', 'GET');
        } else {
            // Real curl, reached the way the class reaches it, against the
            // same listener. Its own exit code is what it reports in $status.
            $fetched = $client->_httpsrequest('http://' . $address . '/');
        }
        return array('fetched' => $fetched, 'status' => $client->status, 'error' => $client->error,
            'lines' => count($client->headers), 'body' => $client->results,
            'response_code' => $client->response_code);
    } finally {
        if (is_resource($socket)) fclose($socket);
        if (is_resource($process)) {
            proc_terminate($process);
            foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
            proc_close($process);
        }
    }
}

function statusLineSame($expected, $actual, $message)
{
    if ($expected !== $actual) throw new RuntimeException($message . '; expected ' . var_export($expected, true)
        . ', got ' . var_export($actual, true));
}

// A reply with no status line at all: an HTTP/0.9 body, or a 1.x reply cut
// before its start-line. The third carries a blank line, which the socket
// reader used to read as the end of a header block, handing back what came
// after it as the response body.
$statusless = array(
    'an HTTP/0.9 body' => "a 0.9 document with no status line\n",
    'a reply of no bytes at all' => '',
    'an HTTP/0.9 body with a blank line in it' => "0.9 head\r\n\r\n0.9 tail\r\n",
);

$tests = array();

foreach ($statusless as $what => $bytes) {
    $tests['the socket reader refuses ' . $what] = function () use ($bytes) {
        $seen = snoopyStatusLineReply($bytes, 'socket');
        statusLineSame(false, $seen['fetched'], 'A reply with no status line is not a response');
        statusLineSame(Snoopy::RESPONSE_BODY_FAILED, $seen['status'], 'The refusal reports refusal status');
        statusLineSame('no-status-line', $seen['error'], 'The refusal names the missing status line');
        statusLineSame(0, $seen['lines'], 'None of the bytes are published as headers');
        statusLineSame('', $seen['body'], 'None of the bytes are published as a body');
        statusLineSame('', $seen['response_code'], 'No status line is published');
    };
    $tests['curl refuses ' . $what . ' too'] = function () use ($bytes) {
        $seen = snoopyStatusLineReply($bytes, 'curl');
        statusLineSame(false, $seen['fetched'], 'curl does not take a reply with no status line either');
        statusLineSame(0, $seen['lines'], 'No headers are published');
        statusLineSame('', $seen['body'], 'No body is published');
        if ($seen['status'] === 0 || $seen['status'] === '0')
            throw new RuntimeException('curl reported no failure status: ' . var_export($seen['status'], true));
    };
}

$tests['a status line is all a response needs for its reply to be taken'] = function () {
    $seen = snoopyStatusLineReply("HTTP/1.0 200 OK\r\nX-A: 1\r\n\r\nbody", 'socket');
    statusLineSame(true, $seen['fetched'], 'A reply with a status line is a response');
    statusLineSame('200', $seen['status'], 'Its status is published');
    statusLineSame(2, $seen['lines'], 'Its headers are published');
    statusLineSame('body', $seen['body'], 'Its body is published');
};

$tests['a start-line too short to parse is still a start-line'] = function () {
    // The rule is that a reply has to carry a status line, not that the line
    // has to parse: a peer that closed partway through one has sent the start
    // of a response, which is a different thing from having sent none.
    $seen = snoopyStatusLineReply('HTTP/1.1 200', 'socket');
    statusLineSame(true, $seen['fetched'], 'A reply whose start-line is cut short is still a reply');
    statusLineSame('HTTP/1.1 200', $seen['response_code'], 'The start-line that arrived is published');
    statusLineSame(1, $seen['lines'], 'It is published as a header line');
};

$tests['a provisional block with no final response keeps its own reason'] = function () {
    // The 1xx block has a status line, so this stays the interim refusal and
    // does not become the missing-status-line one.
    $seen = snoopyStatusLineReply("HTTP/1.1 103 Early Hints\r\nLink: </a.css>; rel=preload\r\n\r\n", 'socket');
    statusLineSame(false, $seen['fetched'], 'An interim-only answer is not a response');
    statusLineSame('missing-final-response', $seen['error'], 'It keeps the interim reason');
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
