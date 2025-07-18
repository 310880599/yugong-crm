<?php

namespace app\admin\controller;

use think\Db;
use think\facade\Request;
use think\facade\Session;
use think\facade\Env;
use PhpOffice\PhpSpreadsheet\IOFactory;
use think\facade\Cache;

class Client extends Common
{
    protected $middleware = [\app\http\middleware\TrimStrings::class];


    const CONTACT_MAP = [
        'phone'         => 1,
        'email'         => 2,
        'whatsapp'      => 3,
        'ali_id'        => 4,
        'wechat'        => 5,
        'facebook'      => 6,
        'twitter'       => 7,
        'linkedin'      => 8,
        'youtube'       => 9,
        'instagram'     => 10,
        'weibo'         => 11,
        'qq'            => 12,
        'trademanager'  => 13,
        'skype'         => 14,
        '传真'           => 15,
        'msn'           => 16,
        'viber'         => 17,
        'pinterest'     => 18,
        'vk'            => 19,
        'line'          => 20,
        'zalo'          => 21,
        'telegram'      => 22,
    ];



    // 添加公共日志
    static function addOperLog($leads_id, $type, $description)
    {
        $description = is_string($description) ? $description : json_encode($description, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        Db::table('crm_operation_log')->insert([
            'user_id' => Session::get('aid'),
            'leads_id' => $leads_id,
            'oper_type' => $type,
            'description' => $description,
            'oper_user' => Session::get('username'),
            'created_at' => date("Y-m-d H:i:s")
        ]);
    }

    //客户联系方式格式化
    public function formatContact($contactList)
    {
        $contactGroup = [];
        foreach ($contactList as $contact) {
            if (isset($contactGroup[$contact['leads_id']][$contact['contact_type']])) {
                $contactGroup[$contact['leads_id']][$contact['contact_type']][] = $contact['contact_extra'] ? $contact['contact_extra'] . '-' . $contact['contact_value'] : $contact['contact_value'];
            } else {
                $contactGroup[$contact['leads_id']][$contact['contact_type']] = [$contact['contact_extra'] ? $contact['contact_extra'] . '-' . $contact['contact_value'] : $contact['contact_value']];
            }
        }
        return $contactGroup;
    }

    public function getContactType($contactGroup)
    {
        $con_map = array_flip(self::CONTACT_MAP);
        $result = [];
        foreach ($con_map as $k => $v) {
            if (isset($contactGroup[$k])) $result[$v] = $contactGroup[$k];
            else $result[$v] = [''];
        }
        // foreach ($contactGroup as $key => $vo) {
        //     $typeName = $con_map[$key] ?? 'unknown';
        //     $result[$typeName] = $vo;
        // }
        return $result;
    }


    //客户列表
    public function index()
    {
        if (request()->isPost()) {
            $page = input('page', 1);
            $pageSize = input('limit', config('pageSize'));
            $adminId = Session::get('aid');
            $subordinates = Db::name('admin')->where('parent_id', $adminId)->column('username');

            // 基本客户条件
            $query = Db::name('crm_leads')->alias('l')->where(['l.status' => 1]);

            if ($adminId == 1) {
                // 超级管理员无需额外条件
            } elseif (!empty($subordinates)) {
                // 主管查看直属下属及自己的客户
                $usernames = array_merge($subordinates, [Session::get('username')]);
                $query->whereIn('l.pr_user', $usernames);
            } else {
                // 普通员工仅查看自己名下的客户
                $query->where(['l.pr_user' => Session::get('username')]);
            }

            // 查询客户数据，并拼接联系方式
            $list = $query
                ->field([
                    'l.*',
                    "GROUP_CONCAT(
                    DISTINCT CASE c.contact_type
                        WHEN 1 THEN '手机号'
                        WHEN 2 THEN '邮箱'
                        WHEN 3 THEN 'WhatsApp'
                        ELSE '其他'
                    END ORDER BY c.id SEPARATOR '<br>'
                ) AS contact_type",
                    "GROUP_CONCAT(DISTINCT c.contact_value ORDER BY c.id SEPARATOR '<br>') AS contact_value"
                ])
                ->leftJoin('crm_contacts c', 'l.id = c.leads_id')
                ->group('l.id')
                ->order('l.ut_time desc')
                ->paginate([
                    'list_rows' => $pageSize,
                    'page' => $page
                ])
                ->toArray();

            return [
                'code' => 0,
                'msg' => '获取成功!',
                'data' => $list['data'],
                'count' => $list['total'],
                'rel' => 1
            ];
        }

        $khRankList = Db::table('crm_client_rank')->select();
        $khStatusList = Db::table('crm_client_status')->select();
        $xsSourceList = Db::table('crm_clues_source')->select();

        $adminResult = Db::name('admin')->where('group_id', '<>', 1)->field('admin_id,username')->select();
        $this->assign('adminResult', $adminResult);
        $this->assign('khRankList', $khRankList);
        $this->assign('khStatusList', $khStatusList);
        $this->assign('xsSourceList', $xsSourceList);

        return $this->fetch();
    }

    //（我的客户）列表
    public function perCliList()
    {



        if (request()->isPost()) {
            $key = input('post.key');
            $page = input('page') ? input('page') : 1;
            $pageSize = input('limit') ? input('limit') : config('pageSize');
            $list = db('crm_leads')
                ->where(['status' => 1, 'issuccess' => -1])
                ->where(['pr_user' => Session::get('username')])
                ->order('ut_time desc')
                ->paginate(array('list_rows' => $pageSize, 'page' => $page))
                ->toArray();
            return $result = ['code' => 0, 'msg' => '获取成功!', 'data' => $list['data'], 'count' => $list['total'], 'rel' => 1];
        }



        $khRankList = Db::table('crm_client_rank')->select();
        $khStatusList = Db::table('crm_client_status')->select();
        $xsSourceList = Db::table('crm_clues_source')->select();

        $this->assign('khRankList', $khRankList);
        $this->assign('khStatusList', $khStatusList);
        $this->assign('xsSourceList', $xsSourceList);  //线索/客户来源

        return $this->fetch('personclient/index');
        
    }

    //成交客户列表
    public function successCliList()
    {

        if (request()->isPost()) {
            $where = [];
            $where['issuccess'] = 1;
            if (session('aid') != 1) {
                $where['pr_user'] = Session::get('username');
            }
            $key = input('post.key');
            $page = input('page') ? input('page') : 1;
            $pageSize = input('limit') ? input('limit') : config('pageSize');
            $list = db('crm_leads')
                ->where($where)
                ->order('ut_time desc')
                ->paginate(array('list_rows' => $pageSize, 'page' => $page))
                ->toArray();
            return $result = ['code' => 0, 'msg' => '获取成功!', 'data' => $list['data'], 'count' => $list['total'], 'rel' => 1];
        }

        $khRankList = Db::table('crm_client_rank')->select();
        $khStatusList = Db::table('crm_client_status')->select();
        $xsSourceList = Db::table('crm_clues_source')->select();

        $this->assign('khRankList', $khRankList);
        $this->assign('khStatusList', $khStatusList);
        $this->assign('xsSourceList', $xsSourceList);  //线索/客户来源
        //查询所有管理员（去除admin）
        $adminResult = Db::name('admin')->where('group_id', '<>', 1)->field('admin_id,username')->select();
        $this->assign('adminResult', $adminResult);
        return $this->fetch('client/chengjiao');
    }



    // public function xlsUpload() {
    //     $xlsFile = request()->file('xlsFile');

    //     if (!$xlsFile) {
    //         return json([
    //             'code' => -1,
    //             'msg'  => '请上传Excel文件（字段名应为xlsFile）',
    //             'data' => []
    //         ]);
    //     }

    //     $uploadPath = Env::get('root_path') . 'public/uploads/';
    //     $info = $xlsFile->move($uploadPath);
    //     if (!$info) {
    //         return json([
    //             'code' => -1,
    //             'msg'  => '文件上传失败：' . $xlsFile->getError(),
    //             'data' => []
    //         ]);
    //     }

    //     $filePath = $uploadPath . $info->getSaveName();
    //     // 使用 PhpSpreadsheet 读取 Excel（支持中文表头）
    //     try {
    //         $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($filePath);
    //         $spreadsheet = $reader->load($filePath);
    //         $sheet = $spreadsheet->getActiveSheet();
    //         $data = $sheet->toArray(null, true, true, true); // 保留键值为 'A','B','C'...
    //     } catch (\Exception $e) {
    //         return json(['code' => -1, 'msg' => '读取Excel出错：' . $e->getMessage()]);
    //     }

    //     // 第一行为标题行
    //     $headers = array_shift($data);
    //     $insertData = [];

    //     foreach ($data as $row) {
    //         $rowAssoc = [];
    //         foreach ($headers as $key => $title) {
    //             $rowAssoc[$title] = $row[$key] ?? '';
    //         }

    //         // 开始映射字段（你可以根据表头调整）
    //         $insertData[] = [
    //             'kh_name'     => $rowAssoc['客户名称'] ?? '',
    //             'kh_rank'     => $rowAssoc['客户等级'] ?? '',
    //             'pr_gh_type'  => $rowAssoc['客户归属公海'] ?? '',
    //             'kh_status'   => $rowAssoc['客户来源'] ?? '',
    //             'xs_source'   => $rowAssoc['客户国家'] ?? '',
    //             'kh_contact'  => $rowAssoc['联系人'] ?? '',
    //             'kh_hangye'   => $rowAssoc['联系人邮箱'] ?? '',

    //             'remark'      => $rowAssoc['客户备注'] ?? '',
    //             'pr_user'     => Session::get('username'),
    //             'ut_time'     => date("Y-m-d H:i:s"),
    //             'at_time'     => date("Y-m-d H:i:s"),
    //             'at_user'     => Session::get('username'),
    //             'status'      => 1
    //         ];
    //     }

    //     if (empty($insertData)) {
    //         return json(['code' => -1, 'msg' => 'Excel中无有效数据']);
    //     }

    //     // 插入数据库
    //     $success = db('crm_leads')->insertAll($insertData);
    //     if ($success) {
    //         return json(['code' => 0, 'msg' => '成功导入 ' . count($insertData) . ' 条客户数据']);
    //     } else {
    //         return json(['code' => -1, 'msg' => '导入失败，请检查字段映射或数据库结构']);
    //     }
    // }

    // const CONTACT_TYPE_PHONE = 1;
    // const CONTACT_TYPE_EMAIL = 2;

    //   public function xlsUpload() {
    //     $xlsFile = request()->file('xlsFile');

    //     if (!$xlsFile) {
    //         return json([
    //             'code' => -1,
    //             'msg'  => '请上传Excel文件（字段名应为xlsFile）',
    //             'data' => []
    //         ]);
    //     }

    //     $uploadPath = Env::get('root_path') . 'public/uploads/';
    //     $info = $xlsFile->move($uploadPath);
    //     if (!$info) {
    //         return json([
    //             'code' => -1,
    //             'msg'  => '文件上传失败：' . $xlsFile->getError(),
    //             'data' => []
    //         ]);
    //     }

    //     $filePath = $uploadPath . $info->getSaveName();
    //     // 使用 PhpSpreadsheet 读取 Excel（支持中文表头）
    //     try {
    //         $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($filePath);
    //         $spreadsheet = $reader->load($filePath);
    //         $sheet = $spreadsheet->getActiveSheet();
    //         $data = $sheet->toArray(null, true, true, true); // 保留键值为 'A','B','C'...
    //     } catch (\Exception $e) {
    //         return json(['code' => -1, 'msg' => '读取Excel出错：' . $e->getMessage()]);
    //     }

    //     // 第一行为标题行
    //     $headers = array_shift($data);
    //     $insertData = [];

    //     foreach ($data as $row) {
    //         $rowAssoc = [];
    //         foreach ($headers as $key => $title) {
    //             $rowAssoc[$title] = $row[$key] ?? '';
    //         }

    //         // 开始映射字段（你可以根据表头调整）
    //         $insertData[] = [
    //             'kh_name'     => $rowAssoc['客户名称'] ?? '',
    //             'kh_rank'     => $rowAssoc['客户等级'] ?? '',
    //             'pr_gh_type'  => $rowAssoc['客户归属公海'] ?? '',
    //             'kh_status'   => $rowAssoc['客户来源'] ?? '',
    //             'xs_area'   => $rowAssoc['客户国家'] ?? '',
    //             'kh_contact'  => $rowAssoc['联系人'] ?? '',
    //             'contact_value'   => $rowAssoc['联系人邮箱'] ?? '',
    //             'contact_value'       => $rowAssoc['联系人电话'] ?? '',
    //             'remark'      => $rowAssoc['客户备注'] ?? '',
    //             'pr_user'     => Session::get('username'),
    //             'ut_time'     => date("Y-m-d H:i:s"),
    //             'at_time'     => date("Y-m-d H:i:s"),
    //             'at_user'     => Session::get('username'),
    //             'status'      => 1
    //         ];
    //     }

    //     if (empty($insertData)) {
    //         return json(['code' => -1, 'msg' => 'Excel中无有效数据']);
    //     }

    //     // 插入数据库
    //     $success = db('crm_leads')->insertAll($insertData);
    //     if ($success) {
    //         return json(['code' => 0, 'msg' => '成功导入 ' . count($insertData) . ' 条客户数据']);
    //     } else {
    //         return json(['code' => -1, 'msg' => '导入失败，请检查字段映射或数据库结构']);
    //     }
    // }
    // public function xlsUpload()
    // {
    //     $xlsFile = request()->file('xlsFile');

    //     if (!$xlsFile) {
    //         return json(['code' => -1, 'msg' => '请上传Excel文件']);
    //     }

    //     $uploadPath = Env::get('root_path') . 'public/uploads/';
    //     $info = $xlsFile->move($uploadPath);
    //     if (!$info) {
    //         return json(['code' => -1, 'msg' => '文件上传失败：' . $xlsFile->getError()]);
    //     }

    //     $filePath = $uploadPath . $info->getSaveName();

    //     try {
    //         $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($filePath);
    //         $spreadsheet = $reader->load($filePath);
    //         $sheet = $spreadsheet->getActiveSheet();
    //         $data = $sheet->toArray(null, true, true, true);
    //     } catch (\Exception $e) {
    //         return json(['code' => -1, 'msg' => '读取Excel出错：' . $e->getMessage()]);
    //     }

    //     // 第一行为标题行
    //     $headers = array_shift($data);

    //     Db::startTrans();
    //     try {
    //         $contactsInsertData = [];
    //         foreach ($data as $row) {
    //             $rowAssoc = [];
    //             foreach ($headers as $key => $title) {
    //                 $rowAssoc[$title] = $row[$key] ?? '';
    //             }

    //             // 主表数据
    //             $leadsRow = [
    //                 'kh_name'     => $rowAssoc['客户名称'] ?? '',
    //                 'kh_rank'     => $rowAssoc['客户等级'] ?? '',
    //                 'pr_gh_type'  => $rowAssoc['客户归属公海'] ?? '',
    //                 'kh_status'   => $rowAssoc['客户来源'] ?? '',
    //                 'xs_area'     => $rowAssoc['客户国家'] ?? '',
    //                 'kh_contact'  => $rowAssoc['联系人'] ?? '',
    //                 'remark'      => $rowAssoc['客户备注'] ?? '',
    //                 'pr_user'     => Session::get('username'),
    //                 'ut_time'     => date("Y-m-d H:i:s"),
    //                 'at_time'     => date("Y-m-d H:i:s"),
    //                 'at_user'     => Session::get('username'),
    //                 'status'      => 1
    //             ];

    //             db('crm_leads')->insert($leadsRow);
    //             $leadsId = Db::name('crm_leads')->getLastInsID();

    //             // 联系方式
    //             $phone = trim($rowAssoc['联系人电话'] ?? '');
    //             $email = trim($rowAssoc['联系人邮箱'] ?? '');
    //             $whatsapp = trim($rowAssoc['联系人WhatsApp'] ?? '');

    //             if (!empty($phone)) {
    //                 db('crm_contacts')->insert([
    //                     'leads_id' => $leadsId,
    //                     'contact_type' => self::CONTACT_MAP['phone'],
    //                     'contact_value' => $phone,
    //                     'created_at' => date("Y-m-d H:i:s")
    //                 ]);
    //             }

    //             if (!empty($email)) {
    //                 db('crm_contacts')->insert([
    //                     'leads_id' => $leadsId,
    //                     'contact_type' => self::CONTACT_MAP['email'],
    //                     'contact_value' => $email,
    //                     'created_at' => date("Y-m-d H:i:s")
    //                 ]);
    //             }

    //             if (!empty($whatsapp)) {
    //                 db('crm_contacts')->insert([
    //                     'leads_id' => $leadsId,
    //                     'contact_type' => self::CONTACT_MAP['whatsapp'],
    //                     'contact_value' => $whatsapp,
    //                     'created_at' => date("Y-m-d H:i:s")
    //                 ]);
    //             }
    //         }

    //         Db::commit();
    //         return json(['code' => 0, 'msg' => '成功导入']);
    //     } catch (\Exception $e) {
    //         Db::rollback();
    //         return json(['code' => -1, 'msg' => '导入失败', 'error' => $e->getMessage()]);
    //     }
    // }



    // public function xlsUpload() {
    //     $xlsFile = request()->file('xlsFile');

    //     if (!$xlsFile) {
    //         return json(['code' => -1, 'msg' => '请上传Excel文件']);
    //     }

    //     $uploadPath = Env::get('root_path') . 'public/uploads/';
    //     $info = $xlsFile->move($uploadPath);

    //     if (!$info) {
    //         return json(['code' => -1, 'msg' => '文件上传失败：' . $xlsFile->getError()]);
    //     }

    //     $filePath = $uploadPath . $info->getSaveName();

    //     try {
    //         $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($filePath);
    //         $spreadsheet = $reader->load($filePath);
    //         $sheet = $spreadsheet->getActiveSheet();
    //         $data = $sheet->toArray(null, true, true, true);
    //     } catch (\Exception $e) {
    //         return json(['code' => -1, 'msg' => '读取Excel出错：' . $e->getMessage()]);
    //     }

    //     // 取表头
    //     $headers = array_shift($data);
    //     $current_time = date("Y-m-d H:i:s");
    //     $pr_user = Session::get('username');

    //     Db::startTrans();
    //     try {
    //         $insertedCount = 0;
    //         $rowNum = 1;

    //         foreach ($data as $row) {
    //             $rowNum++;
    //             $rowAssoc = [];

    //             foreach ($headers as $key => $title) {
    //                 $rowAssoc[$title] = $row[$key] ?? '';
    //             }

    //             // 插入主表 crm_leads
    //             $leadsRow = [
    //                 'kh_name'      => $rowAssoc['客户名称'] ?? '',
    //                 'kh_rank'      => $rowAssoc['客户等级'] ?? '',
    //                 'xs_area'      => $rowAssoc['地区'] ?? '',
    //                 'kh_contact'   => $rowAssoc['联系人'] ?? '',
    //                 'remark'       => $rowAssoc['客户备注'] ?? '',
    //                 'kh_status'    => $rowAssoc['客户来源'] ?? '',
    //                 'pr_user'      => $pr_user,
    //                 'ut_time'      => $current_time,
    //                 'at_time'      => $current_time,
    //                 'at_user'      => $pr_user,
    //                 'status'       => 1,
    //                 'ispublic'     => 3,
    //                 'pr_user_bef'  => $pr_user,
    //             ];

    //             $leadsId = db('crm_leads')->insertGetId($leadsRow);

    //             // 构建联系方式
    //             $contacts = [
    //                 [
    //                     'leads_id'      => $leadsId,
    //                     'contact_type'  => self::CONTACT_MAP['phone'],
    //                     'contact_value' => trim($rowAssoc['联系人电话'] ?? ''),
    //                     'contact_extra' => trim($rowAssoc['国家号'] ?? ''),
    //                     'created_at'    => $current_time
    //                 ],
    //                 [
    //                     'leads_id'      => $leadsId,
    //                     'contact_type'  => self::CONTACT_MAP['email'],
    //                     'contact_value' => trim($rowAssoc['联系人邮箱'] ?? ''),
    //                     'contact_extra' => '',
    //                     'created_at'    => $current_time
    //                 ],
    //                 [
    //                     'leads_id'      => $leadsId,
    //                     'contact_type'  => self::CONTACT_MAP['whatsapp'],
    //                     'contact_value' => trim($rowAssoc['联系人WhatsApp'] ?? ''),
    //                     'contact_extra' => '',
    //                     'created_at'    => $current_time
    //                 ],
    //             ];

    //             // 过滤掉 contact_value 为空的记录再插入
    //             $validContacts = [];
    //             foreach ($contacts as $contact) {
    //                 if ($contact['contact_value'] !== '') {
    //                     $validContacts[] = $contact;
    //                 }
    //             }

    //             if (!empty($validContacts)) {
    //                 db('crm_contacts')
    //                     ->field(['leads_id', 'contact_type', 'contact_value', 'contact_extra', 'created_at'])
    //                     ->insertAll($validContacts);
    //             }

    //             $insertedCount++;
    //         }

    //         Db::commit();
    //         return json(['code' => 0, 'msg' => '成功导入客户数据：' . $insertedCount . '条']);

    //     } catch (\Exception $e) {
    //         Db::rollback();
    //         return json([
    //             'code' => -1,
    //             'msg' => '导入失败，出错在Excel第 ' . $rowNum . ' 行',
    //             'error' => $e->getMessage()
    //         ]);
    //     }
    // }






    public function xlsUploadOld()
    {
        $xlsFile = request()->file('xlsFile');

        if (!$xlsFile) {
            return json(['code' => -1, 'msg' => '请上传Excel文件']);
        }

        $uploadPath = Env::get('root_path') . 'public/uploads/';
        if (!is_dir($uploadPath)) {
            mkdir($uploadPath, 0755, true);
        }

        $info = $xlsFile->move($uploadPath);
        if (!$info) {
            return json(['code' => -1, 'msg' => '文件上传失败：' . $xlsFile->getError()]);
        }

        $filePath = $uploadPath . $info->getSaveName();

        try {
            $reader = IOFactory::createReaderForFile($filePath);
            $spreadsheet = $reader->load($filePath);
            $sheet = $spreadsheet->getActiveSheet();
            $data = $sheet->toArray(null, true, true, true);
        } catch (\Exception $e) {
            return json(['code' => -1, 'msg' => '读取Excel出错：' . $e->getMessage()]);
        }

        $headers = array_shift($data); // 表头
        $current_time = date("Y-m-d H:i:s");
        $pr_user = Session::get('username');

        Db::startTrans();
        try {
            $insertedCount = 0;
            $contactsData = [];

            foreach ($data as $row) {
                $rowAssoc = [];
                foreach ($headers as $key => $title) {
                    $rowAssoc[$title] = $row[$key] ?? '';
                }

                // 插入客户主表（crm_leads）
                $leadsRow = [
                    'kh_name'      => $rowAssoc['客户名称'] ?? '',
                    'kh_rank'      => $rowAssoc['客户等级'] ?? '',
                    'xs_area'      => $rowAssoc['地区'] ?? '',
                    'kh_contact'   => $rowAssoc['联系人'] ?? '',
                    'remark'       => $rowAssoc['客户备注'] ?? '',
                    'kh_status'    => $rowAssoc['客户来源'] ?? '',
                    'pr_user'      => $pr_user,
                    'ut_time'      => $current_time,
                    'at_time'      => $current_time,
                    'at_user'      => $pr_user,
                    'status'       => 1,
                    'ispublic'     => 3,
                    'pr_user_bef'  => $pr_user,
                ];

                $leadsId = Db::name('crm_leads')->insertGetId($leadsRow);

                // 构建联系人数据
                $contacts = [
                    [
                        'leads_id'      => $leadsId,
                        'contact_type'  => self::CONTACT_MAP['phone'],
                        'contact_value' => trim($rowAssoc['联系人电话'] ?? ''),
                        'contact_extra' => trim($rowAssoc['国家号'] ?? ''),
                        'created_at'    => $current_time
                    ],
                    [
                        'leads_id'      => $leadsId,
                        'contact_type'  => self::CONTACT_MAP['email'],
                        'contact_value' => trim($rowAssoc['联系人邮箱'] ?? ''),
                        'contact_extra' => '',
                        'created_at'    => $current_time
                    ],
                    [
                        'leads_id'      => $leadsId,
                        'contact_type'  => self::CONTACT_MAP['whatsapp'],
                        'contact_value' => trim($rowAssoc['联系人WhatsApp'] ?? ''),
                        'contact_extra' => '',
                        'created_at'    => $current_time
                    ],
                ];

                foreach ($contacts as $contact) {
                    if (!empty($contact['contact_value'])) {
                        $contactsData[] = $contact;
                    }
                }

                $insertedCount++;
            }

            // 批量插入联系方式表（crm_contacts）
            if (!empty($contactsData)) {
                Db::name('crm_contacts')
                    ->strict(false)
                    ->field(['leads_id', 'contact_type', 'contact_value', 'contact_extra', 'created_at'])
                    ->insertAll($contactsData);
            }

            Db::commit();
            return json(['code' => 0, 'msg' => '成功导入客户数据：' . $insertedCount . ' 条']);
        } catch (\Exception $e) {
            Db::rollback();
            return json([
                'code' => -1,
                'msg'  => '导入失败，第 ' . ($insertedCount + 1) . ' 行出错',
                'error' => $e->getMessage()
            ]);
        }
    }






    public function xlsUpload()
    {

        $xlsFile = request()->file('xlsFile');

        if (!$xlsFile) {
            return json(['code' => -1, 'msg' => '请上传Excel文件']);
        }

        // 配置文件上传规则
        $uploadConfig = [
            'size' => 1024 * 1024 * 20, // 20MB 文件大小限制
            'ext' => 'xlsx,xls', // 只允许上传 Excel 文件
        ];

        $uploadPath = Env::get('root_path') . 'public/uploads/';
        if (!is_dir($uploadPath)) {
            if (!mkdir($uploadPath, 0755, true)) {
                return json(['code' => -1, 'msg' => '上传目录创建失败，请检查权限']);
            }
        }
        $info = $xlsFile->validate($uploadConfig)->move($uploadPath, $this->generateUniqueFileName($xlsFile));
        if (!$info) {
            return json(['code' => -1, 'msg' => '文件上传失败：' . $xlsFile->getError()]);
        }

        $filePath = $uploadPath . $info->getSaveName();

        $fileHash = hash_file('sha256', $filePath);

        if (Cache::has('excel_import_hash:' . $fileHash)) {
            return json(['code' => -1, 'msg' => '该文件已上传过，请不要重复上传']);
        }

        Cache::set('excel_import_hash:' . $fileHash, true, 172800);

        try {
            $reader = IOFactory::createReaderForFile($filePath);
            $spreadsheet = $reader->load($filePath);
            $sheet = $spreadsheet->getActiveSheet();
            $data = $sheet->toArray(null, true, true, true);
        } catch (\Exception $e) {
            return json(['code' => -1, 'msg' => '读取Excel出错：' . $e->getMessage()]);
        }

        $headers = array_shift($data); // 表头
        $pr_user = Session::get('username');

        // 将数据拆分成小块，每块 100 条记录
        $chunkSize = 100;
        $chunks = array_chunk($data, $chunkSize);

        foreach ($chunks as $chunk) {
            $jobData = [
                'user_id'=> Session::get('aid'),
                'filePath' => $filePath,
                'pr_user' => $pr_user,
                'headers' => $headers,
                'chunkData' => $chunk
            ];

            // 将任务推送到队列
            queue(\app\admin\job\ExcelImport::class, $jobData, 0, 'excel_import');
        }

        return json(['code' => 0, 'msg' => '导入任务已提交，请稍后查看结果']);
    }


    // 生成唯一文件名
    private function generateUniqueFileName($file)
    {
        $fileInfo = $file->getInfo();
        $originalName = $fileInfo['name'] ?? '';
        // 利用 pathinfo 函数提取扩展名
        $ext = pathinfo($originalName, PATHINFO_EXTENSION);
        return uniqid() . '.' . $ext;
    }


    //数据校验
    private function checkData(&$contact)
    {
        $map = [
            'phone' => '手机号码',
            'email' => '邮箱',
            'whatsapp' => 'whatsapp',
            'ali_id' => '阿里id',
            'wechat' => '微信',
        ];
        $request = request();
        $phone_code = $request->param('phone_code');
        foreach (array_keys($map) as $k) {
            $value = $request->param($k);
            if ($value) {
                if (is_array($value)) {
                    foreach ($value as $i => $vv) {
                        if (!empty($vv)) $contact[$k] = $value;
                        else {
                            if ($k == 'phone') {
                                unset($phone_code[$i]);
                            }
                        }
                    }
                } else {
                    $contact[$k] = $value;
                }
            }
        }
        if (empty($contact)) return [false, '请至少填写WhatsApp、阿里id、微信、邮箱或号码中的一个'];
        $require_check = [];;
        foreach ($contact as $k => $v) {
            if (is_string($v)) $v = explode(',', $v);
            $duplicates = getDuplicates($v);
            if ($duplicates) {
                return [false, $map[$k] . ':' . implode(',', $duplicates) . ' 重复录入'];
            }
            $require_check = array_merge($require_check, $v);
        }

        if (!empty($contact['phone'])) {
            $contact['phone'] =  array_map(function ($code, $phone) {
                return [$code, $phone];
            },  $phone_code, $contact['phone']);
            foreach ($contact['phone'] as $item) {
                $require_check[] = $item[0] . $item[1];
            }
        }
        return [true, $require_check];
    }

    //当前录入查重
    private function checkDuplicate($data, $require_checke)
    {

        //更新
        $update = false;
        $where = [];
        if (isset($data['id'])) {
            $update = true;
            $where = [['id', '<>', $data['id']]];
        }
        $find = db('crm_leads')->where($where)->where(function ($query) use ($data) {
            // $query->where('kh_name','like','%'.$data['kh_name'].'%')
            $query->where('kh_name', $data['kh_name']);
            if ($data['kh_contact']) $query->whereOr('kh_contact', $data['kh_contact']);
        })->find();
        if ($find)  return [false, $find['kh_name'] . '客户信息已存在,当前所属人' . $find['pr_user']];
        //查询关联表crm_contacts数据表重复
        if ($update) $where = [['leads_id', '<>', $data['id']]];
        $contactExist = db('crm_contacts')->where($where)->where('is_delete', 0)->whereIn('contact_value', $require_checke)->find();
        if ($contactExist) {
            $find =  db('crm_leads')->where('id', $contactExist['leads_id'])->find();
            return [false, $contactExist['contact_value'] . '客户信息已存在,当前所属人' . $find['pr_user']];
        }
        return [true, ''];
    }

    //数据组装
    private function assemblyData($contact, $leads_id, $update = false)
    {
        $contactData = [];
        foreach ($contact as $k => $v) {
            $contact_type = self::CONTACT_MAP[$k];
            if (is_string($v)) $v = explode(',', $v);
            foreach ($v as $e => $c_v) {

                $contact_value = $c_v;
                $contact_extra = '';
                if (is_array($c_v)) {
                    $contact_extra = $c_v[0];
                    $contact_value = $c_v[1];
                }
                $temp = [
                    'leads_id' => $leads_id,
                    'contact_type' => $contact_type,
                    'contact_value' => $contact_value,
                    'contact_extra' => $contact_extra,
                    'is_delete' => 0,
                    'created_at' => date("Y-m-d H:i:s", time()),
                ];
                $contactData[] = $temp;
                if ($update) {
                    //插入或更新条数
                    $find = db('crm_contacts')->where(['is_delete' => 1, 'contact_value' => $contact_value])->find();
                    if ($find) {
                        db('crm_contacts')->where('id', $find['id'])->update($temp);
                    } else {
                        db('crm_contacts')->insert($temp);
                    }
                }
            }
        }
        return $contactData;
    }


    //新建客户
    public function add()
    {
        if (request()->isPost()) {
            // <!-- 客户名称、地区、行业类别、联系人、联系号码、客户级别、客户状态、用户名、备注 -->
            $contact = [];
            list($res, $require_check) = $this->checkData($contact);
            if (!$res) return fail($require_check);
            $data['kh_name'] = Request::param('kh_name');
            $data['xs_area'] = Request::param('xs_area');
            // $data['kh_contact'] = Request::param('kh_contact');
            $data['kh_rank'] = Request::param('kh_rank');
            $data['kh_status'] = Request::param('kh_status');
            // $data['kh_username'] = Request::param('kh_username');
            $data['remark'] = Request::param('remark');
            // $data['kh_need'] = Request::param('kh_need');
            $data['at_user'] = Session::get('username');
            $data['pr_user'] = Session::get('username');
            $data['pr_user_bef'] = Session::get('username');
            $data['ut_time'] = date("Y-m-d H:i:s", time());
            $data['at_time'] = date("Y-m-d H:i:s", time());
            $data['status'] = 1;
            $data['ispublic'] = 3;
            list($res, $msg) = $this->checkDuplicate($data, $require_check);
            if (!$res) return fail($msg);
            Db::startTrans();
            try {
                // 客户信息保存
                db('crm_leads')->insert($data);
                $id = Db::getLastInsID();
                if (!$id) {
                    return fail('客户信息插入失败');
                }
                $contactData = $this->assemblyData($contact, $id);
                db('crm_contacts')->insertAll($contactData);
                // 提交事务
                Db::commit();
                return success();
            } catch (\Exception $e) {
                // 回滚事务
                Db::rollback();
                return fail($e->getMessage());
            }
        }


        // $xsSourceList = Db::table('crm_clues_source')->select();
        $khRankList = Db::table('crm_client_rank')->select();
        $khStatusList = Db::table('crm_client_status')->select();
        $xsAreaList = Db::table('crm_clues_area')->select();
        $xsHangyeList = Db::table('crm_client_hangye')->select();
        $this->assign('xsHangyeList', $xsHangyeList);
        // $this->assign('xsAreaList', $xsAreaList);
        $this->assign('khRankList', $khRankList);
        $this->assign('khStatusList', $khStatusList);

        //新增地区联动
        $countries = $this->getCountries();
        $this->assign('countries', $countries);

        return $this->fetch('client/add');
    }
    //编辑客户
    public function edit()
    {
        if (Request::isAjax()) {
            $data = Request::param();
            $data['ut_time'] = date("Y-m-d H:i:s", time());
            $contact = [];
            list($res, $require_check) = $this->checkData($contact);
            if (!$res) return fail($require_check);
            list($res, $msg) = $this->checkDuplicate($data, $require_check);
            if (!$res) return fail($msg);
            unset($data['phone_code']);
            foreach (self::CONTACT_MAP as $k => $v) {
                unset($data[$k]);
            }

            Db::startTrans();
            try {
                //删除客户关联联系方式
                db('crm_contacts')->where(['leads_id' => $data['id']])->update(['is_delete' => 1]);

                $this->assemblyData($contact, $data['id'], true);
                //客户信息保存
                Db::table('crm_leads')->where(['id' => $data['id']])->where('status', 1)->update($data);
                // 提交事务
                Db::commit();
                return success();
            } catch (\Exception $e) {
                // 回滚事务
                Db::rollback();
                return fail($e->getMessage());
            }
        }


        $result = Db::table('crm_leads')->where(['id' => Request::param('id')])->find();

        $this->assign('result', $result);

        // $xsSourceList = Db::table('crm_clues_source')->select();
        $khRankList = Db::table('crm_client_rank')->select();
        $khStatusList = Db::table('crm_client_status')->select();
        // $xsAreaList = Db::table('crm_clues_area')->select();
        $xsHangyeList = Db::table('crm_client_hangye')->select();
        $this->assign('xsHangyeList', $xsHangyeList);
        // $this->assign('xsAreaList', $xsAreaList);
        // $this -> assign('xsSourceList',$xsSourceList);
        $this->assign('khRankList', $khRankList);
        $this->assign('khStatusList', $khStatusList);
        //新增地区联动
        $countries = $this->getCountries();
        $this->assign('countries', $countries);
        //客户关联联系方式
        $select = db('crm_contacts')->where(['leads_id' => $result['id'], 'is_delete' => 0])->select();
        $con_map = array_flip(self::CONTACT_MAP);
        $contact = [];

        foreach ($select as $c) {
            $value = $c['contact_extra'] ? $c['contact_extra'] . '#' . $c['contact_value'] : $c['contact_value'];
            // $contact[$con_map[$c['contact_type']]][] = $value;
            $contact[$c['contact_type']][] = $value;
        }
        foreach (self::CONTACT_MAP as $v) {
            if (!isset($contact[$v])) $contact[$v][] = '';
        }
        $contacts = [];
        foreach ($contact as $key => $value) {
            if (isset($con_map[$key])) {
                $contacts[$con_map[$key]] = $value;
            }
        }
        unset($con_map, $contact);
        $this->assign('contact', $contacts);
        return $this->fetch('client/edit');
    }
    //删除客户
    // 修改del方法支持批量删除
    public function del()
    {
        $ids = Request::post('ids');

        if (!$ids || !is_array($ids)) {
            return json(['code' => 500, 'msg' => '请选择要删除的客户']);
        }

        $username = Session::get('username');

        Db::startTrans();
        try {
            // 验证并删除客户
            $clients = Db::name('crm_leads')
                ->where('id', 'in', $ids)
                ->where(function ($query) use ($username) {
                    $query->where('pr_user', $username)
                        ->whereOr('pr_user_bef', $username);
                })
                ->select();

            if (empty($clients)) {
                throw new \Exception('无权限删除选中客户');
            }

            // 删除主表记录和关联数据
            foreach ($ids as $id) {
                Db::name('crm_contacts')->where('leads_id', $id)->delete();
            }

            Db::name('crm_leads')->where('id', 'in', $ids)->delete();

            Db::commit();
            return json(['code' => 0, 'msg' => '删除成功']);
        } catch (\Exception $e) {
            Db::rollback();
            return json(['code' => 500, 'msg' => $e->getMessage()]);
        }
    }



    //客户级别
    public function rankList()
    {
        if (request()->isPost()) {
            $page = input('page') ? input('page') : 1;
            $pageSize = input('limit') ? input('limit') : config('pageSize');
            $list = db('crm_client_rank')
                ->paginate(array('list_rows' => $pageSize, 'page' => $page))
                ->toArray();
            return $result = ['code' => 0, 'msg' => '获取成功!', 'data' => $list['data'], 'count' => $list['total'], 'rel' => 1];
        }
        return $this->fetch();
    }
    //添加客户级别
    public function rankAdd()
    {
        if (request()->isPost()) {
            $data['rank_name'] = Request::param('rank_name');
            $data['add_time'] = time();
            $result = Db::table('crm_client_rank')->insert($data);
            if ($result) {
                $msg = ['code' => 0, 'msg' => '添加成功！', 'data' => []];
                return json($msg);
            } else {
                $msg = ['code' => 500, 'msg' => '添加失败！', 'data' => []];
                return json($msg);
            }
        }
        return $this->fetch('client/rank_list_add');
    }
    //编辑客户级别
    public function rankEdit()
    {
        if (Request::isAjax()) {
            $data  = Request::param();
            // 获取原状态
            $oldstatus = Db::table('crm_client_rank')->where(['id' => $data['id']])->find();
            $oldstatusname = $oldstatus['rank_name'];
            $ischange = false;
            if ($oldstatusname == $data['rank_name']) {
                $msg = ['code' => 500, 'msg' => '没有变化无需修改', 'data' => []];
                return json($msg);
            } else {
                $ischange = true;
            }

            $result = Db::table('crm_client_rank')->where(['id' => $data['id']])->update($data);
            if ($result) {
                // 状态修改后 客户编辑的原来状态都必须修改
                if ($ischange) {
                    // 所有的客户状态全部膝盖
                    $result2 = Db::table('crm_leads')->where(['kh_rank' => $oldstatusname])->update(['kh_rank' => $data['rank_name']]);
                }
                $msg = ['code' => 0, 'msg' => '编辑成功！', 'data' => []];
                return json($msg);
            } else {
                $msg = ['code' => 500, 'msg' => '编辑失败！', 'data' => []];
                return json($msg);
            }
        }

        $result = Db::table('crm_client_rank')->where(['id' => Request::param('id')])->find();
        $this->assign('result', $result);
        return $this->fetch('client/rank_list_edit');
    }
    //删除客户级别
    public function rankDel()
    {
        $id = Request::param('id');
        // 获取原状态
        $oldstatus = Db::table('crm_client_rank')->where(['id' => $data['id']])->find();
        $oldstatusname = $oldstatus['rank_name'];

        $result = Db::table('crm_client_rank')->where('id', $id)->delete();
        if ($result) {
            // 所有的客户状态全部膝盖
            $result2 = Db::table('crm_leads')->where(['kh_rank' => $oldstatusname])->update(['kh_rank' => '']);
            $msg = ['code' => 0, 'msg' => '删除成功！', 'data' => []];
            return json($msg);
        } else {
            $msg = ['code' => 500, 'msg' => '删除失败！', 'data' => []];
            return json($msg);
        }
    }


    //客户状态
    public function statusList()
    {
        if (request()->isPost()) {
            $page = input('page') ? input('page') : 1;
            $pageSize = input('limit') ? input('limit') : config('pageSize');
            $list = db('crm_client_status')
                ->paginate(array('list_rows' => $pageSize, 'page' => $page))
                ->toArray();
            return $result = ['code' => 0, 'msg' => '获取成功!', 'data' => $list['data'], 'count' => $list['total'], 'rel' => 1];
        }
        return $this->fetch();
    }
    //添加客户状态
    public function statusAdd()
    {
        if (request()->isPost()) {
            $data['status_name'] = Request::param('status_name');
            $data['add_time'] = time();
            $result = Db::table('crm_client_status')->insert($data);
            if ($result) {
                $msg = ['code' => 0, 'msg' => '添加成功！', 'data' => []];
                return json($msg);
            } else {
                $msg = ['code' => 500, 'msg' => '添加失败！', 'data' => []];
                return json($msg);
            }
        }
        return $this->fetch('client/status_list_add');
    }
    //编辑客户状态
    public function statusEdit()
    {
        if (Request::isAjax()) {
            $data  = Request::param();
            // 获取原状态
            $oldstatus = Db::table('crm_client_status')->where(['id' => $data['id']])->find();
            $oldstatusname = $oldstatus['status_name'];
            $newstatusname = $data['status_name'];
            $ischange = false;
            if ($oldstatusname == $newstatusname) {
                $msg = ['code' => 500, 'msg' => '状态没有变化无需修改', 'data' => []];
                return json($msg);
            } else {
                $ischange = true;
            }
            $result = Db::table('crm_client_status')->where(['id' => $data['id']])->update($data);
            if ($result) {
                // 状态修改后 客户编辑的原来状态都必须修改
                if ($ischange) {
                    // 所有的客户状态全部膝盖
                    $result2 = Db::table('crm_leads')->where(['kh_status' => $oldstatusname])->update(['kh_status' => $newstatusname]);
                }
                $msg = ['code' => 0, 'msg' => '编辑成功！', 'data' => []];
                return json($msg);
            } else {
                $msg = ['code' => 500, 'msg' => '编辑失败！', 'data' => []];
                return json($msg);
            }
        }


        $result = Db::table('crm_client_status')->where(['id' => Request::param('id')])->find();
        $this->assign('result', $result);
        return $this->fetch('client/status_list_edit');
    }
    //删除客户状态
    public function statusDel()
    {
        $id = Request::param('id');
        // 获取原状态
        $oldstatus = Db::table('crm_client_status')->where(['id' => $data['id']])->find();
        $oldstatusname = $oldstatus['status_name'];
        // $ischange = false;
        // if ($oldstatusname == $data['status_name']) {
        //     $msg = ['code' => 500,'msg'=>'状态没有变化无需修改','data'=>[]];
        //     return json($msg);
        // }else{
        //     $ischange = true;
        // }
        $result = Db::table('crm_client_status')->where('id', $id)->delete();
        if ($result) {
            // 所有的客户状态全部膝盖
            $result2 = Db::table('crm_leads')->where(['kh_status' => $oldstatusname])->update(['kh_status' => '']);
            $msg = ['code' => 0, 'msg' => '删除成功！', 'data' => []];
            return json($msg);
        } else {
            $msg = ['code' => 500, 'msg' => '删除失败！', 'data' => []];
            return json($msg);
        }
    }


    //移入公海
    public function toMoveGh()
    {
        //1，获取提交的线索ID 【1,2,3,4,】
        $ids = Request::param('ids');
        $this->assign('ids', $ids);
        if (Request::isAjax()) {
            $pr_gh_type = Request::param('pr_gh_type');
            $idsArr = explode(",", $ids);


            $count = 0;
            foreach ($idsArr as $key => $value) {
                // $data['pr_user_bef'] = Db::table('crm_leads')->where(['id'=>$value])->field('pr_user')->find();
                // $data['pr_user'] = $username;
                // $data['id'] = $value;
                // $insertAll = Db::name('crm_leads')->update($data);
                $data['pr_gh_type'] = $pr_gh_type;
                $data['to_gh_time'] = date("Y-m-d H:i:s", time());
                $data['status'] = 2; //0-线索，1客户，2公海
                $data['id']  = $value;
                $result = Db::table('crm_leads')->where(['id' => $value])->update($data);
                if ($result) {
                    $count++;
                }
                // 添加日志记录
                // $this->addOperLog(
                //     $value,
                //     '移入公海',
                //     "移入 [{$pr_gh_type}] 公海池"
                // );
            }
            if ($count > 0) {
                $msg = ['code' => 0, 'msg' => $count . '个客户移入公海成功！', 'data' => []];
                return json($msg);
            } else {
                $msg = ['code' => 500, 'msg' => '转入公海失败！', 'data' => []];
                return json($msg);
            }
            // $data['pr_gh_type'] = Request::param('pr_gh_type');
            // $data['to_gh_time'] = date("Y-m-d H:i:s",time());
            // $data['status'] = 2;//0-线索，1客户，2公海
            // $data['id']  = Request::param('id');
            // $result = Db::table('crm_leads')->where(['id'=>$data['id']])->update($data);
            // if ($result){
            //     $msg = ['code' => 0,'msg'=>'移入公海成功！','data'=>[]];
            //     return json($msg);
            // }else{
            //     $msg = ['code' => 500,'msg'=>'抱歉，移入公海失败！','data'=>[]];
            //     return json($msg);
            // }
        }


        $libTypeList = Db::table('crm_liberum_type')->select();

        $this->assign('libTypeList', $libTypeList);

        // $result = Db::table('crm_leads') ->where(['id' => Request::param('id')])->find();
        // $this -> assign('result',$result);
        return $this->fetch('client/move_gh');
    }
    //客户搜索
    public function clientSearch()
    {
        $page = input('page') ? input('page') : 1;
        $limit = input('limit') ? input('limit') : config('pageSize');
        $keyword = Request::param('keyword');
        $list = model('client')->getClientSearchList($page, $limit, $keyword);
        return $result = ['code' => 0, 'msg' => '获取成功!', 'data' => $list['data'], 'count' => $list['total'], 'rel' => 1];
    }
    //（我的客户）搜索
    public function personClientSearch()
    {
        $page = input('page') ? input('page') : 1;
        $limit = input('limit') ? input('limit') : config('pageSize');
        $keyword = Request::param('keyword');
        $list = model('client')->getPersonClientSearchList($page, $limit, $keyword);
        return $result = ['code' => 0, 'msg' => '获取成功!', 'data' => $list['data'], 'count' => $list['total'], 'rel' => 1];
    }
    //（我的客户）搜索
    public function chengjiaoClientSearch()
    {
        $page = input('page') ? input('page') : 1;
        $limit = input('limit') ? input('limit') : config('pageSize');
        $keyword = Request::param('keyword');
        $list = model('client')->getChengjiaoClientSearchList($page, $limit, $keyword);
        return $result = ['code' => 0, 'msg' => '获取成功!', 'data' => $list['data'], 'count' => $list['total'], 'rel' => 1];
    }
    //写跟进
    public function dialogue()
    {
        $result = Db::table('crm_leads')->where(['id' => Request::param('id')])->find();

        $result['comment'] = Db::table('crm_comment')->alias('com')->join('admin adm', 'com.user_id = adm.admin_id')->where(['leads_id' => Request::param('id')])->field('com.*,adm.username,adm.avatar')->select();
        foreach ($result['comment'] as $k => $v) {
            $result['comment'][$k]['reply'] = Db::table('crm_reply')->where(['comment_id' => $v['id']])->select();
        }
        $result['contacts'] = Db::table('crm_contacts')->where('is_delete', 0)->where(['leads_id' => Request::param('id')])->select();
        $contactGroup = $this->formatContact($result['contacts'])[$result['id']];
        $result['contacts'] = $this->getContactType($contactGroup);
        $cid = Session::get('aid'); //获取当前登录账号
        $curname = Session::get('username'); //获取当前登录账号
        $curname = Session::get('username'); //获取当前登录账号



        //$this ->assign('cid',$cid);  //获取当前登录账号$data['id']
        $group_id = Db::table('admin')->where(['admin_id' => $cid])->field('group_id')->find();


        $this->assign('group_id', $group_id['group_id']);  //获取当前登录权限组账号

        $this->assign('curname', $curname);  //获取当前登录账号

        $this->assign('result', $result);
        //$this ->assign('result1',integer($result['id']));  //跟进上一个  下一个 获取当前id。
        return $this->fetch('client/dialogue');
    }

    //评论
    public function comment()
    {

        $data['leads_id'] = Request::param('leads_id');
        $data['user_id'] = Session::get('aid');
        $data['reply_msg'] = Request::param('reply_msg');
        $data['create_date'] = time();

        //更新跟进记录
        $genjin['last_up_records'] = $data['reply_msg'];
        $genjin['last_up_time'] = date("Y-m-d H:i:s", $data['create_date']);
        $genjin['ut_time'] = date("Y-m-d H:i:s", time());

        Db::table('crm_leads')->where(['id' => $data['leads_id']])->update($genjin);

        $result = Db::table('crm_comment')->insert($data);
        $data['create_date'] = date("Y年m月d日 H:i", $data['create_date']);

        if ($result) {
            return json(['code' => 0, 'msg' => '评论成功！', 'data' => $data]);
        } else {
            return json(['code' => 1, 'msg' => '评论失败！']);
        }
    }

    //回复
    public function reply()
    {

        $data['comment_id'] = Request::param('cid');
        $data['from_user_id'] = Session::get('user.id');
        $data['to_user_id'] = Request::param('to_uid');
        $data['reply_msg'] = Request::param('reply_msg');
        $data['create_date'] = time();

        $result = Db::table('crm_reply')->insert($data);
        $data['create_date'] = date("Y年m月d日 H:i", $data['create_date']);
        if ($result) {
            return json(['code' => 0, 'msg' => '回复成功！', 'data' => $data]);
        } else {

            return json(['code' => 1, 'msg' => '回复失败！']);
        }
    }


    //客户转移，变更负责人
    public function alterPrUser()
    {
        //1，获取提交的线索ID 【1,2,3,4,】
        $ids = Request::param('ids');
        $this->assign('ids', $ids);


        //查询所有管理员（去除admin）
        $adminResult = Db::name('admin')->where('group_id', '<>', 1)->field('admin_id,username')->select();
        $this->assign('adminResult', $adminResult);

        if (Request::isAjax()) {
            $username = Request::param('username');
            $idsArr = explode(",", $ids);


            $count = 0;
            foreach ($idsArr as $key => $value) {
                $data['pr_user_bef'] = Db::table('crm_leads')->where(['id' => $value])->field('pr_user')->find();
                $data['pr_user'] = $username;
                $data['id'] = $value;
                $insertAll = Db::name('crm_leads')->update($data);
                if ($insertAll) {
                    $count++;
                }
            }




            if ($count > 0) {
                $msg = ['code' => 0, 'msg' => '转移' . $count . '个客户成功！', 'data' => []];
                return json($msg);
            } else {
                $msg = ['code' => 500, 'msg' => '转移失败！', 'data' => []];
                return json($msg);
            }
        }

        return $this->fetch('client/alter_pr_user');
    }


    //客户转移，变更负责人(个人)
    public function alterPrUserPri()
    {
        //1，获取提交的线索ID 【1,2,3,4,】
        $ids = Request::param('ids');
        $this->assign('ids', $ids);
        //客户信息
        $clientList = Db::name('crm_leads')->where('id', 'in', $ids)->field('id,kh_name')->select();
        $clientName = implode(',', array_column($clientList, 'kh_name'));
        $this->assign('client_name', $clientName);

        //查询所有管理员（去除admin）
        $adminResult = Db::name('admin')->where('group_id', '<>', 1)->field('admin_id,username')->select();
        $this->assign('adminResult', $adminResult);

        if (Request::isAjax()) {
            $username = Request::param('username');
            $idsArr = explode(",", $ids);


            $count = 0;
            foreach ($idsArr as $key => $value) {
                $data['pr_user_bef'] = Db::table('crm_leads')->where(['id' => $value])->field('pr_user')->find();
                $data['pr_user'] = $username;
                $data['id'] = $value;
                $insertAll = Db::name('crm_leads')->update($data);
                if ($insertAll) {
                    $count++;
                }
                // 添加日志记录
                // $this->addOperLog(
                //     $value,
                //     '转移负责人',
                //     "从 [{$data['pr_user_bef']['pr_user']}] 转移给 [{$username}]"
                // );
            }




            if ($count > 0) {
                $msg = ['code' => 0, 'msg' => '转移' . $count . '个客户成功！', 'data' => []];
                return json($msg);
            } else {
                $msg = ['code' => 500, 'msg' => '转移失败！', 'data' => []];
                return json($msg);
            }
        }

        return $this->fetch('personclient/alter_pr_user');
    }

    //客户行业
    public function hangyeList()
    {
        if (request()->isPost()) {
            $page = input('page') ? input('page') : 1;
            $pageSize = input('limit') ? input('limit') : config('pageSize');
            $list = db('crm_client_hangye')
                ->paginate(array('list_rows' => $pageSize, 'page' => $page))
                ->toArray();
            return $result = ['code' => 0, 'msg' => '获取成功!', 'data' => $list['data'], 'count' => $list['total'], 'rel' => 1];
        }
        return $this->fetch();
    }
    //添加客户级别
    public function hangyeAdd()
    {
        if (request()->isPost()) {
            $data['hy_name'] = Request::param('hy_name');
            $data['add_time'] = time();
            $result = Db::table('crm_client_hangye')->insert($data);
            if ($result) {
                $msg = ['code' => 0, 'msg' => '添加成功！', 'data' => []];
                return json($msg);
            } else {
                $msg = ['code' => 500, 'msg' => '添加失败！', 'data' => []];
                return json($msg);
            }
        }
        return $this->fetch('client/hangye_list_add');
    }
    //编辑客户级别
    public function hangyeEdit()
    {
        if (Request::isAjax()) {
            $data  = Request::param();
            // 获取原状态
            $oldstatus = Db::table('crm_client_hangye')->where(['id' => $data['id']])->find();
            $oldstatusname = $oldstatus['hy_name'];
            $ischange = false;
            if ($oldstatusname == $data['hy_name']) {
                $msg = ['code' => 500, 'msg' => '没有变化无需修改', 'data' => []];
                return json($msg);
            } else {
                $ischange = true;
            }

            $result = Db::table('crm_client_hangye')->where(['id' => $data['id']])->update($data);
            if ($result) {
                // 状态修改后 客户编辑的原来状态都必须修改
                if ($ischange) {
                    // 所有的客户状态全部膝盖
                    $result2 = Db::table('crm_leads')->where(['kh_hangye' => $oldstatusname])->update(['kh_hangye' => $data['hy_name']]);
                }
                $msg = ['code' => 0, 'msg' => '编辑成功！', 'data' => []];
                return json($msg);
            } else {
                $msg = ['code' => 500, 'msg' => '编辑失败！', 'data' => []];
                return json($msg);
            }
        }

        $result = Db::table('crm_client_hangye')->where(['id' => Request::param('id')])->find();
        $this->assign('result', $result);
        return $this->fetch('client/hangye_list_edit');
    }
    //删除客户级别
    public function hangyeDel()
    {
        $id = Request::param('id');
        // 获取原状态
        $oldstatus = Db::table('crm_client_hangye')->where(['id' => $data['id']])->find();
        $oldstatusname = $oldstatus['hy_name'];

        $result = Db::table('crm_client_hangye')->where('id', $id)->delete();
        if ($result) {
            // 所有的客户状态全部膝盖
            $result2 = Db::table('crm_leads')->where(['kh_hangye' => $oldstatusname])->update(['kh_hangye' => '']);
            $msg = ['code' => 0, 'msg' => '删除成功！', 'data' => []];
            return json($msg);
        } else {
            $msg = ['code' => 500, 'msg' => '删除失败！', 'data' => []];
            return json($msg);
        }
    }

    //新增各国区号
    public function getCountries()
    {
        $countries = cache('countries');
        //清除缓存
        // cache('countries',null);
        if ($countries) {
            return $countries;
        }
        $list = Db::table('countries')->field('phone_code,english_name,chinese_name')->select();
        foreach ($list as $key => $value) {
            $_key = $value['english_name'] . '(' . $value['chinese_name'] . ')';
            $countries[$_key] =  $value['phone_code'];
        }
        cache('countries', $countries);
        return $countries;
    }

    //客户成交
    public function chengjiao()
    {
        $ids = Request::param('ids');
        if (is_string($ids)) $ids = explode(',', $ids);
        $count = 0;
        foreach ($ids as $key => $value) {
            $data['issuccess'] = 1;
            $data['id'] = $value;
            $insertAll = Db::name('crm_leads')->update($data);
            if ($insertAll) {
                $count++;
            }
        }
        if ($count > 0) {
            $msg = ['code' => 0, 'msg' => '成交' . $count . '个客户成功！', 'data' => []];
            return json($msg);
        } else {
            $msg = ['code' => 500, 'msg' => '成交失败！', 'data' => []];
            return json($msg);
        }
    }



    //冲突查询
    public function conflictOld()
    {
        $keyword = Request::param('keyword');
        $keyword = trim(preg_replace('/[+\-\s]/', '', $keyword));
        if (Request::isAjax()) {
            if (empty($keyword)) return success();

            $query = Db::name('crm_leads')
                ->alias('l')
                ->leftJoin('crm_contacts c', 'l.id = c.leads_id AND c.is_delete = 0')
                ->field('l.kh_name,l.xs_area,l.kh_rank,l.kh_status,l.at_user,l.at_time,l.pr_gh_type,l.pr_user')
                ->group('l.id');
            $query->where(function ($q) use ($keyword) {
                $q->where('l.kh_name', 'like', "%{$keyword}%")
                    ->whereOr(function ($q2) use ($keyword) {
                        $q2->where('c.contact_value', $keyword)
                            ->whereOrRaw("CONCAT(c.contact_extra, c.contact_value) = '{$keyword}'");
                    });
            });

            $page = Request::param('page/d', 1);
            $pageSize = Request::param('limit/d', 10);
            $list = $query->paginate($pageSize, false, ['page' => $page])->items();
            return success($list);
        }
        $this->assign('keyword', $keyword);
        return $this->fetch('client/conflict');
    }

    // //冲突查询
    // public function conflict()
    // {
    //     $_keyword = Request::param('keyword');
    //     $keyword = trim(preg_replace('/[+\-\s]/', '', $_keyword));
    //     if (Request::isAjax()) {
    //         if (empty($keyword)) return success();
    //         $leadsQuery = Db::name('crm_leads')
    //             ->alias('l')
    //             ->field('l.id, l.kh_name, l.xs_area, l.kh_rank, l.kh_status, l.at_user, l.at_time,l.pr_gh_type,l.pr_user')
    //             ->field('NULL as contact_type, NULL as contact_value')
    //             ->where('l.kh_name', 'like', "%{$keyword}%");

    //         $contactsQuery = Db::name('crm_contacts')
    //             ->alias('c')
    //             ->leftJoin('crm_leads l', 'l.id = c.leads_id')
    //             ->where('c.is_delete', 0)
    //             ->where(function ($q) use ($keyword,$_keyword) {
    //                 $q->where('c.contact_value','like', $keyword)
    //                     ->whereOrRaw("CONCAT(c.contact_extra, c.contact_value) like '%{$keyword}%'");
    //                 if($_keyword != $keyword)$q->whereOr('c.contact_value','like', "%{$_keyword}%");
    //             })
    //             ->field('l.id, l.kh_name, l.xs_area, l.kh_rank, l.kh_status, l.at_user, l.at_time,l.pr_gh_type,l.pr_user,c.contact_type,c.contact_value');

    //         $query = Db::query("({$leadsQuery->buildSql()}) UNION ({$contactsQuery->buildSql()})");

    //          // 去除重复记录
    //         $uniqueIds = [];
    //         $list = [];
    //         foreach ($query as $item) {
    //             if (!in_array($item['id'], $uniqueIds)) {
    //                 $uniqueIds[] = $item['id'];
    //                 $list[] = $item;
    //             }
    //         }
            
    //         // 保存总记录数
    //         $total = count($list);
            
    //         // 分页处理
    //         $page = Request::param('page/d', 1);
    //         $pageSize = Request::param('limit/d', 10);
    //         $offset = ($page - 1) * $pageSize;
    //         $paginatedList = array_slice($list, $offset, $pageSize);

    //         // 添加重复类型信息
    //         foreach ($paginatedList as &$item) {
    //             if (isset($item['contact_type'])) {
    //                 // 修改contact_type判断逻辑，使用数字类型匹配
    //                 switch ((int)$item['contact_type']) {
    //                     case 3:
    //                         $item['repeat_info'] = 'WhatsApp：' . $item['contact_value'];
    //                         break;
    //                     case 1:
    //                         $item['repeat_info'] = '电话：' . $item['contact_value'];
    //                         break;
    //                     case 2:
    //                         $item['repeat_info'] = '邮箱：' . $item['contact_value'];
    //                         break;
    //                     case 4:
    //                         $item['repeat_info'] = '阿里ID：' . $item['contact_value'];
    //                         break;
    //                     case 5:
    //                         $item['repeat_info'] = '微信：' . $item['contact_value'];
    //                         break;
    //                     default:
    //                         $item['repeat_info'] = '未知类型(' . $item['contact_type'] . ')：' . $item['contact_value'];
    //                 }
    //             } else {
    //                 $item['repeat_info'] = '客户名称重复';
    //             }
    //         }
    //         unset($item);

    //         return json([
    //             'code' => 0,
    //             'msg' => '',
    //             'count' => $total,
    //             'data' => $paginatedList
    //         ]);
    //     }
    //     $this->assign('keyword', $_keyword);
    //     return $this->fetch('client/conflict');
    // }
    
    
    public function conflict()
    {
        // 获取并清理关键词（去除空格和特殊字符）
        $_keyword = Request::param('keyword');
        $keyword = trim(preg_replace('/[+\-\s]/', '', $_keyword));
        if (Request::isAjax()) {
            if (empty($keyword)) {
                // 关键词为空，直接返回空结果
                return json(['code' => 0, 'msg' => '', 'data' => []]);
            }
            // 生成唯一任务ID，用于关联查重结果
            $taskId = uniqid('', true);  // 例如：生成类似 5f2e5c7fbd98e 的唯一ID
            // 准备任务数据，包含任务ID和原始关键词
            $jobData = [
                'id'      => $taskId,
                'keyword' => $_keyword  // 保留原始关键词，后端队列处理时再做trim处理
            ];
            // 将任务数据推送到Redis队列
            $redis = new \Redis();
            $redis->connect('127.0.0.1', 6379);
            // 若Redis设置了密码，可使用 $redis->auth('密码');
            $redis->rPush('conflict_queue', json_encode($jobData));
            // 返回任务已创建的响应，携带任务ID
            return json([
                'code'    => 0,
                'msg'     => '查重任务已提交',
                'task_id' => $taskId   // 前端据此轮询结果
            ]);
        }
        // 非Ajax请求，渲染页面（保留原有逻辑）
        $this->assign('keyword', $_keyword);
        return $this->fetch('client/conflict');
    }
    
    
    public function getConflictResult()
    {
        $taskId = Request::param('task_id');
        if (empty($taskId)) {
            return json(['code' => 500, 'msg' => '缺少任务ID', 'data' => []]);
        }
        // 查询Redis是否存在该任务的结果
        $redis = new \Redis();
        $redis->connect('127.0.0.1', 6379);
        // 若有密码，同样需要 $redis->auth('密码');
        $resultKey = 'conflict_result:' . $taskId;
        $resultData = $redis->get($resultKey);
        if (!$resultData) {
            // 结果尚未生成，返回状态码表示处理中（前端据此继续轮询）
            return json(['code' => 202, 'msg' => '查重处理中，请稍后...', 'data' => []]);
        }
        // Redis中存在结果，解析结果数据
        $resultList = json_decode($resultData, true);
        // 获取结果后，可以选择删除该结果键，防止占用内存（也可设置过期时间自动删除）
        $redis->del($resultKey);
        // 返回查重结果，结构与原接口一致（code=0，包含数据和条目数）
        return json([
            'code'  => 0,
            'msg'   => '获取成功',
            'data'  => $resultList,
            'count' => count($resultList)
        ]);
    }
    
    
}
