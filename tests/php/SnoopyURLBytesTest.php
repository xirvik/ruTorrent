<?php

require_once(__DIR__ . '/../../php/Snoopy.class.inc');

function urlBytesAssert($condition, $message)
{
    if (!$condition) throw new RuntimeException($message);
}

// A peer that answers one request and hands back the request line it read.
// The target on that line is the resource as far as a server is concerned, so
// it is what a transport has to get right -- not anything the client reports
// about itself once the request is over.
function urlBytesTarget($curl, $target)
{
    $peer = <<<'PHP'
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if ($server === false) exit(2);
fwrite(STDOUT, stream_socket_get_name($server, false) . "\n");
fflush(STDOUT);
$socket = @stream_socket_accept($server, 10);
if ($socket === false) exit(3);
stream_set_timeout($socket, 5);
$request = '';
while (($line = fgets($socket)) !== false) {
    $request .= $line;
    if ($line === "\r\n") break;
}
@fwrite($socket, "HTTP/1.0 200 OK\r\nContent-Length: 2\r\nConnection: close\r\n\r\nok");
fclose($socket);
fclose($server);
echo json_encode(array('request' => base64_encode($request)));
PHP;
    $process = proc_open(array(PHP_BINARY, '-c', __DIR__ . '/../php-test.ini', '-r', $peer),
        array(0 => array('file', '/dev/null', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
    urlBytesAssert(is_resource($process), 'The loopback peer starts');
    try {
        stream_set_timeout($pipes[1], 20);
        $address = trim((string) fgets($pipes[1]));
        urlBytesAssert(preg_match('/^127\.0\.0\.1:[0-9]+$/D', $address) === 1,
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
        $url = 'http://' . $address . $target;
        if ($curl) {
            // _httpsrequest is the curl transport; HTTP keeps the peer local
            // without introducing a certificate fixture unrelated to the
            // bytes of a url.
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
        urlBytesAssert(is_array($request) && isset($request['request']),
            'The peer reports the request it read');
        $line = strtok(base64_decode($request['request']), "\r\n");
        urlBytesAssert(is_string($line) && preg_match('|^[A-Z]+ (\S*) HTTP/|', $line, $match) === 1,
            'The peer read a request line: ' . var_export($line, true));
        return $match[1];
    } finally {
        foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
        if (is_resource($process)) {
            proc_terminate($process);
            proc_close($process);
        }
    }
}

// The authority, which is handed over as it stands. A wrapper records what
// curl was called with, and $this->_pinnedaddr makes the transport write
// --resolve, which is what lets a request for a name arrive somewhere a test
// can read without any name being looked up: --resolve matches on the name
// curl resolves, so pinning the A-label is how a host above ASCII reaches the
// local peer.
function urlBytesAuthority($urlhost, $pinnedname)
{
    $peer = <<<'PHP'
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if ($server === false) exit(2);
fwrite(STDOUT, stream_socket_get_name($server, false) . "\n");
fflush(STDOUT);
// A request that never arrives is an answer here, not a hang.
$socket = @stream_socket_accept($server, 8);
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
    $directory = FileUtil::getTempDirectory();
    $record = $directory . 'url-bytes-record-' . getmypid();
    $wrapper = $directory . 'url-bytes-curl-' . getmypid();
    $template = <<<'SH'
#!/bin/sh
for a in "$@"; do
	printf '%s\n' "$a" >> {ARGV}
done
exec curl "$@"

SH;
    $script = str_replace('{ARGV}', escapeshellarg($record), $template);
    urlBytesAssert(file_put_contents($wrapper, $script) === strlen($script), 'The wrapper is written');
    urlBytesAssert(chmod($wrapper, 0700), 'The wrapper is executable');
    $externals = isset($GLOBALS['pathToExternals']['curl']) ? $GLOBALS['pathToExternals']['curl'] : null;
    $GLOBALS['pathToExternals']['curl'] = $wrapper;
    $process = proc_open(array(PHP_BINARY, '-c', __DIR__ . '/../php-test.ini', '-r', $peer),
        array(0 => array('file', '/dev/null', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
    urlBytesAssert(is_resource($process), 'The loopback peer starts');
    try {
        stream_set_timeout($pipes[1], 20);
        $address = trim((string) fgets($pipes[1]));
        urlBytesAssert(preg_match('/^127\.0\.0\.1:[0-9]+$/D', $address) === 1,
            'The peer announces its listener');
        $port = (int) substr(strrchr($address, ':'), 1);
        $client = new Snoopy();
        $client->proxy_host = $client->proxy_port = '';
        $client->proxy_proto = 'http';
        $client->IP = null;
        $client->block_private = false;
        $client->read_timeout = 3;
        $client->host = $pinnedname;
        $client->port = $port;
        $client->_pinnedaddr = '127.0.0.1';
        $client->_httpsrequest('http://' . $urlhost . ':' . $port . '/pinned');
        $capture = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        unset($pipes[1]);
        fclose($pipes[2]);
        unset($pipes[2]);
        proc_close($process);
        $process = null;
        $request = json_decode($capture, true);
        urlBytesAssert(is_array($request) && isset($request['request']),
            'The peer reports what it read');
        $arrived = base64_decode($request['request']) !== '';
        $given = '';
        foreach (file_exists($record) ? explode("\n", (string) file_get_contents($record)) : array() as $argument)
            if (strncmp($argument, 'http://', 7) === 0) $given = $argument;
        urlBytesAssert($given !== '', 'The wrapper recorded the url curl was given');
        return array('arrived' => $arrived, 'given' => $given,
            'status' => $client->status, 'error' => $client->error);
    } finally {
        foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
        if (is_resource($process)) {
            proc_terminate($process);
            proc_close($process);
        }
        if ($externals === null) unset($GLOBALS['pathToExternals']['curl']);
        else $GLOBALS['pathToExternals']['curl'] = $externals;
        foreach (array($wrapper, $record) as $path) if (file_exists($path)) unlink($path);
    }
}

// Whatever is installed. LC_CTYPE is what decides which byte sequences are a
// character, so it is the setting the bytes of a url depend on; a name the
// host does not have is reported rather than skipped silently.
function urlBytesLocales()
{
    $found = array();
    foreach (array('C', 'C.UTF-8', 'C.utf8', 'POSIX', 'en_US.UTF-8', 'en_US.utf8') as $name) {
        $previous = setlocale(LC_CTYPE, '0');
        if (setlocale(LC_CTYPE, $name) !== false) $found[$name] = true;
        setlocale(LC_CTYPE, $previous);
    }
    return array_keys($found);
}

$targets = array(
    'a path spelt in UTF-8' => "/caf\xc3\xa9",
    'a path of bytes that are no character in any locale' => "/caf\xe9\x80\xfe\xff",
    'a non-ASCII query' => "/x?q=\xc3\xa9&r=1",
    'a path already percent-encoded' => '/caf%C3%A9',
    'plain ASCII' => '/plain?q=1',
);

$tests = array(
    // A url names a resource. The socket transport writes the target it was
    // given to the socket itself; the curl transport passes it to another
    // program, and what a byte means on the way there depends on LC_CTYPE.
    // The peer's request line is the end condition: both transports have to
    // ask for the same resource, whatever the locale, and a target that
    // reaches curl with bytes still in it is one the next locale can change.
    'both transports name the same resource in every installed locale' => function () use ($targets) {
        $locales = urlBytesLocales();
        urlBytesAssert(count($locales) > 0, 'At least one locale is installed');
        echo '# locales exercised: ', implode(', ', $locales), "\n";
        $previous = setlocale(LC_CTYPE, '0');
        try {
            foreach ($locales as $locale) {
                urlBytesAssert(setlocale(LC_CTYPE, $locale) !== false, 'LC_CTYPE is set to ' . $locale);
                foreach ($targets as $label => $target) {
                    $where = $label . ' under ' . $locale;
                    $socket = urlBytesTarget(false, $target);
                    urlBytesAssert($socket === $target,
                        'The socket transport asks for the target it was given: ' . $where
                        . ' asked for ' . bin2hex($socket) . ' of ' . bin2hex($target));
                    $curl = urlBytesTarget(true, $target);
                    urlBytesAssert(rawurldecode($curl) === rawurldecode($socket),
                        'The curl transport must ask for the same resource: ' . $where
                        . ' asked for ' . bin2hex($curl) . ' where the socket asked for ' . bin2hex($socket));
                    urlBytesAssert(preg_match('/[\x80-\xff]/', $curl) !== 1,
                        'No byte of the target is left for the locale to decide: ' . $where
                        . ' asked for ' . bin2hex($curl));
                }
            }
        } finally {
            setlocale(LC_CTYPE, $previous);
        }
    },
    // The authority is the one part of the url that is handed over as it
    // stands, and that is deliberate: curl 7.74 does not percent-decode a
    // host before its IDN library sees it, so "caf%C3%A9.invalid" is looked up
    // as that literal name and never reaches xn--caf-dma.invalid, while the
    // same bytes written plainly do. curl 8.14 decodes first and accepts
    // either. Encoding the authority would therefore stop an IDN host
    // resolving wherever curl is older, which is why it is excluded -- and a
    // host above ASCII stays lossy under C as a result, with no fix available
    // here: deriving the A-label needs idn_to_ascii() from the intl
    // extension, which a PHP install is not required to carry and none of the
    // images this suite runs on has.
    'the authority is handed to curl as it stands, in every installed locale' => function () {
        $locales = urlBytesLocales();
        urlBytesAssert(count($locales) > 0, 'At least one locale is installed');
        $previous = setlocale(LC_CTYPE, '0');
        try {
            foreach ($locales as $locale) {
                urlBytesAssert(setlocale(LC_CTYPE, $locale) !== false, 'LC_CTYPE is set to ' . $locale);
                $result = urlBytesAuthority("caf\xc3\xa9.invalid", 'xn--caf-dma.invalid');
                $authority = substr($result['given'], 7, strcspn($result['given'], '/?#', 7));
                urlBytesAssert(strpos($authority, '%') === false,
                    'Nothing in the authority is percent-encoded under ' . $locale
                    . ': curl was given ' . bin2hex($authority));
                // The path of the same url is encoded, so a locale that left
                // the authority alone for the wrong reason is told apart from
                // the carve-out being in place.
                urlBytesAssert(substr($result['given'], -7) === '/pinned',
                    'The target of the same url is the one asked for: ' . $result['given']);
            }
        } finally {
            setlocale(LC_CTYPE, $previous);
        }
    },
    // And the consequence the carve-out buys, end to end: with a character set
    // curl's IDN library can read, a host above ASCII resolves to its A-label
    // and the request arrives at the address pinned for it. curl reads that
    // character set from its own environment and not from setlocale() here, so
    // the environment is given one -- with LC_ALL unset or C, curl converts no
    // such host in any spelling and says so loudly, which is the host
    // install's limit rather than this transport's.
    'a host above ASCII reaches the address pinned for its A-label' => function () {
        $utf8 = null;
        foreach (urlBytesLocales() as $name)
            if (preg_match('/utf-?8$/i', $name)) { $utf8 = $name; break; }
        urlBytesAssert($utf8 !== null, 'A UTF-8 locale is installed');
        echo '# authority exercised under ', $utf8, "\n";
        $locale = setlocale(LC_CTYPE, '0');
        $environment = getenv('LC_ALL');
        try {
            urlBytesAssert(setlocale(LC_CTYPE, $utf8) !== false, 'LC_CTYPE is set to ' . $utf8);
            putenv('LC_ALL=' . $utf8);
            $result = urlBytesAuthority("caf\xc3\xa9.invalid", 'xn--caf-dma.invalid');
            urlBytesAssert($result['arrived'],
                'The request reaches the pinned address: arrived nowhere with status '
                . $result['status'] . ' ' . $result['error'] . ', curl given ' . $result['given']);
        } finally {
            if ($environment === false) putenv('LC_ALL');
            else putenv('LC_ALL=' . $environment);
            setlocale(LC_CTYPE, $locale);
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
