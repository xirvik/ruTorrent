<?php

require_once(__DIR__ . '/TestCase.php');
require_once(__DIR__ . '/TestTree.php');
require_once(__DIR__ . '/../../php/utility/fileutil.php');

class TempDirectoryModeTest extends TestCase
{
	private $root;
	private $umask;

	// The fixtures mkdir() outside makeDirectory(), which masks nothing of
	// its own, so what these cases read back is the mode the runner's umask
	// allows. Choose one rather than inherit it.
	public function setUp()
	{
		$this->umask = umask(0022);
		$this->root = sys_get_temp_dir().'/rutorrent-temp-mode-'.getmypid();
		@mkdir($this->root, 0700);
	}

	public function tearDown()
	{
		global $tempDirectory, $tempDirectory_init_done, $profileMask, $profilePath;
		$tempDirectory = null;
		$tempDirectory_init_done = false;
		$profileMask = 0777;
		$profilePath = null;
		TestTree::remove($this->root.'/profile');
		foreach(glob($this->root.'/*') ?: array() as $path)
		{
			if(is_link($path) || is_file($path)) @unlink($path);
			else if(is_dir($path))
			{
				foreach(glob($path.'/*') ?: array() as $entry) @unlink($entry);
				@rmdir($path);
			}
		}
		@rmdir($this->root);
		umask($this->umask);
	}

	private function mode($path)
	{
		clearstatcache(true, $path);
		return(fileperms($path) & 07777);
	}

	public function testExistingCustomTempDirectoryKeepsItsProtectedMode()
	{
		global $tempDirectory, $tempDirectory_init_done, $profileMask;
		$path = $this->root.'/existing';
		mkdir($path, 0700);
		file_put_contents($path.'/keep', 'old');
		$tempDirectory = $path;
		$tempDirectory_init_done = false;
		$profileMask = 0777;
		$this->assertEquals($path.'/', FileUtil::getTempDirectory());
		$this->assertEquals(0700, $this->mode($path),
			'selecting an existing custom temp path must not reopen its mode');
		$this->assertEquals('old', file_get_contents($path.'/keep'));
	}

	public function testSymlinkAliasToExistingCustomTempDoesNotRemodeTarget()
	{
		global $tempDirectory, $tempDirectory_init_done, $profileMask;
		$target = $this->root.'/target';
		$alias = $this->root.'/alias';
		mkdir($target, 0700);
		symlink($target, $alias);
		$tempDirectory = $alias;
		$tempDirectory_init_done = false;
		$profileMask = 0777;
		$this->assertEquals($alias.'/', FileUtil::getTempDirectory());
		$this->assertEquals(0700, $this->mode($target),
			'selecting a symlink alias must not reopen the existing target');
	}

	/**
	 * $profileMask is what an operator declared for profile data, and its
	 * default is 0777, so creating a temporary directory at it leaves one any
	 * user on the box may rename or delete ruTorrent's files in. The sticky
	 * bit is what stops that, and is what /tmp -- the directory an install
	 * that configures nothing is given -- carries.
	 */
	public function testACreatedTempDirectoryIsNotLeftOpenToRewriting()
	{
		global $tempDirectory, $tempDirectory_init_done, $profileMask;
		$cases = array(array(0777, 01777, 'a world-writable mask gains the sticky bit'),
			array(0770, 01770, 'a group-writable mask gains it too'),
			array(0755, 0755, 'a mask only its owner may write keeps the mode asked for'),
			array(0700, 0700, 'and so does a private one'));
		foreach($cases as $index => $case)
		{
			$path = $this->root.'/created'.$index;
			$tempDirectory = $path;
			$tempDirectory_init_done = false;
			$profileMask = $case[0];
			$this->assertEquals($path.'/', FileUtil::getTempDirectory());
			$this->assertEquals($case[1], $this->mode($path),
				$case[2].': 0'.decoct($case[0]).' created 0'.decoct($this->mode($path)));
		}
	}

	/**
	 * A profile is created in one call, and one of the four directories it
	 * creates is a temporary directory: the one getTempDirectory() falls back
	 * to when no system directory can be used. The three that hold profile
	 * data keep the declared mask.
	 */
	public function testTheProfileTemporaryDirectoryIsCreatedTheSameWay()
	{
		global $profilePath, $profileMask;
		$profilePath = $this->root.'/profile';
		$profileMask = 0777;
		$profile = FileUtil::getProfilePathEx('u1');
		$this->assertEquals($this->root.'/profile/users/u1', $profile,
			'the profile path is the one the configuration names');
		$this->assertEquals(01777, $this->mode($profile.'/tmp'),
			'the temporary directory inside a new profile is created sticky');
		foreach(array('', '/settings', '/torrents') as $part)
			$this->assertEquals(0777, $this->mode($profile.$part),
				'what holds profile data keeps the declared mask: '.($part === '' ? 'the profile itself' : $part));
	}

	public function testMissingCustomTempDirectoryIsCreatedWithProfileMask()
	{
		global $tempDirectory, $tempDirectory_init_done, $profileMask;
		$path = $this->root.'/new';
		$tempDirectory = $path;
		$tempDirectory_init_done = false;
		$profileMask = 0750;
		$this->assertEquals($path.'/', FileUtil::getTempDirectory());
		$this->assertEquals(0750, $this->mode($path),
			'a missing selected temp path still uses the configured mask');
	}
}
