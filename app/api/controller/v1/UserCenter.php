<?php
/**
 * Created by PhpStorm.
 * User: liaozijie
 * Date: 2018-04-25
 * Time: 9:28
 */

namespace app\api\controller\v1;

use app\api\model\CustomerOrder;
use think\Controller;
use think\Db;
class UserCenter extends Home
{

    /**
     * 首页统计
     * @return json
     * */
    public function index(){
        $data = array();
        $customerModel    = model('CustomerOrder');
        $customerOrgModel = model('CustomerOrg');
        $data['intensityCount'] = $customerOrgModel->customerCount($this->userId, $this->orgId, true, $this->isRole);
        $data['customer'] = [
            'userCount'    => $customerOrgModel->customerCount($this->userId, $this->orgId, false, $this->isRole),
            'total'        => $customerModel->orderCount('', $this->userId, $this->orgId, $this->isRole),
            'unpayDeposit' => $customerModel->orderCount(1, $this->userId, $this->orgId, $this->isRole),
            'bankAudit'    => $customerModel->orderCount(3, $this->userId, $this->orgId, $this->isRole),
            'undelivery'   => $customerModel->orderCount(5, $this->userId, $this->orgId, $this->isRole),
            'others'       => $customerModel->orderCount(['customer_order_state', ['>',5], ['<',15], 'and'], $this->userId, $this->orgId, $this->isRole),
            'unfinished'   => $customerModel->orderCount(15, $this->userId, $this->orgId, $this->isRole),
            'finished'     => $customerModel->orderCount(17, $this->userId, $this->orgId, $this->isRole),
            'insurance'    => $customerModel->orderFeeCount('insurance', $this->userId, $this->orgId, $this->isRole),
            'mortgage'     => $customerModel->orderFeeCount('mortgage', $this->userId, $this->orgId, $this->isRole),
            'boutique'     => $customerModel->orderFeeCount('boutique', $this->userId, $this->orgId, $this->isRole),
            'license'      => $customerModel->orderFeeCount('license', $this->userId, $this->orgId, $this->isRole),
        ];

        $consumerModel = model('ConsumerOrder');
        $data['consumer'] = [
            'placeOrder'        => $consumerModel->orderCount(1, $this->userId, $this->orgId, $this->isRole),//开单
            'total'             => $consumerModel->orderCount('', $this->userId, $this->orgId, $this->isRole),//本月总订单
            'unpayDeposit'      => $consumerModel->orderCount(5, $this->userId, $this->orgId, $this->isRole),//待收定金
//            'unpayfinalpayment' => $consumerModel->orderCount(25, $this->userId, $this->orgId, $this->isRole),//待换车
            'carMatch'          => $consumerModel->orderCount(10, $this->userId, $this->orgId, $this->isRole),//待配车
            'carCheck'          => $consumerModel->orderCount(15, $this->userId, $this->orgId, $this->isRole),//待验车
//            'consulting'        => $consumerModel->orderCount(30, $this->userId, $this->orgId, $this->isRole),//待协商
            'finnalprice'       => $consumerModel->orderCount(35, $this->userId, $this->orgId, $this->isRole),//待收尾款
            'outStock'          => $consumerModel->orderCount(40, $this->userId, $this->orgId, $this->isRole),//待出库
            'tickitUploading'   => $consumerModel->orderCount(45, $this->userId, $this->orgId, $this->isRole),//待上传票证
            'commercial'        => $consumerModel->orderFeeCount('commercial', $this->userId, $this->orgId, $this->isRole),//商业险
            'traffic'           => $consumerModel->orderFeeCount('traffic', $this->userId, $this->orgId, $this->isRole),//交强险
        ];

        $data['commission'] = [
            'returnVisit' => $customerModel->getReturnVisitCount($this->userId, $this->orgId, $this->isRole),
            'appointment' => $customerOrgModel->customerAppointmentCount($this->userId, $this->orgId, $this->isRole)
        ];

        $this->apiReturn(200, $data);
    }

    public function customers(){
        $type  = isset($this->data['type']) && !empty($this->data['type']) ? trim($this->data['type']) : 'all';
        !in_array($type, ['all', 'intensity', 'visit']) && $this->apiReturn(201, '', '参数非法');

        $where = ['org_id' => $this->orgId];
        if(!$this->isRole){
            $where['system_user_id'] = $this->userId;
        }

        if($type == 'intensity'){
            $where['intensity'] = '高';
        }

        if(isset($this->data['keyword']) && !empty($this->data['keyword'])){
            $keyword = htmlspecialchars(trim($this->data['keyword']));
            $fields  = !checkPhone($keyword) ? 'customer_users_name' : 'phone_number';
            $where[$fields] = ['like', '%' . $keyword . '%'];
        }

        if($type == 'visit'){
            $where['appointment_date'] = ['between', [date('Y-m-d'), date('Y-m-d 23:59:59')]];
        }else{
            $where['appointment_date'] = ['between', [date('Y-m-01'), date('Y-m-d 23:59:59')]];
        }

        $field = 'customer_users_org_id as id,customer_users_name as username,phone_number as phone,create_date as createTime,system_user_name as systemUsername,carName,expect_way_name as expectWay';
//        $data = Db::name('customer_customerorg')->where('time_of_appointment_date', ['>=', date('Y-m-01')], ['<=', date('Y-m-t 23:59:59')], 'and')->where($where)->join('car_cars', 'intention_car_id=carId', 'left')->field($field)->select();
        $data = Db::name('customer_customerorg')->where($where)->join('car_cars', 'intention_car_id=carId', 'left')->field($field)->select();
        !$data && $this->apiReturn(201, '', '暂无记录');
        $this->apiReturn(200, $data);
    }

    /**
     * 今日回访列表
     * @return json
     * */
    public function visit(){
        $where = [
            'create_date'          => ['between', [date('Y-m-d', strtotime('-6 day')), date('Y-m-d H:i:s')]],
            'customer_order_state' => 17,
            'org_id'               => $this->orgId,
            'is_delete'            => 0,
        ];

        if(!$this->isRole){
            $where['system_user_id'] = $this->userId;
        }

        $data = model('CustomerOrder')->getOrderList($where);
        !$data && $this->apiReturn(201, '', '暂无数据');
        $this->apiReturn(200, $data);
    }

    /**
     * 报价单提交
     * @return json
     * */
    public function quotation(){
        $result = $this->validate($this->data, 'AddQuotation');
        if($result !== true){
            $this->apiReturn(201, '', $result . '_1');
        }
        
        $priceListKey = ['bareCarPrice', 'purchase_tax', 'license_plate_priace', 'vehicle_vessel_tax', 'insurance_price', 'traffic_insurance_price', 'boutique_priace', 'quality_assurance', 'other'];
        $total = 0;
        $type  = $this->data['type'] + 0;
        if($type == 2){
            $priceListKey[] = 'mortgage';
        }
        foreach($this->data as $key => $value){
            if(in_array($key, $priceListKey)){
                $total += $value;
            }
        }

        $total = $type == 1 ? $total : $total * $this->data['down_payment_rate'] / 100;
        if($this->data['total_fee'] != $total){
            $this->apiReturn(201, '', '预计付费总金额不一致' . $total);
        }

        if($type == 1){
            $this->data['down_payment_rate'] = 0;
            $this->data['periods'] = 0;
            $this->data['annual_rate'] = 0;
            $this->data['monthly_supply'] = 0;
            $this->data['mortgage'] = 0;
        }

        $monthlySupply = $type == 2 ? $total * $this->data['annual_rate'] / $this->data['periods'] / 100 : 0;
        $this->data['monthly_supply'] = intval($monthlySupply * 100) / 100;
        $this->data['create_user_id'] = $this->userId;
        $this->data['create_time']    = date('Y-m-d H:i:s');
        unset($this->data['sessionId']);
        $result = Db::name('consumer_car_quotation')->insert($this->data);

        !$result && $this->apiReturn(201, '', '提交数据失败');
        $this->apiReturn(200, ['id' => Db::name('consumer_car_quotation')->getLastInsID()]);
    }

    public function quotationDetail(){
        (!isset($this->data['id']) || empty($this->data['id'])) && $this->apiReturn(201, '', '报价单ID非法');

        $id   = $this->data['id'] + 0;

        $data = Db::name('consumer_car_quotation')->where(['id' => $id])->find();
        !$data && $this->apiReturn(201, '', '报价单数据不存在');
        $data['user'] = ['username' => $this->user['realName'], 'phone' => $this->user['phoneNumber']];
        $data['carName'] = Db::name('car_cars')->where(['carId' => $data['carId']])->field('carName')->find()['carName'];
        $data['buycarStyle'] = $data['type'] == 1 ? '全款' : '按揭';
        if($data['type'] == 1){

        }
        unset($data['carId'], $data['create_user_id']);
        $this->apiReturn(200, $data);
    }

}