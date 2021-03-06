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


if (!defined('IN_ANWSION'))
{
	die;
}

class question_class extends AWS_MODEL
{

	/**
	 * 记录日志
	 * @param int $item_id 问题id
	 * @param string $type QUESTION|QUESTION_COMMENT|ANSWER|ANSWER_COMMENT
	 * @param string $note
	 * @param int $uid
	 * @param int $anonymous
	 * @param int $child_id 回复/评论id
	 */
	public function log($item_id, $type, $note, $uid = 0, $anonymous = 0, $child_id = 0)
	{
		$this->insert('question_log', array(
			'item_id' => intval($item_id),
			'type' => $type,
			'note' => $note,
			'uid' => intval($uid),
			'anonymous' => intval($anonymous),
			'child_id' => intval($child_id),
			'time' => fake_time()
		));
	}

	/**
	 *
	 * 根据 item_id, 得到日志列表
	 *
	 * @param int     $item_id
	 * @param int     $limit
	 *
	 * @return array
	 */
	public function list_logs($item_id, $limit = 20)
	{
		$log_list = $this->fetch_all('question_log', 'item_id = ' . intval($item_id), 'id DESC', $limit);
		if (!$log_list)
		{
			return false;
		}

		foreach ($log_list AS $key => $log)
		{
			if (!$log['anonymous'])
			{
				$user_ids[] = $log['uid'];
			}
		}

		if ($user_ids)
		{
			$users = $this->model('account')->get_user_info_by_uids($user_ids);
		}
		else
		{
			$users = array();
		}

		foreach ($log_list as $key => $log)
		{
			$log_list[$key]['user_info'] = $users[$log['uid']];
		}

		return $log_list;
	}

	public function get_focus_uid_by_question_id($question_id)
	{
		return $this->query_all('SELECT uid FROM ' . $this->get_table('question_focus') . ' WHERE question_id = ' . intval($question_id));
	}

	public function get_question_info_by_id($question_id)
	{
		if (! $question_id)
		{
			return false;
		}

		static $questions;

		if ($questions[$question_id])
		{
			return $questions[$question_id];
		}

		if ($question = $this->fetch_row('question', 'question_id = ' . intval($question_id)))
		{
			if (-$question['agree_count'] >= get_setting('question_downvote_fold'))
			{
				$question['fold'] = 1;
			}

			$questions[$question_id] = $question;
		}

		return $questions[$question_id];
	}

	public function get_question_info_by_ids($question_ids)
	{
		if (!$question_ids)
		{
			return false;
		}

		array_walk_recursive($question_ids, 'intval_string');

		if ($questions_list = $this->fetch_all('question', "question_id IN(" . implode(',', $question_ids) . ")"))
		{
			$question_downvote_fold = get_setting('question_downvote_fold');
			foreach ($questions_list AS $key => $val)
			{
				if (-$val['agree_count'] >= $question_downvote_fold)
				{
					$val['fold'] = 1;
				}

				$result[$val['question_id']] = $val;
			}
		}

		return $result;
	}

	/**
	 * 增加问题浏览次数记录
	 * @param int $question_id
	 */
	public function update_views($question_id)
	{
		if (AWS_APP::cache()->get('update_views_question_' . md5(session_id()) . '_' . intval($question_id)))
		{
			return false;
		}

		AWS_APP::cache()->set('update_views_question_' . md5(session_id()) . '_' . intval($question_id), time(), 60);

		$this->shutdown_query("UPDATE " . $this->get_table('question') . " SET view_count = view_count + 1 WHERE question_id = " . intval($question_id));

		return true;
	}

	/**
	 *
	 * 增加问题内容
	 * @param string $question_content //问题内容
	 * @param string $question_detail  //问题说明
	 *
	 * @return boolean true|false
	 */
	public function save_question($question_content, $question_detail, $published_uid, $anonymous = 0)
	{
		$now = fake_time();

		$to_save_question = array(
			'question_content' => htmlspecialchars($question_content),
			'question_detail' => htmlspecialchars($question_detail),
			'add_time' => $now,
			'update_time' => $now,
			'published_uid' => intval($published_uid),
			'anonymous' => intval($anonymous)
		);

		$question_id = $this->insert('question', $to_save_question);

		if ($question_id)
		{
			$this->shutdown_update('users', array(
				'question_count' => $this->count('question', 'published_uid = ' . intval($published_uid))
			), 'uid = ' . intval($published_uid));

			$this->model('search_fulltext')->push_index('question', $question_content, $question_id);
		}

		return $question_id;
	}

	public function update_question($question_id, $question_content, $question_detail, $uid, $anonymous = null, $category_id = null)
	{
		if (!$question_info = $this->get_question_info_by_id($question_id) OR !$uid)
		{
			return false;
		}

		if ($question_content)
		{
			$question_content = htmlspecialchars($question_content);
		}

		if ($question_detail)
		{
			$question_detail = htmlspecialchars($question_detail);
		}

		$data = array(
			'question_content' => $question_content,
			'question_detail' => $question_detail
		);

		$this->model('search_fulltext')->push_index('question', $question_content, $question_id);

		$this->update('question', $data, 'question_id = ' . intval($question_id));

		if ($uid == $question_info['published_uid'])
		{
			$is_anonymous =  $question_info['anonymous'];
		}
		$this->model('question')->log($question_id, 'QUESTION', '编辑问题', $uid, $is_anonymous);

		// TODO: 修改分类
		//$this->model('posts')->set_posts_index($question_id, 'question');

		return true;
	}

	public function remove_question($question_id)
	{
		if (!$question_info = $this->get_question_info_by_id($question_id))
		{
			return false;
		}

		$this->model('answer')->remove_answers_by_question_id($question_id); // 删除关联的回复内容

		$this->delete('question_log', 'item_id = ' . intval($question_id));

		$this->delete('question_vote', "question_id = " . intval($question_id)); // 删除赞同

		$this->delete('question_comments', 'question_id = ' . intval($question_id)); // 删除评论

		$this->delete('question_focus', 'question_id = ' . intval($question_id));

		$this->delete('question_thanks', 'question_id = ' . intval($question_id));

		$this->delete('topic_relation', "`type` = 'question' AND item_id = " . intval($question_id));		// 删除话题关联

		$this->delete('question_invite', 'question_id = ' . intval($question_id));	// 删除邀请记录

		ACTION_LOG::delete_action_history('associate_type = ' . ACTION_LOG::CATEGORY_QUESTION .  ' AND associate_id = ' . intval($question_id));	// 删除动作

		ACTION_LOG::delete_action_history('associate_type = ' . ACTION_LOG::CATEGORY_QUESTION .  ' AND associate_action = ' . ACTION_LOG::ANSWER_QUESTION . ' AND associate_attached = ' . intval($question_id));	// 删除动作

		$this->model('notify')->delete_notify('model_type = 1 AND source_id = ' . intval($question_id));	// 删除相关的通知

		$this->shutdown_update('users', array(
			'question_count' => $this->count('question', 'published_uid = ' . intval($question_info['published_uid']))
		), 'uid = ' . intval($question_info['published_uid']));

		$this->delete('redirect', "item_id = " . intval($question_id) . " OR target_id = " . intval($question_id));

		$this->model('posts')->remove_posts_index($question_id, 'question');

		$this->delete('question', 'question_id = ' . intval($question_id));

	}

	public function add_focus_question($question_id, $uid, $anonymous = 0, $save_action = true)
	{
		if (!$question_id OR !$uid)
		{
			return false;
		}

		if (! $this->has_focus_question($question_id, $uid))
		{
			if ($this->insert('question_focus', array(
				'question_id' => intval($question_id),
				'uid' => intval($uid),
				'add_time' => fake_time()
			)))
			{
				$this->update_focus_count($question_id);
			}

			// 记录日志
			/*if ($save_action)
			{
				ACTION_LOG::save_action($uid, $question_id, ACTION_LOG::CATEGORY_QUESTION, ACTION_LOG::ADD_REQUESTION_FOCUS, '', '', null, intval($anonymous));
			}*/

			return 'add';
		}
		else
		{
			// 减少问题关注数量
			if ($this->delete_focus_question($question_id, $uid))
			{
				$this->update_focus_count($question_id);
			}

			return 'remove';
		}
	}

	/**
	 *
	 * 取消问题关注
	 * @param int $question_id
	 *
	 * @return boolean true|false
	 */
	public function delete_focus_question($question_id, $uid)
	{
		if (!$question_id OR !$uid)
		{
			return false;
		}

		//ACTION_LOG::delete_action_history('associate_type = ' . ACTION_LOG::CATEGORY_QUESTION . ' AND associate_action = ' . ACTION_LOG::ADD_REQUESTION_FOCUS . ' AND uid = ' . intval($uid) . ' AND associate_id = ' . intval($question_id));

		return $this->delete('question_focus', 'question_id = ' . intval($question_id) . " AND uid = " . intval($uid));
	}

	public function get_focus_question_ids_by_uid($uid)
	{
		if (!$uid)
		{
			return false;
		}

		if (!$question_focus = $this->fetch_all('question_focus', "uid = " . intval($uid)))
		{
			return false;
		}

		foreach ($question_focus as $key => $val)
		{
			$question_ids[$val['question_id']] = $val['question_id'];
		}

		return $question_ids;
	}

	/**
	 *
	 * 判断是否已经关注问题
	 * @param int $question_id
	 * @param int $uid
	 *
	 * @return boolean true|false
	 */
	public function has_focus_question($question_id, $uid)
	{
		if (!$uid OR !$question_id)
		{
			return false;
		}

		return $this->fetch_one('question_focus', 'focus_id', 'question_id = ' . intval($question_id) . " AND uid = " . intval($uid));
	}

	public function has_focus_questions($question_ids, $uid)
	{
		if (!$uid OR !is_array($question_ids) OR sizeof($question_ids) < 1)
		{
			return array();
		}

		$question_focus = $this->fetch_all('question_focus', "question_id IN(" . implode(',', $question_ids) . ") AND uid = " . intval($uid));

		if ($question_focus)
		{
			foreach ($question_focus AS $key => $val)
			{
				$result[$val['question_id']] = $val['focus_id'];
			}

			return $result;
		}
		else
		{
			return array();
		}
	}

	public function get_focus_users_by_question($question_id, $limit = 10)
	{
		if ($uids = $this->query_all('SELECT DISTINCT uid FROM ' . $this->get_table('question_focus') . ' WHERE question_id = ' . intval($question_id) . ' ORDER BY focus_id DESC', intval($limit)))
		{
			$users_list = $this->model('account')->get_user_info_by_uids(fetch_array_value($uids, 'uid'));
		}

		return $users_list;
	}

	public function get_user_focus($uid, $limit = 10)
	{
		if ($question_focus = $this->fetch_all('question_focus', "uid = " . intval($uid), 'question_id DESC', $limit))
		{
			foreach ($question_focus as $key => $val)
			{
				$question_ids[] = $val['question_id'];
			}
		}

		if ($question_ids)
		{
			return $this->fetch_all('question', "question_id IN(" . implode(',', $question_ids) . ")", 'add_time DESC');
		}
	}

	public function update_answer_count($question_id)
	{
		if (!$question_id)
		{
			return false;
		}

		return $this->update('question', array(
			'answer_count' => $this->count('answer', 'question_id = ' . intval($question_id))
		), 'question_id = ' . intval($question_id));
	}

	public function update_focus_count($question_id)
	{
		if (!$question_id)
		{
			return false;
		}

		return $this->update('question', array(
			'focus_count' => $this->count('question_focus', 'question_id = ' . intval($question_id))
		), 'question_id = ' . intval($question_id));
	}

	public function get_related_question_list($question_id, $question_content, $limit = 10)
	{
		$cache_key = 'question_related_list_' . md5($question_content) . '_' . $limit;

		if ($question_related_list = AWS_APP::cache()->get($cache_key))
		{
			return $question_related_list;
		}

		if ($question_keywords = $this->model('system')->analysis_keyword($question_content))
		{
			if (sizeof($question_keywords) <= 1)
			{
				return false;
			}

			if ($question_list = $this->query_all($this->model('search_fulltext')->bulid_query('question', 'question_content', $question_keywords), 2000))
			{
				$question_list = aasort($question_list, 'score', 'DESC');
				$question_list = aasort($question_list, 'agree_count', 'DESC');

				$question_list = array_slice($question_list, 0, ($limit + 1));

				foreach ($question_list as $key => $val)
				{
					if ($val['question_id'] == $question_id)
					{
						unset($question_list[$key]);
					}
					else
					{
						if (! isset($question_related[$val['question_id']]))
						{
							$question_related[$val['question_id']] = $val['question_content'];

							$question_info[$val['question_id']] = $val;
						}
					}
				}
			}
		}

		if ($question_related)
		{
			foreach ($question_related as $key => $question_content)
			{
				$question_related_list[] = array(
					'question_id' => $key,
					'question_content' => $question_content,
					'answer_count' => $question_info[$key]['answer_count']
				);
			}
		}

		if (sizeof($question_related) > $limit)
		{
			array_pop($question_related);
		}

		AWS_APP::cache()->set($cache_key, $question_related_list, get_setting('cache_level_low'));

		return $question_related_list;
	}

	public function add_invite($question_id, $sender_uid, $recipients_uid = 0)
	{
		if (!$question_id OR !$sender_uid)
		{
			return false;
		}

		if (!$recipients_uid)
		{
			return false;
		}

		$data = array(
			'question_id' => intval($question_id),
			'sender_uid' => intval($sender_uid),
			'add_time' => fake_time(),
		);

		if ($recipients_uid)
		{
			$data['recipients_uid'] = intval($recipients_uid);
		}

		return $this->insert('question_invite', $data);
	}

	/**
	 * 发起者取消邀请
	 * @param unknown_type $question_id
	 * @param unknown_type $sender_uid
	 * @param unknown_type $recipients_uid
	 */
	public function cancel_question_invite($question_id, $sender_uid, $recipients_uid)
	{
		return $this->delete('question_invite', 'question_id = ' . intval($question_id) . ' AND sender_uid = ' . intval($sender_uid) . ' AND recipients_uid = ' . intval($recipients_uid));
	}

	/**
	 * 接收者删除邀请
	 * @param unknown_type $question_invite_id
	 * @param unknown_type $recipients_uid
	 */
	public function delete_question_invite($question_invite_id, $recipients_uid)
	{
		return $this->delete('question_invite', 'question_invite_id = ' . intval($question_invite_id) . ' AND recipients_uid = ' . intval($recipients_uid));
	}

	/**
	 * 接收者回复邀请的问题
	 * @param unknown_type $question_invite_id
	 * @param unknown_type $recipients_uid
	 */
	public function answer_question_invite($question_id, $recipients_uid)
	{
		if (!$question_info = $this->model('question')->get_question_info_by_id($question_id))
		{
			return false;
		}

		if ($question_invites = $this->fetch_row('question_invite', 'question_id = ' . intval($question_id) . ' AND sender_uid = ' . $question_info['published_uid'] . ' AND recipients_uid = ' . intval($recipients_uid)))
		{
			$this->model('currency')->process($question_info['published_uid'], 'INVITE_ANSWER', get_setting('currency_system_config_invite_answer'), '邀请回答成功 #' . $question_id, $question_id);

			$this->model('currency')->process($recipients_uid, 'ANSWER_INVITE', -get_setting('currency_system_config_invite_answer'), '回复邀请回答 #' . $question_id, $question_id);
		}

		$this->delete('question_invite', 'question_id = ' . intval($question_id) . ' AND recipients_uid = ' . intval($recipients_uid));

		$this->model('account')->update_question_invite_count($recipients_uid);
	}

	public function has_question_invite($question_id, $recipients_uid, $sender_uid = null)
	{
		if (!$sender_uid)
		{
			return $this->fetch_one('question_invite', 'question_invite_id', 'question_id = ' . intval($question_id) . ' AND recipients_uid = ' . intval($recipients_uid));
		}
		else
		{
			return $this->fetch_one('question_invite',  'question_invite_id', 'question_id = ' . intval($question_id) . ' AND sender_uid = ' . intval($sender_uid) . ' AND recipients_uid = ' . intval($recipients_uid));
		}
	}

	public function get_invite_users($question_id, $limit = 10)
	{
		if ($invite_users_list = AWS_APP::cache()->get('question_invite_users_' . $question_id))
		{
			return $invite_users_list;
		}
		
		if ($invites = $this->fetch_all('question_invite', 'question_id = ' . intval($question_id), 'question_invite_id DESC', $limit))
		{
			foreach ($invites as $key => $val)
			{
				$invite_users[$val['recipients_uid']] = $val['recipients_uid'];
			}

			$invite_users_list = $this->model('account')->get_user_info_by_uids($invite_users);
			
			AWS_APP::cache()->set('question_invite_users_' . $question_id, $invite_users_list, get_setting('cache_level_normal'));
		}
		
		return $invite_users_list;
	}

	public function get_invite_question_list($uid, $limit = 10)
	{
		if ($list = $this->fetch_all('question_invite', 'recipients_uid = ' . intval($uid), 'question_invite_id DESC', $limit))
		{
			foreach ($list as $key => $val)
			{
				$question_ids[] = $val['question_id'];
			}

			$question_infos = $this->get_question_info_by_ids($question_ids);

			foreach ($list as $key => $val)
			{
				$list[$key]['question_info'] = $question_infos[$val['question_id']];
			}

			return $list;
		}
	}

	public function parse_at_user($content, $popup = false, $with_user = false, $to_uid = false)
	{
		preg_match_all('/@([^@,:\s,]+)/i', strip_tags($content), $matchs);

		if (is_array($matchs[1]))
		{
			$match_name = array();

			foreach ($matchs[1] as $key => $user_name)
			{
				if (in_array($user_name, $match_name))
				{
					continue;
				}

				$match_name[] = $user_name;
			}

			$match_name = array_unique($match_name);

			arsort($match_name);

			$all_users = array();

			$content_uid = $content;

			foreach ($match_name as $key => $user_name)
			{
				if (preg_match('/^[0-9]+$/', $user_name))
				{
					$user_info = $this->model('account')->get_user_info_by_uid($user_name);
				}
				else
				{
					$user_info = $this->model('account')->get_user_info_by_username($user_name);
				}

				if ($user_info)
				{
					$content = str_replace('@' . $user_name, '<a href="people/' . $user_info['url_token'] . '"' . (($popup) ? ' target="_blank"' : '') . ' class="aw-user-name" data-id="' . $user_info['uid'] . '">@' . $user_info['user_name'] . '</a>', $content);

					if ($to_uid)
					{
						$content_uid = str_replace('@' . $user_name, '@' . $user_info['uid'], $content_uid);
					}

					if ($with_user)
					{
						$all_users[] = $user_info['uid'];
					}
				}
			}
		}

		if ($with_user)
		{
			return $all_users;
		}

		if ($to_uid)
		{
			return $content_uid;
		}

		return $content;
	}

	public function update_question_comments_count($question_id)
	{
		$count = $this->count('question_comments', 'question_id = ' . intval($question_id));

		$this->shutdown_update('question', array(
			'comment_count' => $count
		), 'question_id = ' . intval($question_id));
	}

	public function set_recommend($question_id)
	{
		$this->update('question', array(
			'is_recommend' => 1
		), 'question_id = ' . intval($question_id));

		$this->update('posts_index', array(
			'is_recommend' => 1
		), "post_id = " . intval($question_id) . " AND post_type = 'question'" );
	}

	public function unset_recommend($question_id)
	{
		$this->update('question', array(
			'is_recommend' => 0
		), 'question_id = ' . intval($question_id));

		$this->update('posts_index', array(
			'is_recommend' => 0
		), "post_id = " . intval($question_id) . " AND post_type = 'question'" );
	}

	public function insert_question_comment($question_id, $uid, $message, $anonymous = 0)
	{
		if (!$question_info = $this->model('question')->get_question_info_by_id($question_id))
		{
			return false;
		}

		$message = $this->model('question')->parse_at_user($message, false, false, true);

		$comment_id = $this->insert('question_comments', array(
			'uid' => intval($uid),
			'question_id' => intval($question_id),
			'message' => htmlspecialchars($message),
			'anonymous' => intval($anonymous),
			'time' => fake_time()
		));

		if ($question_info['published_uid'] != $uid)
		{
			$this->model('notify')->send($uid, $question_info['published_uid'], notify_class::TYPE_QUESTION_COMMENT, notify_class::CATEGORY_QUESTION, $question_info['question_id'], array(
				'from_uid' => $uid,
				'question_id' => $question_info['question_id'],
				'comment_id' => $comment_id,
				'anonymous' => intval($anonymous)
			));

		}

		if ($at_users = $this->model('question')->parse_at_user($message, false, true))
		{
			foreach ($at_users as $user_id)
			{
				if ($user_id == $question_info['published_uid'])
				{
					continue;
				}

				$this->model('notify')->send($uid, $user_id, notify_class::TYPE_QUESTION_COMMENT_AT_ME, notify_class::CATEGORY_QUESTION, $question_info['question_id'], array(
					'from_uid' => $uid,
					'question_id' => $question_info['question_id'],
					'comment_id' => $comment_id,
					'anonymous' => intval($anonymous)
				));

			}
		}

		$this->update_question_comments_count($question_id);

		return $comment_id;
	}

	public function get_question_comments($question_id)
	{
		return $this->fetch_all('question_comments', 'question_id = ' . intval($question_id), "id ASC");
	}

	public function get_comment_by_id($comment_id)
	{
		return $this->fetch_row('question_comments', "id = " . intval($comment_id));
	}

	/*public function remove_comment($comment_id)
	{
		//return $this->delete('question_comments', "id = " . intval($comment_id));
	}*/

	// 只清空不删除
	public function remove_question_comment($comment, $uid)
	{
		$this->update('question_comments', array(
			'message' => null
		), "id = " . $comment['id']);

		if ($uid == $comment['uid'])
		{
			$is_anonymous =  $comment['anonymous'];
		}
		$this->model('question')->log($comment['question_id'], 'QUESTION_COMMENT', '删除评论', $uid, $is_anonymous, $comment['id']);

		return true;
	}

	public function remove_answer_comment($comment, $uid)
	{
		$this->update('answer_comments', array(
			'message' => null
		), "id = " . $comment['id']);

		if ($uid == $comment['uid'])
		{
			$is_anonymous =  $comment['anonymous'];
		}

		if ($answer = $this->fetch_row('answer', 'answer_id = ' . intval($comment['answer_id'])))
		{
			$this->model('question')->log($answer['question_id'], 'ANSWER_COMMENT', '删除回复评论', $uid, $is_anonymous, $comment['id']);
		}

		return true;
	}

	public function redirect($uid, $item_id, $target_id = NULL)
	{
		if ($item_id == $target_id)
		{
			return false;
		}

		if (! $target_id)
		{
			if ($this->delete('redirect', 'item_id = ' . intval($item_id)))
			{
				return; //ACTION_LOG::save_action($uid, $item_id, ACTION_LOG::CATEGORY_QUESTION, ACTION_LOG::DEL_REDIRECT_QUESTION);
			}
		}
		else if ($question = $this->get_question_info_by_id($item_id))
		{
			if (! $this->fetch_row('redirect', 'item_id = ' . intval($item_id) . ' AND target_id = ' . intval($target_id)))
			{
				$redirect_id = $this->insert('redirect', array(
					'item_id' => intval($item_id),
					'target_id' => intval($target_id),
					'time' => fake_time(),
					'uid' => intval($uid)
				));

				//ACTION_LOG::save_action($uid, $item_id, ACTION_LOG::CATEGORY_QUESTION, ACTION_LOG::REDIRECT_QUESTION, $question['question_content'], $target_id);

				return $redirect_id;
			}
		}
	}

	public function get_redirect($item_id)
	{
		return $this->fetch_row('redirect', 'item_id = ' . intval($item_id));
	}

	public function save_last_answer($question_id, $answer_id = null)
	{
		if (!$answer_id)
		{
			if ($last_answer = $this->fetch_row('answer', 'question_id = ' . intval($question_id), 'answer_id DESC')) // 'add_time DESC'
			{
				$answer_id = $last_answer['answer_id'];
			}
		}

		return $this->shutdown_update('question', array('last_answer' => intval($answer_id)), 'question_id = ' . intval($question_id));
	}

	/**
	 *
	 * 根据问题ID,得到相关联的话题标题信息
	 * @param int $question_id
	 * @param string $limit
	 *
	 * @return array
	 */
	public function get_question_topic_by_questions($question_ids, $limit = null)
	{
		if (!is_array($question_ids) OR sizeof($question_ids) == 0)
		{
			return false;
		}

		if (!$topic_ids_query = $this->query_all("SELECT DISTINCT topic_id FROM " . $this->get_table('topic_relation') . " WHERE item_id IN(" . implode(',', $question_ids) . ") AND `type` = 'question'"))
		{
			return false;
		}

		foreach ($topic_ids_query AS $key => $val)
		{
			$topic_ids[] = $val['topic_id'];
		}

		if ($topic_list = $this->query_all("SELECT * FROM " . $this->get_table('topic') . " WHERE topic_id IN(" . implode(',', $topic_ids) . ") ORDER BY discuss_count DESC", $limit))
		{
			foreach ($topic_list AS $key => $val)
			{
				if (!$val['url_token'])
				{
					$topic_list[$key]['url_token'] = urlencode($val['topic_title']);
				}
			}
		}

		return $topic_list;
	}

	public function get_topic_info_by_question_ids($question_ids)
	{
		if (!is_array($question_ids))
		{
			return false;
		}

		if ($topic_relation = $this->fetch_all('topic_relation', 'item_id IN(' . implode(',', $question_ids) . ") AND `type` = 'question'"))
		{
			foreach ($topic_relation AS $key => $val)
			{
				$topic_ids[$val['topic_id']] = $val['topic_id'];
			}

			$topics_info = $this->model('topic')->get_topics_by_ids($topic_ids);

			foreach ($topic_relation AS $key => $val)
			{
				$topics_by_questions_ids[$val['item_id']][] = array(
					'topic_id' => $val['topic_id'],
					'topic_title' => $topics_info[$val['topic_id']]['topic_title'],
					'url_token' => $topics_info[$val['topic_id']]['url_token'],
				);
			}
		}

		return $topics_by_questions_ids;
	}

	public function lock_question($question_id, $lock_status = true, $uid = 0)
	{
		$lock_status = intval($lock_status);
		$this->update('question', array(
			'lock' => $lock_status
		), 'question_id = ' . intval($question_id));

		if ($lock_status)
		{
			$this->model('question')->log($question_id, 'QUESTION', '锁定问题', $uid);
		}
		else
		{
			$this->model('question')->log($question_id, 'QUESTION', '解除锁定', $uid);
		}

		return true;
	}

	public function auto_lock_question()
	{
		if (!get_setting('auto_question_lock_day'))
		{
			return false;
		}

		return $this->shutdown_update('question', array(
			'lock' => 1
		), '`lock` = 0 AND `update_time` < ' . (time() - 3600 * 24 * get_setting('auto_question_lock_day')));
	}

	public function get_question_thanks($question_id, $uid)
	{
		if (!$question_id OR !$uid)
		{
			return false;
		}

		return $this->fetch_row('question_thanks', 'question_id = ' . intval($question_id) . " AND uid = " . intval($uid));
	}

	public function question_thanks($question_id, $uid)
	{
		if (!$question_id OR !$uid)
		{
			return false;
		}

		if (!$question_info = $this->get_question_info_by_id($question_id))
		{
			return false;
		}

		if ($question_thanks = $this->get_question_thanks($question_id, $uid))
		{
			//$this->delete('question_thanks', "id = " . $question_thanks['id']);

			return false;
		}
		else
		{
			$this->insert('question_thanks', array(
				'question_id' => $question_id,
				'uid' => $uid,
				'time' => fake_time()
			));

			$this->query('UPDATE ' . $this->get_table('question') . ' SET thanks_count = thanks_count + 1 WHERE question_id = ' . intval($question_id));

			$this->model('currency')->process($uid, 'QUESTION_THANKS', get_setting('currency_system_config_thanks'), '感谢问题 #' . $question_id, $question_id);

			$this->model('currency')->process($question_info['published_uid'], 'THANKS_QUESTION', -get_setting('currency_system_config_thanks'), '问题被感谢 #' . $question_id, $question_id);

			$this->model('account')->update_thanks_count($question_info['published_uid']);

			return true;
		}
	}

	public function get_related_topics($question_content)
	{
		if ($question_related_list = $this->get_related_question_list(null, $question_content, 10))
		{
			foreach ($question_related_list AS $key => $val)
			{
				$question_related_ids[$val['question_id']] = $val['question_id'];
			}

			if (!$topic_ids_query = $this->fetch_all('topic_relation', 'item_id IN(' . implode(',', $question_related_ids) . ") AND `type` = 'question'"))
			{
				return false;
			}

			foreach ($topic_ids_query AS $key => $val)
			{
				if ($val['merged_id'])
				{
					continue;
				}

				$topic_hits[$val['topic_id']] = intval($topic_hits[$val['topic_id']]) + 1;
			}

			if (!$topic_hits)
			{
				return false;
			}

			arsort($topic_hits);

			$topic_hits = array_slice($topic_hits, 0, 3, true);

			foreach ($topic_hits AS $topic_id => $hits)
			{
				if ($topic_info = $this->model('topic')->get_topic_by_id($topic_id))
				{
					$topics[$topic_info['topic_title']] = $topic_info['topic_title'];
				}
			}
		}

		return $topics;
	}

	public function get_answer_users_by_question_id($question_id, $limit = 5, $published_uid = null)
	{
		if ($result = AWS_APP::cache()->get('answer_users_by_question_id_' . md5($question_id . $limit . $published_uid)))
		{
			return $result;
		}

		if (!$published_uid)
		{
			if (!$question_info = $this->get_question_info_by_id($question_id))
			{
				return false;
			}

			$published_uid = $question_info['published_uid'];
		}

		if ($answer_users = $this->query_all("SELECT DISTINCT uid FROM " . get_table('answer') . " WHERE question_id = " . intval($question_id) . " AND uid <> " . intval($published_uid) . " AND anonymous = 0 ORDER BY agree_count DESC LIMIT " . intval($limit)))
		{
			foreach ($answer_users AS $key => $val)
			{
				$answer_uids[] = $val['uid'];
			}

			$result = $this->model('account')->get_user_info_by_uids($answer_uids);

			AWS_APP::cache()->set('answer_users_by_question_id_' . md5($question_id . $limit . $published_uid), $result, get_setting('cache_level_normal'));
		}

		return $result;
	}


	/**
	 *
	 * 问题投票
	 * @param int $question_id //问题ID
	 * @param int $vote_value  //-1反对 1 赞同
	 * @param int $uid         //用户ID
	 * @param int $reputation_factor
	 * @param int $question_uid //被投票用户ID
	 *
	 * @return boolean true|false
	 */
	public function change_question_vote($question_id, $vote_value, $uid, $reputation_factor, $question_uid)
	{
		if (!$question_id)
		{
			return false;
		}

		if (! in_array($vote_value, array(
			- 1,
			1
		)))
		{
			return false;
		}

		$question_id = intval($question_id);
		$question_uid = intval($question_uid);

		if (!$vote_info = $this->get_question_vote_status($question_id, $uid)) //添加记录
		{
			$this->insert('question_vote', array(
				'question_id' => $question_id,
				'vote_uid' => $uid,
				'add_time' => fake_time(),
				'vote_value' => $vote_value,
				'reputation_factor' => $reputation_factor
			));

			if ($vote_value == 1)
			{
				if (!$this->model('currency')->fetch_log($uid, 'AGREE_QUESTION', $question_id))
				{
					$this->model('currency')->process($uid, 'AGREE_QUESTION', get_setting('currency_system_config_agree_question'), '赞同问题 #' . $question_id, $question_id);
					$this->model('currency')->process($question_uid, 'QUESTION_AGREED', get_setting('currency_system_config_question_agreed'), '问题被赞同 #' . $question_id, $question_id);
				}
			}
			else if ($vote_value == -1)
			{
				if (!$this->model('currency')->fetch_log($uid, 'DISAGREE_QUESTION', $question_id))
				{
					$this->model('currency')->process($uid, 'DISAGREE_QUESTION', get_setting('currency_system_config_disagree_question'), '反对问题 #' . $question_id, $question_id);
					$this->model('currency')->process($question_uid, 'QUESTION_DISAGREED', get_setting('currency_system_config_question_disagreed'), '问题被反对 #' . $question_id, $question_id);
				}
			}

			$add_agree_count = $vote_value;
		}
		else if ($vote_info['vote_value'] == $vote_value) //删除记录
		{
			$this->delete_question_vote($vote_info['voter_id']);

			$add_agree_count = -$vote_value;
		}
		else //更新记录
		{
			$this->set_question_vote_status($vote_info['voter_id'], $vote_value);

			$add_agree_count = $vote_value * 2;
		}

		$this->update_vote_count($question_id, $add_agree_count);

		// 更新作者赞同数和威望
		$this->model('reputation')->increase_agree_count_and_reputation($question_uid, $add_agree_count, $reputation_factor);

		return true;
	}

	/**
	 * 删除问题投票
	 * Enter description here ...
	 * @param unknown_type $voter_id
	 */
	public function delete_question_vote($voter_id)
	{
		return $this->delete('question_vote', "voter_id = " . intval($voter_id));
	}

	public function update_vote_count($question_id, $delta)
	{
		$this->query('UPDATE ' . $this->get_table('question') . ' SET agree_count = agree_count + ' . $delta . ' WHERE question_id = ' . intval($question_id));
	}

	public function set_question_vote_status($voter_id, $vote_value)
	{
		return $this->update('question_vote', array(
			"add_time" => fake_time(),
			"vote_value" => $vote_value
		), "voter_id = " . intval($voter_id));
	}

	public function get_question_vote_status($question_id, $uid)
	{
		return $this->fetch_row('question_vote', "question_id = " . intval($question_id) . " AND vote_uid = " . intval($uid));
	}

	public function delete_expired_votes()
	{
		$days = intval(get_setting('expiration_votes'));
		if (!$days)
		{
			return;
		}
		$seconds = $days * 24 * 3600;
		$time_before = real_time() - $seconds;
		if ($time_before < 0)
		{
			$time_before = 0;
		}
		$this->delete('question_vote', 'add_time < ' . $time_before);
		$this->delete('answer_vote', 'add_time < ' . $time_before);
		$this->delete('question_thanks', 'time < ' . $time_before);
		$this->delete('answer_thanks', 'time < ' . $time_before);
	}

}
