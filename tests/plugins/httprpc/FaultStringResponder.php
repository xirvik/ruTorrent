<?php

require_once(__DIR__ . '/../../php/RtorrentProbeFixture.php');

/**
 * A daemon that answers a d.multicall by refusing the command parameter it was
 * given, naming that parameter in faultString.
 *
 * rtorrent does this itself: several of its refusals interpolate the argument
 * of the command they refuse (command_throttle.cc, command_ui.cc, globals.cc,
 * tracker_list.cc), and the argument of a d.multicall command parameter is the
 * caller's own bytes. The escaping round trip is reproduced as well: the
 * request arrives with '<' and '>' as entities, this reads them back, and
 * FakeRtorrentDaemon escapes the reply the way rtorrent's XMLRPC layer does.
 *
 * Everything else is the probe fixture's answer, so the script learns the same
 * daemon every other test learns.
 */
class FaultStringResponder
{
	public function __invoke($body, $methods = array())
	{
		$names = RtorrentProbeFixture::methodNames($body);
		foreach($names as $name)
			if(strpos($name, 'd.multicall') === 0)
				return FakeRtorrentDaemon::fault(
					'Could not set "' . self::lastArgument($body) . '": out of range');
		$responder = RtorrentProbeFixture::responder();
		return $responder($body);
	}

	/** The last string parameter of the request, as rtorrent would read it. */
	private static function lastArgument($body)
	{
		if(!preg_match_all('|<value><string>(.*)</string></value>|Us', $body, $matches))
			return '';
		$last = end($matches[1]);
		return html_entity_decode($last, ENT_COMPAT, 'UTF-8');
	}
}
