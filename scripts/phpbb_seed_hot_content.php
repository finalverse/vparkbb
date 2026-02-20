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

$options = getopt('', array(
	'topics-per-forum:',
	'password:',
	'feed-limit:',
	'dry-run',
));

$topics_per_forum = max(1, (int) ($options['topics-per-forum'] ?? 8));
$feed_limit = max(12, (int) ($options['feed-limit'] ?? ($topics_per_forum * 5)));
$seed_password = (string) ($options['password'] ?? 'VictoriaPark!2026');
$dry_run = isset($options['dry-run']);

$forum_specs = array(
	array('name' => '百家论坛', 'slug' => 'baijia', 'query' => '时政 社会 观点 热点'),
	array('name' => '军事纵横', 'slug' => 'military', 'query' => '军事 地缘 冲突 战争'),
	array('name' => '经济观察', 'slug' => 'economy', 'query' => '宏观经济 政策 通胀 就业'),
	array('name' => '谈股论金', 'slug' => 'stocktalk', 'query' => '股市 交易 投资 心态'),
	array('name' => '股票投资', 'slug' => 'stocklab', 'query' => 'A股 港股 美股 个股 研报'),
	array('name' => '娱乐八卦', 'slug' => 'ent', 'query' => '娱乐 明星 八卦 热搜'),
	array('name' => '笑口常开', 'slug' => 'funny', 'query' => '搞笑 段子 趣闻'),
	array('name' => '生活百态', 'slug' => 'life', 'query' => '生活 海外 经验 热门'),
	array('name' => '婚姻家庭', 'slug' => 'family', 'query' => '婚姻 家庭 亲子 情感'),
	array('name' => '文化长廊', 'slug' => 'culture', 'query' => '文化 阅读 随笔 书评'),
	array('name' => '网际谈兵', 'slug' => 'geopolitics', 'query' => '国际关系 地缘政治 军事评论'),
	array('name' => '史海钩沉', 'slug' => 'history', 'query' => '历史 考据 文史 热点'),
	array('name' => '自由文学', 'slug' => 'literature', 'query' => '原创 文学 小说 诗歌'),
	array('name' => '体坛纵横', 'slug' => 'sports', 'query' => '体育 赛事 足球 篮球 热点'),
	array('name' => '电脑前线 / 数码家电', 'slug' => 'tech', 'query' => '科技 数码 AI 手机 电脑'),
);

function out(string $message): void
{
	echo '[hot-seed] ' . $message . PHP_EOL;
}

function fail(string $message): void
{
	fwrite(STDERR, '[hot-seed] ERROR: ' . $message . PHP_EOL);
	exit(1);
}

function clean_text(string $text): string
{
	$text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
	$text = preg_replace('/\s+/u', ' ', $text) ?: '';
	return trim($text);
}

function shorten(string $text, int $max_chars): string
{
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

function is_low_quality_title(string $title): bool
{
	$deny = array(
		'开奖',
		'彩票',
		'博彩',
		'娱乐城',
		'投注',
		'官方网站',
		'官网',
		'注册',
		'app下载',
		'APP下载',
		'备用网址',
		'客服',
		'手机网',
	);

	foreach ($deny as $term)
	{
		if (strpos($title, $term) !== false)
		{
			return true;
		}
	}

	return false;
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

function forum_rows_by_name(array $forum_names): array
{
	global $db;

	if (empty($forum_names))
	{
		return array();
	}

	$sql = 'SELECT forum_id, forum_name
		FROM ' . FORUMS_TABLE . '
		WHERE forum_type = ' . FORUM_POST . '
			AND ' . $db->sql_in_set('forum_name', $forum_names);
	$result = $db->sql_query($sql);

	$rows = array();
	while ($row = $db->sql_fetchrow($result))
	{
		$rows[(string) $row['forum_name']] = array(
			'forum_id' => (int) $row['forum_id'],
			'forum_name' => (string) $row['forum_name'],
		);
	}
	$db->sql_freeresult($result);

	return $rows;
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

	if (!$row)
	{
		return null;
	}

	return array(
		'user_id' => (int) $row['user_id'],
		'username' => (string) $row['username'],
		'user_email' => (string) $row['user_email'],
	);
}

function ensure_user(string $username, string $email, string $password, int $group_id): array
{
	global $config, $phpbb_container;

	$existing = user_by_username($username);
	if ($existing)
	{
		return $existing;
	}

	$password_manager = $phpbb_container->get('passwords.manager');
	$user_row = array(
		'username' => $username,
		'user_password' => $password_manager->hash($password),
		'user_email' => $email,
		'group_id' => $group_id,
		'user_timezone' => (string) $config['board_timezone'],
		'user_lang' => 'zh_cmn_hans',
		'user_type' => USER_NORMAL,
		'user_regdate' => time(),
	);

	$user_id = (int) user_add($user_row);
	if ($user_id <= 0)
	{
		fail('Unable to create user: ' . $username);
	}

	return array(
		'user_id' => $user_id,
		'username' => $username,
		'user_email' => $email,
	);
}

function ensure_user_in_group(int $group_id, array $user_ids): void
{
	if ($group_id <= 0 || empty($user_ids))
	{
		return;
	}

	$result = group_user_add($group_id, $user_ids);
	if ($result && $result !== 'GROUP_USERS_EXIST')
	{
		out('group_user_add returned: ' . (string) $result);
	}
}

function fetch_google_news_items(string $query, int $limit): array
{
	$full_query = trim($query . ' when:7d -彩票 -博彩 -娱乐城 -投注 -官网');
	$url = 'https://news.google.com/rss/search?q=' . rawurlencode($full_query) . '&hl=zh-CN&gl=CN&ceid=CN:zh-Hans';

	$context = stream_context_create(array(
		'http' => array(
			'timeout' => 12,
			'header' => "User-Agent: VictoriaParkSeedBot/1.0\r\n",
		),
	));

	$raw = @file_get_contents($url, false, $context);
	if ($raw === false || trim($raw) === '')
	{
		return array();
	}

	libxml_use_internal_errors(true);
	$xml = @simplexml_load_string($raw, \SimpleXMLElement::class, LIBXML_NOCDATA);
	libxml_clear_errors();

	if (!$xml || !isset($xml->channel) || !isset($xml->channel->item))
	{
		return array();
	}

	$items = array();
	$seen = array();
	foreach ($xml->channel->item as $item)
	{
		$title = clean_text((string) ($item->title ?? ''));
		$link = clean_text((string) ($item->link ?? ''));
		$desc = clean_text((string) ($item->description ?? ''));
		$source = clean_text((string) ($item->source ?? 'Google News'));
		$published_at = strtotime((string) ($item->pubDate ?? '')) ?: time();

		if ($title === '')
		{
			continue;
		}

		if (is_low_quality_title($title))
		{
			continue;
		}

		$key = utf8_clean_string($title);
		if (isset($seen[$key]))
		{
			continue;
		}
		$seen[$key] = true;

		$items[] = array(
			'title' => shorten($title, 108),
			'link' => $link,
			'summary' => shorten($desc, 220),
			'source' => $source !== '' ? $source : 'Google News',
			'published_at' => $published_at,
		);

		if (count($items) >= $limit)
		{
			break;
		}
	}

	return $items;
}

function fallback_items(string $forum_name, int $limit): array
{
	$items = array();
	for ($i = 1; $i <= $limit; $i++)
	{
		$items[] = array(
			'title' => shorten($forum_name . ' 热点讨论 #' . $i, 108),
			'link' => 'https://news.google.com/',
			'summary' => '网络源暂不可用，使用本地占位内容。欢迎围绕该话题补充观点与资料。',
			'source' => 'VictoriaPark Seed',
			'published_at' => time() - ($i * 600),
		);
	}
	return $items;
}

function topic_exists(int $forum_id, string $topic_title): bool
{
	global $db;

	$sql = 'SELECT topic_id
		FROM ' . TOPICS_TABLE . '
		WHERE forum_id = ' . (int) $forum_id . "
			AND topic_title = '" . $db->sql_escape($topic_title) . "'";
	$result = $db->sql_query_limit($sql, 1);
	$row = $db->sql_fetchrow($result);
	$db->sql_freeresult($result);

	return (bool) $row;
}

function unique_topic_title(int $forum_id, string $base_title): string
{
	$base_title = shorten($base_title, 108);
	if ($base_title === '')
	{
		return '';
	}

	if (!topic_exists($forum_id, $base_title))
	{
		return $base_title;
	}

	$suffix_stamp = gmdate('mdHi');
	for ($i = 1; $i <= 6; $i++)
	{
		$candidate = shorten($base_title . ' [热榜' . $suffix_stamp . '-' . $i . ']', 108);
		if (!topic_exists($forum_id, $candidate))
		{
			return $candidate;
		}
	}

	return '';
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

	$sql = 'SELECT t.topic_id, t.forum_id, t.topic_title, t.topic_first_post_id, t.topic_last_post_id,
			t.topic_posts_approved, t.topic_posts_unapproved, t.topic_posts_softdeleted, t.topic_attachment,
			f.forum_name
		FROM ' . TOPICS_TABLE . ' t
		JOIN ' . FORUMS_TABLE . ' f
			ON f.forum_id = t.forum_id
		WHERE t.topic_id = ' . (int) $topic_id;
	$result = $db->sql_query_limit($sql, 1);
	$row = $db->sql_fetchrow($result);
	$db->sql_freeresult($result);

	return $row ?: null;
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

function submit_topic_reply(int $topic_id, int $poster_id, string $message, int $post_time): bool
{
	$row = topic_row($topic_id);
	if (!$row)
	{
		return false;
	}

	$prepared = prepare_post_text($message);
	switch_actor($poster_id);

	$data = array(
		'topic_title' => (string) $row['topic_title'],
		'topic_first_post_id' => (int) $row['topic_first_post_id'],
		'topic_last_post_id' => (int) $row['topic_last_post_id'],
		'topic_time_limit' => 0,
		'topic_attachment' => (int) $row['topic_attachment'],
		'post_id' => 0,
		'topic_id' => (int) $row['topic_id'],
		'forum_id' => (int) $row['forum_id'],
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
		'forum_name' => (string) $row['forum_name'],
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
		'topic_posts_approved' => (int) $row['topic_posts_approved'],
		'topic_posts_unapproved' => (int) $row['topic_posts_unapproved'],
		'topic_posts_softdeleted' => (int) $row['topic_posts_softdeleted'],
		'force_approved_state' => ITEM_APPROVED,
		'topic_visibility' => ITEM_APPROVED,
		'post_visibility' => ITEM_APPROVED,
	);

	$subject = 'Re: ' . shorten((string) $row['topic_title'], 90);
	$poll = array();
	submit_post('reply', $subject, '', POST_NORMAL, $poll, $data, true, true);
	return true;
}

function build_topic_body(array $item, string $forum_name): string
{
	$summary = clean_text((string) ($item['summary'] ?? ''));
	if ($summary === '')
	{
		$summary = '该新闻由实时源抓取，欢迎结合版面主题补充分析。';
	}

	$link = clean_text((string) ($item['link'] ?? ''));
	$source = clean_text((string) ($item['source'] ?? 'Google News'));
	$time_label = gmdate('Y-m-d H:i', (int) ($item['published_at'] ?? time())) . ' UTC';

	$lines = array(
		'[b]热点速览[/b]',
		shorten($summary, 260),
		'',
		'[b]话题归属版面[/b]：' . $forum_name,
		'[b]来源[/b]：' . ($source !== '' ? $source : 'Google News'),
		'[b]发布时间[/b]：' . $time_label,
	);

	if ($link !== '')
	{
		$lines[] = '[b]原文链接[/b]：[url=' . $link . ']' . $link . '[/url]';
	}

	$lines[] = '';
	$lines[] = '欢迎围绕本帖继续补充观点、事实核验与延伸资料。';

	return implode("\n", $lines);
}

function build_reply_body(array $item): string
{
	$source = clean_text((string) ($item['source'] ?? 'Google News'));
	$link = clean_text((string) ($item['link'] ?? ''));

	$reply = '补充观点：这个话题值得持续跟踪，欢迎大家给出更多数据或一线观察。' . "\n";
	$reply .= '来源参考：' . ($source !== '' ? $source : 'Google News') . "\n";
	if ($link !== '')
	{
		$reply .= '扩展阅读：[url=' . $link . ']' . $link . '[/url]';
	}
	return $reply;
}

$registered_group_id = group_id_by_name('REGISTERED');
if (!$registered_group_id)
{
	fail('Missing group: REGISTERED');
}

$trusted_group_id = group_id_by_name('TRUSTED') ?: 0;
$forum_names = array_map(static fn(array $spec): string => $spec['name'], $forum_specs);
$forum_rows = forum_rows_by_name($forum_names);

if (count($forum_rows) === 0)
{
	fail('No target forums found. Run scripts/configure_forums.sh first.');
}

$all_forum_ids = array();
$created_topics = 0;
$created_replies = 0;
$created_users = 0;
$news_items_seen = 0;
$seed_start_time = time();

out('start: topics-per-forum=' . $topics_per_forum . ', feed-limit=' . $feed_limit . ($dry_run ? ', dry-run=1' : ''));

foreach ($forum_specs as $index => $spec)
{
	$forum_name = (string) $spec['name'];
	if (!isset($forum_rows[$forum_name]))
	{
		out('skip missing forum: ' . $forum_name);
		continue;
	}

	$forum_id = (int) $forum_rows[$forum_name]['forum_id'];
	$all_forum_ids[] = $forum_id;

	$user_a_name = 'ai_' . $spec['slug'] . '_a';
	$user_b_name = 'ai_' . $spec['slug'] . '_b';
	$user_a = ensure_user($user_a_name, $user_a_name . '@victoriapark.local', $seed_password, $registered_group_id);
	$user_b = ensure_user($user_b_name, $user_b_name . '@victoriapark.local', $seed_password, $registered_group_id);
	ensure_user_in_group($trusted_group_id, array((int) $user_a['user_id'], (int) $user_b['user_id']));

	if ((int) $user_a['user_id'] > 0 && (int) $user_b['user_id'] > 0)
	{
		$created_users += 2;
	}

	$feed_items = fetch_google_news_items((string) $spec['query'], $feed_limit);
	if (empty($feed_items))
	{
		out('feed fallback for forum: ' . $forum_name);
		$feed_items = fallback_items($forum_name, $feed_limit);
	}
	elseif (count($feed_items) < $topics_per_forum)
	{
		$feed_items = array_merge($feed_items, fallback_items($forum_name, $feed_limit));
	}

	$news_items_seen += count($feed_items);
	$forum_created = 0;

	foreach ($feed_items as $feed_index => $item)
	{
		if ($forum_created >= $topics_per_forum)
		{
			break;
		}

		$subject = clean_text((string) ($item['title'] ?? ''));
		$subject = unique_topic_title($forum_id, $subject);
		if ($subject === '')
		{
			continue;
		}

		$post_time = $seed_start_time - (($index * $topics_per_forum + $forum_created) * 90);
		$primary_user = ($forum_created % 2 === 0) ? $user_a : $user_b;
		$secondary_user = ($forum_created % 2 === 0) ? $user_b : $user_a;
		$topic_body = build_topic_body($item, $forum_name);

		if ($dry_run)
		{
			out('[dry-run] create topic f=' . $forum_id . ' by=' . $primary_user['username'] . ' title=' . $subject);
			$forum_created++;
			$created_topics++;
			$created_replies++;
			continue;
		}

		$topic_id = submit_topic_post($forum_id, $forum_name, (int) $primary_user['user_id'], $subject, $topic_body, $post_time);
		if ($topic_id <= 0)
		{
			out('failed topic create: ' . $subject);
			continue;
		}

		$reply_body = build_reply_body($item);
		$reply_ok = submit_topic_reply($topic_id, (int) $secondary_user['user_id'], $reply_body, $post_time + 30);
		$forum_created++;
		$created_topics++;
		if ($reply_ok)
		{
			$created_replies++;
		}
	}

	out('forum=' . $forum_name . ' created_topics=' . $forum_created);
}

if (!$dry_run && !empty($all_forum_ids))
{
	$all_forum_ids = array_values(array_unique($all_forum_ids));
	sync('forum', 'forum_id', $all_forum_ids, true, true);
	set_config('newest_user_colour', '');
}

out('done: topics=' . $created_topics . ', replies=' . $created_replies . ', users_touched=' . $created_users . ', feed_items_seen=' . $news_items_seen);
