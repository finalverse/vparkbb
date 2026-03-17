<?php
/**
 * Fix board settings for VictoriaPark.
 *
 * Fixes:
 *   1. Enable open registration (no activation required)
 *   2. Allow posts without moderator approval
 *   3. Set default language to zh_cmn_hans
 *
 * Usage:
 *   docker compose exec php php /var/www/html/scripts/fix_board_settings.php
 */

define('IN_PHPBB', true);
$phpbb_root_path = dirname(__DIR__) . '/';
$phpEx = 'php';

require($phpbb_root_path . 'common.' . $phpEx);

$user->session_begin();
$auth->acl($user->data);

echo "=== VictoriaPark Board Settings Fix ===\n\n";

// 1. Enable open registration (REQUIRE_ACTIVATION = 0 means no activation needed)
// 0 = None (instant), 1 = User email, 2 = Admin approval
$settings = array(
	'require_activation'    => 0,   // No activation required - instant registration
	'enable_confirm'        => 1,   // Keep CAPTCHA for anti-spam
	'allow_namechange'      => 1,   // Allow username changes
	'coppa_enable'          => 0,   // Disable COPPA (not needed)
);

foreach ($settings as $key => $value)
{
	$config->set($key, $value);
	echo "[OK] Set $key = $value\n";
}

// 2. Fix post approval - set all forums to not require post approval
// forum_flags bit 4 (16) = POST_REVIEW means posts need moderator approval
// We need to clear that bit.
$sql = 'SELECT forum_id, forum_name, forum_flags FROM ' . FORUMS_TABLE . ' WHERE forum_type = ' . FORUM_POST;
$result = $db->sql_query($sql);
$updated = 0;
while ($row = $db->sql_fetchrow($result))
{
	$flags = (int) $row['forum_flags'];
	// Bit 4 (value 16) = POST_REVIEW - if set, posts need approval
	// Clear it by unsetting bit 4
	if ($flags & 16)
	{
		$new_flags = $flags & ~16;
		$sql_update = 'UPDATE ' . FORUMS_TABLE . '
			SET forum_flags = ' . $new_flags . '
			WHERE forum_id = ' . (int) $row['forum_id'];
		$db->sql_query($sql_update);
		echo "[OK] Cleared POST_REVIEW flag on forum: " . $row['forum_name'] . " (flags: $flags -> $new_flags)\n";
		$updated++;
	}
}
$db->sql_freeresult($result);
if ($updated === 0)
{
	echo "[INFO] No forums had POST_REVIEW flag set.\n";
}

// 3. Fix permissions - grant REGISTERED users permission to post without approval
// First, find the REGISTERED group
$sql = "SELECT group_id FROM " . GROUPS_TABLE . " WHERE group_name = 'REGISTERED'";
$result = $db->sql_query($sql);
$registered_group = $db->sql_fetchrow($result);
$db->sql_freeresult($result);

if ($registered_group)
{
	$group_id = (int) $registered_group['group_id'];
	echo "\n[INFO] Found REGISTERED group: ID $group_id\n";

	// Get all post-type forums
	$sql = 'SELECT forum_id, forum_name FROM ' . FORUMS_TABLE . ' WHERE forum_type = ' . FORUM_POST;
	$result = $db->sql_query($sql);
	$forums = array();
	while ($row = $db->sql_fetchrow($result))
	{
		$forums[] = $row;
	}
	$db->sql_freeresult($result);

	// Key permission options for posting:
	// f_post = can post new topics
	// f_reply = can reply
	// f_noapprove = posts don't need approval (THIS IS THE KEY ONE)
	$permission_options = array('f_post', 'f_reply', 'f_noapprove');

	// Get auth option IDs
	$sql = 'SELECT auth_option_id, auth_option
		FROM ' . ACL_OPTIONS_TABLE . '
		WHERE ' . $db->sql_in_set('auth_option', $permission_options);
	$result = $db->sql_query($sql);
	$option_ids = array();
	while ($row = $db->sql_fetchrow($result))
	{
		$option_ids[$row['auth_option']] = (int) $row['auth_option_id'];
	}
	$db->sql_freeresult($result);

	echo "[INFO] Permission option IDs: " . json_encode($option_ids) . "\n";

	foreach ($forums as $forum)
	{
		$forum_id = (int) $forum['forum_id'];
		foreach ($option_ids as $option_name => $option_id)
		{
			// Check if permission already exists
			$sql = 'SELECT auth_setting FROM ' . ACL_GROUPS_TABLE . '
				WHERE group_id = ' . $group_id . '
					AND forum_id = ' . $forum_id . '
					AND auth_option_id = ' . $option_id;
			$result_check = $db->sql_query($sql);
			$existing = $db->sql_fetchrow($result_check);
			$db->sql_freeresult($result_check);

			if ($existing)
			{
				if ((int) $existing['auth_setting'] !== 1)
				{
					$sql_update = 'UPDATE ' . ACL_GROUPS_TABLE . '
						SET auth_setting = 1
						WHERE group_id = ' . $group_id . '
							AND forum_id = ' . $forum_id . '
							AND auth_option_id = ' . $option_id;
					$db->sql_query($sql_update);
					echo "[OK] Updated $option_name = ALLOW for forum: " . $forum['forum_name'] . "\n";
				}
			}
			else
			{
				$sql_insert = 'INSERT INTO ' . ACL_GROUPS_TABLE . '
					(group_id, forum_id, auth_option_id, auth_role_id, auth_setting)
					VALUES (' . $group_id . ', ' . $forum_id . ', ' . $option_id . ', 0, 1)';
				$db->sql_query($sql_insert);
				echo "[OK] Inserted $option_name = ALLOW for forum: " . $forum['forum_name'] . "\n";
			}
		}
	}

	// Also do the same for NEWLY_REGISTERED group
	$sql = "SELECT group_id FROM " . GROUPS_TABLE . " WHERE group_name = 'NEWLY_REGISTERED'";
	$result = $db->sql_query($sql);
	$newly_registered = $db->sql_fetchrow($result);
	$db->sql_freeresult($result);

	if ($newly_registered)
	{
		$nr_group_id = (int) $newly_registered['group_id'];
		echo "\n[INFO] Found NEWLY_REGISTERED group: ID $nr_group_id\n";

		foreach ($forums as $forum)
		{
			$forum_id = (int) $forum['forum_id'];
			foreach ($option_ids as $option_name => $option_id)
			{
				$sql = 'SELECT auth_setting FROM ' . ACL_GROUPS_TABLE . '
					WHERE group_id = ' . $nr_group_id . '
						AND forum_id = ' . $forum_id . '
						AND auth_option_id = ' . $option_id;
				$result_check = $db->sql_query($sql);
				$existing = $db->sql_fetchrow($result_check);
				$db->sql_freeresult($result_check);

				if ($existing)
				{
					if ((int) $existing['auth_setting'] !== 1)
					{
						$sql_update = 'UPDATE ' . ACL_GROUPS_TABLE . '
							SET auth_setting = 1
							WHERE group_id = ' . $nr_group_id . '
								AND forum_id = ' . $forum_id . '
								AND auth_option_id = ' . $option_id;
						$db->sql_query($sql_update);
						echo "[OK] Updated $option_name = ALLOW for NEWLY_REGISTERED on forum: " . $forum['forum_name'] . "\n";
					}
				}
				else
				{
					$sql_insert = 'INSERT INTO ' . ACL_GROUPS_TABLE . '
						(group_id, forum_id, auth_option_id, auth_role_id, auth_setting)
						VALUES (' . $nr_group_id . ', ' . $forum_id . ', ' . $option_id . ', 0, 1)';
					$db->sql_query($sql_insert);
					echo "[OK] Inserted $option_name = ALLOW for NEWLY_REGISTERED on forum: " . $forum['forum_name'] . "\n";
				}
			}
		}
	}
}

// 4. Ensure all language packs are installed in phpBB
$languages = array(
	'en'           => array('name' => 'British English',   'local_name' => 'British English'),
	'en_us'        => array('name' => 'American English',  'local_name' => 'American English'),
	'zh_cmn_hans'  => array('name' => 'Chinese Simplified','local_name' => '简体中文'),
	'zh_cmn_hant'  => array('name' => 'Chinese Traditional','local_name' => '繁體中文'),
	'fr'           => array('name' => 'French',            'local_name' => 'Français'),
	'es_x_tu'      => array('name' => 'Spanish',           'local_name' => 'Español'),
);

echo "\n--- Installing language packs ---\n";
foreach ($languages as $lang_iso => $lang_info)
{
	$lang_dir = $phpbb_root_path . 'language/' . $lang_iso;
	if (!is_dir($lang_dir))
	{
		echo "[SKIP] Language directory not found: $lang_iso\n";
		continue;
	}

	$sql = "SELECT lang_id FROM " . LANG_TABLE . " WHERE lang_iso = '" . $db->sql_escape($lang_iso) . "'";
	$result = $db->sql_query($sql);
	$existing_lang = $db->sql_fetchrow($result);
	$db->sql_freeresult($result);

	if ($existing_lang)
	{
		echo "[OK] Language already installed: $lang_iso ({$lang_info['local_name']})\n";
	}
	else
	{
		$sql = 'INSERT INTO ' . LANG_TABLE . ' (lang_iso, lang_dir, lang_english_name, lang_local_name, lang_author)
			VALUES (\'' . $db->sql_escape($lang_iso) . '\', \'' . $db->sql_escape($lang_iso) . '\', \'' . $db->sql_escape($lang_info['name']) . '\', \'' . $db->sql_escape($lang_info['local_name']) . '\', \'VictoriaPark\')';
		$db->sql_query($sql);
		echo "[OK] Installed language: $lang_iso ({$lang_info['local_name']})\n";
	}
}

// 5. Set default language to zh_cmn_hans
$config->set('default_lang', 'zh_cmn_hans');
echo "\n[OK] Set default language to zh_cmn_hans (简体中文)\n";

// 6. Clear the permissions cache so changes take effect
if (isset($phpbb_container))
{
	try
	{
		$cache_service = $phpbb_container->get('cache');
		$cache_service->purge();
		echo "\n[OK] Purged all caches (permissions + templates).\n";
	}
	catch (\Exception $e)
	{
		echo "\n[WARN] Could not purge cache: " . $e->getMessage() . "\n";
	}
}

echo "\n=== Done ===\n";
echo "Registration is now open (no activation required).\n";
echo "Posts no longer require moderator approval.\n";
echo "Please refresh your browser to see changes.\n";
