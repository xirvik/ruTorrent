<?php

require_once(__DIR__ . '/../../php/TestCase.php');

/**
 * plugins/screenshots/action.php, cmd=ffmpeggetimage, builds the download name
 * from the frame number the request asks for. frameName() coerces that number
 * before it becomes a path; the download name did not, and arithmetic on a
 * string that is not a number is a TypeError from PHP 8 on.
 *
 * So the subject is the response: a frame number that is not a number must
 * answer exactly as the number it coerces to does, which only an HTTP SAPI
 * shows. The body is checked for an error report as well, because what a
 * fatal reveals is decided by display_errors at the time, not by the fatal.
 */
class FrameNumberRequestTest extends TestCase
{
	private $base;
	private $server;
	const FRAME = "\xff\xd8frame bytes\xff\xd9";

	public function setUp()
	{
		$this->base = sys_get_temp_dir() . '/rutorrent-screenshots-fno-' . getmypid();
		$this->removeTree($this->base);
		mkdir($this->base . '/www', 0700, true);
		mkdir($this->base . '/profile/settings/tasks/1.1', 0700, true);
		file_put_contents($this->base . '/profile/settings/tasks/1.1/frame0.jpg', self::FRAME);
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
		$root = realpath(__DIR__ . '/../../..');
		file_put_contents($this->base . '/www/router.php', "<?php\n"
			. '$_ENV[\'RU_PROFILE_PATH\'] = ' . var_export($this->base . '/profile', true) . ";\n"
			. '$_ENV[\'RU_LOG_FILE\'] = ' . var_export($this->base . '/app.log', true) . ";\n"
			. 'require_once(' . var_export($root . '/conf/config.php', true) . ");\n"
			. '$scgi_host = "127.0.0.1";' . "\n"
			. '$scgi_port = 1;' . "\n"
			. '$rpcTimeOut = 2;' . "\n"
			. '$topDirectory = "/";' . "\n"
			. 'chdir(' . var_export($root . '/plugins/screenshots', true) . ");\n"
			. 'require(' . var_export($root . '/plugins/screenshots/action.php', true) . ");\n");
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

	private function get($address, $query)
	{
		$peer = stream_socket_client('tcp://' . $address, $errno, $error, 5);
		if($peer === false)
			throw new RuntimeException('could not reach the web server: ' . $error);
		stream_set_timeout($peer, 20);
		fwrite($peer, 'GET /?' . $query . " HTTP/1.0\r\nHost: " . $address
			. "\r\nConnection: close\r\n\r\n");
		$response = stream_get_contents($peer);
		fclose($peer);
		if(!is_string($response) || strpos($response, "\r\n\r\n") === false)
			throw new RuntimeException('the web server sent no complete response');
		list($head, $body) = explode("\r\n\r\n", $response, 2);
		return array($head, $body);
	}

	public function testAFrameNumberThatIsNotANumberAnswersAsTheNumberItCoercesTo()
	{
		$address = $this->start();
		$query = 'cmd=ffmpeggetimage&no=1.1&file=' . rawurlencode('clip');
		list($plainHead, $plainBody) = $this->get($address, $query . '&fno=0');
		list($head, $body) = $this->get($address, $query . '&fno=' . rawurlencode('abc'));
		$this->stopServer();
		$this->assertTrue(strpos($plainHead, 'HTTP/1.0 200 OK') === 0,
			'frame 0 is served: ' . var_export($plainHead, true));
		$this->assertEquals(self::FRAME, $plainBody, 'frame 0 sends the frame bytes');
		$this->assertTrue(strpos($head, ' 500 ') === false,
			'a frame number that is not a number is not a server error: '
			. var_export($head, true));
		$this->assertEquals($plainHead, $head,
			'it receives the same headers as frame 0: ' . var_export($head, true));
		$this->assertEquals($plainBody, $body, 'it receives the same bytes as frame 0');
		foreach(array('Stack trace', 'TypeError', 'action.php on line') as $leak)
			$this->assertTrue(strpos($body, $leak) === false,
				'the response reveals no ' . $leak);
	}
}
