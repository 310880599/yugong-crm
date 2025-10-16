<?php
namespace app\admin\model;

use think\Model;

class Feedback extends Model
{
    // 设置当前模型对应的完整数据表名称
    protected $table = 'crm_feedback';
    
    // 设置当前模型的数据库连接
    protected $connection = '';
    
    // 自动写入时间戳
    protected $autoWriteTimestamp = true;
    
    // 定义时间戳字段名
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';
    
    // 转换为时间格式
    protected $type = [
        'create_time' => 'timestamp:Y-m-d H:i:s',
        'update_time' => 'timestamp:Y-m-d H:i:s'
    ];
    
    /**
     * 获取状态文本
     */
    public function getStatusTextAttr($value, $data)
    {
        $status = [0 => '未解决', 1 => '已解决'];
        return $status[$data['status']];
    }
}