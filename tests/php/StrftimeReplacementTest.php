<?php

require_once(__DIR__ . '/TestCase.php');

/**
 * strftime() and gmstrftime() are deprecated as of PHP 8.1 and removed in
 * PHP 9: every call logs a deprecation, from shipped code an operator
 * cannot fix. The replacements have to print what the old calls printed,
 * because what they print ends up in labels, RSS feeds and notifications
 * people already rely on.
 *
 * Each rendering is run in a child PHP with every error reported, so a
 * deprecation on the path shows up in what the child printed.
 */
class StrftimeReplacementTest extends TestCase
{
	private $root = null;

	public function setUp()
	{
		$this->root = realpath(__DIR__ . '/../..');
	}

	/** Runs $code in a child PHP; returns what it printed, errors included. */
	private function runChild($code)
	{
		$file = tempnam(sys_get_temp_dir(), 'strftime-test-');
		file_put_contents($file, "<?php\n" . $code);
		$out = array();
		exec(escapeshellarg(PHP_BINARY) . ' -d error_reporting=-1 -d display_errors=1 '
			. escapeshellarg($file) . ' 2>&1', $out, $status);
		unlink($file);
		return implode("\n", $out);
	}

	private function shippedSources()
	{
		$files = array($this->root . '/rpc2.php');
		foreach (array('/conf', '/php', '/plugins') as $sub) {
			$walk = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
				$this->root . $sub, FilesystemIterator::SKIP_DOTS));
			foreach ($walk as $file) {
				if (substr($file->getFilename(), -4) === '.php') {
					$files[] = $file->getPathname();
				}
			}
		}
		sort($files);
		return $files;
	}

	public function testNoShippedCodeCallsStrftime()
	{
		$bad = array();
		$skip = array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT);
		foreach ($this->shippedSources() as $path) {
			$tokens = array_values(array_filter(token_get_all(file_get_contents($path)),
				function ($t) use ($skip) { return !is_array($t) || !in_array($t[0], $skip); }));
			foreach ($tokens as $i => $t) {
				if (!is_array($t) || !in_array($t[0], array(T_STRING, T_NAME_FULLY_QUALIFIED))
					|| !in_array(strtolower(ltrim($t[1], '\\')), array('strftime', 'gmstrftime'))) {
					continue;
				}
				$before = $i > 0 ? $tokens[$i - 1] : null;
				$after = isset($tokens[$i + 1]) ? $tokens[$i + 1] : null;
				// a call, not a method or a declaration of that name
				if ($after === '(' && !(is_array($before) && in_array($before[0],
					array(T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION)))) {
					$bad[] = substr($path, strlen($this->root) + 1) . ':' . $t[2];
				}
			}
		}
		$this->assertEquals('[]', json_encode($bad), empty($bad)
			? 'no shipped code calls strftime() or gmstrftime()'
			: 'strftime()/gmstrftime() calls: ' . implode(', ', $bad));
	}

	/**
	 * The {NOW:<format>} label template takes strftime() conversions, so
	 * templates written for the old call have to render as they did.
	 */
	public function testLabelDateRendersTheStrftimeConversions()
	{
		$cases = array(
			array('Europe/Madrid', 1790000000, '%Y-%m-%d', '2026-09-21'),
			array('Europe/Madrid', 1790000000, '{%d/%m %H:%M}', '{21/09 16:13}'),
			array('UTC', 1790000000, '%c', 'Mon Sep 21 14:13:20 2026'),
			array('UTC', 1788307200, '%c|%e|%-d|%_m|%0e|%k|%l', 'Wed Sep  2 00:00:00 2026| 2|2| 9|02| 0|12'),
			array('UTC', 1790000000, '%a %A %b %B %h %^a', 'Mon Monday Sep September Sep MON'),
			array('UTC', 1790000000, '%C %y %G %g %j %U %V %W %u %w', '20 26 2026 26 264 38 39 38 1 1'),
			array('UTC', 1790000000, '%D %F %T %R %r %x %X %p %P', '09/21/26 2026-09-21 14:13:20 14:13 02:13:20 PM 09/21/26 14:13:20 PM pm'),
			array('Asia/Kolkata', 1790000000, '%z %Z %s', '+0530 IST 1790000000'),
			array('UTC', 1790000000, '%Ey %Od 100%% %Q %', '26 21 100% %Q %'),
			array('UTC', 1609459199, '%G-W%V-%u %Y', '2020-W53-4 2020'),
		);
		$code = 'chdir(' . var_export($this->root . '/plugins/autotools', true) . ");\n"
			. 'require_once("./util_rt.php");' . "\n"
			. '$out = array();' . "\n"
			. 'foreach (' . var_export($cases, true) . ' as $c) {' . "\n"
			. '	date_default_timezone_set($c[0]);' . "\n"
			. '	$out[] = rtStrftime($c[2], $c[1]);' . "\n"
			. "}\n"
			. 'echo json_encode($out);' . "\n";
		$printed = $this->runChild($code);
		$this->assertEquals(json_encode(array_column($cases, 3)), $printed,
			'label dates render as strftime() rendered them, with no deprecation; the child printed: '
			. $printed);
	}

	/** The RSS <pubDate> stays an RFC 822 date in GMT. */
	public function testFeedPubDateIsRfc822InGmt()
	{
		$src = file_get_contents($this->root . '/plugins/feeds/action.php');
		$this->assertTrue(preg_match('`<pubDate>"\.(.*?),\$val\)(.*?)</pubDate>`', $src, $m) === 1,
			'the feed builds <pubDate> from $val');
		$printed = $this->runChild('date_default_timezone_set("Europe/Madrid"); $val = 1790000000.0;'
			. ' echo ' . $m[1] . ',$val)' . $m[2] . "';\n");
		$this->assertEquals('Mon, 21 Sep 2026 14:13:20 GMT', $printed,
			'pubDate renders in GMT, with no deprecation; the child printed: ' . $printed);
	}

	/** Pushbullet notifications show {creation}, {added} and {finished} this way. */
	public function testHistoryNotificationTimeKeepsItsFormat()
	{
		$printed = $this->runChild('require_once('
			. var_export($this->root . '/plugins/history/history.php', true) . ");\n"
			. 'date_default_timezone_set("Europe/Madrid");' . "\n"
			. 'echo rHistory::formatTime(1788307200), "|", rHistory::formatTime("1790000000");' . "\n");
		$this->assertEquals('Wed Sep  2 02:00:00 2026|Mon Sep 21 16:13:20 2026', $printed,
			'notification times render as strftime("%c") did, with no deprecation; the child printed: '
			. $printed);
	}
}
