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

class main extends AWS_CONTROLLER
{
	public function get_access_rule()
	{
		$rule_action['rule_type'] = 'white'; //黑名单,黑名单中的检查  'white'白名单,白名单以外的检查
		$rule_action['actions'] = array();
		return $rule_action;
	}

	public function setup()
	{
		$this->crumb(AWS_APP::lang()->_t('发布'), '/publish/');
	}

	public function index_action()
	{
		$id = intval($_GET['id']);
		if ($id)
		{
			if (!$question_info = $this->model('question')->get_question_info_by_id($id))
			{
				H::redirect_msg(AWS_APP::lang()->_t('指定问题不存在'));
			}

			if (!$this->user_info['permission']['is_administrator'] AND !$this->user_info['permission']['is_moderator'] AND !$this->user_info['permission']['edit_question'] AND $question_info['published_uid'] != $this->user_id)
			{
				H::redirect_msg(AWS_APP::lang()->_t('你没有权限编辑这个问题'), '/question/' . $question_info['question_id']);
			}
		}
		else if (!$this->user_info['permission']['publish_question'])
		{
			H::redirect_msg(AWS_APP::lang()->_t('你的等级还不够'));
		}
		else if ($this->is_post() AND $_POST['question_detail'])
		{
			$question_info = array(
				'question_content' => htmlspecialchars($_POST['question_content']),
				'question_detail' => htmlspecialchars($_POST['question_detail']),
				'category_id' => intval($_POST['category_id'])
			);
		}
		else
		{
			$question_info = array(
				'question_content' => htmlspecialchars($_POST['question_content']),
				'question_detail' => ''
			);
		}

		if (!$id)
		{
			if (!$this->model('currency')->check_balance_for_operation($this->user_info['currency'], 'currency_system_config_new_question'))
			{
				H::redirect_msg(AWS_APP::lang()->_t('你的剩余%s已经不足以进行此操作', get_setting('currency_name')), '/currency/rule/');
			}

			if (!$this->model('publish')->check_question_limit_rate($this->user_id))
			{
				H::redirect_msg(AWS_APP::lang()->_t('你今天发布的问题已经达到上限'));
			}
		}

		if (!$question_info['category_id'])
		{
			$question_info['category_id'] = ($_GET['category_id']) ? intval($_GET['category_id']) : 0;
		}

		if (get_setting('category_enable') == 'Y')
		{
			TPL::assign('question_category_list', $this->model('system')->build_category_html('question', 0, $question_info['category_id']));
		}

		TPL::assign('human_valid', human_valid('question_valid_hour'));

		TPL::import_js('js/app/publish.js');

		if (get_setting('advanced_editor_enable') == 'Y')
		{
			import_editor_static_files();
		}

		TPL::assign('question_info', $question_info);

		TPL::assign('recent_topics', @unserialize($this->user_info['recent_topics']));

		TPL::output('publish/index');
	}

	public function article_action()
	{
		$id = intval($_GET['id']);
		if ($id)
		{
			if (!$article_info = $this->model('article')->get_article_info_by_id($id))
			{
				H::redirect_msg(AWS_APP::lang()->_t('指定文章不存在'));
			}

			if (!$this->user_info['permission']['is_administrator'] AND !$this->user_info['permission']['is_moderator'] AND !$this->user_info['permission']['edit_article'] AND $article_info['uid'] != $this->user_id)
			{
				H::redirect_msg(AWS_APP::lang()->_t('你没有权限编辑这个文章'), '/article/' . $article_info['id']);
			}

			TPL::assign('article_topics', $this->model('topic')->get_topics_by_item_id($article_info['id'], 'article'));
		}
		else if (!$this->user_info['permission']['publish_article'])
		{
			H::redirect_msg(AWS_APP::lang()->_t('你的等级还不够'));
		}
		else if ($this->is_post() AND $_POST['message'])
		{
			$article_info = array(
				'title' => htmlspecialchars($_POST['title']),
				'message' => htmlspecialchars($_POST['message']),
				'category_id' => intval($_POST['category_id'])
			);
		}
		else
		{
			$article_info =  array(
				'title' => htmlspecialchars($_POST['title']),
				'message' => ''
			);
		}

		if (!$id)
		{
			if (!$this->model('currency')->check_balance_for_operation($this->user_info['currency'], 'currency_system_config_new_article'))
			{
				H::redirect_msg(AWS_APP::lang()->_t('你的剩余%s已经不足以进行此操作', get_setting('currency_name')), '/currency/rule/');
			}

			if (!$this->model('publish')->check_article_limit_rate($this->user_id))
			{
				H::redirect_msg(AWS_APP::lang()->_t('你今天发布的文章已经达到上限'));
			}
		}

		if (!$article_info['category_id'])
		{
			$article_info['category_id'] = ($_GET['category_id']) ? intval($_GET['category_id']) : 0;
		}

		if (get_setting('category_enable') == 'Y')
		{
			TPL::assign('article_category_list', $this->model('system')->build_category_html('question', 0, $article_info['category_id']));
		}

		TPL::assign('human_valid', human_valid('question_valid_hour'));

		TPL::import_js('js/app/publish.js');

		if (get_setting('advanced_editor_enable') == 'Y')
		{
			import_editor_static_files();
		}

		TPL::assign('recent_topics', @unserialize($this->user_info['recent_topics']));

		TPL::assign('article_info', $article_info);

		TPL::output('publish/article');
	}

	public function delay_display_action()
	{
		$url = '/';

		H::redirect_msg(AWS_APP::lang()->_t('发布成功, 内容将会延迟显示, 请稍后再来查看...'), $url);
	}

}
