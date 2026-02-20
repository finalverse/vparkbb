<?php
/**
 *
 * VictoriaPark glue extension for phpBB.
 *
 */

if (!defined('IN_PHPBB'))
{
	exit;
}

if (empty($lang) || !is_array($lang))
{
	$lang = array();
}

$lang = array_merge($lang, array(
	'VPARK_PORTAL_LINK' => 'Portal',
	'VPARK_PORTAL_SPACES' => 'City Spaces',
	'VPARK_TOPIC_SUMMARY' => 'Summary',
	'VPARK_LEGAL_DISCLAIMER' => 'VictoriaPark.io does not represent or guarantee the truthfulness, accuracy, or reliability of any of communications posted by other users.',
	'VPARK_COPYRIGHT_LINE' => 'Copyright ©2026 victoriapark.io All rights reserved',
	'VPARK_LANG_ZH_HANS' => '简体',
	'VPARK_LANG_ZH_HANT' => '繁體',
	'VPARK_LANG_EN' => 'EN',
));
