<?php
namespace app\admin\controller;

use app\admin\model\Feedback as FeedbackModel;
use app\admin\model\Admin;
use think\Controller;
use think\Request;
use think\facade\Log;
use think\Response;

class Feedback extends Controller
{
    /**
     * 问题列表
     */
    public function index()
    {
        $list = FeedbackModel::order('id', 'desc')->paginate(15);
        $this->assign('list', $list);
        return $this->fetch();
    }


    /**
     * 用户提交问题
     */
    public function submit(Request $request)
    {
        if ($request->isPost()) {
            $data = $request->post();
            
            // 1. 获取当前登录用户信息
            $admin_id = session('admin_id');
            $admin_username = session('username');
            
            // 2. 从admin表中获取用户名
            $admin = null;
            if ($admin_id) {
                $admin = Admin::where('admin_id', $admin_id)->find();
            }
            
            // 3. 设置用户信息
            if ($admin) {
                $data['user_id'] = $admin->admin_id;
                $data['username'] = $admin->username;
            } else if (!empty($admin_username)) {
                $data['user_id'] = $admin_id ?: 0;
                $data['username'] = $admin_username;
            } else {
                $admin_session = session('admin');
                if (!empty($admin_session) && isset($admin_session['username'])) {
                    $data['user_id'] = $admin_session['admin_id'] ?? 0;
                    $data['username'] = $admin_session['username'];
                } else {
                    $data['user_id'] = 0;
                    $data['username'] = '匿名用户';
                }
            }
            
            // 4. 确保必填字段
            if (empty($data['title'])) {
                return $this->handleResponse(0, '问题标题不能为空');
            }
            if (empty($data['content'])) {
                return $this->handleResponse(0, '问题内容不能为空');
            }
            
            $data['create_time'] = time();
            $data['update_time'] = time();
            
            // 5. 保存到数据库
            try {
                $feedback = new FeedbackModel();
                $result = $feedback->save($data);
                
                if ($result) {
                    return $this->handleResponse(1, '提交成功');
                } else {
                    return $this->handleResponse(0, '提交失败');
                }
            } catch (\Exception $e) {
                return $this->handleResponse(0, '提交成功: ' . $e->getMessage());
            }
        } else {
            // 获取当前用户名并传递给视图
            $admin_id = session('admin_id');
            $admin_username = session('username');
            
            $current_username = null;
            
            if ($admin_id) {
                $admin = Admin::where('admin_id', $admin_id)->find();
                if ($admin) {
                    $current_username = $admin->username;
                }
            }
            
            if (empty($current_username) && !empty($admin_username)) {
                $current_username = $admin_username;
            }
            
            if (empty($current_username)) {
                $admin_session = session('admin');
                if (!empty($admin_session) && isset($admin_session['username'])) {
                    $current_username = $admin_session['username'];
                }
            }
            
            if (empty($current_username)) {
                $current_username = '当前用户';
            }
            
            $this->assign('current_username', $current_username);
            return $this->fetch();
        }
    }
    
    /**
     * 统一处理响应
     */
    private function handleResponse($code, $msg)
    {
        // 对于AJAX请求，返回JSON
        if (request()->isAjax()) {
            return json(['code' => $code, 'msg' => $msg]);
        } else {
            // 对于非AJAX请求，返回重定向或页面
            if ($code == 1) {
                $this->success($msg, url('feedback/submit'));
            } else {
                $this->error($msg, url('feedback/submit'));
            }
            // 确保不会继续执行
            exit;
        }
    }
}