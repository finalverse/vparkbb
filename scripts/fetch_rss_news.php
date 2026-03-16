<?php
/**
 * RSS Breaking News Fetcher for VictoriaPark.
 *
 * Fetches headlines from public RSS feeds in multiple languages and caches
 * them for the breaking news ticker. Run via cron every 10 minutes:
 *
 *   docker compose exec php php /var/www/html/scripts/fetch_rss_news.php
 *
 * Or add to crontab (every 10 minutes):
 *   docker compose -f /path/to/docker-compose.yml exec -T php php /var/www/html/scripts/fetch_rss_news.php
 */

define('IN_PHPBB', true);
$phpbb_root_path = dirname(__DIR__) . '/';
$phpEx = 'php';

require($phpbb_root_path . 'common.' . $phpEx);

$user->session_begin();
$auth->acl($user->data);

// Default RSS feeds organized by language
$default_feeds = array(
	// Chinese (Simplified)
	'zh_cmn_hans' => array(
		'https://www.chinanews.com.cn/rss/scroll-news.xml',
		'https://cn.nytimes.com/rss/',
	),
	// Chinese (Traditional)
	'zh_cmn_hant' => array(
		'https://news.pts.org.tw/xml/newsfeed.xml',
	),
	// English
	'en' => array(
		'https://rss.nytimes.com/services/xml/rss/nyt/World.xml',
		'https://feeds.bbci.co.uk/news/world/rss.xml',
	),
	// French
	'fr' => array(
		'https://www.lemonde.fr/rss/une.xml',
		'https://www.rfi.fr/fr/rss',
	),
	// Spanish
	'es' => array(
		'https://feeds.elpais.com/mrss-s/pages/ep/site/elpais.com/portada',
		'https://e00-elmundo.uecdn.es/elmundo/rss/portada.xml',
	),
);

// Allow override via env var: comma-separated list of feed URLs
$env_feeds = trim((string) getenv('VPARK_BREAKING_NEWS_FEEDS'));
if ($env_feeds !== '')
{
	$default_feeds = array('custom' => explode(',', $env_feeds));
}

$max_items_per_feed = 15;
$max_total = 80;
$cache_key = '_vpark_rss_breaking_news';
$cache_ttl = 600; // 10 minutes

$all_items = array();

foreach ($default_feeds as $lang => $feed_urls)
{
	foreach ($feed_urls as $feed_url)
	{
		$feed_url = trim($feed_url);
		if ($feed_url === '')
		{
			continue;
		}

		$items = fetch_rss_items($feed_url, $max_items_per_feed, $lang);
		$all_items = array_merge($all_items, $items);
	}
}

// Shuffle so different sources mix together in the ticker
shuffle($all_items);

// Limit total items
if (count($all_items) > $max_total)
{
	$all_items = array_slice($all_items, 0, $max_total);
}

// Cache the results
if (isset($phpbb_container))
{
	try
	{
		$cache_service = $phpbb_container->get('cache');
		$cache_service->put($cache_key, $all_items, $cache_ttl);
		echo '[rss] Cached ' . count($all_items) . " breaking news items.\n";
	}
	catch (\Exception $e)
	{
		echo '[rss] WARNING: Could not cache results: ' . $e->getMessage() . "\n";
	}
}
else
{
	echo "[rss] WARNING: phpBB container not available, cannot cache.\n";
}

echo '[rss] Done. Fetched ' . count($all_items) . " items from " . count_feeds($default_feeds) . " feeds.\n";

function count_feeds(array $feeds_by_lang)
{
	$count = 0;
	foreach ($feeds_by_lang as $urls)
	{
		$count += count($urls);
	}
	return $count;
}

function fetch_rss_items($url, $limit, $lang)
{
	$items = array();

	$context = stream_context_create(array(
		'http' => array(
			'timeout' => 10,
			'user_agent' => 'VictoriaPark-RSS/1.0',
			'ignore_errors' => true,
		),
		'ssl' => array(
			'verify_peer' => true,
			'verify_peer_name' => true,
		),
	));

	$xml_string = @file_get_contents($url, false, $context);
	if ($xml_string === false)
	{
		echo "[rss] WARN: Failed to fetch $url\n";
		return $items;
	}

	// Suppress XML parsing warnings
	$prev = libxml_use_internal_errors(true);
	$xml = @simplexml_load_string($xml_string);
	libxml_use_internal_errors($prev);

	if ($xml === false)
	{
		echo "[rss] WARN: Failed to parse XML from $url\n";
		return $items;
	}

	$count = 0;

	// RSS 2.0 format
	if (isset($xml->channel->item))
	{
		foreach ($xml->channel->item as $entry)
		{
			if ($count >= $limit)
			{
				break;
			}

			$title = trim((string) $entry->title);
			$link = trim((string) $entry->link);
			if ($title === '' || $link === '')
			{
				continue;
			}

			// Clean up title
			$title = html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8');
			$title = strip_tags($title);
			if (mb_strlen($title) > 80)
			{
				$title = mb_substr($title, 0, 77) . '...';
			}

			$items[] = array(
				'title' => $title,
				'url'   => $link,
				'lang'  => $lang,
			);
			$count++;
		}
	}
	// Atom format
	elseif (isset($xml->entry))
	{
		foreach ($xml->entry as $entry)
		{
			if ($count >= $limit)
			{
				break;
			}

			$title = trim((string) $entry->title);
			$link = '';
			if (isset($entry->link))
			{
				foreach ($entry->link as $l)
				{
					$rel = (string) $l['rel'];
					if ($rel === 'alternate' || $rel === '')
					{
						$link = (string) $l['href'];
						break;
					}
				}
				if ($link === '' && isset($entry->link[0]))
				{
					$link = (string) $entry->link[0]['href'];
				}
			}

			if ($title === '' || $link === '')
			{
				continue;
			}

			$title = html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8');
			$title = strip_tags($title);
			if (mb_strlen($title) > 80)
			{
				$title = mb_substr($title, 0, 77) . '...';
			}

			$items[] = array(
				'title' => $title,
				'url'   => $link,
				'lang'  => $lang,
			);
			$count++;
		}
	}

	echo "[rss] Fetched $count items from $url ($lang)\n";
	return $items;
}
