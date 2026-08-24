<?php

require_once(__DIR__ . '/TestCase.php');

/**
 * One safe-parameter policy, for every door.
 *
 * conf/xmlrpc_proxy.php states which command names a caller may attach to a
 * load.* or to a multicall. rpc2.php reads that file, and
 * plugins/httprpc/action.php reads it before evaluating the plugin conf, so the
 * two doors decide the same way about the same request.
 *
 * A second $XMLRPCProxySafeParams anywhere in the shipped tree replaces it for
 * whichever door loads that file, and nothing about the two lists says they are
 * meant to agree: they drift, and a client that works through one door is
 * refused through the other. That is what this asserts -- a shipped file may
 * restate the policy, but not restate it differently.
 */
class XMLRPCProxyPolicyParityTest extends TestCase
{
	private $root = null;
	private $reference = null;

	public function setUp()
	{
		$this->root = realpath(__DIR__ . '/../..');
		$this->reference = $this->safeParamsOf($this->root . '/conf/xmlrpc_proxy.php');
	}

	/**
	 * The list a policy file defines on its own, or null when it defines none.
	 * Evaluated inside a closure so the file's other variables cannot reach the
	 * next one.
	 */
	private function safeParamsOf($file)
	{
		$read = function ($f) {
			$XMLRPCProxySafeParams = null;
			require($f);
			return $XMLRPCProxySafeParams;
		};
		return $read($file);
	}

	/** Every shipped conf file that assigns the list, other than the reference. */
	private function otherDefiners()
	{
		$found = array();
		foreach (array('/conf', '/plugins') as $sub) {
			$dir = $this->root . $sub;
			if (!is_dir($dir)) {
				continue;
			}
			$walk = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
				$dir, FilesystemIterator::SKIP_DOTS));
			foreach ($walk as $file) {
				$path = $file->getPathname();
				if (!preg_match('`/(conf|conf\.local|xmlrpc_proxy)\.php$`', $path)) {
					continue;
				}
				if ($path === $this->root . '/conf/xmlrpc_proxy.php') {
					continue;
				}
				$src = @file_get_contents($path);
				if (($src !== false) && preg_match('`\$XMLRPCProxySafeParams\s*=`', $src)) {
					$found[] = $path;
				}
			}
		}
		sort($found);
		return $found;
	}

	public function testTheReferencePolicyIsTheOneWithTheViewActions()
	{
		$this->assertTrue(is_array($this->reference) && (count($this->reference) > 0),
			'conf/xmlrpc_proxy.php defines $XMLRPCProxySafeParams');
		foreach (array('d.open', 'd.close', 'd.start', 'd.stop') as $action) {
			$this->assertTrue(in_array($action, (array)$this->reference, true),
				"the shared policy allows {$action}, so a client can pause and resume a view");
		}
	}

	public function testEveryOtherDefinitionOfThePolicyAgreesWithIt()
	{
		$reference = (array)$this->reference;
		sort($reference);
		foreach ($this->otherDefiners() as $path) {
			$theirs = (array)$this->safeParamsOf($path);
			sort($theirs);
			$name = substr($path, strlen($this->root) + 1);
			$this->assertEquals(json_encode($reference), json_encode($theirs),
				"{$name} states the same safe-parameter policy as conf/xmlrpc_proxy.php");
		}
	}

	public function testTheHttprpcDoorReadsTheSharedPolicyBeforeItsOwnConf()
	{
		$src = @file_get_contents($this->root . '/plugins/httprpc/action.php');
		$this->assertTrue($src !== false, 'plugins/httprpc/action.php is readable');
		$policy = strpos((string)$src, 'conf/xmlrpc_proxy.php');
		$conf = strpos((string)$src, "FileUtil::getPluginConf('httprpc')");
		$this->assertTrue($policy !== false,
			'plugins/httprpc/action.php loads conf/xmlrpc_proxy.php');
		$this->assertTrue(($policy !== false) && ($conf !== false) && ($policy < $conf),
			'it loads the shared policy before the plugin conf, so the plugin conf can override it');
	}
}
