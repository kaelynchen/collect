<?php

(new WoolCollector)->init();

class WoolCollector
{
    public function init()
    {
        $targetUrl = array(
            '14' => 'http://www.example.com/forum.php?mod=forumdisplay&fid=15&filter=author&orderby=dateline&typeid=14', // 页面1
            '15' => 'http://www.example.com/forum.php?mod=forumdisplay&fid=15&filter=typeid&orderby=dateline&typeid=15'  // 页面2
        );
        define('THREAD_URL_PRE', 'http://www.example.com/forum.php?mod=viewthread&tid=');

        global $argv;
        if (isset($argv[1])) {
        	if (!isset($argv[2])) {
        		$this->terminal('帖子ID为空，请重新输入', 'warning');
        		exit;
        	}
        	switch ($argv[1]) {
        		case 'save':
        			$this->saveThreadToFile($argv[2]);
        			break;
    			case 'recollect':
    				$this->recollectOne($argv[2]);
					break;
				default:
					$this->terminal('不存在的指令', 'warning');
        	}
    		exit;
        }
        $this->terminal('目标页面数量：' . count($targetUrl), 'info');
        $i = 1;
        foreach ($targetUrl as $k => $v) {
            $this->terminal(PHP_EOL . '抓取第' . $i++ . '个目标页面', 'info');
            $this->start($v, $k);
        }
    }
    

    public function start($targetUrl, $typeid)
    {
        $pdo = new PDO('mysql:host=localhost;dbname=wool;port=3306', 'root', 'zoulinlove');
        // 防止入库后的中文乱码
        $pdo->query('SET NAMES utf8');
        $lastThread = $pdo->query('SELECT * fROM wool_thread WHERE original_typeid = ' . $typeid . ' ORDER BY created_time DESC, id ASC LIMIT 1')->fetch();
        $lastTid = $lastThread['original_tid'] ?? 0;
        $threadList = $this->collect($targetUrl, $lastTid);
        if (empty($threadList)) {
            $this->terminal('没有更新的内容' . PHP_EOL, 'success');
            return;
        }
        $this->terminal('获取完毕，入库中...', 'success');
        $this->save($pdo, $threadList, $typeid);
        $this->terminal('完成!', 'success');
    }

    /**
     * 采集单个帖子内容并保存
     *
     * @param $tid
     */
    public function saveThreadToFile($tid)
    {
    	$this->terminal('获取帖子内容', 'info');
        $thread = $this->curl(THREAD_URL_PRE . $tid);
        fwrite(fopen('demo.txt', 'w'), $thread);
        $this->terminal('文件已保存：' . __DIR__ . DIRECTORY_SEPARATOR . 'demo.txt', 'success');
    }

    /**
     * 核心采集入库流程
     *
     * @param $targetUrl
     * @param $lastTid
     * @return array
     */
    public function collect($targetUrl, $lastTid)
    {
        $response = $this->curl($targetUrl);
        if (empty($response)) {
            $this->terminal('获取内容为空，尝试登录……', 'warning');
            $this->login();
            $response = $this->curl($targetUrl);
        }
        // 获取帖子列表
        $this->terminal('获取帖子列表...', 'info');
        preg_match_all('{<th class="new" style="width:638px;">\s*<a href="[\w\W]+?tid=(\d+)[\w\W]+?</a>}', $response, $tidArr);
        if (empty($tidArr[1])) {
            $this->terminal('获取列表失败', 'error');
            exit;
        }
        $allThread = array_values(array_unique($tidArr[1]));
        // 取所有帖子详情
        $this->terminal('获取所有帖子详情...', 'info');
        $threadList = [];
        $count = 1;
        foreach ($allThread as $k => $tid) {
            // if ($count == 10) break;
            if ($tid == $lastTid) {
                $this->terminal('更新的帖子数量：' . --$count, 'info');
                break;
            }
            $this->terminal('抓取第' . $count++ . '条', 'info');
			list($title, $content, $postTime) = $this->collectOne($tid);
            if (!$this->filter($title, $content, $tid)) {
                continue;
            }
            $threadList[$k]['title'] = $title;
            $threadList[$k]['content'] = $content;
            $threadList[$k]['post_time'] = $postTime;
            $threadList[$k]['original_tid'] = $tid;
            sleep(mt_rand(1, 5));
        }
        $this->terminal('有效的帖子数量：' . count($threadList), 'success');
        return $threadList;
    }
    
    public function collectOne($tid)
    {
    	$thread = $this->curl(THREAD_URL_PRE . $tid);
        preg_match('{<span id="thread_subject" title="([\w\W]+?)">}', $thread, $title);
        preg_match(
            '!<style type="text/css">\.pcb{margin-right:0}</style><div class="pcb">\s*<div class="t_fsz">\s*<table cellspacing="0" cellpadding="0"><tr><td class="t_f" id="postmessage_\d+">([\s\S]+?)</td></tr></table>!iu',
            $thread,
            $content
        );
        preg_match('{楼主<span class="pipe">\|</span>\s*<em id="[\w\d]*?">发表于 ([\w\W]*?)</em>}', $thread, $postTime);
        return [
            $title[1] ?? '',
            $content[1] ?? '',
            $postTime[1] ?? ''
        ];
    }
    
    public function recollectOne ($id) {
    	$pdo = new PDO('mysql:host=localhost;dbname=wool;port=3306', 'root', 'zoulinlove');
        $pdo->query('SET NAMES utf8');
        $thread = $pdo->query('select original_tid from wool_thread where id='.$id)->fetch();
        if(!$thread['original_tid']){
        	$this->terminal('该帖在数据库中不存在', 'warning');
        	exit;
        }
    	list($title, $content, $postTime) = $this->collectOne($thread['original_tid']);
    	if(!$this->filter($title, $content, $thread['original_tid'])){
            $this->terminal('帖子不符合校验规则，略过', 'warning');
            exit;
        }
        $update = $pdo->exec("
        	UPDATE wool_thread SET title='{$title}', content='{$content}'
            WHERE original_tid={$thread['original_tid']}
        ");
        if ($update) {
        	$this->terminal($thread['original_tid'] . ' 重新采集成功！', 'success');
        } else {
        	$this->terminal($thread['original_tid'] . ' 重新采集失败', 'error');
        }
    }

    /**
     * 标题与内容过滤、优化
     *
     * @param $title
     * @param $content
     * @param $tid
     * @return bool
     */
    public function filter(&$title, &$content, $tid)
    {
        if (empty($content)) {
            $this->terminal('—— 内容为空，tid：' . $tid, 'warning');
            return false;
        }
        $sensitive = '/示例网站|示例/';
        $title = preg_replace($sensitive, '小站', $title);
        $content = preg_replace($sensitive, '小站', $content);
        $sensitive2 = '/赚小客/';
        $title = preg_replace($sensitive2, '站长', $title);
        $content = preg_replace($sensitive2, '站长', $content);
        $sensitive3 = '/果果/';
        $title = preg_replace($sensitive3, '优惠券', $title);
        $content = preg_replace($sensitive3, '优惠券', $content);
        $sensitive4 = '/赚客/';
        $title = preg_replace($sensitive4, '羊毛党', $title);
        $content = preg_replace($sensitive4, '羊毛党', $content);
        $sensitive5 = '/加[\s\S]{0,9}?果|荚[\s\S]{0,9}?果/';
        $title = preg_replace($sensitive5, '欢迎分享', $title);
        $content = preg_replace($sensitive5, '欢迎分享', $content);
        $content = preg_replace('/www\.example\.com/', 'www.xianbaoxz.com', $content);
        $content = preg_replace('/>\s*</', '><', $content);
        $content = preg_replace('{<div class="locked">以下内容需要积分高于[\s\S]*?才可浏览</div>}', '', $content);

        // 图片src修正
        $replace = "<img src=\"$1\">";
        // $content = preg_replace('/src="([\s\S]*?)"([\s\S]*?)zoomfile="([\s\S]*?)"([\s\S]*?)>/', $replace, $content);
        $content = preg_replace('/<img[\s\S]*?file="([\s\S]*?)"[\s\S]*?>/', $replace, $content);
        // 超链接完全显示
        $content = preg_replace('{href="([\s\S]*?)"([\s\S]*?)>([\w\W]*?)</a>}', 'href="$1" target="_blank">$1</a>', $content);
        // 单引号符号入库前调整
        $content = str_replace('\'', '\'\'', $content);
        if (!isset($title)) {
            $this->terminal('—— 标题匹配失败，tid：' . $tid, 'warning');
            return false;
        }
        if (!isset($content)) {
            $this->terminal('—— 内容匹配失败，tid：' . $tid, 'warning');
            return false;
        }
        // 过滤
        if (strlen($title) < 18 && strlen($content) < 18) {
            $this->terminal('—— 标题与内容长度少于18个字符，跳过。tid：' . $tid, 'warning');
            return false;
        }
        if (strlen($content) < 40 && preg_match('/点评|2楼|二楼|楼下|看我|[1一顶]+[楼层]+防/', $content)) {
            $this->terminal('—— 水贴或无效内容，跳过。tid：' . $tid, 'warning');
            return false;
        }
        if (preg_match('/\?|？/', $title)) {
            $this->terminal('—— 询问类型帖子，跳过。tid：' . $tid, 'warning');
            return false;
        }
        if (preg_match('/任何交易相关的帖，都必须发布到赚品交换区/', $content)) {
            $this->terminal('—— 帖子内容包含论坛告示，跳过。tid：' . $tid, 'warning');
            return false;
        }
        return true;
    }

    /**
     * 保存数据
     *
     * @param $pdo
     * @param $threadList
     * @param $typeid
     */
    public function save($pdo, $threadList, $typeid)
    {
        $dateTime = date('Y-m-d H:i:s');
        foreach ($threadList as $k => $v) {
            $pdo->exec("
                INSERT INTO wool_thread 
                (title, content, created_time, post_time, original_tid, original_typeid) 
                VALUE ('{$v['title']}', '{$v['content']}', '{$dateTime}', '{$v['post_time']}', '{$v['original_tid']}', $typeid)
            ");
        }
    }

    /**
     * 发起请求
     *
     * @param $url
     * @return string
     */
    public function curl($url)
    {
        $instance = curl_init();
        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_COOKIEFILE => 'cookie.txt',
        ];
        curl_setopt_array($instance, $options);
        $data = curl_exec($instance);
        curl_close($instance);
        return mb_convert_encoding($data, 'UTF-8', 'GBK');
    }

    /**
     * 登录，获取、保存cookie
     *
     * @return string
     */
    public function login()
    {
        $instance = curl_init();
        $options = [
            CURLOPT_URL => 'http://www.example.com/member.php?mod=logging&action=login&loginsubmit=yes&loginhash=LEcW0&inajax=1',
            CURLOPT_POSTFIELDS => http_build_query([
                'username' => 'admin',
                'password' => md5('123456'),
                'questionid' => 1,
                'answer' => 'answer'
            ]),
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_COOKIEJAR => 'cookie.txt',
        ];
        curl_setopt_array($instance, $options);
        $data = curl_exec($instance);
        curl_close($instance);
        return mb_convert_encoding($data, 'UTF-8', 'GBK');
    }
    
    /**
     * 终端输出
     *
     * @param $text
     * @param $type
     */
    public function terminal($text, $type)
    {
        switch ($type) {
            case 'error':
                $pre = chr(27) . '[31m'; // 红色
                break;
            case 'success':
                $pre = chr(27) . '[32m'; // 绿色
                break;
            case 'warning':
                $pre = chr(27) . '[33m'; // 黄色
                break;
            case 'info':
                $pre = chr(27) . '[36m'; // 蓝色
                break;
            default:
                $pre = '';
        }
        $end = chr(27) . '[0m';
        echo $pre . $text . $end . PHP_EOL;
    }
    
    
}
