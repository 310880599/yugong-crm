<?php
namespace app\model;

use think\Model;

class Feedback extends Model
{
    // 定义时间戳字段名
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';
    
    // 自动写入时间戳
    protected $autoWriteTimestamp = true;
    
    /**
     * 获取状态文本
     */
    public function getStatusTextAttr($value, $data)
    {
        $status = [0 => '未处理', 1 => '处理中', 2 => '已解决'];
        return $status[$data['status']];
    }
}