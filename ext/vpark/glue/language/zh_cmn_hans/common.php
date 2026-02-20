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
	'VPARK_PORTAL_LINK' => '门户',
	'VPARK_PORTAL_SPACES' => '城市分区',
	'VPARK_TOPIC_SUMMARY' => '摘要',
	'VPARK_LEGAL_DISCLAIMER' => 'VictoriaPark.io 不代表或保证其他用户发布内容的真实性、准确性或可靠性。',
	'VPARK_COPYRIGHT_LINE' => 'Copyright ©2026 victoriapark.io 版权所有',
	'VPARK_LANG_ZH_HANS' => '简体中文',
	'VPARK_LANG_ZH_HANT' => '繁體中文',
	'VPARK_LANG_EN' => 'ENGLISH',
	'VPARK_LANG_MORE' => '更多语言',
	'VPARK_LANG_FR' => 'FRENCH',
	'VPARK_LANG_ES' => 'SPANISH',
	'VPARK_LANG_EN_GB' => 'ENGLISH GB',
));
