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

require $phpbb_root_path . 'common.' . $phpEx;
require $phpbb_root_path . 'includes/functions_admin.' . $phpEx;
require $phpbb_root_path . 'includes/functions_acp.' . $phpEx;
require $phpbb_root_path . 'includes/functions_user.' . $phpEx;
require $phpbb_root_path . 'includes/acp/acp_forums.' . $phpEx;

$user->session_begin();
$auth->acl($user->data);
$user->setup(array('common', 'acp/forums', 'acp/groups'));

$forum_admin = new \acp_forums();
$forum_admin->u_action = '';

function fail(string $message): void
{
	fwrite(STDERR, "[forums] ERROR: {$message}\n");
	exit(1);
}

function forum_row_by_name(string $name, ?int $parent_id = null): ?array
{
	global $db;

	$sql = 'SELECT forum_id, parent_id, forum_name, forum_type
		FROM ' . FORUMS_TABLE . "
		WHERE forum_name = '" . $db->sql_escape($name) . "'";
	if ($parent_id !== null)
	{
		$sql .= ' AND parent_id = ' . (int) $parent_id;
	}
	$sql .= ' ORDER BY forum_id ASC';
	$result = $db->sql_query_limit($sql, 1);
	$row = $db->sql_fetchrow($result);
	$db->sql_freeresult($result);

	return $row ?: null;
}

function update_forum_basics(int $forum_id, int $forum_type, string $forum_name, string $forum_desc): void
{
	global $db;

	$sql = 'UPDATE ' . FORUMS_TABLE . "
		SET forum_type = " . (int) $forum_type . ",
			forum_name = '" . $db->sql_escape($forum_name) . "',
			forum_desc = '" . $db->sql_escape($forum_desc) . "',
			forum_desc_uid = '',
			forum_desc_bitfield = '',
			forum_desc_options = 7,
			display_on_index = 1,
			enable_indexing = 1,
			enable_icons = 1
		WHERE forum_id = " . (int) $forum_id;
	$db->sql_query($sql);
}

function ensure_forum(\acp_forums $forum_admin, int $parent_id, int $forum_type, string $forum_name, string $forum_desc, array $aliases = array()): int
{
	$row = forum_row_by_name($forum_name, $parent_id);
	if ($row)
	{
		update_forum_basics((int) $row['forum_id'], $forum_type, $forum_name, $forum_desc);
		return (int) $row['forum_id'];
	}

	foreach ($aliases as $alias)
	{
		$alias_row = forum_row_by_name($alias, $parent_id);
		if ($alias_row)
		{
			update_forum_basics((int) $alias_row['forum_id'], $forum_type, $forum_name, $forum_desc);
			return (int) $alias_row['forum_id'];
		}
	}

	$forum_data = array(
		'parent_id'					=> $parent_id,
		'forum_type'				=> $forum_type,
		'type_action'				=> '',
		'forum_status'				=> ITEM_UNLOCKED,
		'forum_parents'				=> '',
		'forum_name'				=> $forum_name,
		'forum_link'				=> '',
		'forum_link_track'			=> false,
		'forum_desc'				=> $forum_desc,
		'forum_desc_uid'			=> '',
		'forum_desc_options'		=> 7,
		'forum_desc_bitfield'		=> '',
		'forum_rules'				=> '',
		'forum_rules_uid'			=> '',
		'forum_rules_options'		=> 7,
		'forum_rules_bitfield'		=> '',
		'forum_rules_link'			=> '',
		'forum_image'				=> '',
		'forum_style'				=> 0,
		'display_subforum_list'		=> true,
		'display_subforum_limit'	=> false,
		'display_on_index'			=> true,
		'forum_topics_per_page'		=> 0,
		'enable_indexing'			=> true,
		'enable_icons'				=> true,
		'enable_prune'				=> false,
		'enable_post_review'		=> true,
		'enable_quick_reply'		=> true,
		'enable_shadow_prune'		=> false,
		'prune_days'				=> 7,
		'prune_viewed'				=> 7,
		'prune_freq'				=> 1,
		'prune_old_polls'			=> false,
		'prune_announce'			=> false,
		'prune_sticky'				=> false,
		'prune_shadow_days'			=> 7,
		'prune_shadow_freq'			=> 1,
		'forum_password'			=> '',
		'forum_password_confirm'	=> '',
		'forum_password_unset'		=> true,
		'show_active'				=> ($forum_type === FORUM_POST),
		'forum_options'				=> 0,
	);

	$errors = $forum_admin->update_forum_data($forum_data);
	if (!empty($errors))
	{
		fail('Unable to create forum "' . $forum_name . '": ' . implode('; ', $errors));
	}

	return (int) $forum_data['forum_id'];
}

function group_id_by_name(string $group_name): ?int
{
	global $db;

	$sql = 'SELECT group_id
		FROM ' . GROUPS_TABLE . "
		WHERE group_name = '" . $db->sql_escape($group_name) . "'";
	$result = $db->sql_query_limit($sql, 1);
	$row = $db->sql_fetchrow($result);
	$db->sql_freeresult($result);

	return $row ? (int) $row['group_id'] : null;
}

function ensure_group(string $group_name, string $group_desc): int
{
	$group_id = group_id_by_name($group_name);
	if ($group_id !== null)
	{
		return $group_id;
	}

	$group_attributes = array(
		'group_colour'			=> '',
		'group_rank'			=> 0,
		'group_avatar'			=> '',
		'group_avatar_type'		=> 0,
		'group_avatar_width'	=> 0,
		'group_avatar_height'	=> 0,
		'group_legend'			=> 0,
		'group_receive_pm'		=> 0,
		'group_message_limit'	=> 0,
		'group_max_recipients'	=> 5,
	);

	$new_group_id = 0;
	$errors = group_create($new_group_id, GROUP_CLOSED, $group_name, $group_desc, $group_attributes, false, false, false);
	if (!empty($errors))
	{
		fail('Unable to create group "' . $group_name . '": ' . implode('; ', $errors));
	}

	return (int) $new_group_id;
}

function role_id(string $role_name): int
{
	global $db;

	$sql = 'SELECT role_id
		FROM ' . ACL_ROLES_TABLE . "
		WHERE role_name = '" . $db->sql_escape($role_name) . "'
		ORDER BY role_id ASC";
	$result = $db->sql_query_limit($sql, 1);
	$row = $db->sql_fetchrow($result);
	$db->sql_freeresult($result);

	if (!$row)
	{
		fail('Missing ACL role: ' . $role_name);
	}

	return (int) $row['role_id'];
}

function auth_option_id(string $auth_option): int
{
	global $db;

	$sql = 'SELECT auth_option_id
		FROM ' . ACL_OPTIONS_TABLE . "
		WHERE auth_option = '" . $db->sql_escape($auth_option) . "'";
	$result = $db->sql_query_limit($sql, 1);
	$row = $db->sql_fetchrow($result);
	$db->sql_freeresult($result);

	if (!$row)
	{
		fail('Missing ACL option: ' . $auth_option);
	}

	return (int) $row['auth_option_id'];
}

function ensure_trusted_forum_role(int $role_forum_standard, int $opt_attach, int $opt_ignoreflood, int $opt_noapprove): int
{
	global $db;

	$role_name = 'ROLE_FORUM_TRUSTED';
	$sql = 'SELECT role_id
		FROM ' . ACL_ROLES_TABLE . "
		WHERE role_name = '" . $db->sql_escape($role_name) . "'
			AND role_type = 'f_'
		ORDER BY role_id ASC";
	$result = $db->sql_query_limit($sql, 1);
	$row = $db->sql_fetchrow($result);
	$db->sql_freeresult($result);

	if ($row)
	{
		$role_id = (int) $row['role_id'];
	}
	else
	{
		$sql = 'SELECT COALESCE(MAX(role_order), 0) + 1 AS next_role_order
			FROM ' . ACL_ROLES_TABLE . "
			WHERE role_type = 'f_'";
		$result = $db->sql_query($sql);
		$order_row = $db->sql_fetchrow($result);
		$db->sql_freeresult($result);
		$next_role_order = (int) $order_row['next_role_order'];

		$sql_ary = array(
			'role_name'			=> $role_name,
			'role_description'	=> 'Trusted members: standard forum access with flood bypass and auto-approval.',
			'role_type'			=> 'f_',
			'role_order'		=> $next_role_order,
		);
		$sql = 'INSERT INTO ' . ACL_ROLES_TABLE . ' ' . $db->sql_build_array('INSERT', $sql_ary);
		$db->sql_query($sql);
		$role_id = (int) $db->sql_nextid();
	}

	$sql = 'DELETE FROM ' . ACL_ROLES_DATA_TABLE . '
		WHERE role_id = ' . $role_id;
	$db->sql_query($sql);

	$sql = 'SELECT auth_option_id, auth_setting
		FROM ' . ACL_ROLES_DATA_TABLE . '
		WHERE role_id = ' . $role_forum_standard;
	$result = $db->sql_query($sql);
	$rows = array();
	while ($row = $db->sql_fetchrow($result))
	{
		$rows[] = array(
			'role_id'			=> $role_id,
			'auth_option_id'	=> (int) $row['auth_option_id'],
			'auth_setting'		=> (int) $row['auth_setting'],
		);
	}
	$db->sql_freeresult($result);

	if (empty($rows))
	{
		fail('Unable to clone ROLE_FORUM_STANDARD into ROLE_FORUM_TRUSTED.');
	}

	$db->sql_multi_insert(ACL_ROLES_DATA_TABLE, $rows);

	$override_rows = array(
		array('role_id' => $role_id, 'auth_option_id' => $opt_attach, 'auth_setting' => 1),
		array('role_id' => $role_id, 'auth_option_id' => $opt_ignoreflood, 'auth_setting' => 1),
		array('role_id' => $role_id, 'auth_option_id' => $opt_noapprove, 'auth_setting' => 1),
	);

	foreach ($override_rows as $override_row)
	{
		$sql = 'DELETE FROM ' . ACL_ROLES_DATA_TABLE . '
			WHERE role_id = ' . (int) $override_row['role_id'] . '
				AND auth_option_id = ' . (int) $override_row['auth_option_id'];
		$db->sql_query($sql);
	}
	$db->sql_multi_insert(ACL_ROLES_DATA_TABLE, $override_rows);

	return $role_id;
}

function apply_forum_acl(array $managed_forum_ids, array $group_ids, array $role_ids, array $category_guest_readable, array $board_guest_readable): void
{
	global $db;

	$forum_id_sql = $db->sql_in_set('forum_id', array_map('intval', $managed_forum_ids));
	$group_id_sql = $db->sql_in_set('group_id', array_map('intval', array_values($group_ids)));
	$sql = 'DELETE FROM ' . ACL_GROUPS_TABLE . '
		WHERE ' . $forum_id_sql . '
			AND ' . $group_id_sql;
	$db->sql_query($sql);

	$rows = array();

	$add_role_row = static function (array &$rows_ref, int $group_id, int $forum_id, int $role_id): void
	{
		$rows_ref[] = array(
			'group_id'			=> $group_id,
			'forum_id'			=> $forum_id,
			'auth_option_id'	=> 0,
			'auth_role_id'		=> $role_id,
			'auth_setting'		=> 0,
		);
	};

	foreach ($category_guest_readable as $forum_id => $guest_readable)
	{
		$add_role_row($rows, $group_ids['guests'], (int) $forum_id, $guest_readable ? $role_ids['forum_readonly'] : $role_ids['forum_noaccess']);
		$add_role_row($rows, $group_ids['registered'], (int) $forum_id, $role_ids['forum_readonly']);
		$add_role_row($rows, $group_ids['newly_registered'], (int) $forum_id, $role_ids['forum_readonly']);
		$add_role_row($rows, $group_ids['trusted'], (int) $forum_id, $role_ids['forum_readonly']);
		$add_role_row($rows, $group_ids['board_moderators'], (int) $forum_id, $role_ids['forum_full']);
		$add_role_row($rows, $group_ids['board_moderators'], (int) $forum_id, $role_ids['mod_standard']);
		$add_role_row($rows, $group_ids['administrators'], (int) $forum_id, $role_ids['forum_full']);
		$add_role_row($rows, $group_ids['administrators'], (int) $forum_id, $role_ids['mod_full']);
	}

	foreach ($board_guest_readable as $forum_id => $guest_readable)
	{
		$add_role_row($rows, $group_ids['guests'], (int) $forum_id, $guest_readable ? $role_ids['forum_readonly'] : $role_ids['forum_noaccess']);
		$add_role_row($rows, $group_ids['registered'], (int) $forum_id, $role_ids['forum_standard']);
		$add_role_row($rows, $group_ids['newly_registered'], (int) $forum_id, $role_ids['forum_new_member']);
		$add_role_row($rows, $group_ids['trusted'], (int) $forum_id, $role_ids['forum_trusted']);
		$add_role_row($rows, $group_ids['board_moderators'], (int) $forum_id, $role_ids['forum_full']);
		$add_role_row($rows, $group_ids['board_moderators'], (int) $forum_id, $role_ids['mod_standard']);
		$add_role_row($rows, $group_ids['administrators'], (int) $forum_id, $role_ids['forum_full']);
		$add_role_row($rows, $group_ids['administrators'], (int) $forum_id, $role_ids['mod_full']);
	}

	if (!empty($rows))
	{
		$db->sql_multi_insert(ACL_GROUPS_TABLE, $rows);
	}
}

function apply_security_config(): void
{
	$settings = array(
		// Registration flow
		'require_activation'		=> '2',
		'enable_confirm'			=> '1',
		'captcha_plugin'			=> 'core.captcha.plugins.gd',

		// Post editing and anti-flood
		'edit_time'					=> '30',
		'delete_time'				=> '30',
		'flood_interval'			=> '30',
		'search_interval'			=> '10',

		// New member moderation
		'new_member_group_default'	=> '1',
		'new_member_post_limit'		=> '5',

		// Login/register anti-spam knobs
		'max_reg_attempts'			=> '3',
		'ip_login_limit_max'		=> '10',
		'ip_login_limit_time'		=> '3600',
		'check_dnsbl'				=> '1',
	);

	foreach ($settings as $key => $value)
	{
		set_config($key, $value);
	}
}

$forum_plan = array(
	array(
		'name'				=> '生活与移民 | Life Abroad & Immigration',
		'description'		=> '海外生活经验、教育工作与家庭互助交流。',
		'aliases'			=> array('Your first category'),
		'guest_readable'	=> false,
		'boards'			=> array(
			array(
				'name'				=> '海外生活 | Life Abroad',
				'description'		=> '衣食住行、亲子教育、职场与本地生活见闻。',
				'aliases'			=> array('Your first forum'),
				'guest_readable'	=> false,
			),
			array(
				'name'				=> '移民签证 | Immigration',
				'description'		=> '签证路径、身份转换、政策解读与申请经验分享。',
				'guest_readable'	=> false,
			),
		),
	),
	array(
		'name'				=> '时政与观点 | Current Affairs',
		'description'		=> '全球热点、国际关系与公共议题讨论。',
		'guest_readable'	=> true,
		'boards'			=> array(
			array(
				'name'				=> '时事政经 | Current Affairs',
				'description'		=> '欢迎理性讨论时政新闻、社会议题与政策影响。',
				'guest_readable'	=> true,
			),
		),
	),
	array(
		'name'				=> '科技与投资 | Tech & Investment',
		'description'		=> '技术趋势、数字产品、投资策略与风险管理。',
		'guest_readable'	=> true,
		'boards'			=> array(
			array(
				'name'				=> '科技数码 | Tech',
				'description'		=> 'AI、软件、硬件、开发工具与前沿科技讨论。',
				'guest_readable'	=> true,
			),
			array(
				'name'				=> '投资理财 | Investment',
				'description'		=> '股票、基金、宏观市场与资产配置交流。',
				'guest_readable'	=> true,
			),
		),
	),
	array(
		'name'				=> '文化文娱与社区 | Culture & Community',
		'description'		=> '文化传承、娱乐八卦与社区交易信息。',
		'guest_readable'	=> true,
		'boards'			=> array(
			array(
				'name'				=> '文化历史 | Culture',
				'description'		=> '中文文化、历史、文学影视与跨文化交流。',
				'guest_readable'	=> true,
			),
			array(
				'name'				=> '八卦娱乐 | Gossip / Entertainment',
				'description'		=> '明星动态、综艺影视与轻松话题讨论。',
				'guest_readable'	=> true,
			),
			array(
				'name'				=> '跳蚤市场 | Marketplace',
				'description'		=> '二手交易、求购转让、同城服务与招聘信息。',
				'guest_readable'	=> false,
			),
		),
	),
);

$category_ids = array();
$board_ids = array();
$category_guest_readable = array();
$board_guest_readable = array();

foreach ($forum_plan as $category_spec)
{
	$category_id = ensure_forum(
		$forum_admin,
		0,
		FORUM_CAT,
		$category_spec['name'],
		$category_spec['description'],
		$category_spec['aliases'] ?? array()
	);

	$category_ids[] = $category_id;
	$category_guest_readable[$category_id] = (bool) $category_spec['guest_readable'];

	foreach ($category_spec['boards'] as $board_spec)
	{
		$board_id = ensure_forum(
			$forum_admin,
			$category_id,
			FORUM_POST,
			$board_spec['name'],
			$board_spec['description'],
			$board_spec['aliases'] ?? array()
		);
		$board_ids[] = $board_id;
		$board_guest_readable[$board_id] = (bool) $board_spec['guest_readable'];
	}
}

$group_ids = array(
	'guests'				=> ensure_group('GUESTS', ''),
	'registered'			=> ensure_group('REGISTERED', ''),
	'newly_registered'		=> ensure_group('NEWLY_REGISTERED', ''),
	'trusted'				=> ensure_group('TRUSTED', 'Trusted members with reduced posting friction and attachment permission.'),
	'board_moderators'		=> ensure_group('BOARD_MODERATORS', 'Per-board moderators for VictoriaPark forums.'),
	'administrators'		=> ensure_group('ADMINISTRATORS', ''),
);

$role_ids = array(
	'forum_noaccess'	=> role_id('ROLE_FORUM_NOACCESS'),
	'forum_readonly'	=> role_id('ROLE_FORUM_READONLY'),
	'forum_standard'	=> role_id('ROLE_FORUM_STANDARD'),
	'forum_new_member'	=> role_id('ROLE_FORUM_NEW_MEMBER'),
	'forum_full'		=> role_id('ROLE_FORUM_FULL'),
	'mod_standard'		=> role_id('ROLE_MOD_STANDARD'),
	'mod_full'			=> role_id('ROLE_MOD_FULL'),
);

$trusted_role_id = ensure_trusted_forum_role(
	$role_ids['forum_standard'],
	auth_option_id('f_attach'),
	auth_option_id('f_ignoreflood'),
	auth_option_id('f_noapprove')
);
$role_ids['forum_trusted'] = $trusted_role_id;

$managed_forum_ids = array_merge($category_ids, $board_ids);
apply_forum_acl($managed_forum_ids, $group_ids, $role_ids, $category_guest_readable, $board_guest_readable);
apply_security_config();

sync('forum', 'forum_id', $board_ids, true, true);
phpbb_cache_moderators($db, $cache, $auth);
$cache->destroy('_acl_options');
$auth->acl_clear_prefetch();

echo '[forums] Categories configured: ' . count($category_ids) . PHP_EOL;
echo '[forums] Boards configured: ' . count($board_ids) . PHP_EOL;
echo '[forums] Groups ensured: TRUSTED, BOARD_MODERATORS' . PHP_EOL;
echo '[forums] Trusted role: ROLE_FORUM_TRUSTED' . PHP_EOL;
echo '[forums] ACL + anti-spam settings applied successfully.' . PHP_EOL;
