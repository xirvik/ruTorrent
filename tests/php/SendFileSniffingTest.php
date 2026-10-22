<?php

require_once(__DIR__ . '/TestCase.php');

/**
 * SendFile serves a file whose extension it does not choose: plugins/data
 * hands over the saved name as rtorrent reports it, appending no suffix of its
 * own, so whoever made the torrent chose it. The responses all declare a type,
 * and a declared type is only honoured if the response also forbids guessing.
 *
 * Every exit SendFile has is asked, over a loopback HTTP server, because
 * header() under the CLI SAPI sets nothing a client reads: the whole body, a
 * byte range, a revalidated request, and the cached-image sender.
 */
class SendFileSniffingTest extends TestCase
{
	const BODY = 'the file bytes';

	private $base;
	private $server;

	public function setUp()
	{
		$this->base = sys_get_temp_dir() . '/rutorrent-sendfile-nosniff-' . getmypid();
		$this->removeTree($this->base);
		mkdir($this->base . '/www', 0700, true);
		file_put_contents($this->base . '/payload.html', self::BODY);
	}

	public function tearDown()
	{
		$this->stopServer();
		$this->removeTree($this->base);
	}

	private function removeTree($path)
	{
		if(!is_dir($path) || is_link($path))
		{
			@unlink($path);
			return;
		}
		foreach(array_diff(scandir($path), array('.', '..')) as $entry)
			$this->removeTree($path . '/' . $entry);
		@rmdir($path);
	}

	private function stopServer()
	{
		if(is_resource($this->server))
		{
			proc_terminate($this->server);
			proc_close($this->server);
		}
		$this->server = null;
	}

	private function start()
	{
		$root = realpath(__DIR__ . '/../..');
		$file = $this->base . '/payload.html';
		file_put_contents($this->base . '/www/router.php', "<?php\n"
			. '$canUseXSendFile = false;' . "\n"
			. 'require ' . var_export($root . '/php/utility/sendfile.php', true) . ";\n"
			. 'if (isset($_GET[\'cached\'])) '
			. 'SendFile::sendCachedImage(' . var_export($file, true) . ", 'image/png', 60);\n"
			. 'SendFile::send(' . var_export($file, true) . ", 'application/octet-stream');\n");
		$listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
		if($listener === false)
			throw new RuntimeException('no port for the web server: ' . $error);
		$address = stream_socket_get_name($listener, false);
		fclose($listener);
		$log = $this->base . '/server.log';
		$this->server = proc_open(array(PHP_BINARY, '-c', $root . '/tests/php-test.ini',
			'-S', $address, $this->base . '/www/router.php'),
			array(0 => array('pipe', 'r'), 1 => array('file', $log, 'a'),
				2 => array('file', $log, 'a')), $pipes, $this->base . '/www');
		if(!is_resource($this->server))
			throw new RuntimeException('the web server did not start');
		fclose($pipes[0]);
		for($i = 0; $i < 100; ++$i)
		{
			$peer = @stream_socket_client('tcp://' . $address, $errno, $error, 0.05);
			if($peer !== false)
			{
				fclose($peer);
				return $address;
			}
			usleep(10000);
		}
		throw new RuntimeException('the web server is unavailable: ' . @file_get_contents($log));
	}

	/** $extra is added to the request head; returns the status, the headers and the body. */
	private function get($address, $path, $extra = '')
	{
		$peer = stream_socket_client('tcp://' . $address, $errno, $error, 5);
		if($peer === false)
			throw new RuntimeException('could not reach the web server: ' . $error);
		stream_set_timeout($peer, 20);
		fwrite($peer, 'GET ' . $path . " HTTP/1.0\r\nHost: " . $address . "\r\n"
			. $extra . "Connection: close\r\n\r\n");
		$response = stream_get_contents($peer);
		fclose($peer);
		if(!is_string($response) || strpos($response, "\r\n\r\n") === false)
			throw new RuntimeException('the web server sent no complete response');
		list($head, $body) = explode("\r\n\r\n", $response, 2);
		$lines = explode("\r\n", $head);
		$status = array_shift($lines);
		$headers = array();
		foreach($lines as $line)
		{
			$colon = strpos($line, ':');
			if($colon !== false)
				$headers[strtolower(substr($line, 0, $colon))] = trim(substr($line, $colon + 1));
		}
		return array($status, $headers, $body);
	}

	public function testEveryResponseSendFileHasForbidsContentSniffing()
	{
		$address = $this->start();
		$whole = $this->get($address, '/file');
		$etag = isset($whole[1]['etag']) ? $whole[1]['etag'] : '';
		$range = $this->get($address, '/file', "Range: bytes=0-4\r\n");
		$fresh = $this->get($address, '/file', 'If-None-Match: ' . $etag . "\r\n");
		$cached = $this->get($address, '/file?cached=1');
		$this->stopServer();
		$answers = array(
			'the whole file' => array($whole, 'HTTP/1.0 200 OK', self::BODY),
			'a byte range' => array($range, ' 206 ', substr(self::BODY, 0, 4)),
			'a revalidated request' => array($fresh, ' 304 ', ''),
			'the cached-image sender' => array($cached, ' 200 ', self::BODY),
		);
		foreach($answers as $what => $case)
		{
			list($answer, $expectedStatus, $expectedBody) = $case;
			$this->assertTrue(strpos($answer[0], $expectedStatus) !== false,
				$what . ' answers ' . $expectedStatus . ': ' . var_export($answer[0], true));
			$this->assertEquals($expectedBody, $answer[2],
				$what . ' keeps its body: ' . var_export($answer[2], true));
			$this->assertEquals('nosniff',
				isset($answer[1]['x-content-type-options'])
					? $answer[1]['x-content-type-options'] : null,
				$what . ' forbids sniffing');
		}
	}
}
