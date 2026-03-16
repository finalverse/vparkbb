<?php
/**
 * One-time migration: populate forum_desc from the previously hardcoded
 * subtitles in the glue extension's forum_panel_items() array.
 *
 * Usage:
 *   docker compose exec php php /var/www/html/scripts/migrate_forum_descriptions.php
 *
 * Safe to run multiple times — only updates forums whose forum_desc is empty.
 */

define('IN_PHPBB', true);
$phpbb_root_path = dirname(__DIR__) . '/';
$phpEx = 'php';

require($phpbb_root_path . 'common.' . $phpEx);

$user->session_begin();
$auth->acl($user->data);

$subtitles = array(
	'百家论坛'              => '综合时政 / 社会 / 观点',
	'军事纵横'              => '军事 / 地缘 / 战争',
	'经济观察'              => '宏观 / 政策 / 经济评论',
	'谈股论金'              => '股市 / 交易 / 投资心态',
	'股票投资'              => '实操策略 / 个股复盘',
	'娱乐八卦'              => '明星热点 / 轻内容',
	'笑口常开'              => '段子 / 搞笑 / 轻松',
	'生活百态'              => '生活杂谈 / 海外见闻',
	'婚姻家庭'              => '两性 / 家庭 / 亲子',
	'文化长廊'              => '文化 / 阅读 / 随笔',
	'网际谈兵'              => '国际关系 / 军政延展',
	'史海钩沉'              => '历史 / 考据 / 旧闻',
	'自由文学'              => '原创 / 连载 / 文艺',
	'体坛纵横'              => '体育赛事 / 热点讨论',
	'电脑前线 / 数码家电'   => '技术工具 / 数码家电',
);

$updated = 0;
$skipped = 0;

foreach ($subtitles as $forum_name => $description)
{
	$sql = 'SELECT forum_id, forum_desc
		FROM ' . FORUMS_TABLE . '
		WHERE forum_name = \'' . $db->sql_escape($forum_name) . '\'';
	$result = $db->sql_query($sql);
	$row = $db->sql_fetchrow($result);
	$db->sql_freeresult($result);

	if (!$row)
	{
		echo "  SKIP: Forum '$forum_name' not found in database.\n";
		$skipped++;
		continue;
	}

	if (trim($row['forum_desc']) !== '')
	{
		echo "  SKIP: Forum '$forum_name' (id={$row['forum_id']}) already has a description.\n";
		$skipped++;
		continue;
	}

	$sql = 'UPDATE ' . FORUMS_TABLE . '
		SET forum_desc = \'' . $db->sql_escape($description) . '\'
		WHERE forum_id = ' . (int) $row['forum_id'];
	$db->sql_query($sql);
	$updated++;
	echo "  OK:   Forum '$forum_name' (id={$row['forum_id']}) → '$description'\n";
}

echo "\nDone. Updated: $updated, Skipped: $skipped\n";

// Purge the forum panel cache so changes show immediately
if (isset($phpbb_container))
{
	try
	{
		$cache = $phpbb_container->get('cache');
		$cache->destroy('_vpark_forum_panel');
		echo "Cache purged.\n";
	}
	catch (\Exception $e)
	{
		echo "Note: Could not purge cache automatically. Clear via ACP.\n";
	}
}
