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
	'VPARK_PORTAL_SPACES' => 'Espacios de ciudad',
	'VPARK_TOPIC_SUMMARY' => 'Resumen',
	'VPARK_LEGAL_DISCLAIMER' => 'VictoriaPark.io no representa ni garantiza la veracidad, exactitud o confiabilidad de ninguna comunicación publicada por otros usuarios.',
	'VPARK_COPYRIGHT_LINE' => 'Copyright ©2026 victoriapark.io Todos los derechos reservados',
	'VPARK_LANG_ZH_HANS' => '简体中文',
	'VPARK_LANG_ZH_HANT' => '繁體中文',
	'VPARK_LANG_EN' => 'ENGLISH',
	'VPARK_LANG_MORE' => 'Más idiomas',
	'VPARK_LANG_FR' => 'FRENCH',
	'VPARK_LANG_ES' => 'SPANISH',
	'VPARK_LANG_EN_GB' => 'ENGLISH GB',
));
