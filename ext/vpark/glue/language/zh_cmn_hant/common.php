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
	'VPARK_PORTAL_LINK' => '門戶',
	'VPARK_PORTAL_SPACES' => '城市分區',
	'VPARK_TOPIC_SUMMARY' => '摘要',
	'VPARK_LEGAL_DISCLAIMER' => 'VictoriaPark.io 不代表或保證其他使用者發布內容的真實性、準確性或可靠性。',
	'VPARK_COPYRIGHT_LINE' => 'Copyright ©2026 victoriapark.io 版權所有',
	'VPARK_LANG_ZH_HANS' => '简体中文',
	'VPARK_LANG_ZH_HANT' => '繁體中文',
	'VPARK_LANG_EN' => 'ENGLISH',
));
