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
	'VPARK_FORUM_PANEL_TITLE' => '维园社区论坛导航',
	'VPARK_FORUM_PANEL_SUBTITLE' => '一键进入重点版面（全部为简体中文社区）。',
	'VPARK_AD_SLOT_HOME_TITLE' => '首页广告位（预留）',
	'VPARK_AD_SLOT_HOME_DESC' => '此区域预留给后续付费商业广告投放。',
	'VPARK_AD_SLOT_SUB_TITLE' => '子页面广告位（预留）',
	'VPARK_AD_SLOT_SUB_DESC' => '论坛子页面广告展示位，后续可接入商业广告。',
));
