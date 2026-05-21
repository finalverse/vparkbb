<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli')
{
	fwrite(STDERR, "This script must be run via CLI.\n");
	exit(1);
}

define('IN_PHPBB', true);
$phpbb_root_path = dirname(__DIR__) . '/';
$phpEx = 'php';

require_once $phpbb_root_path . 'common.' . $phpEx;
require_once $phpbb_root_path . 'includes/functions_user.' . $phpEx;
require_once $phpbb_root_path . 'includes/functions_content.' . $phpEx;
require_once $phpbb_root_path . 'includes/functions_posting.' . $phpEx;
require_once $phpbb_root_path . 'includes/functions_admin.' . $phpEx;

$user->session_begin();
$auth->acl($user->data);
$user->setup('common');

set_time_limit(0);

const WENXUECITY_BASE_URL = 'https://www.wenxuecity.com';
const WENXUECITY_IMPORT_USER = 'wenxuecity_importer';
const WENXUECITY_IMPORT_EMAIL = 'wenxuecity-importer@victoriapark.local';
const WENXUECITY_IMPORT_PASSWORD = 'VictoriaPark!2026';

$options = getopt('', array(
	'limit:',
	'dry-run',
	'forum:',
	'forum-id:',
	'max-list-pages:',
	'cleanup-existing-metadata',
	'help',
));

if (isset($options['help']))
{
	echo <<<USAGE
Usage:
  php scripts/import_wenxuecity.php [options]

Options:
  --limit <n>           Max articles to import. Default: 20
  --dry-run             Crawl and parse without creating users, topics, posts, or import rows.
  --forum-id <id>       Target phpBB forum id. Must be an existing postable forum.
  --forum <name>        Target phpBB forum name. Must be an existing postable forum.
  --max-list-pages <n>  Max Wenxuecity listing pages to crawl. Default scales with --limit.
  --cleanup-existing-metadata
                        Remove the old public metadata block from imported Wenxuecity posts.
  --help                Show this help.

Examples:
  php scripts/import_wenxuecity.php --limit 20
  php scripts/import_wenxuecity.php --dry-run
  php scripts/import_wenxuecity.php --forum-id 2
  php scripts/import_wenxuecity.php --forum "百家论坛"
  php scripts/import_wenxuecity.php --cleanup-existing-metadata --limit 20

USAGE;
	exit(0);
}

$limit = max(1, (int) ($options['limit'] ?? 20));
$dry_run = isset($options['dry-run']);
$target_forum_name = isset($options['forum']) ? trim((string) $options['forum']) : '';
$target_forum_id = isset($options['forum-id']) ? (int) $options['forum-id'] : 0;
$max_list_pages = max(1, (int) ($options['max-list-pages'] ?? min(24, max(8, $limit))));
$cleanup_existing_metadata = isset($options['cleanup-existing-metadata']);

if ($target_forum_name !== '' && $target_forum_id > 0)
{
	fail('Use either --forum-id or --forum, not both.');
}

if (isset($options['forum']) && $target_forum_name === '')
{
	fail('Target forum name cannot be empty.');
}

if (isset($options['forum-id']) && $target_forum_id <= 0)
{
	fail('Target forum id must be a positive integer.');
}

$import_table = $table_prefix . 'wenxuecity_imports';
$import_table_ready = import_table_exists($import_table);

out('start: limit=' . $limit . ($cleanup_existing_metadata ? ', cleanup-existing-metadata=1' : '') . ($dry_run ? ', dry-run=1' : ''));

if ($cleanup_existing_metadata)
{
	if (!$import_table_ready)
	{
		fail('Import metadata table does not exist: ' . $import_table);
	}

	$cleaned = cleanup_existing_metadata_posts($import_table, $limit, $dry_run);
	out('cleanup done: cleaned=' . $cleaned);
	exit(0);
}

$target_forum = resolve_target_forum($target_forum_id, $target_forum_name);
out('selected target forum id: ' . (int) $target_forum['forum_id']);
out('selected target forum name: ' . (string) $target_forum['forum_name']);

if (!$dry_run && !$import_table_ready)
{
	ensure_import_table($import_table);
	$import_table_ready = true;
}
elseif ($dry_run && !$import_table_ready)
{
	out('[dry-run] metadata table would be created: ' . $import_table);
}

$article_urls = collect_article_urls($limit, $max_list_pages);

out('candidate articles found: ' . count($article_urls));

$import_user = null;
$stats = array(
	'imported' => 0,
	'skipped_duplicate' => 0,
	'failed' => 0,
	'seen' => 0,
);

foreach ($article_urls as $source_url)
{
	if ($stats['imported'] >= $limit)
	{
		break;
	}

	$stats['seen']++;
	$html = fetch_url($source_url);
	if ($html === null)
	{
		$stats['failed']++;
		out('failed URL: ' . $source_url . ' reason=fetch');
		continue;
	}

	$article = parse_article_page($source_url, $html);
	if ($article === null)
	{
		$stats['failed']++;
		out('failed URL: ' . $source_url . ' reason=parse');
		continue;
	}

	$duplicate_reason = $import_table_ready ? duplicate_reason($import_table, $article) : null;
	if ($duplicate_reason !== null)
	{
		$stats['skipped_duplicate']++;
		out('skipped duplicate: reason=' . $duplicate_reason . ' url=' . $article['source_url']);
		continue;
	}

	if ($dry_run)
	{
		out('[dry-run] would import URL: ' . $article['source_url']);
		out('[dry-run] title=' . $article['title'] . ' category=' . $article['category']);
		$stats['imported']++;
		continue;
	}

	if ($import_user === null)
	{
		$import_user = ensure_import_user();
	}

	$message = build_post_body($article);
	$post_time = usable_post_time((int) $article['publish_time']);
	$topic_id = submit_topic_post(
		(int) $target_forum['forum_id'],
		(string) $target_forum['forum_name'],
		(int) $import_user['user_id'],
		shorten($article['title'], 118),
		$message,
		$post_time
	);

	if ($topic_id <= 0)
	{
		$stats['failed']++;
		out('failed URL: ' . $article['source_url'] . ' reason=topic_create');
		continue;
	}

	$topic = topic_row($topic_id);
	$post_id = $topic ? (int) $topic['topic_first_post_id'] : 0;
	record_import($import_table, $article, (int) $target_forum['forum_id'], $topic_id, $post_id);
	$stats['imported']++;

	out('imported: topic_id=' . $topic_id . ' post_id=' . $post_id . ' title=' . $article['title']);
}

if (!$dry_run && $stats['imported'] > 0)
{
	sync('forum', 'forum_id', array((int) $target_forum['forum_id']), true, true);
}

out('done: imported=' . $stats['imported'] . ', skipped_duplicate=' . $stats['skipped_duplicate'] . ', failed=' . $stats['failed'] . ', seen=' . $stats['seen']);

function out(string $message): void
{
	echo '[wenxuecity-import] ' . $message . PHP_EOL;
}

function fail(string $message): void
{
	fwrite(STDERR, '[wenxuecity-import] ERROR: ' . $message . PHP_EOL);
	exit(1);
}

function import_table_exists(string $table_name): bool
{
	global $phpbb_container;

	$db_tools = $phpbb_container->get('dbal.tools');
	return $db_tools->sql_table_exists($table_name);
}

function ensure_import_table(string $table_name): void
{
	global $phpbb_container;

	$db_tools = $phpbb_container->get('dbal.tools');
	if ($db_tools->sql_table_exists($table_name))
	{
		return;
	}

	$db_tools->sql_create_table($table_name, array(
		'COLUMNS' => array(
			'import_id'			=> array('UINT', null, 'auto_increment'),
			'source_site'		=> array('VCHAR:32', 'wenxuecity'),
			'source_url_hash'	=> array('CHAR:64', ''),
			'source_url'		=> array('VCHAR_UNI:2048', ''),
			'content_hash'		=> array('CHAR:64', ''),
			'title_pub_hash'	=> array('CHAR:64', ''),
			'title'				=> array('STEXT_UNI', ''),
			'category'			=> array('STEXT_UNI', ''),
			'author'			=> array('STEXT_UNI', ''),
			'publish_time'		=> array('TIMESTAMP', 0),
			'thumbnail_url'		=> array('VCHAR_UNI:2048', ''),
			'image_urls'		=> array('MTEXT_UNI', ''),
			'body_html'			=> array('MTEXT_UNI', ''),
			'body_text'			=> array('MTEXT_UNI', ''),
			'forum_id'			=> array('UINT', 0),
			'topic_id'			=> array('UINT', 0),
			'post_id'			=> array('UINT', 0),
			'imported_at'		=> array('TIMESTAMP', 0),
		),
		'PRIMARY_KEY' => 'import_id',
		'KEYS' => array(
			'src_hash'		=> array('UNIQUE', 'source_url_hash'),
			'body_hash'		=> array('UNIQUE', 'content_hash'),
			'title_pub'		=> array('INDEX', 'title_pub_hash'),
			'imported_at'	=> array('INDEX', 'imported_at'),
			'topic_id'		=> array('INDEX', 'topic_id'),
		),
	));

	out('created metadata table: ' . $table_name);
}

function resolve_target_forum(int $forum_id, string $forum_name): array
{
	if ($forum_id > 0)
	{
		$row = forum_row_by_id($forum_id, FORUM_POST);
		if (!$row)
		{
			fail('Target forum id not found or not postable: ' . $forum_id);
		}
		return $row;
	}

	if ($forum_name !== '')
	{
		$row = forum_row_by_name($forum_name, FORUM_POST);
		if (!$row)
		{
			fail('Target forum name not found or not postable: ' . $forum_name);
		}
		return $row;
	}

	$row = first_postable_forum();
	if (!$row)
	{
		fail('No existing postable phpBB forum found. Create a board first, then rerun the importer.');
	}

	return $row;
}

function forum_row_by_id(int $forum_id, ?int $forum_type = null): ?array
{
	global $db;

	$sql = 'SELECT forum_id, parent_id, forum_name, forum_type, left_id
		FROM ' . FORUMS_TABLE . '
		WHERE forum_id = ' . (int) $forum_id;
	if ($forum_type !== null)
	{
		$sql .= ' AND forum_type = ' . (int) $forum_type;
	}
	$sql .= ' ORDER BY left_id ASC, forum_id ASC';
	$result = $db->sql_query_limit($sql, 1);
	$row = $db->sql_fetchrow($result);
	$db->sql_freeresult($result);

	return $row ? array(
		'forum_id' => (int) $row['forum_id'],
		'parent_id' => (int) $row['parent_id'],
		'forum_name' => (string) $row['forum_name'],
		'forum_type' => (int) $row['forum_type'],
		'left_id' => (int) $row['left_id'],
	) : null;
}

function forum_row_by_name(string $name, ?int $forum_type = null): ?array
{
	global $db;

	$sql = 'SELECT forum_id, parent_id, forum_name, forum_type, left_id
		FROM ' . FORUMS_TABLE . "
		WHERE forum_name = '" . $db->sql_escape($name) . "'";
	if ($forum_type !== null)
	{
		$sql .= ' AND forum_type = ' . (int) $forum_type;
	}
	$sql .= ' ORDER BY left_id ASC, forum_id ASC';
	$result = $db->sql_query_limit($sql, 1);
	$row = $db->sql_fetchrow($result);
	$db->sql_freeresult($result);

	return $row ? array(
		'forum_id' => (int) $row['forum_id'],
		'parent_id' => (int) $row['parent_id'],
		'forum_name' => (string) $row['forum_name'],
		'forum_type' => (int) $row['forum_type'],
		'left_id' => (int) $row['left_id'],
	) : null;
}

function first_postable_forum(): ?array
{
	global $db;

	$sql = 'SELECT forum_id, parent_id, forum_name, forum_type, left_id
		FROM ' . FORUMS_TABLE . '
		WHERE forum_type = ' . FORUM_POST . '
		ORDER BY left_id ASC, forum_id ASC';
	$result = $db->sql_query_limit($sql, 1);
	$row = $db->sql_fetchrow($result);
	$db->sql_freeresult($result);

	return $row ? array(
		'forum_id' => (int) $row['forum_id'],
		'parent_id' => (int) $row['parent_id'],
		'forum_name' => (string) $row['forum_name'],
		'forum_type' => (int) $row['forum_type'],
		'left_id' => (int) $row['left_id'],
	) : null;
}

function collect_article_urls(int $limit, int $max_list_pages): array
{
	$seed_urls = array(
		WENXUECITY_BASE_URL . '/',
		WENXUECITY_BASE_URL . '/news/',
		WENXUECITY_BASE_URL . '/news/morenews/',
		WENXUECITY_BASE_URL . '/news/photo/',
		WENXUECITY_BASE_URL . '/news/video/',
		WENXUECITY_BASE_URL . '/news/socialnews/',
		WENXUECITY_BASE_URL . '/news/ent/',
		WENXUECITY_BASE_URL . '/news/hotnews.php',
	);

	$queue = $seed_urls;
	$visited = array();
	$article_urls = array();
	$article_target = max($limit * 4, $limit + 10);

	while (!empty($queue) && count($visited) < $max_list_pages && count($article_urls) < $article_target)
	{
		$list_url = array_shift($queue);
		$list_url = normalize_url($list_url);
		if ($list_url === '' || isset($visited[$list_url]))
		{
			continue;
		}
		$visited[$list_url] = true;

		$html = fetch_url($list_url);
		if ($html === null)
		{
			out('failed listing: ' . $list_url);
			continue;
		}

		$links = extract_links($html, $list_url);
		foreach ($links as $link)
		{
			if (is_wenxuecity_article_url($link))
			{
				$article_urls[$link] = true;
				continue;
			}

			if (is_wenxuecity_listing_url($link) && !isset($visited[$link]) && count($queue) < $max_list_pages * 3)
			{
				$queue[] = $link;
			}
		}
	}

	return array_slice(array_keys($article_urls), 0, $article_target);
}

function fetch_url(string $url): ?string
{
	$headers = array(
		'User-Agent: VictoriaParkWenxuecityImporter/1.0 (+https://victoriapark.io)',
		'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
		'Accept-Language: zh-CN,zh;q=0.9,en;q=0.6',
	);

	$context = stream_context_create(array(
		'http' => array(
			'method' => 'GET',
			'timeout' => 15,
			'ignore_errors' => true,
			'follow_location' => 1,
			'max_redirects' => 5,
			'header' => implode("\r\n", $headers) . "\r\n",
		),
		'ssl' => array(
			'verify_peer' => true,
			'verify_peer_name' => true,
		),
	));

	$raw = @file_get_contents($url, false, $context);
	if ($raw === false || trim($raw) === '')
	{
		return null;
	}

	return ensure_utf8($raw);
}

function ensure_utf8(string $html): string
{
	if (function_exists('mb_check_encoding') && !mb_check_encoding($html, 'UTF-8'))
	{
		$converted = @mb_convert_encoding($html, 'UTF-8', 'GB18030,GBK,Big5,UTF-8');
		if (is_string($converted) && $converted !== '')
		{
			return $converted;
		}
	}

	return $html;
}

function extract_links(string $html, string $base_url): array
{
	$dom = html_dom($html);
	if (!$dom)
	{
		return array();
	}

	$xpath = new DOMXPath($dom);
	$links = array();
	foreach ($xpath->query('//a[@href]') as $node)
	{
		$href = $node instanceof DOMElement ? $node->getAttribute('href') : '';
		$url = absolute_url($href, $base_url);
		$url = normalize_url($url);
		if ($url !== '')
		{
			$links[$url] = true;
		}
	}

	return array_keys($links);
}

function parse_article_page(string $source_url, string $html): ?array
{
	$dom = html_dom($html);
	if (!$dom)
	{
		return null;
	}

	$xpath = new DOMXPath($dom);
	$title = first_text($xpath, array(
		'//main//h1',
		'//h1',
	)) ?: clean_title((string) meta_content($xpath, 'og:title')) ?: clean_title((string) first_text($xpath, array('//title')));

	$article_node = first_node($xpath, array(
		'//*[@id="articleContent"]',
		'//article[contains(concat(" ", normalize-space(@class), " "), " article ")]',
		'//article',
		'//main',
	));

	if ($article_node === null || $title === '')
	{
		return null;
	}

	$body_html = sanitized_inner_html($article_node, $source_url);
	$body_text = clean_text($body_html);
	if ($body_text === '' || utf8_strlen($body_text) < 80)
	{
		$description = (string) meta_content($xpath, 'description');
		$body_text = clean_text($description);
		if ($body_html === '' && $body_text !== '')
		{
			$body_html = '<p>' . htmlspecialchars($body_text, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</p>';
		}
	}

	if ($body_text === '' || utf8_strlen($body_text) < 80)
	{
		return null;
	}

	$author = first_text($xpath, array(
		'//*[@id="postmeta"]//*[@itemprop="author"]',
		'//*[@itemprop="author"]',
	));
	$meta_text = first_text($xpath, array('//*[@id="postmeta"]')) ?: '';
	if ($author === '' && preg_match('/文章\x{6765}\x{6e90}:\s*([^\s]+)\s+于/u', $meta_text, $matches))
	{
		$author = clean_text($matches[1]);
	}

	$publish_time = parse_publish_time($meta_text);
	if ($publish_time <= 0)
	{
		$publish_time = parse_publish_time((string) json_ld_value($html, 'datePublished'));
	}
	if ($author === '')
	{
		$author = clean_text((string) json_ld_value($html, 'author'));
	}

	$images = extract_article_images($body_html, $source_url);
	$og_image = absolute_url((string) meta_content($xpath, 'og:image'), $source_url);
	if ($og_image !== '' && !is_low_value_image($og_image))
	{
		array_unshift($images, $og_image);
	}
	$images = array_values(array_unique(array_filter($images)));

	$content_hash = hash('sha256', normalized_hash_text($body_text));
	$title_pub_hash = $publish_time > 0 ? hash('sha256', utf8_clean_string($title) . '|' . $publish_time) : '';

	return array(
		'source_url' => normalize_url($source_url),
		'source_url_hash' => hash('sha256', normalize_url($source_url)),
		'title' => shorten($title, 180),
		'body_html' => $body_html,
		'body_text' => $body_text,
		'category' => category_from_url($source_url, $xpath),
		'author' => shorten($author, 120),
		'publish_time' => $publish_time,
		'thumbnail_url' => $images[0] ?? '',
		'image_urls' => $images,
		'content_hash' => $content_hash,
		'title_pub_hash' => $title_pub_hash,
	);
}

function html_dom(string $html): ?DOMDocument
{
	if (!class_exists('DOMDocument'))
	{
		fail('PHP DOM extension is required for Wenxuecity parsing.');
	}

	$previous = libxml_use_internal_errors(true);
	$dom = new DOMDocument('1.0', 'UTF-8');
	$ok = @$dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET);
	libxml_clear_errors();
	libxml_use_internal_errors($previous);

	return $ok ? $dom : null;
}

function first_node(DOMXPath $xpath, array $queries): ?DOMNode
{
	foreach ($queries as $query)
	{
		$nodes = $xpath->query($query);
		if ($nodes && $nodes->length > 0)
		{
			return $nodes->item(0);
		}
	}

	return null;
}

function first_text(DOMXPath $xpath, array $queries): string
{
	$node = first_node($xpath, $queries);
	return $node ? clean_text($node->textContent ?? '') : '';
}

function meta_content(DOMXPath $xpath, string $name): string
{
	$name = strtolower($name);
	$queries = array(
		'//meta[translate(@property, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="' . $name . '"]/@content',
		'//meta[translate(@name, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="' . $name . '"]/@content',
	);

	foreach ($queries as $query)
	{
		$nodes = $xpath->query($query);
		if ($nodes && $nodes->length > 0)
		{
			return clean_text($nodes->item(0)->nodeValue ?? '');
		}
	}

	return '';
}

function clean_title(string $title): string
{
	$title = clean_text($title);
	$title = preg_replace('/\s*[|_-]\s*文学城\s*$/u', '', $title) ?: $title;
	return trim($title);
}

function sanitized_inner_html(DOMNode $node, string $base_url): string
{
	$dom = new DOMDocument('1.0', 'UTF-8');
	$container = $dom->createElement('div');
	$dom->appendChild($container);

	foreach ($node->childNodes as $child)
	{
		$container->appendChild($dom->importNode($child, true));
	}

	clean_dom_node($container, $base_url);

	$html = '';
	foreach ($container->childNodes as $child)
	{
		$html .= $dom->saveHTML($child);
	}

	return trim($html);
}

function clean_dom_node(DOMNode $node, string $base_url): void
{
	if ($node instanceof DOMElement)
	{
		$tag = strtolower($node->tagName);
		$class_id = strtolower($node->getAttribute('class') . ' ' . $node->getAttribute('id'));
		if (in_array($tag, array('script', 'style', 'iframe', 'form', 'input', 'button', 'svg', 'noscript'), true) ||
			preg_match('/(^|\s)(ad|ads|advert|freestar|comment|share|social|otherposts|related|infometa|postmeta)(\s|$|[-_])/i', $class_id))
		{
			$node->parentNode?->removeChild($node);
			return;
		}

		if ($tag === 'a' && $node->hasAttribute('href'))
		{
			$node->setAttribute('href', absolute_url($node->getAttribute('href'), $base_url));
		}

		if ($tag === 'img')
		{
			$src = image_src($node);
			if ($src === '' || is_low_value_image($src))
			{
				$node->parentNode?->removeChild($node);
				return;
			}
			$node->setAttribute('src', absolute_url($src, $base_url));
			$node->removeAttribute('srcset');
			$node->removeAttribute('data-src');
			$node->removeAttribute('data-original');
			$node->removeAttribute('style');
		}

		foreach (iterator_to_array($node->attributes ?? array()) as $attribute)
		{
			$attribute_name = strtolower($attribute->name);
			if (strpos($attribute_name, 'on') === 0 || in_array($attribute_name, array('style', 'class', 'id'), true))
			{
				$node->removeAttribute($attribute->name);
			}
		}
	}

	foreach (iterator_to_array($node->childNodes) as $child)
	{
		clean_dom_node($child, $base_url);
	}
}

function image_src(DOMElement $img): string
{
	foreach (array('data-src', 'data-original', 'src') as $attribute)
	{
		$value = trim($img->getAttribute($attribute));
		if ($value !== '')
		{
			return $value;
		}
	}

	return '';
}

function extract_article_images(string $body_html, string $base_url): array
{
	$dom = html_dom('<div id="wxc-body">' . $body_html . '</div>');
	if (!$dom)
	{
		return array();
	}

	$xpath = new DOMXPath($dom);
	$images = array();
	foreach ($xpath->query('//img') as $node)
	{
		if (!$node instanceof DOMElement)
		{
			continue;
		}
		$src = absolute_url(image_src($node), $base_url);
		if ($src !== '' && !is_low_value_image($src))
		{
			$images[$src] = true;
		}
	}

	return array_keys($images);
}

function is_low_value_image(string $url): bool
{
	$url_lc = strtolower($url);
	return strpos($url_lc, 'twdelogo') !== false ||
		strpos($url_lc, 'logo') !== false ||
		strpos($url_lc, 'adserver') !== false ||
		strpos($url_lc, 'pixel') !== false ||
		strpos($url_lc, 'postcomment') !== false ||
		strpos($url_lc, 'dealsaving') !== false ||
		strpos($url_lc, 'doubleclick') !== false;
}

function parse_publish_time(string $text): int
{
	$text = trim($text);
	if ($text === '')
	{
		return 0;
	}

	if (preg_match('/(\d{4}-\d{2}-\d{2}(?:[T\s]\d{2}:\d{2}(?::\d{2})?(?:[+-]\d{2}:?\d{2}|Z)?)?)/', $text, $matches))
	{
		$time = strtotime(str_replace('T', ' ', $matches[1]));
		return $time ?: 0;
	}

	$time = strtotime($text);
	return $time ?: 0;
}

function json_ld_value(string $html, string $key): string
{
	if (!preg_match_all('#<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $matches))
	{
		return '';
	}

	foreach ($matches[1] as $json)
	{
		$data = json_decode(html_entity_decode(trim($json), ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);
		if (!is_array($data))
		{
			continue;
		}

		$value = json_find_key($data, $key);
		if (is_string($value))
		{
			return clean_text($value);
		}
		if (is_array($value))
		{
			if (isset($value['name']) && is_string($value['name']))
			{
				return clean_text($value['name']);
			}
			if (isset($value[0]['name']) && is_string($value[0]['name']))
			{
				return clean_text($value[0]['name']);
			}
		}
	}

	return '';
}

function json_find_key(array $data, string $wanted_key)
{
	foreach ($data as $key => $value)
	{
		if ((string) $key === $wanted_key)
		{
			return $value;
		}
		if (is_array($value))
		{
			$found = json_find_key($value, $wanted_key);
			if ($found !== null)
			{
				return $found;
			}
		}
	}

	return null;
}

function category_from_url(string $url, DOMXPath $xpath): string
{
	$path = parse_url($url, PHP_URL_PATH) ?: '';
	if (preg_match('#/ent-\d+\.html$#i', $path))
	{
		return '娱乐新闻';
	}
	if (preg_match('#/socialnews-\d+\.html$#i', $path))
	{
		return '生活百态';
	}
	if (preg_match('#/photo-\d+\.html$#i', $path))
	{
		return '图片新闻';
	}
	if (preg_match('#/video-\d+\.html$#i', $path))
	{
		return '视频新闻';
	}

	return '焦点新闻';
}

function build_post_body(array $article): string
{
	$body = html_to_bbcode((string) ($article['body_html'] ?? ''));
	if ($body !== '')
	{
		return $body;
	}

	return clean_text((string) ($article['body_text'] ?? ''));
}

function cleanup_existing_metadata_posts(string $table_name, int $limit, bool $dry_run): int
{
	global $db;

	$sql = 'SELECT i.import_id, i.topic_id, i.post_id, i.body_html, i.body_text, i.title, p.post_text
		FROM ' . $table_name . ' i
		INNER JOIN ' . POSTS_TABLE . ' p
			ON p.post_id = i.post_id
		WHERE i.post_id > 0
		ORDER BY i.import_id DESC';
	$result = $db->sql_query_limit($sql, $limit);

	$rows = array();
	while ($row = $db->sql_fetchrow($result))
	{
		$rows[] = $row;
	}
	$db->sql_freeresult($result);

	$cleaned = 0;
	if (!$dry_run)
	{
		$db->sql_transaction('begin');
	}

	foreach ($rows as $row)
	{
		if (!has_public_metadata_block((string) $row['post_text']))
		{
			continue;
		}

		$message = build_post_body(array(
			'body_html' => (string) $row['body_html'],
			'body_text' => (string) $row['body_text'],
		));
		if ($message === '')
		{
			$message = remove_metadata_from_stored_post((string) $row['post_text']);
		}
		if ($message === '')
		{
			out('cleanup skipped: post_id=' . (int) $row['post_id'] . ' reason=empty_body');
			continue;
		}

		if ($dry_run)
		{
			out('[dry-run] would clean post_id=' . (int) $row['post_id'] . ' topic_id=' . (int) $row['topic_id'] . ' title=' . (string) $row['title']);
			$cleaned++;
			continue;
		}

		update_post_message((int) $row['post_id'], $message);
		$cleaned++;
		out('cleaned post_id=' . (int) $row['post_id'] . ' topic_id=' . (int) $row['topic_id'] . ' title=' . (string) $row['title']);
	}

	if (!$dry_run)
	{
		$db->sql_transaction('commit');
	}

	return $cleaned;
}

function has_public_metadata_block(string $post_text): bool
{
	$prefix = utf8_substr($post_text, 0, 2400);
	$has_label = strpos($prefix, '来源') !== false ||
		strpos($prefix, '分类') !== false ||
		strpos($prefix, '发布时间') !== false ||
		strpos($prefix, '原文链接') !== false;

	return $has_label && strpos($prefix, '------------------------------------') !== false;
}

function remove_metadata_from_stored_post(string $post_text): string
{
	$text = preg_replace('#<br\s*/?>#i', "\n", $post_text) ?: $post_text;
	$text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
	$text = preg_replace('/^\s*(?:\[b\])?(?:来源|分类|发布时间|原文链接)(?:\[\/b\])?[：:].*$/mu', '', $text) ?: $text;
	$text = preg_replace('/^\s*-{8,}\s*$/mu', '', $text) ?: $text;
	$text = preg_replace("/[ \t]+\n/u", "\n", $text) ?: $text;
	$text = preg_replace("/\n{3,}/u", "\n\n", $text) ?: $text;

	return trim($text);
}

function update_post_message(int $post_id, string $message): void
{
	global $db;

	$prepared = prepare_post_text($message);
	$sql_ary = array(
		'post_text' => $prepared['message'],
		'post_checksum' => md5((string) $prepared['message']),
		'bbcode_bitfield' => $prepared['bitfield'],
		'bbcode_uid' => $prepared['uid'],
		'enable_bbcode' => 1,
		'enable_smilies' => 1,
		'enable_magic_url' => 1,
	);

	$sql = 'UPDATE ' . POSTS_TABLE . '
		SET ' . $db->sql_build_array('UPDATE', $sql_ary) . '
		WHERE post_id = ' . (int) $post_id;
	$db->sql_query($sql);
}

function html_to_bbcode(string $html): string
{
	$dom = html_dom('<div id="wxc-body">' . $html . '</div>');
	if (!$dom)
	{
		return clean_text($html);
	}

	$xpath = new DOMXPath($dom);
	$root = $xpath->query('//*[@id="wxc-body"]')->item(0);
	if (!$root)
	{
		return clean_text($html);
	}

	$text = node_to_bbcode($root);
	$text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
	$text = preg_replace("/[ \t]+\n/u", "\n", $text) ?: $text;
	$text = preg_replace("/\n{3,}/u", "\n\n", $text) ?: $text;

	return trim($text);
}

function node_to_bbcode(DOMNode $node): string
{
	if ($node instanceof DOMText)
	{
		return preg_replace('/[ \t\x{00a0}]+/u', ' ', $node->nodeValue) ?: '';
	}

	if (!$node instanceof DOMElement && !$node instanceof DOMDocument)
	{
		return '';
	}

	$tag = $node instanceof DOMElement ? strtolower($node->tagName) : '';
	if (in_array($tag, array('script', 'style', 'iframe', 'noscript'), true))
	{
		return '';
	}

	if ($tag === 'br')
	{
		return "\n";
	}

	if ($tag === 'img' && $node instanceof DOMElement)
	{
		$src = trim($node->getAttribute('src'));
		return $src !== '' ? "\n[img]" . $src . "[/img]\n" : '';
	}

	$content = '';
	foreach ($node->childNodes as $child)
	{
		$content .= node_to_bbcode($child);
	}
	$content = trim($content);

	if ($content === '')
	{
		return '';
	}

	switch ($tag)
	{
		case 'strong':
		case 'b':
			return '[b]' . $content . '[/b]';

		case 'em':
		case 'i':
			return '[i]' . $content . '[/i]';

		case 'h1':
		case 'h2':
		case 'h3':
		case 'h4':
			return "\n[b]" . $content . "[/b]\n\n";

		case 'blockquote':
			return "\n[quote]" . $content . "[/quote]\n\n";

		case 'a':
			$href = $node instanceof DOMElement ? trim($node->getAttribute('href')) : '';
			if ($href !== '' && preg_match('#^https?://#i', $href))
			{
				return '[url=' . $href . ']' . $content . '[/url]';
			}
			return $content;

		case 'li':
			return "\n- " . $content;

		case 'p':
		case 'div':
		case 'section':
		case 'article':
		case 'ul':
		case 'ol':
			return "\n" . $content . "\n\n";
	}

	return $content;
}

function clean_text(string $text): string
{
	$text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
	$text = preg_replace('/\x{00a0}/u', ' ', $text) ?: $text;
	$text = preg_replace('/[ \t\r\n]+/u', ' ', $text) ?: '';
	return trim($text);
}

function normalized_hash_text(string $text): string
{
	$text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
	$text = preg_replace('/\s+/u', '', $text) ?: '';
	return utf8_clean_string($text);
}

function shorten(string $text, int $max_chars): string
{
	$text = trim($text);
	if ($text === '')
	{
		return '';
	}

	if (utf8_strlen($text) <= $max_chars)
	{
		return $text;
	}

	return utf8_substr($text, 0, max(1, $max_chars - 1)) . '…';
}

function absolute_url(string $href, string $base_url): string
{
	$href = trim(html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
	if ($href === '' || preg_match('#^(javascript|mailto|tel):#i', $href))
	{
		return '';
	}

	if (strpos($href, '//') === 0)
	{
		return 'https:' . $href;
	}

	if (preg_match('#^https?://#i', $href))
	{
		return $href;
	}

	$base = parse_url($base_url);
	if (!$base || empty($base['host']))
	{
		return '';
	}

	$scheme = $base['scheme'] ?? 'https';
	$host = $base['host'];
	$port = isset($base['port']) ? ':' . $base['port'] : '';

	if (strpos($href, '/') === 0)
	{
		return $scheme . '://' . $host . $port . $href;
	}

	$path = $base['path'] ?? '/';
	$dir = preg_replace('#/[^/]*$#', '/', $path) ?: '/';
	return normalize_path_url($scheme . '://' . $host . $port . $dir . $href);
}

function normalize_path_url(string $url): string
{
	$parts = parse_url($url);
	if (!$parts || empty($parts['host']))
	{
		return '';
	}

	$segments = explode('/', $parts['path'] ?? '/');
	$out = array();
	foreach ($segments as $segment)
	{
		if ($segment === '' || $segment === '.')
		{
			continue;
		}
		if ($segment === '..')
		{
			array_pop($out);
			continue;
		}
		$out[] = $segment;
	}

	$path = '/' . implode('/', $out);
	return ($parts['scheme'] ?? 'https') . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '') . $path . (isset($parts['query']) ? '?' . $parts['query'] : '');
}

function normalize_url(string $url): string
{
	$url = trim($url);
	if ($url === '')
	{
		return '';
	}

	$parts = parse_url($url);
	if (!$parts || empty($parts['host']))
	{
		return '';
	}

	$host = strtolower($parts['host']);
	if ($host === 'wenxuecity.com')
	{
		$host = 'www.wenxuecity.com';
	}

	$path = $parts['path'] ?? '/';
	$query = isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '';

	return 'https://' . $host . $path . $query;
}

function is_wenxuecity_article_url(string $url): bool
{
	$parts = parse_url($url);
	if (!$parts || empty($parts['host']) || strtolower($parts['host']) !== 'www.wenxuecity.com')
	{
		return false;
	}

	$path = $parts['path'] ?? '';
	return (bool) preg_match('#^/news/\d{4}/\d{2}/\d{2}/(?:[a-z]+-)?\d+\.html$#i', $path);
}

function is_wenxuecity_listing_url(string $url): bool
{
	$parts = parse_url($url);
	if (!$parts || empty($parts['host']) || strtolower($parts['host']) !== 'www.wenxuecity.com')
	{
		return false;
	}

	$path = rtrim($parts['path'] ?? '/', '/') . '/';
	return in_array($path, array(
		'/',
		'/news/',
		'/news/morenews/',
		'/news/photo/',
		'/news/video/',
		'/news/socialnews/',
		'/news/ent/',
	), true);
}

function duplicate_reason(string $table_name, array $article): ?string
{
	global $db;

	$checks = array(
		'source_url' => "source_url_hash = '" . $db->sql_escape($article['source_url_hash']) . "'",
		'content_hash' => "content_hash = '" . $db->sql_escape($article['content_hash']) . "'",
	);

	if ($article['title_pub_hash'] !== '')
	{
		$checks['title_publish_time'] = "title_pub_hash = '" . $db->sql_escape($article['title_pub_hash']) . "'";
	}

	foreach ($checks as $reason => $where)
	{
		$sql = 'SELECT import_id
			FROM ' . $table_name . '
			WHERE ' . $where;
		$result = $db->sql_query_limit($sql, 1);
		$row = $db->sql_fetchrow($result);
		$db->sql_freeresult($result);
		if ($row)
		{
			return $reason;
		}
	}

	return null;
}

function record_import(string $table_name, array $article, int $forum_id, int $topic_id, int $post_id): void
{
	global $db;

	$sql_ary = array(
		'source_site' => 'wenxuecity',
		'source_url_hash' => $article['source_url_hash'],
		'source_url' => $article['source_url'],
		'content_hash' => $article['content_hash'],
		'title_pub_hash' => $article['title_pub_hash'],
		'title' => $article['title'],
		'category' => $article['category'],
		'author' => $article['author'],
		'publish_time' => (int) $article['publish_time'],
		'thumbnail_url' => $article['thumbnail_url'],
		'image_urls' => json_encode($article['image_urls'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]',
		'body_html' => $article['body_html'],
		'body_text' => $article['body_text'],
		'forum_id' => $forum_id,
		'topic_id' => $topic_id,
		'post_id' => $post_id,
		'imported_at' => time(),
	);

	$sql = 'INSERT INTO ' . $table_name . ' ' . $db->sql_build_array('INSERT', $sql_ary);
	$db->sql_query($sql);
}

function group_id_by_name(string $name): ?int
{
	global $db;

	$sql = 'SELECT group_id
		FROM ' . GROUPS_TABLE . "
		WHERE group_name = '" . $db->sql_escape($name) . "'";
	$result = $db->sql_query_limit($sql, 1);
	$row = $db->sql_fetchrow($result);
	$db->sql_freeresult($result);

	return $row ? (int) $row['group_id'] : null;
}

function user_by_username(string $username): ?array
{
	global $db;

	$sql = 'SELECT user_id, username, user_email
		FROM ' . USERS_TABLE . "
		WHERE username_clean = '" . $db->sql_escape(utf8_clean_string($username)) . "'";
	$result = $db->sql_query_limit($sql, 1);
	$row = $db->sql_fetchrow($result);
	$db->sql_freeresult($result);

	return $row ? array(
		'user_id' => (int) $row['user_id'],
		'username' => (string) $row['username'],
		'user_email' => (string) $row['user_email'],
	) : null;
}

function ensure_import_user(): array
{
	global $config, $phpbb_container;

	$existing = user_by_username(WENXUECITY_IMPORT_USER);
	if ($existing)
	{
		return $existing;
	}

	$registered_group_id = group_id_by_name('REGISTERED');
	if (!$registered_group_id)
	{
		fail('Missing group: REGISTERED');
	}

	$password_manager = $phpbb_container->get('passwords.manager');
	$user_row = array(
		'username' => WENXUECITY_IMPORT_USER,
		'user_password' => $password_manager->hash(WENXUECITY_IMPORT_PASSWORD),
		'user_email' => WENXUECITY_IMPORT_EMAIL,
		'group_id' => $registered_group_id,
		'user_timezone' => (string) $config['board_timezone'],
		'user_lang' => (string) ($config['default_lang'] ?? 'zh_cmn_hans'),
		'user_type' => USER_NORMAL,
		'user_regdate' => time(),
	);

	$user_id = (int) user_add($user_row);
	if ($user_id <= 0)
	{
		fail('Unable to create importer user.');
	}

	$trusted_group_id = group_id_by_name('TRUSTED');
	if ($trusted_group_id)
	{
		group_user_add($trusted_group_id, array($user_id));
	}

	out('created importer user: ' . WENXUECITY_IMPORT_USER . ' (user_id=' . $user_id . ')');

	return array(
		'user_id' => $user_id,
		'username' => WENXUECITY_IMPORT_USER,
		'user_email' => WENXUECITY_IMPORT_EMAIL,
	);
}

function switch_actor(int $user_id): void
{
	global $user, $auth;

	$user->session_create($user_id, false, false);
	$user->ip = '127.0.0.1';
	$auth->acl($user->data);
}

function prepare_post_text(string $message): array
{
	$uid = '';
	$bitfield = '';
	$flags = OPTION_FLAG_BBCODE + OPTION_FLAG_LINKS + OPTION_FLAG_SMILIES;
	$compiled = $message;
	generate_text_for_storage($compiled, $uid, $bitfield, $flags, true, true, true);

	return array(
		'message' => $compiled,
		'uid' => $uid,
		'bitfield' => $bitfield,
		'flags' => $flags,
	);
}

function submit_topic_post(int $forum_id, string $forum_name, int $poster_id, string $subject, string $message, int $post_time): int
{
	$prepared = prepare_post_text($message);
	switch_actor($poster_id);

	$data = array(
		'topic_title' => $subject,
		'topic_first_post_id' => 0,
		'topic_last_post_id' => 0,
		'topic_time_limit' => 0,
		'topic_attachment' => 0,
		'post_id' => 0,
		'topic_id' => 0,
		'forum_id' => $forum_id,
		'icon_id' => 0,
		'poster_id' => $poster_id,
		'enable_sig' => true,
		'enable_bbcode' => true,
		'enable_smilies' => true,
		'enable_urls' => true,
		'enable_indexing' => true,
		'message_md5' => md5((string) $prepared['message']),
		'post_checksum' => '',
		'post_edit_reason' => '',
		'post_edit_user' => 0,
		'forum_parents' => '',
		'forum_name' => $forum_name,
		'notify' => false,
		'notify_set' => false,
		'poster_ip' => '127.0.0.1',
		'post_edit_locked' => 0,
		'bbcode_bitfield' => $prepared['bitfield'],
		'bbcode_uid' => $prepared['uid'],
		'message' => $prepared['message'],
		'attachment_data' => array(),
		'filename_data' => array(),
		'topic_status' => ITEM_UNLOCKED,
		'post_time' => $post_time,
		'force_approved_state' => ITEM_APPROVED,
		'topic_visibility' => ITEM_APPROVED,
		'post_visibility' => ITEM_APPROVED,
	);

	$poll = array();
	$redirect = submit_post('post', $subject, '', POST_NORMAL, $poll, $data, true, true);
	if (preg_match('/[?&]t=(\d+)/', (string) $redirect, $matches))
	{
		return (int) $matches[1];
	}

	return topic_id_by_forum_title($forum_id, $subject);
}

function topic_id_by_forum_title(int $forum_id, string $topic_title): int
{
	global $db;

	$sql = 'SELECT topic_id
		FROM ' . TOPICS_TABLE . '
		WHERE forum_id = ' . (int) $forum_id . "
			AND topic_title = '" . $db->sql_escape($topic_title) . "'
		ORDER BY topic_id DESC";
	$result = $db->sql_query_limit($sql, 1);
	$row = $db->sql_fetchrow($result);
	$db->sql_freeresult($result);

	return $row ? (int) $row['topic_id'] : 0;
}

function topic_row(int $topic_id): ?array
{
	global $db;

	$sql = 'SELECT topic_id, forum_id, topic_title, topic_first_post_id, topic_last_post_id
		FROM ' . TOPICS_TABLE . '
		WHERE topic_id = ' . (int) $topic_id;
	$result = $db->sql_query_limit($sql, 1);
	$row = $db->sql_fetchrow($result);
	$db->sql_freeresult($result);

	return $row ?: null;
}

function usable_post_time(int $publish_time): int
{
	$now = time();
	if ($publish_time > 0 && $publish_time <= $now + 3600)
	{
		return $publish_time;
	}

	return $now;
}
