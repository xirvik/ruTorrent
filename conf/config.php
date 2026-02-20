<?php
	// configuration parameters

	// for snoopy client
	$httpUserAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36';
	$httpTimeOut = 30;			// in seconds
	$httpUseGzip = true;
	$httpIP = "_XIRVIK_IP_ADDRESS";				// IP string. Or null for any.
	$httpProxy = array
	(
		'use' 	=> false,
		'proto'	=> 'http',		// 'http' or 'https'
		'host'	=> 'PROXY_HOST_HERE',
		'port'	=> 3128
	);

	// for xmlrpc actions
	$rpcTimeOut = 5;			// in seconds
	$rpcLogCalls = false;
	$rpcLogFaults = true;

	// for php
	$phpUseGzip = false;
	$phpGzipLevel = 2;

	$schedule_rand = 10;			// rand for schedulers start, +0..X seconds

	$do_diagnostic = true;			// Diagnose ruTorrent. Recommended to keep enabled, unless otherwise required.
	$al_diagnostic = true;			// Diagnose auto-loader. Set to "false" to make composer plugins work.

	$log_file = '/var/log/rtorrent/error_XIRVIK_NUM.log';	// path to log file (comment or leave blank to disable logging)

	$saveUploadedTorrents = true;		// Save uploaded torrents to profile/torrents directory or not
	$overwriteUploadedTorrents = false;	// Overwrite existing uploaded torrents in profile/torrents directory or make unique name

	$topDirectory = '_XIRVIK_TOPDIRECTORY';	// Upper available directory. Absolute path with trail slash.
	$topDirectory = rtrim($topDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
	$forbidUserSettings = true;

	$scgi_port = _XIRVIK_SCGI_PORT;
	$scgi_host = "127.0.0.1";

	// For web->rtorrent link through unix domain socket
	// (scgi_local in rtorrent conf file), change variables
	// above to something like this:
	//
	// $scgi_port = 0;
	// $scgi_host = "unix:///tmp/rpc.socket";

	$XMLRPCMountPoint = "/RPC2";		// DO NOT DELETE THIS LINE!!! DO NOT COMMENT THIS LINE!!!

	$throttleMaxSpeed = 327625*1024;	// DO NOT EDIT THIS LINE!!! DO NOT COMMENT THIS LINE!!!
	// Can't be greater then 327625*1024 due to limitation in libtorrent ResourceManager::set_max_upload_unchoked function.

	$pathToExternals = array(
		"php"	=> '/usr/bin/php',
		"curl"	=> '/usr/bin/curl',
		"gzip"	=> '/bin/gzip',
		"id"	=> '/usr/bin/id',
		"stat"	=> '/usr/bin/stat',
		"sudo"	=> '/usr/bin/sudo',
		"mktorrent"	=> '/usr/local/bin/mktorrent'
	);

	$localHostedMode = true;		// Set to true if rTorrent is hosted on the SAME machine as ruTorrent

	$pluginMinification = true;		// Reduce loading times by minimizing JavaScript (new in v5.x)

	$localhosts = array(			// list of local interfaces
		"::1",
		"127.0.0.1",
		"localhost",
	);

	$profilePath = '../../share';		// Path to user profiles
	$profileMask = 0777;			// Mask for files and directory creation in user profiles.
						// Both Webserver and rtorrent users must have read-write access to it.
						// For example, if Webserver and rtorrent users are in the same group then the value may be 0770.

	$tempDirectory = '_XIRVIK_TEMP_PATH';	// Temporary directory; use one in the user's partition because /tmp might be too small

	$canUseXSendFile = false;		// If true then use X-Sendfile feature if it exist

	$locale = "UTF8";

	$enableCSRFCheck = false;		// If true then Origin and Referer will be checked
	$enabledOrigins = array();		// List of enabled domains for CSRF check (only hostnames, without protocols, port etc.).
						// If empty, then will retrieve domain from HTTP_HOST / HTTP_X_FORWARDED_HOST
