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
		\phpbb\config\config $config,
		\phpbb\user $user,
		$request = null,
		$phpbb_root_path = '',
		$php_ext = ''
	)
	{
		$this->template = $template;
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
		));
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
