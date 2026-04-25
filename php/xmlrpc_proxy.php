<?php
/**
 * XMLRPC Proxy — handles raw XMLRPC pass-through with configurable trust.
 *
 * Modes:
 *   "off"                — reject all raw XMLRPC
 *   "passthrough_unsafe" — send all raw XMLRPC as trusted (dangerous)
 *   "sanitize"           — parse and sanitize known methods, send safe
 *                          payload as trusted; pass unknown methods as
 *                          untrusted (rtorrent whitelist decides)
 */

class XMLRPCProxy
{
	// Methods that need trusted connections but can carry dangerous
	// command parameters. We rebuild these from scratch.
	private static $sanitizeMethods = array(
		'load.start', 'load.raw_start', 'load.raw', 'load.normal',
		'load_start', 'load_raw_start', 'load_raw',
	);

	private static $log = true;

	private static function log($msg)
	{
		if(self::$log)
			FileUtil::toLog("xmlrpc-proxy: ".$msg);
	}

	/**
	 * Process a raw XMLRPC payload according to the configured mode.
	 *
	 * @param string $rawData   Raw XMLRPC XML from the client
	 * @param string $mode      "off", "passthrough_unsafe", or "sanitize"
	 * @param bool   $enableLog Enable/disable logging
	 * @return string|null      SCGI response, or null on error/rejection
	 */
	public static function process($rawData, $mode = 'sanitize', $enableLog = true)
	{
		self::$log = $enableLog;

		if($mode === 'off')
		{
			self::log("rejected (proxy disabled)");
			return null;
		}

		if($mode === 'passthrough_unsafe')
		{
			self::log("passthrough (UNSAFE mode)");
			return rXMLRPCRequest::send($rawData, true);
		}

		// sanitize mode
		$xml = @simplexml_load_string($rawData);
		if($xml === false || !isset($xml->methodName))
		{
			self::log("rejected (invalid XML)");
			return rXMLRPCRequest::send($rawData, false);
		}

		$methodName = (string)$xml->methodName;

		if(in_array($methodName, self::$sanitizeMethods))
			return self::sanitizeLoad($xml, $methodName);

		// Unknown method — pass through as untrusted.
		// rtorrent's own whitelist will allow/reject.
		self::log("untrusted: ".$methodName);
		return rXMLRPCRequest::send($rawData, false);
	}

	/**
	 * Rebuild a load.* call with only safe parameters.
	 * Strips command strings (params beyond target + URL/data).
	 */
	private static function sanitizeLoad($xml, $methodName)
	{
		$originalCount = isset($xml->params->param) ? count($xml->params->param) : 0;

		// Rebuild a clean XMLRPC call keeping only safe params:
		// param 0: target (empty string)
		// param 1: URL or raw torrent data
		// param 2+: command strings — STRIP these
		$cleanXml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$cleanXml .= '<methodCall><methodName>' . htmlspecialchars($methodName) . '</methodName>';
		$cleanXml .= '<params>';
		if(isset($xml->params->param))
		{
			$count = 0;
			foreach($xml->params->param as $param)
			{
				if($count >= 2)
					break;
				// Preserve original XML type (string, base64, etc.)
				$cleanXml .= '<param>' . $param->value->asXML() . '</param>';
				$count++;
			}
		}
		$cleanXml .= '</params></methodCall>';

		$strippedCount = $originalCount - min($originalCount, 2);
		if($strippedCount > 0)
			self::log("sanitized: ".$methodName." (stripped ".$strippedCount." command param(s))");
		else
			self::log("trusted: ".$methodName);

		return rXMLRPCRequest::send($cleanXml, true);
	}
}
