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

	/** @var \phpbb\cache\service|null */
	protected $cache;

	/** @var array<int, array<string, mixed>> */
	protected $topic_cards = array();

	public function __construct(
		\phpbb\template\template $template,
		\phpbb\db\driver\driver_interface $db,
		\phpbb\config\config $config,
		\phpbb\user $user,
		$request = null,
		$phpbb_root_path = '',
		$php_ext = '',
		$cache = null
	)
	{
		$this->template = $template;
		$this->db = $db;
		$this->config = $config;
		$this->user = $user;
		$this->request = ($request instanceof \phpbb\request\request_interface) ? $request : null;
		$this->phpbb_root_path = $phpbb_root_path !== '' ? $phpbb_root_path : './';
		$this->php_ext = $php_ext !== '' ? $php_ext : 'php';
		$this->cache = ($cache instanceof \phpbb\cache\service) ? $cache : null;
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
			'core.acp_manage_forums_request_data' => 'purge_forum_cache',
			'core.acp_manage_forums_update_data_after' => 'purge_forum_cache',
			'core.display_forums_modify_template_vars' => 'assign_forum_card_vars',
			'core.viewforum_modify_topics_data' => 'prepare_viewforum_topic_cards',
			'core.viewforum_modify_topicrow' => 'assign_topic_card_vars',
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

		// Handle language switching via ?language= parameter for ALL users
		// (phpBB core only handles this for guests)
		if ($this->request)
		{
			$requested_lang = $this->request->variable('language', '', true);
			$from_cookie = false;
			if ($requested_lang === '')
			{
				$requested_lang = $this->request->variable(
					$this->config['cookie_name'] . '_lang',
					'',
					true,
					\phpbb\request\request_interface::COOKIE
				);
				$from_cookie = true;
			}

			if ($requested_lang !== '')
			{
				$use_lang = basename($requested_lang);
				$lang_file = $this->phpbb_root_path . 'language/' . $use_lang . '/common.' . $this->php_ext;

				if (file_exists($lang_file))
				{
					// Set cookie for persistence when switching via URL param
					if (!$from_cookie)
					{
						$this->user->set_cookie('lang', $use_lang, time() + 86400 * 365);
					}

					// Override user_lang_name — phpBB uses this after the event
					$event['user_lang_name'] = $use_lang;

					// Also update user data so current_lang() picks it up
					$user_data = $event['user_data'];
					$user_data['user_lang'] = $use_lang;
					$event['user_data'] = $user_data;
				}
			}
		}
	}

	public function purge_forum_cache()
	{
		if ($this->cache !== null)
		{
			$this->cache->destroy('_vpark_forum_panel');
		}
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

		// Demo ads when no real ads are configured
		if ($home_ad_custom_title === '')
		{
			if ($use_east_asian_title)
			{
				$home_ad_custom_title = '在此投放广告';
				$home_ad_custom_desc = '维园网广告位 — 触达全球华人社区。联系 ads@victoriapark.io 了解合作方案。';
			}
			else
			{
				$home_ad_custom_title = 'Advertise Here';
				$home_ad_custom_desc = 'Reach the VictoriaPark.io community. Contact ads@victoriapark.io for partnership opportunities.';
			}
		}

		// Header ad demo
		$header_ad_title = $use_east_asian_title ? '广告合作' : 'Sponsor';
		$header_ad_desc = $use_east_asian_title
			? '此处可放置 300x80 横幅广告。联系我们了解详情。'
			: 'Premium 300x80 banner spot. Contact us for details.';

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
			'VPARK_HEADER_AD_TITLE' => $header_ad_title,
			'VPARK_HEADER_AD_DESC' => $header_ad_desc,
		));

		$this->assign_forum_panel_items();
		$this->assign_breaking_news_items();
		$this->assign_home_portal_items();
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

	public function assign_forum_card_vars($event)
	{
		$forum_row = $event['forum_row'];
		$row = $event['row'];
		$forum_id = isset($row['forum_id']) ? (int) $row['forum_id'] : 0;
		$forum_name = isset($row['forum_name']) ? (string) $row['forum_name'] : '';
		$forum_desc = isset($row['forum_desc']) ? (string) $row['forum_desc'] : '';

		$forum_row['VPARK_FORUM_IMAGE'] = !empty($row['forum_image'])
			? $this->phpbb_root_path . ltrim((string) $row['forum_image'], '/')
			: $this->fallback_image_for_forum($forum_id, $forum_name);
		$forum_row['VPARK_FORUM_KICKER'] = $this->forum_kicker($forum_name);
		$forum_row['VPARK_FORUM_EXCERPT'] = $this->clean_excerpt($forum_desc, 110);

		$event['forum_row'] = $forum_row;
	}

	public function prepare_viewforum_topic_cards($event)
	{
		$rowset = $event['rowset'];
		if (empty($rowset) || !is_array($rowset))
		{
			return;
		}

		$topic_ids = array();
		foreach ($rowset as $row)
		{
			if (!empty($row['topic_id']))
			{
				$topic_ids[] = (int) $row['topic_id'];
			}
		}

		$this->topic_cards = $this->topic_cards + $this->topic_cards_for_topic_ids($topic_ids);
	}

	public function assign_topic_card_vars($event)
	{
		$row = $event['row'];
		$topic_row = $event['topic_row'];
		$topic_id = isset($row['topic_id']) ? (int) $row['topic_id'] : 0;

		if ($topic_id > 0 && !isset($this->topic_cards[$topic_id]))
		{
			$this->topic_cards = $this->topic_cards + $this->topic_cards_for_topic_ids(array($topic_id));
		}

		$card = isset($this->topic_cards[$topic_id]) ? $this->topic_cards[$topic_id] : array();
		$forum_id = isset($row['forum_id']) ? (int) $row['forum_id'] : (isset($card['forum_id']) ? (int) $card['forum_id'] : 0);
		$forum_name = isset($row['forum_name']) ? (string) $row['forum_name'] : (isset($card['forum_name']) ? (string) $card['forum_name'] : '');
		$reply_count = max(0, ((int) ($row['topic_posts_approved'] ?? 1)) - 1);

		$topic_row['VPARK_TOPIC_IMAGE'] = isset($card['image']) && $card['image'] !== ''
			? $card['image']
			: $this->fallback_image_for_forum($forum_id, $forum_name, $topic_id);
		$topic_row['VPARK_TOPIC_EXCERPT'] = isset($card['excerpt']) ? (string) $card['excerpt'] : '';
		$topic_row['VPARK_TOPIC_FORUM'] = $forum_name;
		$topic_row['VPARK_TOPIC_KICKER'] = $this->forum_kicker($forum_name);
		$topic_row['VPARK_TOPIC_AUTHOR'] = isset($row['topic_first_poster_name']) ? (string) $row['topic_first_poster_name'] : '';
		$topic_row['VPARK_TOPIC_COMMENTS'] = (string) $reply_count;

		$event['topic_row'] = $topic_row;
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

		foreach ($panel_items as $item)
		{
			$forum_id = isset($item['forum_id']) ? (int) $item['forum_id'] : 0;
			$url = $forum_id > 0
				? append_sid("{$this->phpbb_root_path}viewforum.{$this->php_ext}", 'f=' . $forum_id)
				: append_sid("{$this->phpbb_root_path}index.{$this->php_ext}");

			$this->template->assign_block_vars('vpark_forum_panel', array(
				'TITLE'		=> $item['title'],
				'SUBTITLE'	=> $item['subtitle'],
				'METRIC'	=> $item['metric'] ?? '',
				'U_FORUM'	=> $url,
			));
		}
	}

	protected function rss_lang_for_current_lang($current_lang)
	{
		// Map phpBB lang codes to RSS feed lang tags
		if (strpos($current_lang, 'zh_cmn_hans') === 0) return 'zh_cmn_hans';
		if (strpos($current_lang, 'zh_cmn_hant') === 0) return 'zh_cmn_hant';
		if (strpos($current_lang, 'zh') === 0) return 'zh_cmn_hans';
		if (strpos($current_lang, 'fr') === 0) return 'fr';
		if (strpos($current_lang, 'es') === 0) return 'es';
		return 'en'; // default to English
	}

	protected function assign_breaking_news_items()
	{
		// Try cached RSS feed items first
		if ($this->cache !== null)
		{
			$rss_items = $this->cache->get('_vpark_rss_breaking_news');
			if ($rss_items !== false && !empty($rss_items))
			{
				$current_lang = $this->current_lang();
				$rss_lang = $this->rss_lang_for_current_lang($current_lang);

				$filtered = array();
				foreach ($rss_items as $rss_item)
				{
					if (isset($rss_item['lang']) && $rss_item['lang'] === $rss_lang)
					{
						$filtered[] = $rss_item;
					}
				}

				// If no items match the current language, show all items
				if (empty($filtered))
				{
					$filtered = $rss_items;
				}

				foreach ($filtered as $rss_item)
				{
					$this->template->assign_block_vars('vpark_breaking_news', array(
						'TITLE'		=> (string) $rss_item['title'],
						'U_TOPIC'	=> (string) $rss_item['url'],
					));
				}
				return;
			}
		}

		// Fallback to forum topics
		$panel_items = $this->forum_panel_items();
		$forum_ids = array();
		foreach ($panel_items as $item)
		{
			if (isset($item['forum_id']) && (int) $item['forum_id'] > 0)
			{
				$forum_ids[] = (int) $item['forum_id'];
			}
		}

		if (empty($forum_ids))
		{
			return;
		}

		$sql = 'SELECT t.topic_id, t.topic_title
			FROM ' . TOPICS_TABLE . ' t
			WHERE t.topic_moved_id = 0
				AND ' . $this->db->sql_in_set('t.forum_id', $forum_ids) . '
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
			$index_url = append_sid("{$this->phpbb_root_path}index.{$this->php_ext}");
			$fallback_items = array_slice($panel_items, 0, 15);
			foreach ($fallback_items as $item)
			{
				$forum_id = isset($item['forum_id']) ? (int) $item['forum_id'] : 0;
				$url = $forum_id > 0
					? append_sid("{$this->phpbb_root_path}viewforum.{$this->php_ext}", 'f=' . $forum_id)
					: $index_url;

				$this->template->assign_block_vars('vpark_breaking_news', array(
					'TITLE'		=> (string) $item['title'],
					'U_TOPIC'	=> $url,
				));
			}
		}
	}

	protected function assign_home_portal_items()
	{
		if (!$this->is_index_page())
		{
			return;
		}

		$index_url = append_sid("{$this->phpbb_root_path}index.{$this->php_ext}");
		$panel_items = $this->forum_panel_items();
		$forum_ids = array();
		foreach ($panel_items as $item)
		{
			if (isset($item['forum_id']) && (int) $item['forum_id'] > 0)
			{
				$forum_ids[] = (int) $item['forum_id'];
			}
		}

		$news_forum_url = $index_url;
		$news_forum = $this->first_forum_by_names(array(
			'æ–°é—»',
			'VictoriaParkæ–°é—»',
			'VictoriaPark.ioæ–°é—»',
			'æ–°é—»é€Ÿé€’',
			'新闻',
			'VictoriaPark新闻',
			'VictoriaPark.io新闻',
			'新闻速递',
		));

		if ($news_forum)
		{
			if (empty($forum_ids))
			{
				$forum_ids = array((int) $news_forum['forum_id']);
			}
			$news_forum_url = append_sid("{$this->phpbb_root_path}viewforum.{$this->php_ext}", 'f=' . (int) $news_forum['forum_id']);
		}

		$cards = $this->editorial_topic_cards($forum_ids, 16);
		if ($news_forum_url === $index_url && !empty($cards[0]['forum_id']))
		{
			$news_forum_url = append_sid("{$this->phpbb_root_path}viewforum.{$this->php_ext}", 'f=' . (int) $cards[0]['forum_id']);
		}

		$hero_card = !empty($cards) ? $cards[0] : array();
		$story_cards = array_slice($cards, 1, 6);
		$hot_cards = array_slice($cards, 7, 8);

		$this->template->assign_vars(array(
			'U_VPARK_NEWS_FORUM' => $news_forum_url,
			'S_VPARK_HOME_NEWS' => !empty($cards),
			'S_VPARK_HOME_STORIES' => !empty($story_cards),
			'S_VPARK_HOME_HOT' => !empty($hot_cards),
		));

		if (!empty($hero_card))
		{
			$this->template->assign_vars(array(
				'VPARK_HOME_HERO_TITLE' => $hero_card['title'],
				'VPARK_HOME_HERO_EXCERPT' => $hero_card['excerpt'],
				'VPARK_HOME_HERO_IMAGE' => $hero_card['image'],
				'VPARK_HOME_HERO_FORUM' => $hero_card['forum_name'],
				'VPARK_HOME_HERO_AUTHOR' => $hero_card['author'],
				'VPARK_HOME_HERO_TIME' => $hero_card['time'],
				'VPARK_HOME_HERO_COMMENTS' => (string) $hero_card['comments'],
				'U_VPARK_HOME_HERO' => $hero_card['url'],
			));
		}

		foreach ($story_cards as $card)
		{
			$this->template->assign_block_vars('vpark_home_stories', array(
				'TITLE' => $card['title'],
				'EXCERPT' => $card['excerpt'],
				'IMAGE' => $card['image'],
				'FORUM_NAME' => $card['forum_name'],
				'AUTHOR' => $card['author'],
				'TIME' => $card['time'],
				'COMMENTS' => (string) $card['comments'],
				'U_TOPIC' => $card['url'],
			));
		}

		foreach ($hot_cards as $card)
		{
			$this->template->assign_block_vars('vpark_hot_discussions', array(
				'TITLE' => $card['title'],
				'EXCERPT' => $card['excerpt'],
				'FORUM_NAME' => $card['forum_name'],
				'AUTHOR' => $card['author'],
				'TIME' => $card['time'],
				'COMMENTS' => (string) $card['comments'],
				'U_TOPIC' => $card['url'],
			));
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
			$forum_ids = array();
			foreach ($panel_items as $item)
			{
				if (isset($item['forum_id']) && (int) $item['forum_id'] > 0)
				{
					$forum_ids[] = (int) $item['forum_id'];
				}
			}

			if (!empty($forum_ids))
			{
				$sql = 'SELECT t.topic_id, t.topic_title, t.forum_id, f.forum_name
					FROM ' . TOPICS_TABLE . ' t
					JOIN ' . FORUMS_TABLE . ' f
						ON f.forum_id = t.forum_id
					WHERE t.topic_moved_id = 0
						AND ' . $this->db->sql_in_set('t.forum_id', $forum_ids) . '
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

	protected function latest_topic_cards(array $forum_ids, $limit = 12)
	{
		if (empty($forum_ids))
		{
			return array();
		}

		$sql = 'SELECT t.topic_id
			FROM ' . TOPICS_TABLE . ' t
			WHERE t.topic_moved_id = 0
				AND t.topic_visibility = ' . ITEM_APPROVED . '
				AND ' . $this->db->sql_in_set('t.forum_id', array_map('intval', $forum_ids)) . '
			ORDER BY t.topic_last_post_time DESC';
		$result = $this->db->sql_query_limit($sql, (int) $limit);

		$topic_ids = array();
		while ($row = $this->db->sql_fetchrow($result))
		{
			$topic_ids[] = (int) $row['topic_id'];
		}
		$this->db->sql_freeresult($result);

		$cards_by_topic = $this->topic_cards_for_topic_ids($topic_ids);
		$cards = array();
		foreach ($topic_ids as $topic_id)
		{
			if (isset($cards_by_topic[$topic_id]))
			{
				$cards[] = $cards_by_topic[$topic_id];
			}
		}

		return $cards;
	}

	protected function editorial_topic_cards(array $forum_ids, $limit = 12)
	{
		if (empty($forum_ids))
		{
			return array();
		}

		$sql = 'SELECT t.topic_id, t.forum_id
			FROM ' . TOPICS_TABLE . ' t
			WHERE t.topic_moved_id = 0
				AND t.topic_visibility = ' . ITEM_APPROVED . '
				AND ' . $this->db->sql_in_set('t.forum_id', array_map('intval', $forum_ids)) . '
			ORDER BY t.topic_last_post_time DESC';
		$result = $this->db->sql_query_limit($sql, max((int) $limit * 5, 40));

		$primary_by_forum = array();
		$overflow = array();
		while ($row = $this->db->sql_fetchrow($result))
		{
			$topic_id = (int) $row['topic_id'];
			$forum_id = (int) $row['forum_id'];
			if (!isset($primary_by_forum[$forum_id]))
			{
				$primary_by_forum[$forum_id] = $topic_id;
			}
			else
			{
				$overflow[] = $topic_id;
			}
		}
		$this->db->sql_freeresult($result);

		$topic_ids = array_values($primary_by_forum);
		foreach ($overflow as $topic_id)
		{
			if (count($topic_ids) >= $limit)
			{
				break;
			}
			$topic_ids[] = $topic_id;
		}
		$topic_ids = array_slice(array_values(array_unique($topic_ids)), 0, (int) $limit);

		$cards_by_topic = $this->topic_cards_for_topic_ids($topic_ids);
		$cards = array();
		foreach ($topic_ids as $topic_id)
		{
			if (isset($cards_by_topic[$topic_id]))
			{
				$cards[] = $cards_by_topic[$topic_id];
			}
		}

		return $cards;
	}

	protected function topic_cards_for_topic_ids(array $topic_ids)
	{
		$topic_ids = array_values(array_unique(array_filter(array_map('intval', $topic_ids))));
		if (empty($topic_ids))
		{
			return array();
		}

		$attachment_images = $this->topic_attachment_images($topic_ids);

		$sql = 'SELECT t.topic_id, t.forum_id, t.topic_title, t.topic_first_poster_name,
				t.topic_time, t.topic_last_post_time, t.topic_posts_approved, t.topic_views,
				f.forum_name, p.post_text, p.bbcode_uid
			FROM ' . TOPICS_TABLE . ' t
			JOIN ' . FORUMS_TABLE . ' f
				ON f.forum_id = t.forum_id
			LEFT JOIN ' . POSTS_TABLE . ' p
				ON p.post_id = t.topic_first_post_id
			WHERE ' . $this->db->sql_in_set('t.topic_id', $topic_ids);
		$result = $this->db->sql_query($sql);

		$cards = array();
		while ($row = $this->db->sql_fetchrow($result))
		{
			$topic_id = (int) $row['topic_id'];
			$forum_id = (int) $row['forum_id'];
			$forum_name = (string) $row['forum_name'];
			$title = trim(censor_text((string) $row['topic_title']));
			if ($title === '')
			{
				continue;
			}

			$post_text = isset($row['post_text']) ? (string) $row['post_text'] : '';
			$image = isset($attachment_images[$topic_id]) ? $attachment_images[$topic_id] : $this->first_post_image_url($post_text);
			if ($image === '')
			{
				$image = $this->fallback_image_for_forum($forum_id, $forum_name, $topic_id);
			}

			$cards[$topic_id] = array(
				'topic_id' => $topic_id,
				'forum_id' => $forum_id,
				'forum_name' => $forum_name,
				'title' => $title,
				'excerpt' => $this->clean_excerpt($post_text, 170, isset($row['bbcode_uid']) ? (string) $row['bbcode_uid'] : ''),
				'image' => $image,
				'author' => (string) $row['topic_first_poster_name'],
				'time' => $this->user->format_date((int) $row['topic_time']),
				'comments' => max(0, ((int) $row['topic_posts_approved']) - 1),
				'views' => (int) $row['topic_views'],
				'url' => append_sid("{$this->phpbb_root_path}viewtopic.{$this->php_ext}", 't=' . $topic_id),
			);
		}
		$this->db->sql_freeresult($result);

		return $cards;
	}

	protected function topic_attachment_images(array $topic_ids)
	{
		$topic_ids = array_values(array_unique(array_filter(array_map('intval', $topic_ids))));
		if (empty($topic_ids))
		{
			return array();
		}

		$sql = 'SELECT attach_id, topic_id, thumbnail, mimetype, extension
			FROM ' . ATTACHMENTS_TABLE . '
			WHERE is_orphan = 0
				AND in_message = 0
				AND ' . $this->db->sql_in_set('topic_id', $topic_ids) . "
				AND (LOWER(mimetype) LIKE 'image/%'
					OR LOWER(extension) IN ('jpg', 'jpeg', 'png', 'gif', 'webp'))
			ORDER BY filetime ASC, attach_id ASC";
		$result = $this->db->sql_query($sql);

		$images = array();
		while ($row = $this->db->sql_fetchrow($result))
		{
			$topic_id = (int) $row['topic_id'];
			if (isset($images[$topic_id]))
			{
				continue;
			}

			$params = 'id=' . (int) $row['attach_id'];
			if (!empty($row['thumbnail']))
			{
				$params .= '&amp;t=1';
			}
			$images[$topic_id] = append_sid("{$this->phpbb_root_path}download/file.{$this->php_ext}", $params);
		}
		$this->db->sql_freeresult($result);

		return $images;
	}

	protected function first_post_image_url($post_text)
	{
		$post_text = (string) $post_text;
		if ($post_text === '')
		{
			return '';
		}

		$patterns = array(
			'#\[img(?:=[^\]]*)?\](https?://[^\[]+)\[/img\]#iu',
			'#<img[^>]+src=["\']([^"\']+)["\']#iu',
			'#<IMG[^>]+src=["\']([^"\']+)["\']#u',
			'#<URL\s+url=["\']([^"\']+)["\']#u',
			'#(https?://[^\s<>"\']+\.(?:jpe?g|png|gif|webp)(?:\?[^\s<>"\']*)?)#iu',
		);

		foreach ($patterns as $pattern)
		{
			if (preg_match_all($pattern, $post_text, $matches))
			{
				foreach ($matches[1] as $candidate)
				{
					$url = html_entity_decode(trim((string) $candidate), ENT_QUOTES | ENT_HTML5, 'UTF-8');
					if ($this->is_likely_image_url($url))
					{
						return $url;
					}
				}
			}
		}

		return '';
	}

	protected function is_likely_image_url($url)
	{
		$url = trim((string) $url);
		if ($url === '' || !preg_match('#^https?://#i', $url))
		{
			return false;
		}

		$path = parse_url($url, PHP_URL_PATH);
		return is_string($path) && preg_match('#\.(jpe?g|png|gif|webp)$#i', $path);
	}

	protected function clean_excerpt($text, $limit = 140, $bbcode_uid = '')
	{
		$text = (string) $text;
		if ($text === '')
		{
			return '';
		}

		if (function_exists('strip_bbcode'))
		{
			strip_bbcode($text, $bbcode_uid);
		}

		$text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$text = preg_replace('#<br\s*/?>#i', ' ', $text);
		$text = preg_replace('#<[^>]+>#', ' ', $text);
		$text = preg_replace('#https?://\S+#iu', ' ', $text);
		$text = preg_replace('/\s+/u', ' ', $text);
		$text = trim($text);

		if ($text === '')
		{
			return '';
		}

		if (mb_strlen($text) > $limit)
		{
			$text = rtrim(mb_substr($text, 0, max(0, $limit - 3))) . '...';
		}

		return $text;
	}

	protected function fallback_image_for_forum($forum_id, $forum_name, $topic_id = 0)
	{
		$slug = $this->forum_image_slug((int) $forum_id, (string) $forum_name);
		$seed = (int) $topic_id > 0 ? ((int) floor((int) $topic_id / 8) + (int) $forum_id) : (int) $forum_id;
		$variants = array(
			'forum-finance' => array('forum-finance', 'forum-finance-brief', 'forum-finance-ledger'),
			'forum-community' => array('forum-community', 'forum-community-talk'),
			'forum-entertainment' => array('forum-entertainment', 'forum-entertainment-stage'),
			'forum-generic' => array('forum-generic', 'forum-community-talk'),
		);
		if ($seed > 0 && isset($variants[$slug]))
		{
			$pool = $variants[$slug];
			$slug = $pool[$seed % count($pool)];
		}

		return $this->phpbb_root_path . 'ext/vpark/glue/styles/all/theme/images/editorial/' . $slug . '.svg';
	}

	protected function forum_image_slug($forum_id, $forum_name)
	{
		$name = mb_strtolower((string) $forum_name);

		if (strpos($name, '军') !== false || strpos($name, '谈兵') !== false || strpos($name, '談兵') !== false || strpos($name, '国际') !== false || strpos($name, '世界') !== false)
		{
			return 'forum-world';
		}
		if (strpos($name, '股') !== false || strpos($name, '经济') !== false || strpos($name, '經濟') !== false || strpos($name, '金融') !== false || strpos($name, '投资') !== false)
		{
			return 'forum-finance';
		}
		if (strpos($name, '电脑') !== false || strpos($name, '電腦') !== false || strpos($name, '数码') !== false || strpos($name, '科技') !== false || strpos($name, '网络') !== false || stripos($name, 'tech') !== false)
		{
			return 'forum-tech';
		}
		if (strpos($name, '历史') !== false || strpos($name, '歷史') !== false)
		{
			return 'forum-history';
		}
		if (strpos($name, '体坛') !== false || strpos($name, '體壇') !== false || strpos($name, '体育') !== false || stripos($name, 'sport') !== false)
		{
			return 'forum-sports';
		}
		if (strpos($name, '娱乐') !== false || strpos($name, '娛樂') !== false || strpos($name, '笑') !== false)
		{
			return 'forum-entertainment';
		}
		if (strpos($name, '生活') !== false || strpos($name, '婚姻') !== false || strpos($name, '家庭') !== false)
		{
			return 'forum-lifestyle';
		}
		if (strpos($name, '文化') !== false || strpos($name, '文学') !== false || strpos($name, '文學') !== false || strpos($name, '长廊') !== false || strpos($name, '長廊') !== false)
		{
			return 'forum-culture';
		}
		if (strpos($name, '百家') !== false || strpos($name, '社区') !== false || strpos($name, '社區') !== false || strpos($name, '论坛') !== false || strpos($name, '論壇') !== false || strpos($name, '新闻') !== false || strpos($name, '新聞') !== false)
		{
			return 'forum-community';
		}

		if (strpos($name, '股') !== false || strpos($name, '经济') !== false || stripos($name, 'finance') !== false)
		{
			return 'forum-finance';
		}
		if (strpos($name, '电脑') !== false || strpos($name, '数码') !== false || strpos($name, '网') !== false || stripos($name, 'tech') !== false)
		{
			return 'forum-tech';
		}
		if (strpos($name, '军') !== false || strpos($name, '谈兵') !== false || stripos($name, 'world') !== false)
		{
			return 'forum-world';
		}
		if (strpos($name, '史') !== false || strpos($name, '历史') !== false)
		{
			return 'forum-history';
		}
		if (strpos($name, '体坛') !== false || strpos($name, '体育') !== false || stripos($name, 'sport') !== false)
		{
			return 'forum-sports';
		}
		if (strpos($name, '娱乐') !== false || strpos($name, '笑') !== false)
		{
			return 'forum-entertainment';
		}
		if (strpos($name, '生活') !== false || strpos($name, '婚姻') !== false || strpos($name, '家庭') !== false)
		{
			return 'forum-lifestyle';
		}
		if (strpos($name, '文化') !== false || strpos($name, '文学') !== false || strpos($name, '长廊') !== false)
		{
			return 'forum-culture';
		}
		if (strpos($name, '百家') !== false || strpos($name, '社区') !== false)
		{
			return 'forum-community';
		}

		return 'forum-generic';
	}

	protected function forum_kicker($forum_name)
	{
		$name = (string) $forum_name;
		if (strpos($name, '股') !== false || strpos($name, '经济') !== false)
		{
			return 'Markets';
		}
		if (strpos($name, '电脑') !== false || strpos($name, '数码') !== false || strpos($name, '网') !== false)
		{
			return 'Technology';
		}
		if (strpos($name, '军') !== false || strpos($name, '谈兵') !== false)
		{
			return 'World';
		}
		if (strpos($name, '史') !== false || strpos($name, '历史') !== false)
		{
			return 'History';
		}
		if (strpos($name, '体坛') !== false || strpos($name, '体育') !== false)
		{
			return 'Sports';
		}
		if (strpos($name, '娱乐') !== false || strpos($name, '笑') !== false)
		{
			return 'Culture';
		}
		if (strpos($name, '生活') !== false || strpos($name, '婚姻') !== false || strpos($name, '家庭') !== false)
		{
			return 'Life';
		}
		if (strpos($name, '文化') !== false || strpos($name, '文学') !== false || strpos($name, '长廊') !== false)
		{
			return 'Ideas';
		}

		return 'Community';
	}

	protected function forum_panel_items()
	{
		$cache_key = '_vpark_forum_panel';
		if ($this->cache !== null)
		{
			$cached = $this->cache->get($cache_key);
			if ($cached !== false)
			{
				return $cached;
			}
		}

		$sql = 'SELECT forum_id, forum_name, forum_desc, forum_topics_approved, left_id
			FROM ' . FORUMS_TABLE . '
			WHERE display_on_index = 1
				AND forum_status = 0
				AND forum_type = ' . FORUM_POST . '
			ORDER BY left_id ASC';
		$result = $this->db->sql_query($sql);

		$items = array();
		while ($row = $this->db->sql_fetchrow($result))
		{
			$desc = preg_replace('/\[\/?\w+(?::[a-z0-9]+)?\]/', '', (string) $row['forum_desc']);
			$desc = trim(strip_tags($desc));
			if (mb_strlen($desc) > 60)
			{
				$desc = mb_substr($desc, 0, 57) . '...';
			}

			$items[] = array(
				'title'    => (string) $row['forum_name'],
				'subtitle' => $desc,
				'metric'   => '',
				'forum_id' => (int) $row['forum_id'],
			);
		}
		$this->db->sql_freeresult($result);

		if ($this->cache !== null)
		{
			$this->cache->put($cache_key, $items, 300);
		}

		return $items;
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
