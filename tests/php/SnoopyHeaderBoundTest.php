<?php

require_once(__DIR__ . '/../../php/Snoopy.class.inc');

/**
 * The socket reader keeps every header line it reads in $headers and empties
 * that array only at a block boundary. A block that carries no boundary never
 * reaches one, so a peer that sends header lines without end is read without
 * end and the array grows until the process runs out of memory: a fatal error,
 * with no return value for a caller to check and no response to report.
 *
 * Snoopy::MAX_HEADER_LINES and Snoopy::MAX_HEADER_BYTES bound the section
 * instead, over all of the blocks one answer carries together. The peer is a
 * real listener in a separate process, so the lines arrive off a socket as
 * they would in use.
 */
function snoopyHeaderBoundRead($plan, $readTimeout = 0)
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
} while ($line !== "\r\n");
$plan = unserialize(file_get_contents($argv[1]), array("allowed_classes" => false));
unlink($argv[1]);
foreach ($plan as $step) {
    if ($step[0] === "send") {
        if (@fwrite($peer, $step[1]) === false) break;
    } else {
        // Keep writing the same lines for as long as the reader takes them.
        while (true) {
            $written = @fwrite($peer, $step[1]);
            if ($written === false || $written === 0) break 2;
        }
    }
}
// A flood peer is still mid-write when the reader stops at a bound, so the
// cases that assert the reader does not wait for a close need nothing more.
fclose($peer);
fclose($server);
PHP;
    // The plan carries whole header sections, which are far longer than one
    // argument may be, so it is handed over as a file the peer reads and removes.
    $planfile = tempnam(sys_get_temp_dir(), 'snoopy-header-bound');
    if ($planfile === false || file_put_contents($planfile, serialize($plan)) === false)
        throw new RuntimeException('Could not write the HTTP peer plan');
    $process = proc_open(
        array(PHP_BINARY, '-c', __DIR__ . '/../php-test.ini', '-r', $worker, $planfile),
        array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
        $pipes
    );
    if (!is_resource($process)) {
        unlink($planfile);
        throw new RuntimeException('Could not start the HTTP peer');
    }
    $socket = false;
    try {
        fclose($pipes[0]);
        stream_set_timeout($pipes[1], 10);
        $address = fgets($pipes[1]);
        if ($address === false) throw new RuntimeException('The HTTP peer did not announce its address');
        $socket = stream_socket_client('tcp://' . trim($address), $errno, $error, 10);
        if ($socket === false) throw new RuntimeException('Could not connect to the HTTP peer: ' . $error);
        $client = new Snoopy();
        $client->block_private = false;
        $client->read_timeout = $readTimeout;
        $client->host = '127.0.0.1';
        $before = memory_get_usage(true);
        $started = microtime(true);
        $fetched = $client->_httprequest('/', $socket, 'http://' . trim($address) . '/', 'GET');
        return array('fetched' => $fetched, 'status' => $client->status, 'error' => $client->error,
            'lines' => count($client->headers), 'body' => $client->results,
            'response_code' => $client->response_code, 'timed_out' => $client->timed_out,
            'grew' => memory_get_usage(true) - $before, 'seconds' => microtime(true) - $started);
    } finally {
        if (is_resource($socket)) fclose($socket);
        if (is_resource($process)) {
            proc_terminate($process);
            foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
            proc_close($process);
        }
        if (file_exists($planfile)) unlink($planfile);
    }
}

function headerBoundSame($expected, $actual, $message)
{
    if ($expected !== $actual) throw new RuntimeException($message . '; expected ' . var_export($expected, true)
        . ', got ' . var_export($actual, true));
}

// 34 bytes a line, so the line bound and the byte bound can be reached apart.
$short = "X-Pad: 0123456789012345678901234\r\n";
$long = 'X-Pad: ' . str_repeat('p', 3985) . "\r\n";

$tests = array();

$tests['an endless header section is refused instead of exhausting memory'] = function () use ($short) {
    $seen = snoopyHeaderBoundRead(array(array('send', "HTTP/1.1 200 OK\r\n"), array('flood', str_repeat($short, 256))));
    headerBoundSame(false, $seen['fetched'], 'An unbounded header section is not a response');
    headerBoundSame(Snoopy::RESPONSE_BODY_FAILED, $seen['status'], 'The refusal reports refusal status');
    headerBoundSame('oversized-header-section', $seen['error'], 'The refusal names the section, not the body');
    headerBoundSame(0, $seen['lines'], 'No header of a refused section is published');
    headerBoundSame('', $seen['body'], 'No body is published');
    headerBoundSame('', $seen['response_code'], 'No status line is published');
    // Unbounded, this read grew until memory_limit ended the process.
    if ($seen['grew'] > 33554432)
        throw new RuntimeException('the refused read grew by ' . $seen['grew'] . ' bytes, over 32 MiB');
};

$tests['a header section at the line bound completes and one line past it is refused'] = function () use ($short) {
    // Every line read counts, so the budget covers the status line and the
    // blank line that ends the block as well as the fields between them.
    $fields = Snoopy::MAX_HEADER_LINES - 2;
    $seen = snoopyHeaderBoundRead(array(array('send',
        "HTTP/1.1 200 OK\r\n" . str_repeat($short, $fields) . "\r\nbody")));
    headerBoundSame(true, $seen['fetched'], 'A section at the line bound is a response');
    headerBoundSame('200', $seen['status'], 'Its status is published');
    headerBoundSame($fields + 1, $seen['lines'], 'Every header line is published');
    headerBoundSame('body', $seen['body'], 'Its body is published');

    $seen = snoopyHeaderBoundRead(array(array('send',
        "HTTP/1.1 200 OK\r\n" . str_repeat($short, $fields + 1) . "\r\nbody")));
    headerBoundSame(false, $seen['fetched'], 'One line past the bound is refused');
    headerBoundSame('oversized-header-section', $seen['error'], 'The refusal names the section');
    headerBoundSame(0, $seen['lines'], 'No header of a refused section is published');
};

$tests['a header section of exactly the byte bound completes'] = function () use ($long) {
    // Padded to the bound to the byte, so the comparison has to be the one
    // that lets a section of exactly that size through.
    $section = "HTTP/1.1 200 OK\r\n" . str_repeat($long, 76);
    $pad = Snoopy::MAX_HEADER_BYTES - strlen($section) - 2;
    if ($pad < 10) throw new RuntimeException('the filler leaves no room for a last line');
    $section .= 'X-Pad: ' . str_repeat('t', $pad - 9) . "\r\n";
    headerBoundSame(Snoopy::MAX_HEADER_BYTES, strlen($section) + 2, 'The section plus its blank line is the bound');
    $seen = snoopyHeaderBoundRead(array(array('send', $section . "\r\nbody")));
    headerBoundSame(true, $seen['fetched'], 'A section of exactly the byte bound is a response');
    headerBoundSame('200', $seen['status'], 'Its status is published');
    headerBoundSame(78, $seen['lines'], 'Every header line is published');
    headerBoundSame('body', $seen['body'], 'Its body is published');
};

$tests['a header section past the byte bound is refused well under the line bound'] = function () use ($long) {
    $lines = (int)ceil((Snoopy::MAX_HEADER_BYTES + 1) / strlen($long));
    if ($lines >= Snoopy::MAX_HEADER_LINES)
        throw new RuntimeException('the byte bound cannot be reached before the line bound');
    $seen = snoopyHeaderBoundRead(array(array('send',
        "HTTP/1.1 200 OK\r\n" . str_repeat($long, $lines) . "\r\nbody")));
    headerBoundSame(false, $seen['fetched'], 'A section over the byte bound is refused');
    headerBoundSame(Snoopy::RESPONSE_BODY_FAILED, $seen['status'], 'The refusal reports refusal status');
    headerBoundSame('oversized-header-section', $seen['error'], 'The refusal names the section');
    headerBoundSame(0, $seen['lines'], 'No header of a refused section is published');
};

$tests['a header section an ordinary server sends is nowhere near either bound'] = function () {
    $headers = "HTTP/1.1 200 OK\r\nServer: nginx\r\nDate: Thu, 01 Jan 1970 00:00:00 GMT\r\n"
        . "Content-Type: application/x-bittorrent\r\nContent-Length: 3\r\n"
        . "Content-Disposition: attachment; filename=\"a.torrent\"\r\nSet-Cookie: sid=1; Path=/\r\n"
        . "Cache-Control: no-store\r\nVary: Accept-Encoding\r\nConnection: close\r\n";
    $seen = snoopyHeaderBoundRead(array(array('send', $headers . "\r\nd1:")));
    headerBoundSame(true, $seen['fetched'], 'A real header section is a response');
    headerBoundSame('200', $seen['status'], 'Its status is published');
    headerBoundSame(10, $seen['lines'], 'Every header line is published');
    headerBoundSame('d1:', $seen['body'], 'Its body is published');
};

$tests['the bound is told from a read timeout on a peer that stays open'] = function () use ($short) {
    // The peer never closes and never sends a boundary, so a reader that kept
    // going past the bound would report -100 off the read timeout instead.
    $seen = snoopyHeaderBoundRead(array(array('send', "HTTP/1.1 200 OK\r\n"),
        array('flood', str_repeat($short, 256))), 2);
    headerBoundSame(false, $seen['fetched'], 'The section is refused');
    headerBoundSame(Snoopy::RESPONSE_BODY_FAILED, $seen['status'], 'The status is the refusal, not -100');
    headerBoundSame('oversized-header-section', $seen['error'], 'The reason is the section, not a timeout');
    headerBoundSame(false, $seen['timed_out'], 'No read timed out');
    if ($seen['seconds'] >= 2.0)
        throw new RuntimeException('the refusal took ' . $seen['seconds'] . 's, as long as the read timeout');
};

$tests['an endless provisional block is refused for its section, not its count'] = function () use ($short) {
    $seen = snoopyHeaderBoundRead(array(array('send', "HTTP/1.1 103 Early Hints\r\n"),
        array('flood', str_repeat($short, 256))), 2);
    headerBoundSame(false, $seen['fetched'], 'An unbounded provisional block is refused');
    headerBoundSame('oversized-header-section', $seen['error'],
        'A single block reaches the section bound, not the interim-response cap');
};

$tests['the permitted provisional blocks cannot each carry a whole section'] = function () use ($short) {
    // Lines are counted over the answer, so the blocks share one section
    // budget rather than taking MAX_HEADER_LINES apiece.
    $perBlock = (int)(Snoopy::MAX_HEADER_LINES / 2);
    $block = "HTTP/1.1 103 Early Hints\r\n" . str_repeat($short, $perBlock) . "\r\n";
    $seen = snoopyHeaderBoundRead(array(array('send',
        str_repeat($block, Snoopy::MAX_INTERIM_RESPONSES) . "HTTP/1.1 200 OK\r\n\r\nbody")), 2);
    headerBoundSame(false, $seen['fetched'], 'The blocks together pass the section bound');
    headerBoundSame('oversized-header-section', $seen['error'], 'The refusal names the section');
    headerBoundSame(0, $seen['lines'], 'No header of a refused section is published');
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
