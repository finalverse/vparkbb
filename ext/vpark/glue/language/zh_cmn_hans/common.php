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
	'VPARK_HEADER_AD_TITLE' => '广告合作',
	'VPARK_HEADER_AD_DESC' => '此处可放置 300x80 横幅广告。联系 ads@victoriapark.io',
	'VPARK_AD_SLOT_HOME_TITLE' => '在此投放广告',
	'VPARK_AD_SLOT_HOME_DESC' => '维园网广告位 — 触达全球华人社区。联系 ads@victoriapark.io 了解合作方案。',
	'VPARK_AD_SLOT_SUB_TITLE' => '在此投放广告',
	'VPARK_AD_SLOT_SUB_DESC' => '联系 ads@victoriapark.io 了解广告投放方案。',
	'VPARK_BREAKING_LABEL' => '即时滚动新闻',
	'VPARK_BREAKING_EMPTY' => '暂无新闻滚动内容',
	'VPARK_HOME_NEWS_TITLE' => '维园实时新闻',
	'VPARK_HOME_NEWS_MORE' => '更多新闻',
	'VPARK_HOME_NEWS_EMPTY' => '暂无新闻内容',
));
