<?php
/**
 *
 * VictoriaPark glue extension for phpBB.
 *
 */

namespace vpark\glue\event;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class listener implements EventSubscriberInterface
{
	/** @var \phpbb\template\template */
	protected $template;

	/** @var \phpbb\db\driver\driver_interface */
	protected $db;

	/** @var \phpbb\config\config */
	protected $config;

	/** @var \phpbb\user */
	protected $user;

	/** @var \phpbb\request\request_interface|null */
	protected $request;

	/** @var string */
	protected $phpbb_root_path;

	/** @var string */
	protected $php_ext;

	public function __construct(
		\phpbb\template\template $template,
		\phpbb\db\driver\driver_interface $db,
		\phpbb\config\config $config,
		\phpbb\user $user,
		$request = null,
		$phpbb_root_path = '',
		$php_ext = ''
	)
	{
		$this->template = $template;
		$this->db = $db;
		$this->config = $config;
		$this->user = $user;
		$this->request = ($request instanceof \phpbb\request\request_interface) ? $request : null;
		$this->phpbb_root_path = $phpbb_root_path !== '' ? $phpbb_root_path : './';
		$this->php_ext = $php_ext !== '' ? $php_ext : 'php';
	}

	/**
	 * {@inheritDoc}
	 */
	public static function getSubscribedEvents()
	{
		return array(
			'core.user_setup' => 'load_language',
			'core.page_header_after' => 'assign_portal_links',
			'core.viewtopic_assign_template_vars_before' => 'assign_topic_summary_link',
		);
	}

	public function load_language($event)
	{
		$lang_set_ext = $event['lang_set_ext'];
		$lang_set_ext[] = array(
			'ext_name' => 'vpark/glue',
			'lang_set' => 'common',
		);
		$event['lang_set_ext'] = $lang_set_ext;
	}

	public function assign_portal_links()
	{
		$portal_url = $this->portal_url();
		$portal_enabled = $portal_url !== '';
		$lang = $this->current_lang();
		$use_east_asian_title = $this->is_east_asian_lang($lang);
		$site_title = $use_east_asian_title ? '维园网' : 'Victoria Park';
		$index_url = append_sid("{$this->phpbb_root_path}index.{$this->php_ext}");
		$home_ad_target = trim((string) getenv('VPARK_HOME_AD_URL'));
		$home_ad_image = trim((string) getenv('VPARK_HOME_AD_IMAGE_URL'));
		$home_ad_custom_title = trim((string) getenv('VPARK_HOME_AD_TITLE'));
		$home_ad_custom_desc = trim((string) getenv('VPARK_HOME_AD_DESC'));

		$this->template->assign_vars(array(
			'S_VPARK_PORTAL_ENABLED' => $portal_enabled,
			'U_VPARK_PORTAL' => $portal_url,
			'U_VPARK_PORTAL_SPACES' => $portal_enabled ? ($portal_url . '/spaces') : '',
			'SITENAME' => $site_title,
			'SITE_DESCRIPTION' => 'VictoriaPark.io',
			'U_VPARK_LANG_ZH_HANS' => $this->with_language($index_url, 'zh_cmn_hans'),
			'U_VPARK_LANG_ZH_HANT' => $this->with_language($index_url, 'zh_cmn_hant'),
			'U_VPARK_LANG_EN' => $this->with_language($index_url, 'en_us'),
			'U_VPARK_LANG_FR' => $this->with_language($index_url, 'fr'),
			'U_VPARK_LANG_ES' => $this->with_language($index_url, 'es_x_tu'),
			'U_VPARK_LANG_EN_GB' => $this->with_language($index_url, 'en'),
			'S_VPARK_LANG_ZH_HANS' => $lang === 'zh_cmn_hans',
			'S_VPARK_LANG_ZH_HANT' => $lang === 'zh_cmn_hant',
			'S_VPARK_LANG_EN' => $lang === 'en_us',
			'S_VPARK_LANG_FR' => $lang === 'fr',
			'S_VPARK_LANG_ES' => $lang === 'es_x_tu',
			'S_VPARK_LANG_EN_GB' => $lang === 'en',
			'S_VPARK_INDEX_PAGE' => $this->is_index_page(),
			'U_VPARK_HOME_AD_TARGET' => $home_ad_target,
			'VPARK_HOME_AD_IMAGE_URL' => $home_ad_image,
			'VPARK_HOME_AD_CUSTOM_TITLE' => $home_ad_custom_title,
			'VPARK_HOME_AD_CUSTOM_DESC' => $home_ad_custom_desc,
		));

		$this->assign_forum_panel_items();
		$this->assign_breaking_news_items();
		$this->assign_home_news_items();
	}

	public function assign_topic_summary_link($event)
	{
		$summary_enabled = $this->summary_enabled();
		$portal_url = $this->portal_url();
		$topic_id = (int) $event['topic_id'];

		$this->template->assign_vars(array(
			'S_VPARK_SUMMARY_ENABLED' => $summary_enabled && $portal_url !== '' && $topic_id > 0,
			'U_VPARK_TOPIC_SUMMARY' => ($summary_enabled && $portal_url !== '' && $topic_id > 0) ? ($portal_url . '/topic/' . $topic_id) : '',
		));
	}

	protected function portal_url()
	{
		$portal_url = trim((string) getenv('VPARK_PORTAL_URL'));
		if ($portal_url === '')
		{
			$portal_url = 'https://victoriapark.io';
		}

		return rtrim($portal_url, '/');
	}

	protected function summary_enabled()
	{
		$value = strtolower(trim((string) getenv('VPARK_SUMMARY_BUTTON_ENABLED')));
		if ($value === '')
		{
			return true;
		}

		return !in_array($value, array('0', 'false', 'off', 'no'), true);
	}

	protected function with_language($url, $lang)
	{
		$separator = (strpos($url, '?') === false) ? '?' : '&amp;';
		return $url . $separator . 'language=' . rawurlencode($lang);
	}

	protected function current_lang()
	{
		$lang = '';
		$is_registered = !empty($this->user->data['is_registered']);

		if ($this->request)
		{
			$lang = $this->normalize_supported_lang((string) $this->request->variable('language', '', true));
		}

		if ($lang === '' && $this->request)
		{
			$lang = $this->normalize_supported_lang((string) $this->request->variable(
				$this->config['cookie_name'] . '_lang',
				'',
				true,
				\phpbb\request\request_interface::COOKIE
			));
		}

		if ($lang === '')
		{
			$cookie_name = (string) $this->config['cookie_name'] . '_lang';
			if (isset($_COOKIE[$cookie_name]))
			{
				$lang = $this->normalize_supported_lang((string) $_COOKIE[$cookie_name]);
			}
		}

		if ($lang === '' && $is_registered && !empty($this->user->data['user_lang']))
		{
			$lang = $this->normalize_supported_lang((string) $this->user->data['user_lang']);
		}

		if ($lang === '')
		{
			$lang = $this->normalize_supported_lang((string) $this->config['default_lang']);
		}

		if ($lang === '' && !empty($this->user->lang_name))
		{
			$lang = $this->normalize_supported_lang((string) $this->user->lang_name);
		}

		return strtolower($lang);
	}

	protected function is_east_asian_lang($lang)
	{
		return strpos($lang, 'zh') === 0 || strpos($lang, 'ja') === 0 || strpos($lang, 'ko') === 0;
	}

	protected function is_index_page()
	{
		return isset($this->user->page['page_name']) && $this->user->page['page_name'] === 'index.' . $this->php_ext;
	}

	protected function assign_forum_panel_items()
	{
		$panel_items = $this->forum_panel_items();

		$forum_names = array();
		foreach ($panel_items as $item)
		{
			$forum_names[] = $item['title'];
		}
		$forum_links = $this->forum_links_by_name($forum_names);
		$index_url = append_sid("{$this->phpbb_root_path}index.{$this->php_ext}");

		foreach ($panel_items as $item)
		{
			$title = $item['title'];
			$this->template->assign_block_vars('vpark_forum_panel', array(
				'TITLE'		=> $title,
				'SUBTITLE'	=> $item['subtitle'],
				'METRIC'	=> $item['metric'] ?? '',
				'U_FORUM'	=> $forum_links[$title] ?? $index_url,
			));
		}
	}

	protected function assign_breaking_news_items()
	{
		$panel_items = $this->forum_panel_items();
		$forum_names = array();
		foreach ($panel_items as $item)
		{
			$forum_names[] = (string) $item['title'];
		}
		$forum_links = $this->forum_links_by_name($forum_names);
		$index_url = append_sid("{$this->phpbb_root_path}index.{$this->php_ext}");

		if (empty($forum_names))
		{
			return;
		}

		$sql = 'SELECT t.topic_id, t.topic_title
			FROM ' . TOPICS_TABLE . ' t
			JOIN ' . FORUMS_TABLE . ' f
				ON f.forum_id = t.forum_id
			WHERE t.topic_moved_id = 0
				AND ' . $this->db->sql_in_set('f.forum_name', $forum_names) . '
			ORDER BY t.topic_last_post_time DESC';
		$result = $this->db->sql_query_limit($sql, 100);
		$news_count = 0;

		while ($row = $this->db->sql_fetchrow($result))
		{
			$title = trim((string) $row['topic_title']);
			if ($title === '')
			{
				continue;
			}

			$this->template->assign_block_vars('vpark_breaking_news', array(
				'TITLE'		=> $title,
				'U_TOPIC'	=> append_sid("{$this->phpbb_root_path}viewtopic.{$this->php_ext}", 't=' . (int) $row['topic_id']),
			));
			$news_count++;
		}
		$this->db->sql_freeresult($result);

		if ($news_count === 0)
		{
			$fallback_items = array_slice($panel_items, 0, 15);
			foreach ($fallback_items as $item)
			{
				$title = (string) $item['title'];
				$this->template->assign_block_vars('vpark_breaking_news', array(
					'TITLE'		=> $title,
					'U_TOPIC'	=> $forum_links[$title] ?? $index_url,
				));
			}
		}
	}

	protected function assign_home_news_items()
	{
		if (!$this->is_index_page())
		{
			return;
		}

		$index_url = append_sid("{$this->phpbb_root_path}index.{$this->php_ext}");
		$news_forum = $this->first_forum_by_names(array(
			'新闻',
			'VictoriaPark新闻',
			'VictoriaPark.io新闻',
			'新闻速递',
		));

		$rows = array();
		$news_forum_url = $index_url;

		if ($news_forum)
		{
			$news_forum_url = append_sid(
				"{$this->phpbb_root_path}viewforum.{$this->php_ext}",
				'f=' . (int) $news_forum['forum_id']
			);

			$sql = 'SELECT t.topic_id, t.topic_title, t.forum_id, f.forum_name
				FROM ' . TOPICS_TABLE . ' t
				JOIN ' . FORUMS_TABLE . ' f
					ON f.forum_id = t.forum_id
				WHERE t.topic_moved_id = 0
					AND t.forum_id = ' . (int) $news_forum['forum_id'] . '
				ORDER BY t.topic_last_post_time DESC';
			$result = $this->db->sql_query_limit($sql, 40);
			while ($row = $this->db->sql_fetchrow($result))
			{
				$rows[] = $row;
			}
			$this->db->sql_freeresult($result);
		}

		if (empty($rows))
		{
			$panel_items = $this->forum_panel_items();
			$forum_names = array();
			foreach ($panel_items as $item)
			{
				$forum_names[] = (string) $item['title'];
			}

			if (!empty($forum_names))
			{
				$sql = 'SELECT t.topic_id, t.topic_title, t.forum_id, f.forum_name
					FROM ' . TOPICS_TABLE . ' t
					JOIN ' . FORUMS_TABLE . ' f
						ON f.forum_id = t.forum_id
					WHERE t.topic_moved_id = 0
						AND ' . $this->db->sql_in_set('f.forum_name', $forum_names) . '
					ORDER BY t.topic_last_post_time DESC';
				$result = $this->db->sql_query_limit($sql, 40);
				while ($row = $this->db->sql_fetchrow($result))
				{
					$rows[] = $row;
				}
				$this->db->sql_freeresult($result);
			}
		}

		$first_forum_id = 0;
		if (!empty($rows))
		{
			if (isset($rows[0]['forum_id']))
			{
				$first_forum_id = (int) $rows[0]['forum_id'];
			}
			elseif (isset($rows[0]['FORUM_ID']))
			{
				$first_forum_id = (int) $rows[0]['FORUM_ID'];
			}
		}

		if ($news_forum_url === $index_url && $first_forum_id > 0)
		{
			$news_forum_url = append_sid(
				"{$this->phpbb_root_path}viewforum.{$this->php_ext}",
				'f=' . $first_forum_id
			);
		}

		$this->template->assign_vars(array(
			'U_VPARK_NEWS_FORUM' => $news_forum_url,
			'S_VPARK_HOME_NEWS' => !empty($rows),
		));

		$counter = 0;
		foreach ($rows as $row)
		{
			$title = trim((string) $row['topic_title']);
			if ($title === '')
			{
				continue;
			}

			$block = ($counter % 2 === 0) ? 'vpark_home_news_left' : 'vpark_home_news_right';
			$this->template->assign_block_vars($block, array(
				'TITLE' => $title,
				'FORUM_NAME' => (string) $row['forum_name'],
				'U_TOPIC' => append_sid("{$this->phpbb_root_path}viewtopic.{$this->php_ext}", 't=' . (int) $row['topic_id']),
			));
			$counter++;
		}
	}

	protected function forum_panel_items()
	{
		return array(
			array('title' => '百家论坛', 'subtitle' => '综合时政 / 社会 / 观点', 'metric' => '人气股价 60.25金币（第10名）'),
			array('title' => '军事纵横', 'subtitle' => '军事 / 地缘 / 战争'),
			array('title' => '经济观察', 'subtitle' => '宏观 / 政策 / 经济评论'),
			array('title' => '谈股论金', 'subtitle' => '股市 / 交易 / 投资心态'),
			array('title' => '股票投资', 'subtitle' => '实操策略 / 个股复盘'),
			array('title' => '娱乐八卦', 'subtitle' => '明星热点 / 轻内容'),
			array('title' => '笑口常开', 'subtitle' => '段子 / 搞笑 / 轻松'),
			array('title' => '生活百态', 'subtitle' => '生活杂谈 / 海外见闻'),
			array('title' => '婚姻家庭', 'subtitle' => '两性 / 家庭 / 亲子'),
			array('title' => '文化长廊', 'subtitle' => '文化 / 阅读 / 随笔'),
			array('title' => '网际谈兵', 'subtitle' => '国际关系 / 军政延展'),
			array('title' => '史海钩沉', 'subtitle' => '历史 / 考据 / 旧闻'),
			array('title' => '自由文学', 'subtitle' => '原创 / 连载 / 文艺'),
			array('title' => '体坛纵横', 'subtitle' => '体育赛事 / 热点讨论'),
			array('title' => '电脑前线 / 数码家电', 'subtitle' => '技术工具 / 数码家电'),
		);
	}

	protected function forum_links_by_name(array $forum_names)
	{
		if (empty($forum_names))
		{
			return array();
		}

		$escaped_names = array();
		foreach ($forum_names as $forum_name)
		{
			$escaped_names[] = (string) $forum_name;
		}

		$sql = 'SELECT forum_id, forum_name
			FROM ' . FORUMS_TABLE . '
			WHERE forum_type = ' . FORUM_POST . '
				AND ' . $this->db->sql_in_set('forum_name', $escaped_names);
		$result = $this->db->sql_query($sql);

		$links = array();
		while ($row = $this->db->sql_fetchrow($result))
		{
			$name = (string) $row['forum_name'];
			$links[$name] = append_sid("{$this->phpbb_root_path}viewforum.{$this->php_ext}", 'f=' . (int) $row['forum_id']);
		}
		$this->db->sql_freeresult($result);

		return $links;
	}

	protected function first_forum_by_names(array $forum_names)
	{
		if (empty($forum_names))
		{
			return null;
		}

		$sql = 'SELECT forum_id, forum_name
			FROM ' . FORUMS_TABLE . '
			WHERE forum_type = ' . FORUM_POST . '
				AND ' . $this->db->sql_in_set('forum_name', $forum_names);
		$result = $this->db->sql_query($sql);
		$rows = array();
		while ($row = $this->db->sql_fetchrow($result))
		{
			$rows[(string) $row['forum_name']] = array(
				'forum_id' => (int) $row['forum_id'],
				'forum_name' => (string) $row['forum_name'],
			);
		}
		$this->db->sql_freeresult($result);

		foreach ($forum_names as $forum_name)
		{
			if (isset($rows[$forum_name]))
			{
				return $rows[$forum_name];
			}
		}

		return null;
	}

	protected function normalize_supported_lang($lang)
	{
		$lang = strtolower(trim((string) $lang));
		$lang = str_replace('-', '_', $lang);

		$aliases = array(
			'zh_cn' => 'zh_cmn_hans',
			'zh_hans' => 'zh_cmn_hans',
			'zh_cmn_hans' => 'zh_cmn_hans',
			'zh_tw' => 'zh_cmn_hant',
			'zh_hk' => 'zh_cmn_hant',
			'zh_hant' => 'zh_cmn_hant',
			'zh_traditional' => 'zh_cmn_hant',
			'zh_cmn_hant' => 'zh_cmn_hant',
			'en' => 'en',
			'en_gb' => 'en',
			'en_us' => 'en_us',
			'fr' => 'fr',
			'fr_fr' => 'fr',
			'es' => 'es_x_tu',
			'es_es' => 'es_x_tu',
			'es_x_tu' => 'es_x_tu',
			'sp_sp' => 'es_x_tu',
		);

		return isset($aliases[$lang]) ? $aliases[$lang] : '';
	}
}
