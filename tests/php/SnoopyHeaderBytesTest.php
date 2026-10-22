<?php

require_once(__DIR__ . '/../../php/Snoopy.class.inc');

function headerBytesAssert($condition, $message)
{
    if (!$condition) throw new RuntimeException($message);
}

// A peer that answers one request and hands back the header block it read. A
// field value is opaque bytes to a recipient, so the bytes on the wire are the
// whole of what a transport has to get right; nothing the client reports about
// itself afterwards says what the server was told.
function headerBytesRequest($curl, $configure)
{
    $peer = <<<'PHP'
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if ($server === false) exit(2);
fwrite(STDOUT, stream_socket_get_name($server, false) . "\n");
fflush(STDOUT);
// A declined request reaches no peer at all, so the absence of a connection
// is an answer this harness has to be able to report rather than a timeout.
$socket = @stream_socket_accept($server, 10);
$request = '';
if ($socket !== false) {
    stream_set_timeout($socket, 5);
    while (($line = fgets($socket)) !== false) {
        $request .= $line;
        if ($line === "\r\n") break;
    }
    @fwrite($socket, "HTTP/1.0 200 OK\r\nContent-Length: 2\r\nConnection: close\r\n\r\nok");
    fclose($socket);
}
fclose($server);
echo json_encode(array('request' => base64_encode($request)));
PHP;
    $process = proc_open(array(PHP_BINARY, '-c', __DIR__ . '/../php-test.ini', '-r', $peer),
        array(0 => array('file', '/dev/null', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
    headerBytesAssert(is_resource($process), 'The loopback peer starts');
    try {
        stream_set_timeout($pipes[1], 20);
        $address = trim((string) fgets($pipes[1]));
        headerBytesAssert(preg_match('/^127\.0\.0\.1:[0-9]+$/D', $address) === 1,
            'The peer announces its listener');
        $client = new Snoopy();
        $client->proxy_host = $client->proxy_port = '';
        $client->proxy_proto = 'http';
        $client->IP = null;
        // php/util.php loads conf/config.php, so the configuration of the
        // install the suite is run from reaches this client. With the
        // private-network guard on, fetch() refuses the loopback peer before
        // connecting and the failure reads as the peer's.
        $client->block_private = false;
        $client->read_timeout = 3;
        // Only what a case sets travels, so a default User-Agent or Accept
        // from conf/config.php cannot stand in for the value under test.
        $client->agent = '';
        $client->accept = '';
        $configure($client);
        $url = 'http://' . $address . '/h';
        if ($curl) {
            // _httpsrequest is the curl transport; HTTP keeps the peer local
            // without introducing a certificate fixture unrelated to the
            // bytes of a header.
            $client->host = '127.0.0.1';
            $client->port = (int) substr(strrchr($address, ':'), 1);
            $client->_httpsrequest($url);
        } else {
            $client->fetch($url);
        }
        $capture = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        unset($pipes[1]);
        fclose($pipes[2]);
        unset($pipes[2]);
        proc_close($process);
        $process = null;
        $request = json_decode($capture, true);
        headerBytesAssert(is_array($request) && isset($request['request']),
            'The peer reports the request it read');
        $block = base64_decode($request['request']);
        $end = strpos($block, "\r\n");
        $lines = ($end === false) ? array()
            : array_values(array_filter(explode("\r\n", substr($block, $end + 2)), 'strlen'));
        return array('status' => $client->status, 'error' => $client->error, 'lines' => $lines);
    } finally {
        foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
        if (is_resource($process)) {
            proc_terminate($process);
            proc_close($process);
        }
    }
}

// Whatever is installed. LC_CTYPE is what decides which byte sequences are a
// character, so it is the setting the bytes of a header depend on; a name the
// host does not have is reported rather than skipped silently.
function headerBytesLocales()
{
    $found = array();
    foreach (array('C', 'C.UTF-8', 'C.utf8', 'POSIX', 'en_US.UTF-8', 'en_US.utf8') as $name) {
        $previous = setlocale(LC_CTYPE, '0');
        if (setlocale(LC_CTYPE, $name) !== false) $found[$name] = true;
        setlocale(LC_CTYPE, $previous);
    }
    return array_keys($found);
}

// Each case sets one of the places a header value comes from and names the
// line it has to put on the wire. The non-ASCII bytes are not decoration: a
// cookie name and value are whatever a server's Set-Cookie carried, an entity
// tag may hold obs-text by RFC 9110 2.2, and a referer or an agent is a
// configured string nobody promised was ASCII.
function headerBytesCases()
{
    return array(
        'a cookie spelt in UTF-8' => array(
            function ($client) { $client->cookies = array('sid' => "caf\xc3\xa9"); },
            "Cookie: sid=caf\xc3\xa9"),
        'a cookie of bytes that are no character in any locale' => array(
            function ($client) { $client->cookies = array('sid' => "\x80\xfe\xff"); },
            "Cookie: sid=\x80\xfe\xff"),
        'an entity tag carrying obs-text' => array(
            function ($client) { $client->rawheaders = array('If-None-Match' => "\"caf\xc3\xa9\x80\xff\""); },
            "If-None-Match: \"caf\xc3\xa9\x80\xff\""),
        'a non-ASCII agent' => array(
            function ($client) { $client->agent = "ruTorrent/caf\xc3\xa9"; },
            "User-Agent: ruTorrent/caf\xc3\xa9"),
        'a non-ASCII referer' => array(
            function ($client) { $client->referer = "http://example.invalid/caf\xe9"; },
            "Referer: http://example.invalid/caf\xe9"),
        'a non-ASCII accept' => array(
            function ($client) { $client->accept = "text/caf\xc3\xa9"; },
            "Accept: text/caf\xc3\xa9"),
        'a plain ASCII cookie' => array(
            function ($client) { $client->cookies = array('sid' => 'plain'); },
            'Cookie: sid=plain'),
    );
}

$tests = array(
    // A header value is opaque bytes: RFC 9110 5.5 gives a field value no
    // encoding of its own and tells a recipient to treat anything above ASCII
    // as opaque data, so there is no spelling a transport may substitute for
    // the bytes it was handed. The socket transport writes them to the socket
    // itself; the curl transport passes them to another program, and what a
    // byte means on the way there depends on LC_CTYPE. The peer's header block
    // is the end condition.
    'both transports send the header bytes they were given in every installed locale' => function () {
        $locales = headerBytesLocales();
        headerBytesAssert(count($locales) > 0, 'At least one locale is installed');
        echo '# locales exercised: ', implode(', ', $locales), "\n";
        $previous = setlocale(LC_CTYPE, '0');
        try {
            foreach ($locales as $locale) {
                headerBytesAssert(setlocale(LC_CTYPE, $locale) !== false, 'LC_CTYPE is set to ' . $locale);
                foreach (headerBytesCases() as $label => $case) {
                    list($configure, $expected) = $case;
                    $where = $label . ' under ' . $locale;
                    foreach (array(false => 'socket', true => 'curl') as $curl => $transport) {
                        $result = headerBytesRequest($curl, $configure);
                        headerBytesAssert(in_array($expected, $result['lines'], true),
                            'The ' . $transport . ' transport must send ' . bin2hex($expected) . ': ' . $where
                            . ' sent ' . implode(' | ', array_map('bin2hex', $result['lines']))
                            . ' (status ' . $result['status'] . ' ' . $result['error'] . ')');
                    }
                }
            }
        } finally {
            setlocale(LC_CTYPE, $previous);
        }
    },
    // What the headers are handed over as, observed from the other side: a
    // wrapper records the arguments curl is called with and the mode of any
    // file among them. A Cookie and an Authorization header are credentials,
    // and an argument is readable in the process table by anyone on the host.
    'the headers are handed over as a file no other user can read' => function () {
        $directory = FileUtil::getTempDirectory();
        $record = $directory . 'header-bytes-record-' . getmypid();
        $wrapper = $directory . 'header-bytes-curl-' . getmypid();
        $template = <<<'SH'
#!/bin/sh
for a in "$@"; do
	printf '%s\n' "$a" >> {ARGV}
	case "$a" in
		@/*) stat -c '%a' "${a#@}" >> {MODES} ;;
	esac
done
exec curl "$@"

SH;
        $script = str_replace(array('{ARGV}', '{MODES}'),
            array(escapeshellarg($record . '.argv'), escapeshellarg($record)), $template);
        headerBytesAssert(file_put_contents($wrapper, $script) === strlen($script), 'The wrapper is written');
        headerBytesAssert(chmod($wrapper, 0700), 'The wrapper is executable');
        $previous = isset($GLOBALS['pathToExternals']['curl']) ? $GLOBALS['pathToExternals']['curl'] : null;
        $GLOBALS['pathToExternals']['curl'] = $wrapper;
        try {
            $result = headerBytesRequest(true, function ($client) {
                $client->cookies = array('sid' => "secret-caf\xc3\xa9");
                $client->user = 'u';
                $client->pass = 's3cret';
            });
            headerBytesAssert(in_array("Cookie: sid=secret-caf\xc3\xa9", $result['lines'], true),
                'The cookie reaches the peer whole: ' . implode(' | ', array_map('bin2hex', $result['lines'])));
            $arguments = file_exists($record . '.argv')
                ? explode("\n", rtrim(file_get_contents($record . '.argv'), "\n")) : array();
            headerBytesAssert(count($arguments) > 0, 'The wrapper recorded what curl was called with');
            foreach ($arguments as $argument)
            {
                headerBytesAssert(strpos($argument, 'secret-caf') === false,
                    'No argument carries a cookie: ' . $argument);
                headerBytesAssert(strpos($argument, base64_encode('u:s3cret')) === false,
                    'No argument carries the credentials: ' . $argument);
            }
            $modes = file_exists($record) ? explode("\n", trim(file_get_contents($record))) : array();
            headerBytesAssert($modes === array('600'),
                'The header file is readable by its owner only: ' . implode(', ', $modes));
        } finally {
            if ($previous === null) unset($GLOBALS['pathToExternals']['curl']);
            else $GLOBALS['pathToExternals']['curl'] = $previous;
            foreach (array($wrapper, $record, $record . '.argv') as $path)
                if (file_exists($path)) unlink($path);
        }
    },
    // A header handed over in a file leaves no file behind, the same end the
    // request body is held to.
    'the curl transport leaves no copy of the headers behind' => function () {
        $directory = FileUtil::getTempDirectory();
        $before = glob($directory . 'rutorrent-*');
        $result = headerBytesRequest(true, function ($client) {
            $client->cookies = array('sid' => "caf\xc3\xa9");
        });
        headerBytesAssert(in_array("Cookie: sid=caf\xc3\xa9", $result['lines'], true),
            'The request completes with the cookie it was given');
        $after = array_diff(glob($directory . 'rutorrent-*'), $before === false ? array() : $before);
        headerBytesAssert($after === array(),
            'No temporary file outlives the request: ' . implode(', ', $after));
    },
    // What a file of one header per line cannot carry, measured rather than
    // assumed: curl 7.74 ends a value at a CR or a NUL and curl 8.14 writes
    // the CR through, and a name beginning with "#" -- a token character, so a
    // caller may ask for one -- is a comment to the newer and a header to the
    // older. RFC 9110 5.5 excludes CR, LF and NUL from a field value, so none
    // of them is a value to preserve; what matters is that the request does
    // not go out silently missing a header, differently per curl version.
    'a header the file cannot carry is declined rather than sent short' => function () {
        $cases = array(
            'a field name read as a comment' => array('#Comment' => 'value'),
            'a value holding a bare CR' => array('X-Validator' => "one\rtwo"),
            'a value holding an LF' => array('X-Validator' => "one\ntwo"),
            'a value holding a NUL' => array('X-Validator' => "one\0two"),
        );
        foreach ($cases as $label => $rawheaders) {
            $result = headerBytesRequest(true, function ($client) use ($rawheaders) {
                $client->rawheaders = $rawheaders;
            });
            headerBytesAssert($result['status'] === Snoopy::RESPONSE_BODY_FAILED,
                'The request is refused for ' . $label . ': status ' . $result['status']);
            headerBytesAssert($result['error'] === 'uncarriable-header',
                'The refusal says what it was for ' . $label . ': ' . $result['error']);
            headerBytesAssert($result['lines'] === array(),
                'Nothing reaches the peer for ' . $label . ': '
                . implode(' | ', array_map('bin2hex', $result['lines'])));
        }
    },
);

$failures = 0;
foreach ($tests as $name => $test) {
    try {
        $test();
        echo 'ok - ' . $name . "\n";
    } catch (Throwable $error) {
        ++$failures;
        echo 'not ok - ' . $name . ': ' . $error->getMessage() . "\n";
    }
}
echo count($tests) . ' tests, ' . $failures . " failures\n";
exit($failures ? 1 : 0);
