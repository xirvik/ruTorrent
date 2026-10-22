<?php

require_once(__DIR__ . '/../../php/TestCase.php');
require_once(__DIR__ . '/../../php/FakeRtorrentDaemon.php');
require_once(__DIR__ . '/../../php/RtorrentProbeFixture.php');
require_once(__DIR__ . '/FaultStringResponder.php');

/**
 * plugins/httprpc/action.php reports a failed call by sending rtorrent's
 * faultString. A command parameter of d.multicall is the caller's own text and
 * rtorrent names it in several of its refusals, so that string arrives back
 * carrying whatever the request put in it.
 *
 * The subject is therefore the response a browser is handed: the type it
 * declares and the bytes of the body. Only an HTTP SAPI answers that -- a CLI
 * header() call sets nothing a client reads -- so the script runs behind a
 * loopback server with a scripted daemon underneath it.
 */
class FaultStringResponseTest extends TestCase
{
	const MARKUP = '<svg onload=x>';

	private $base;
	private $daemon;
	private $server;

	public function setUp()
	{
		$this->base = sys_get_temp_dir() . '/rutorrent-httprpc-fault-' . getmypid();
		$this->removeTree($this->base);
		mkdir($this->base . '/www', 0700, true);
		mkdir($this->base . '/profile/settings', 0700, true);
	}

	public function tearDown()
	{
		$this->stopServer();
		if($this->daemon !== null)
		{
			$this->daemon->stop();
			$this->daemon = null;
		}
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

	/** Start the plugin behind a loopback HTTP server, with or without a daemon. */
	private function start($withDaemon)
	{
		$root = realpath(__DIR__ . '/../../..');
		$port = 1;
		if($withDaemon)
		{
			RtorrentProbeFixture::warmSettingsCache($this->base . '/profile');
			$this->daemon = new FakeRtorrentDaemon(new FaultStringResponder(),
				$this->base . '/calls.log', false,
				array(realpath(__DIR__ . '/FaultStringResponder.php')));
			$port = $this->daemon->port();
		}
		file_put_contents($this->base . '/www/router.php', "<?php\n"
			. '$_ENV[\'RU_PROFILE_PATH\'] = ' . var_export($this->base . '/profile', true) . ";\n"
			. '$_ENV[\'RU_LOG_FILE\'] = ' . var_export($this->base . '/app.log', true) . ";\n"
			. 'require_once(' . var_export($root . '/conf/config.php', true) . ");\n"
			. '$scgi_host = "127.0.0.1";' . "\n"
			. '$scgi_port = ' . $port . ";\n"
			. '$rpcTimeOut = 10;' . "\n"
			. '$rpcLogCalls = false;' . "\n"
			. '$topDirectory = "/";' . "\n"
			. 'chdir(' . var_export($root . '/plugins/httprpc', true) . ");\n"
			. 'require(' . var_export($root . '/plugins/httprpc/action.php', true) . ");\n");
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

	/** POST the body and return the status line, the headers and the body. */
	private function post($address, $body)
	{
		$peer = stream_socket_client('tcp://' . $address, $errno, $error, 5);
		if($peer === false)
			throw new RuntimeException('could not reach the web server: ' . $error);
		stream_set_timeout($peer, 20);
		fwrite($peer, "POST / HTTP/1.0\r\nHost: " . $address . "\r\n"
			. "Content-Type: application/x-www-form-urlencoded\r\n"
			. 'Content-Length: ' . strlen($body) . "\r\nConnection: close\r\n\r\n" . $body);
		$response = stream_get_contents($peer);
		fclose($peer);
		if(!is_string($response) || strpos($response, "\r\n\r\n") === false)
			throw new RuntimeException('the web server sent no complete response');
		list($head, $payload) = explode("\r\n\r\n", $response, 2);
		$lines = explode("\r\n", $head);
		$status = array_shift($lines);
		$headers = array();
		foreach($lines as $line)
		{
			$colon = strpos($line, ':');
			if($colon !== false)
				$headers[strtolower(substr($line, 0, $colon))] = trim(substr($line, $colon + 1));
		}
		return array($status, $headers, $payload);
	}

	public function testARefusalNamingTheCallersTextIsPlainTextAndCarriesNoMarkup()
	{
		$address = $this->start(true);
		$command = 'throttle.up="' . self::MARKUP . '"';
		list($status, $headers, $body) = $this->post($address,
			'mode=list&cmd=' . rawurlencode($command));
		$this->stopServer();
		$this->assertTrue(strpos($status, ' 500 ') !== false,
			'a refused call still answers 500: ' . $status);
		$this->assertTrue(strpos($body, self::MARKUP) === false,
			'the refusal carries no unescaped markup: ' . var_export($body, true));
		$this->assertTrue(strpos($body, '&lt;svg onload=x&gt;') !== false,
			'the refusal still names what was refused: ' . var_export($body, true));
		$this->assertEquals('text/plain; charset=utf-8',
			isset($headers['content-type']) ? strtolower($headers['content-type']) : null,
			'the refusal is declared as text, not as a document: '
			. var_export(isset($headers['content-type']) ? $headers['content-type'] : null, true));
		$this->assertEquals('nosniff',
			isset($headers['x-content-type-options']) ? $headers['x-content-type-options'] : null,
			'the refusal forbids sniffing');
	}

	public function testAnUnreachableDaemonIsReportedAsPlainTextToo()
	{
		$address = $this->start(false);
		list($status, $headers, $body) = $this->post($address, 'mode=list');
		$this->stopServer();
		$this->assertTrue(strpos($status, ' 500 ') !== false,
			'an unreachable daemon answers 500: ' . $status);
		$this->assertEquals('text/plain; charset=utf-8',
			isset($headers['content-type']) ? strtolower($headers['content-type']) : null,
			'the report is declared as text: '
			. var_export(isset($headers['content-type']) ? $headers['content-type'] : null, true));
		$this->assertTrue(strpos($body, 'rTorrent') !== false,
			'the report still says what went wrong: ' . var_export($body, true));
	}
}
