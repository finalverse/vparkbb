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
	'VPARK_FORUM_PANEL_TITLE' => 'Directorio de Foros VictoriaPark',
	'VPARK_FORUM_PANEL_SUBTITLE' => 'Acceso rápido a foros clave (contenido principalmente en chino simplificado).',
	'VPARK_HEADER_AD_TITLE' => 'Patrocinador',
	'VPARK_HEADER_AD_DESC' => 'Espacio banner 300x80. Contacta ads@victoriapark.io',
	'VPARK_AD_SLOT_HOME_TITLE' => 'Publicidad',
	'VPARK_AD_SLOT_HOME_DESC' => 'Únete a la comunidad VictoriaPark.io. Contacta ads@victoriapark.io para oportunidades.',
	'VPARK_AD_SLOT_SUB_TITLE' => 'Publicidad',
	'VPARK_AD_SLOT_SUB_DESC' => 'Contacta ads@victoriapark.io para oportunidades publicitarias.',
	'VPARK_BREAKING_LABEL' => 'Noticias en Tiempo Real',
	'VPARK_BREAKING_EMPTY' => 'Todavía no hay noticias',
	'VPARK_HOME_NEWS_TITLE' => 'Noticias en vivo de VictoriaPark',
	'VPARK_HOME_NEWS_MORE' => 'Más noticias',
	'VPARK_HOME_NEWS_EMPTY' => 'Todavía no hay noticias',
));
