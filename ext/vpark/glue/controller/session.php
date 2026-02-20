<?php
/**
 *
 * VictoriaPark glue extension for phpBB.
 *
 */

namespace vpark\glue\controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class session
{
	/** @var \phpbb\user */
	protected $user;

	/** @var \phpbb\config\config */
	protected $config;

	/** @var \phpbb\request\request_interface|null */
	protected $request;

	public function __construct(
		\phpbb\user $user,
		\phpbb\config\config $config,
		$request = null
	)
	{
		$this->user = $user;
		$this->config = $config;
		$this->request = ($request instanceof \phpbb\request\request_interface) ? $request : null;
	}

	public function validate(Request $request)
	{
		$allowed_origin = $this->resolve_allowed_origin($request);
		$is_preflight = strtoupper($request->getMethod()) === 'OPTIONS';

		if ($is_preflight)
		{
			$response = new JsonResponse(array(), 204);
			return $this->with_common_headers($response, $allowed_origin, true);
		}

		$is_authenticated = (int) $this->user->data['user_id'] !== ANONYMOUS;
		$payload = array(
			'authenticated' => $is_authenticated,
			'user_id' => $is_authenticated ? (int) $this->user->data['user_id'] : 0,
			'username' => $is_authenticated ? (string) $this->user->data['username'] : '',
			'user_lang' => $this->effective_lang($is_authenticated),
		);

		$response = new JsonResponse($payload, 200);
		return $this->with_common_headers($response, $allowed_origin, false);
	}

	protected function with_common_headers(JsonResponse $response, $allowed_origin, $is_preflight)
	{
		if ($allowed_origin !== '')
		{
			$response->headers->set('Access-Control-Allow-Origin', $allowed_origin);
			$response->headers->set('Access-Control-Allow-Credentials', 'true');
			$response->headers->set('Vary', 'Origin');
		}

		if ($is_preflight)
		{
			$response->headers->set('Access-Control-Allow-Methods', 'GET, OPTIONS');
			$response->headers->set('Access-Control-Allow-Headers', 'Content-Type');
		}

		$response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
		$response->headers->set('Pragma', 'no-cache');

		return $response;
	}

	protected function resolve_allowed_origin(Request $request)
	{
		$configured_origin = trim((string) getenv('VPARK_SSO_ALLOWED_ORIGIN'));
		if ($configured_origin !== '')
		{
			return rtrim($configured_origin, '/');
		}

		$request_origin = trim((string) $request->headers->get('Origin', ''));
		if ($request_origin === '')
		{
			return '';
		}

		$portal_origin = $this->portal_origin();
		if ($portal_origin === '')
		{
			return '';
		}

		return strcasecmp(rtrim($request_origin, '/'), $portal_origin) === 0 ? $request_origin : '';
	}

	protected function portal_origin()
	{
		$portal_url = trim((string) getenv('VPARK_PORTAL_URL'));
		if ($portal_url === '')
		{
			$portal_url = 'https://victoriapark.io';
		}

		$parts = parse_url($portal_url);
		if (!$parts || empty($parts['scheme']) || empty($parts['host']))
		{
			return '';
		}

		$origin = $parts['scheme'] . '://' . $parts['host'];
		if (!empty($parts['port']))
		{
			$origin .= ':' . $parts['port'];
		}

		return $origin;
	}

	protected function effective_lang($is_authenticated)
	{
		$lang = '';

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

		if ($lang === '' && $is_authenticated && !empty($this->user->data['user_lang']))
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
