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
	'VPARK_LANG_MORE' => '更多語言',
	'VPARK_LANG_FR' => 'FRENCH',
	'VPARK_LANG_ES' => 'SPANISH',
	'VPARK_LANG_EN_GB' => 'ENGLISH GB',
	'VPARK_FORUM_PANEL_TITLE' => '維園社區論壇導航',
	'VPARK_FORUM_PANEL_SUBTITLE' => '一鍵進入重點版面（全部為簡體中文社區）。',
	'VPARK_AD_SLOT_HOME_TITLE' => '首頁廣告位（預留）',
	'VPARK_AD_SLOT_HOME_DESC' => '此區域預留給後續付費商業廣告投放。',
	'VPARK_AD_SLOT_SUB_TITLE' => '子頁面廣告位（預留）',
	'VPARK_AD_SLOT_SUB_DESC' => '論壇子頁面廣告展示位，後續可接入商業廣告。',
	'VPARK_BREAKING_LABEL' => '即時滾動新聞',
	'VPARK_BREAKING_EMPTY' => '暫無新聞滾動內容',
));
