<?php

/**
 * RFC 6266 download names: a quoted ASCII fallback and an RFC 8187 UTF-8 name.
 * Quotes and backslashes must be quoted-pairs in the fallback. Invalid UTF-8
 * cannot honestly be advertised as UTF-8, so it receives only the fallback.
 */
class ContentDisposition
{
	public static function attachment( $name, $userAgent = null )
	{
		$name = self::oneName((string)$name);
		if(is_string($userAgent) && (strpos($userAgent,'MSIE')!==false))
			$fallback = rawurlencode($name);
		else
			$fallback = str_replace(array('\\', '"'), array('\\\\', '\\"'),
				preg_replace('/[^\x20-\x7E]/', '_', $name));
		$value = 'attachment; filename="'.$fallback.'"';
		if(preg_match('//u', $name) === 1)
			$value .= "; filename*=UTF-8''".rawurlencode($name);
		return($value);
	}

	/**
	 * The name as one directory entry. RFC 6266 names a file and not a path,
	 * and the RFC 8187 form would carry a separator percent-encoded rather
	 * than removed, so the path has to go before either form is built. A
	 * backslash is left alone: it is an ordinary character in the names this
	 * receives, and the fallback quotes it as a quoted-pair.
	 */
	private static function oneName( $name )
	{
		$cut = strrpos($name,'/');
		if($cut !== false)
			$name = substr($name,$cut+1);
		return((($name==='') || ($name==='.') || ($name==='..')) ? '_' : $name);
	}
}
