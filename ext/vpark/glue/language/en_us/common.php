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
	'VPARK_LANG_ZH_HANS' => '简体中文',
	'VPARK_LANG_ZH_HANT' => '繁體中文',
	'VPARK_LANG_EN' => 'ENGLISH',
	'VPARK_LANG_MORE' => 'More Languages',
	'VPARK_LANG_FR' => 'FRENCH',
	'VPARK_LANG_ES' => 'SPANISH',
	'VPARK_LANG_EN_GB' => 'ENGLISH GB',
	'VPARK_FORUM_PANEL_TITLE' => 'VictoriaPark Forum Directory',
	'VPARK_FORUM_PANEL_SUBTITLE' => 'Quick entry to key boards (content is primarily Simplified Chinese).',
	'VPARK_HEADER_AD_TITLE' => 'Sponsor',
	'VPARK_HEADER_AD_DESC' => 'Premium 300x80 banner spot. Contact ads@victoriapark.io',
	'VPARK_AD_SLOT_HOME_TITLE' => 'Advertise Here',
	'VPARK_AD_SLOT_HOME_DESC' => 'Reach the VictoriaPark.io community. Contact ads@victoriapark.io for partnership opportunities.',
	'VPARK_AD_SLOT_SUB_TITLE' => 'Advertise Here',
	'VPARK_AD_SLOT_SUB_DESC' => 'Contact ads@victoriapark.io for advertising opportunities.',
	'VPARK_BREAKING_LABEL' => 'Breaking News',
	'VPARK_BREAKING_EMPTY' => 'No breaking items yet',
	'VPARK_HOME_NEWS_TITLE' => 'VictoriaPark Live News',
	'VPARK_HOME_NEWS_MORE' => 'More News',
	'VPARK_HOME_NEWS_EMPTY' => 'No news items yet',
));
