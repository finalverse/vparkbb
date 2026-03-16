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
	'VPARK_PORTAL_LINK' => 'Portail',
	'VPARK_PORTAL_SPACES' => 'Espaces de ville',
	'VPARK_TOPIC_SUMMARY' => 'Résumé',
	'VPARK_LEGAL_DISCLAIMER' => 'VictoriaPark.io ne représente ni ne garantit la véracité, l’exactitude ou la fiabilité des messages publiés par d’autres utilisateurs.',
	'VPARK_COPYRIGHT_LINE' => 'Copyright ©2026 victoriapark.io Tous droits réservés',
	'VPARK_LANG_ZH_HANS' => '简体中文',
	'VPARK_LANG_ZH_HANT' => '繁體中文',
	'VPARK_LANG_EN' => 'ENGLISH',
	'VPARK_LANG_MORE' => 'Plus de langues',
	'VPARK_LANG_FR' => 'FRENCH',
	'VPARK_LANG_ES' => 'SPANISH',
	'VPARK_LANG_EN_GB' => 'ENGLISH GB',
	'VPARK_FORUM_PANEL_TITLE' => 'Répertoire des forums VictoriaPark',
	'VPARK_FORUM_PANEL_SUBTITLE' => 'Accès rapide aux sections clés (contenu principalement en chinois simplifié).',
	‘VPARK_HEADER_AD_TITLE’ => ‘Sponsor’,
	‘VPARK_HEADER_AD_DESC’ => ‘Espace bannière 300x80. Contactez ads@victoriapark.io’,
	‘VPARK_AD_SLOT_HOME_TITLE’ => ‘Publicité’,
	‘VPARK_AD_SLOT_HOME_DESC’ => ‘Rejoignez la communauté VictoriaPark.io. Contactez ads@victoriapark.io pour les opportunités.’,
	‘VPARK_AD_SLOT_SUB_TITLE’ => ‘Publicité’,
	‘VPARK_AD_SLOT_SUB_DESC’ => ‘Contactez ads@victoriapark.io pour les opportunités publicitaires.’,
	'VPARK_BREAKING_LABEL' => 'Actualités Défilantes',
	'VPARK_BREAKING_EMPTY' => 'Aucune actualité pour le moment',
	'VPARK_HOME_NEWS_TITLE' => 'Actualités en direct VictoriaPark',
	'VPARK_HOME_NEWS_MORE' => 'Plus d’actualités',
	'VPARK_HOME_NEWS_EMPTY' => 'Aucune actualité pour le moment',
));
