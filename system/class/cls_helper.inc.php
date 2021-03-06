<?php
/*
+--------------------------------------------------------------------------
|   WeCenter [#RELEASE_VERSION#]
|   ========================================
|   by WeCenter Software
|   © 2011 - 2014 WeCenter. All Rights Reserved
|   http://www.wecenter.com
|   ========================================
|   Support: WeCenter@qq.com
|
+---------------------------------------------------------------------------
*/

class H
{
	public static function get_file_ext($file_name, $merge_type = true)
	{
		$file_ext = end(explode('.', $file_name));

		if ($merge_type)
		{
			if ($file_ext == 'jpeg' or $file_ext == 'jpe')
			{
				$file_ext = 'jpg';
			}

			if ($file_ext == 'htm')
			{
				$file_ext = 'html';
			}
		}

		return $file_ext;
	}

	/**
	 * 数组JSON返回
	 *
	 * @param  $array
	 */
	public static function ajax_json_output($array)
	{
		//HTTP::no_cache_header('text/javascript');

		echo str_replace(array("\r", "\n", "\t"), '', json_encode($array));
		exit;
	}

	public static function redirect_msg($message, $url = NULL, $interval = 5, $exit = true)
	{
		TPL::assign('message', $message);
		TPL::assign('url_bit', HTTP::parse_redirect_url($url));
		TPL::assign('interval', $interval);

		TPL::output('global/show_message');

		if ($exit)
		{
			die;
		}
	}

	/** 生成 Options **/
	public static function display_options($param, $default = '_DEFAULT_', $default_key = 'key')
	{
		if (is_array($param))
		{
			$keyindex = 0;

			foreach ($param as $key => $value)
			{
				if ($default_key == 'value')
				{
					$output .= '<option value="' . $key . '"' . (($value == $default) ? '  selected' : '') . '>' . $value . '</option>';
				}
				else
				{
					$output .= '<option value="' . $key . '"' . (($key == $default) ? '  selected' : '') . '>' . $value . '</option>';
				}
			}

		}

		return $output;
	}

	/**
	 * 敏感词替换
	 * @param unknown_type $content
	 * @return mixed
	 */
	public static function sensitive_words_replace($content)
	{
		if (!$content)
		{
			return $content;
		}

		if (!$sensitive_words_replacement = get_setting('sensitive_words_replacement'))
		{
			return $content;
		}

		if (!$sensitive_words = get_setting('sensitive_words'))
		{
			return $content;
		}

		$sensitive_words = explode("\n", $sensitive_words);

		foreach($sensitive_words as $word)
		{
			$word = trim($word);

			if (!$word)
			{
				continue;
			}

			if (substr($word, 0, 1) == '{' AND substr($word, -1, 1) == '}')
			{
				$regex[] = substr($word, 1, -1);
			}
			else
			{
				$content = str_ireplace($word, $sensitive_words_replacement, $content);
			}
		}

		if (isset($regex))
		{
			preg_replace($regex, $sensitive_words_replacement, $content);
		}

		return $content;
	}

	/**
	 * 是否包含敏感词
	 * @param string $content
	 * @return mixed
	 */
	public static function sensitive_word_exists($content)
	{
		if (!$content)
		{
			return false;
		}

		if (!$sensitive_words = get_setting('sensitive_words'))
		{
			return false;
		}

		$sensitive_words = explode("\n", $sensitive_words);

		foreach($sensitive_words as $word)
		{
			$word = trim($word);

			if (!$word)
			{
				continue;
			}

			if (substr($word, 0, 1) == '{' AND substr($word, -1, 1) == '}')
			{
				if (preg_match(substr($word, 1, -1), $content))
				{
					return true;
				}
			}
			else
			{
				if (stripos($content, $word) !== false)
				{
					return true;
				}
			}
		}

		return false;
	}


	public static function content_url_whitelist_check($url)
	{
		if (!$url or !get_setting('content_url_whitelist'))
		{
			return false;
		}

		$whitelist = explode("\n", get_setting('content_url_whitelist'));

		foreach($whitelist as $word)
		{
			$word = trim($word);

			if (!$word)
			{
				continue;
			}

			if (substr($word, 0, 1) == '{' AND substr($word, -1, 1) == '}')
			{
				if (preg_match(substr($word, 1, -1), $url))
				{
					return true;
				}
			}
			else
			{
				if (stripos($url, $word) === 0)
				{
					return true;
				}
			}
		}

		return false;
	}

}
