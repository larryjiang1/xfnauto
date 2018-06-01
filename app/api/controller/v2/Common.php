<?php
/**
 * Created by PhpStorm.
 * User: liaozijie
 * Date: 2018-04-25
 * Time: 9:28
 */

namespace app\api\controller\v2\Frontend;

use app\api\service\Imagick;
use Qiniu\Auth;
use Qiniu\Storage\UploadManager;
use think\Controller;
use think\Db;
use think\Request;

class Common extends Base
{
    public function _initialize(Request $request = null)
    {
        parent::_initialize($request); // TODO: Change the autogenerated stub
    }

    public function area(){
        $pid = isset($this->data['id']) || empty($this->data['id']) ? $this->data['id'] + 0 : 0;
        $data = model('Area')->getAreaList($pid);
        $this->apiReturn(200, $data);
    }

    public function brand(){
        $model = model('Brand');
        $data  = $model->getBrandList();
        !$data && $this->apiReturn(201);
        $this->apiReturn(200, $data);
    }

    /*
     * 通过汽车品牌ID获取车系
     * */
    public function series(){
        (!isset($this->data['bid']) || empty($this->data['bid'])) && $this->apiReturn(201, '', '品牌ID非法');
        $brandId = $this->data['bid'] + 0;

        $model = model('Brand');
        $data  = $model->getCarFamilyByBrandId($brandId);
        !$data && $this->apiReturn(201);
        $this->apiReturn(200, $data);
    }

    /*
     * 通过车系查询
     * */
    public function carList(){
        (!isset($this->data['fid']) || empty($this->data['fid'])) && $this->apiReturn(201, '', '系列ID非法');
        $page = isset($this->data['page']) ? $this->data['page'] + 0: 1;

        $familyId = $this->data['fid'] + 0;
        $field = 'carId as id,carName as name,indexImage as image,price,pl as output,styleName';
        $model = model('Car');
        $data  = $model->getCarByFamilyId($familyId, $field, $page);
        !$data && $this->apiReturn(201);
        $this->apiReturn(200, $data);
    }

    public function share(){
        set_time_limit(0);
        require_once '../extend/mpdf/mpdf.php';
//        $mpdf = new \mPDF('utf-8','A4','','',25 , 25, 16, 16); //'utf-8' 或者 '+aCJK' 或者 'zh-CN'都可以显示中文
        $mpdf = new \mPDF('utf-8','A4','','',0 , 0, 0, 0); //'utf-8' 或者 '+aCJK' 或者 'zh-CN'都可以显示中文
        //设置字体，解决中文乱码
        $mpdf->useAdobeCJK = TRUE;
        $mpdf->autoScriptToLang = true;
        $mpdf->autoLangToFont = true;
        //$mpdf-> showImageErrors = true; //显示图片无法加载的原因，用于调试，注意的是,我的机子上gif格式的图片无法加载出来。
        //设置pdf显示方式
        $mpdf->SetDisplayMode('fullpage');
        //目录相关设置：
        //Remember bookmark levels start at 0(does not work inside tables)H1 - H6 must be uppercase
        //$this->h2bookmarks = array('H1'=>0, 'H2'=>1, 'H3'=>2);
//        $mpdf->h2toc = array('H3'=>0,'H4'=>1,'H5'=>2);
//        $mpdf->h2bookmarks = array('H3'=>0,'H4'=>1,'H5'=>2);
        $mpdf->mirrorMargins = 1;
        //是否缩进列表的第一级
        $mpdf->list_indent_first_level = 0;

        $options = [
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
            ]
        ];

        $url = 'http://api.mifengqiche.com/index.html';
        $html = file_get_contents($url, false, stream_context_create($options));
        $mpdf->WriteHTML($html);  //$html中的内容即为变成pdf格式的html内容。
        $microtime   = explode('.', microtime(true));
        $fileName    = date('YmdHis') . end($microtime);
        $pdfFileName = $fileName . '.pdf';
        //输出pdf文件
        $mpdf->Output('upload/' . $pdfFileName, 'D'); //'I'表示在线展示 'D'则显示下载窗口
        die;
        $startTime = microtime(true);
        if(file_exists('upload/' . $pdfFileName)){
            $result = pdf2png('upload/' . $pdfFileName, 'upload/image');
            unlink('upload/' . $pdfFileName);
            if($result){
                $auth  = new Auth(config('qiniu.accesskey'), config('qiniu.secretkey'));
                $token = $auth->uploadToken(config('qiniu.bucket'));
                $data  = array();
                $upload = new UploadManager();
                foreach($result as $key => $value){
                    list($ret, $err) = $upload->putFile($token, $value, $value);
                    if($err === null){
                        $data[$key] = 'http://' . config('qiniu.domain') . '/' . $ret['key'];
                    }
                    unlink($value);
                }
                $time = microtime(true) - $startTime;
                logs_write($time, request()->controller(), request()->action(), $result);
                $this->apiReturn(200, $data);
            }else{
                $this->apiReturn(201, '', '图片生成失败');
            }
        }else{
            $this->apiReturn(201, '', '文件不存在');
        }
    }

    public function createImage(){
        (!isset($this->data['id']) || empty($this->data['id'])) && $this->apiReturn(201, '', 'ID非法');
        $id = $this->data['id'] + 0;
        $join = [
            ['share_material sm', 'sm.material_id=info.material_id', 'left'],
            ['system_user', 'info.system_user_id=usersId', 'left'],
        ];
        $info = Db::name('share_material_info info')->where(['material_info_id' => $id])->field('material_name as title,info.remarks,sm.image materialImage,info.image,realName,phoneNumber')->join($join)->find();
        // $info = Db::name('share_material_info info')->where(['material_info_id' => $id])->field('material_name as title,info.remarks,realName,phoneNumber')->join($join)->find();
        if(!$info){
            $this->apiReturn(201, '', '数据不存在');
        }

        if(isset($info['materialImage']) && $info['materialImage']){
            $materialImage = explode(',', $info['materialImage']);
            $info = array_merge($info, $materialImage);
            unset($info['materialImage']);
        }

        foreach($info as $key => $value){
            if(!in_array($key, ['image', 'realName', 'phoneNumber'], true)){
                $data[] = $value;
            }
        }

        if(isset($info['image']) && $info['image']){
            $image = explode(',', $info['image']);
            $data  = array_merge($data, $image);
        }

        $url = $this->getWchatQcode($data);
        if(!$url){
            $this->apiReturn(201, '', 'file not found');
        }

        $data[] = $url['url'];
        $data[] = '分享人：' . $info['realName'] . '　　电话：' . $info['phoneNumber'];

        $data = array_filter($data);
        $data = array_values($data);
//dump($data);
        $img  = 'upload/image/' . md5(serialize($data) . microtime(true)) . '.jpg';

        $width  = [];
        $height = [];
        $font   = './msyhbd.ttf';
        $fontSize   = 10;//磅值字体
        $rowSpacing = 60;//行距
        $colSpacing = 30;//左右边距
        $top        = 60;
        $imageWidth = [];
        $main       = [];
        $targetWidth= 640;//画板宽度 *
//        dump($data);die;
        logs_write($data, request()->controller(), request()->action(), []);
        foreach($data as $key => &$value){
            if(filter_var($value, FILTER_VALIDATE_URL)){
                $wUrl = $this->dealWchatQcode($value);
                if(!$wUrl){
                    $this->apiReturn(201, '', 'file not found');
                }
                $value = $wUrl['filename'];
                $imageFile[] = $wUrl['filename'];
                $imgInfo     = resizeImage($value, $targetWidth);//按画板宽度缩放该图片
                if(!$imgInfo){
                    $this->apiReturn(201, 'file not found');
                }
                $value = $imgInfo['path'];
                $height[$key] = $imgInfo['height'];
                $imageFile[]  = $imgInfo['path'];
                $value = (is_https() ? 'https://api.' : 'http://api.') . config('url_domain_root') . '/' . $value;
            }else{
                if($value){
                    if($key == 1){
                        $textInfo         = autowrap($fontSize, 0, $font, $value, $targetWidth);
                        $value            = $textInfo['content'];
                        $fontBox          = imagettfbbox($fontSize, 0, $font, $value);//文字水平居中实质
                        $height[$key]     = (abs($fontBox[1]) + abs($fontBox[7]) ) * 2 + 200;
                    }else{
                        $fontBox          = imagettfbbox($fontSize, 0, $font, $value);//文字水平居中实质
                        $fontWidth[$key]  = $fontBox[2];
                        $height[$key]     = abs($fontBox[1]) + abs($fontBox[7]);
                        $height[$key]     += $key == 0 ? $rowSpacing : 0;
                    }
                }
            }
        }
        logs_write($height, request()->controller(), request()->action(), []);
        $targetHeight = array_sum($height) + $top;
        $target       = imagecreatetruecolor($targetWidth, $targetHeight);
        $white        = imagecolorallocate($target, 255, 255, 255);
        imagefill ($target, 0, 0, $white );
        $fontColor    = imagecolorallocate ($target, 0, 0, 0 );//字的RGB颜色

        $h = $top;
        foreach($data as $k => $val){
            if($k != 0){
                $h += intval($height[$k - 1]);
            }

            if(filter_var($val, FILTER_VALIDATE_URL)){
                $imageInfo = @get_headers($val, true);
                $ext       = @explode('/', is_array($imageInfo['Content-Type']) ? $imageInfo['Content-Type'][1] : $imageInfo['Content-Type'])[1];
                if(in_array($ext, ['png', 'jpeg', 'gif'])){
                    $imagecreate = 'imagecreatefrom' . $ext;
                    if(function_exists($imagecreate)){
                        $temp = @$imagecreate($val);
                        imagecopy($target, $temp, 0, $h, 0, 0, $targetWidth, $height[$k]);
                    }
                }
            }else{
                if($val){
                    $fontSize     = $k == 0 ? 24 : $fontSize;
                    $fontBox      = imagettfbbox($fontSize, 0, $font, $val);
                    // $text         = autowrap($fontSize, 0, $font, $value, ($imageWidth ? max($imageWidth) : 500));
                    $w = $fontBox[2] >= $targetWidth ? $colSpacing : ($targetWidth - $fontBox[2]) / 2;
                    imagettftext($target, $fontSize, 0, ceil($w), $h, $fontColor, $font, $val);
                }
            }
        }

        imagejpeg ($target, './' . $img, 75);
        if($main){
            foreach($main as $key => $value){
                imagedestroy($value);
            }
        }

        imagedestroy ($target);
//        $data = array();
//        $data['url'] = $this->upFile($img);
        unlink($url['filename']);
        if(isset($imageFile)){
            foreach($imageFile as $key => $value){
                unlink($imageFile[$key]);
            }
        }
        $this->apiReturn(200, $this->upFile($img));
    }

    /**
     * 处理微信二维码到本地
     * */
    protected function getWchatQcode($data){
//        dump($data);die;
        $url    = model('Wechat', 'service')->qcode($this->userId);
        if(!$url){
            return false;
        }
        $options = [
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
            ]
        ];
        $wImageName = md5(json_encode($data) . microtime(true) . '_' . $this->userId) . '.jpg';
        $urlInfo = file_get_contents($url, false, stream_context_create($options));
        file_put_contents($wImageName, $urlInfo);

        if(!file_exists($wImageName)){
            $this->apiReturn(201, '', 'file not found');
        }

        return ['url' => (is_https() ? 'https://api.' : 'http://api.') . config('url_domain_root') . '/' . $wImageName, 'filename' => $wImageName];
    }

    public function upFile($img){
        $auth  = new Auth(config('qiniu.accesskey'), config('qiniu.secretkey'));
        $token = $auth->uploadToken(config('qiniu.bucket'));
        $upload = new UploadManager();
        if(file_exists($img)){
            list($ret, $err) = $upload->putFile($token, $img, $img);
            if($err === null){
                unlink($img);
                return 'https://' . config('qiniu.domain') . '/' . $ret['key'];
            }
            return ['error' => $err, 'ret' => $ret];
        }
        return 'file not found';
    }

    /**
     * 处理微信二维码或者图片到本地
     * */
    protected function dealWchatQcode($url){
        $options = [
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
            ]
        ];
        $wImageName = md5(json_encode($url) . microtime(true) . '_' . $this->userId) . '.jpg';
        $urlInfo    = file_get_contents($url, false, stream_context_create($options));
        file_put_contents($wImageName, $urlInfo);

        if(!file_exists($wImageName)){
            return false;
        }

        return ['url' => (is_https() ? 'https://api.' : 'http://api.') . config('url_domain_root') . '/' . $wImageName, 'filename' => $wImageName];
    }



    public function upload(){
        $file = request()->file('image');
        !$file && $this->apiReturn(201, '', '请上传图片');

        // 要上传图片的本地路径
        $filePath = $file->getRealPath();
        $ext      = pathinfo($file->getInfo('name'), PATHINFO_EXTENSION);  //后缀
        $rule     = [
            'size' => 2000000,
            'ext'  => ['jpg', 'png', 'bmp', 'jpeg'],
        ];
        if(!$file->check($rule)){
            $this->apiReturn(201, '', $file->getError());
        }

        // 上传到七牛后保存的文件名
        $key = substr(md5($filePath) , 0, 5). date('YmdHis') . rand(0, 9999) . '.' . $ext;

        vendor('Qiniu.autoload');
        $auth  = new Auth(config('qiniu.accesskey'), config('qiniu.secretkey'));
        $token = $auth->uploadToken(config('qiniu.bucket'));

        $upload = new UploadManager();
        list($ret, $err) = $upload->putFile($token, $key, $filePath);
        if ($err !== null) {
            $this->apiReturn(201, ['state' => 'error', 'msg' => $err]);
        } else {
            //返回图片的完整URL
            $this->apiReturn(200, ['state' => 'success', 'url' => 'https://' . config('qiniu.domain') . '/' . $ret['key']]);
        }
    }

    public function getToken(){
        vendor('Qiniu.autoload');
        $auth  = new Auth(config('qiniu.accesskey'), config('qiniu.secretkey'));
        $token = $auth->uploadToken(config('qiniu.bucket'));
        $this->apiReturn(200, ['token' => $token]);
    }

    /**
     * 生成电子合同
     * @param orderId 资源订单ID
     * @return json
     * */
    public function contract(){
        (!isset($this->data['orderId']) || empty($this->data['orderId'])) && $this->apiReturn(201, '', '参数错误');
        $orderId = $this->data['orderId'] + 0;

        $data = model('ConsumerOrder')->getOrderDetailByOrderId($orderId);
        $img  = 'upload/image/' . md5(serialize($this->data) . microtime(true)) . '.jpg';
        if(!$data['signet']){
            $this->apiReturn(201, '', '请上传印章');
        }
        $head = [
            '甲方（供车方）：' . $data['partyA'],
            '乙方（购车方）：' . $data['orgName'],
            '    经甲乙双方共同协商一致，乙方愿意全权委托甲方代购指定车辆，双方特立此合同' . $data['orderCode'] . '，以兹共同遵守：',
            '    ',
            '一、委托代购事项',
        ];

        $customers = [
            '    客户姓名：item.userName   手机号码：item.userPhone',
        ];

        $cars  = [
            '    车型：{{carItem.carsName}}',
            '    车身颜色：{{carItem.colorName}}   内饰颜色：{{carItem.interiorName}}',
            '    购买数量：{{carItem.carNum}}辆   官方指导价：{{carItem.guidePrice}}元',
            '    裸车价：{{carItem.nakedPrice}}元   交强险：{{carItem.trafficCompulsoryInsurancePrice}}元',
            '    商业险：{{carItem.commercialInsurancePrice}}元   {{carItem.mode}}：{{carItem.changePrice}}元',
            '    备注：{{carItem.remark}}',
        ];
        $wuliu = ['自提', '其它', '送车'];
        $info = [
            '    提车地点：' . $data['pickCarAddr'],
            '    提车时间：' . $data['pickCarDate'],
            '    物流方式：'  . $wuliu[$data['logisticsType'] - 1],
            '    物流费用：' . ($data['freight'] ?: 0) . '元',
            '    定金总额：' . ($data['totalDepositPrice'] ?: 0) . '元',
            '    尾款总额：' . ($data['totalRestPrice'] ?: 0) . '元',
        ];

        $footer = [
            '    ',
            '二、款项及支付方式',
            '    1、本合同签订时则代表乙方完全同意合同' . $data['orderCode'] . '中的所有订单信息，并且乙方根据订购单' . $data['orderCode'] . '中的定金要向甲方支付代购定金总额￥' . $data['totalDepositPrice'] . '；尾款总额￥' . $data['totalRestPrice'],
            '    2、乙方必须在甲方通知提车当天内付清订购单' . $data['orderCode'] . '的全款，如乙方未能在规定期限内付清全款，逾期7天未付甲方有权单方面解除合同，乙方所付定金不予退还，同时甲方有权处置乙方所定车辆；',
            '    3、上述所有款项，甲方指定收款账号为：',
            '    户　名：' . $data['bankAccountName'],
            '    账　号：' . $data['bankCardNum'],
            '    开户行：' . $data['bankBranch'],
            '    ',
            '三、车辆机动车销售发票、合格证及相关资料由相应品牌专营开具并按约定时间交付；',
            '    ',
            '四、乙方须提供准确真实客户信息，如发现作假资料不符合品牌区域销售管控的，本合约自动作废并没收定金；',
            '    ',
            '五、车辆验收：提车时按车辆出厂标准由乙方或乙方委托的代表验收，如有异议应当场提出，如当场未提出异议，则视为乙方认可甲方代购的车辆符合出厂标准，车辆交接完毕后所产生的全部损失由乙方自行承担；',
            '    ',
            '六、乙方购车客户须符合上牌资格，如因乙方客户自身原因导致不能上牌的责任由乙方负责，甲方不予退车或退款；',
            '    ',
            '七、免责条款：协议生效后，因不可抗力的情况下（如因生产商停车，4S店发车时间、价格变动、4S店交通运输延误）而导致甲方无法履行合同，甲方有权解除本合约并退还所收定金；',
            '    ',
            '八、本合同一式两份，于甲方收到购车定金即时生效，同时甲方保留本合约一切解释权',
            '    ',
            '    ',
            '盖章处：',
            '    ',
            '    ',
            '客户经理签名：' . $data['creator'],
            '日期：' . (isset($data['createTime']) ? date('Y-m-d', strtotime($data['createTime'])) : '')
        ];

        $string    = '';
        $endHandle = "\n";
        $search  = ['{{carItem.carsName}}', '{{carItem.colorName}}', '{{carItem.interiorName}}', '{{carItem.carNum}}', '{{carItem.guidePrice}}', '{{carItem.nakedPrice}}', '{{carItem.trafficCompulsoryInsurancePrice}}', '{{carItem.commercialInsurancePrice}}', '{{carItem.changePrice}}', '{{carItem.remark}}', '{{carItem.mode}}'];

        foreach($data['customers'] as $key => $value){
            $string .= str_replace(['item.userName', 'item.userPhone'], [$value['userName'], $value['userPhone']], $customers[0]) . $endHandle;
            foreach($value['infos'] as $val){
                $replace = [$val['carsName'], $val['colorName'], $val['interiorName'], $val['carNum'], $val['guidePrice'], $val['nakedPrice'], $val['trafficCompulsoryInsurancePrice'] ?: 0, $val['commercialInsurancePrice'] ?: 0, abs($val['changePrice']), trim($val['remark']), $val['changePrice'] > 0 ? '加价' : '优惠'];
                $temp    = implode($endHandle, $cars);
                $string .= str_replace($search, $replace, $temp) . $endHandle;
            }
            $string .= $endHandle;
        }

        $string = implode($endHandle, $head) . $endHandle . $string . implode($endHandle, $info) . $endHandle . implode($endHandle, $footer);

        $targetWidth      = 435;//画板宽度
        $left             = 16;//左边距
        $contentWidth     = $targetWidth - $left * 2;//内容宽度
        $rowSpacing       = 15;//行间隔
        $top              = 30;

        //合同标题处理
        $title            = '购车电子合同';
        $titleFontSize    = 14;
        $titleFont        = './msyhbd.ttf';
        $titleTextInfo    = autowrap($titleFontSize, 0, $titleFont, $title, $contentWidth);
        $title            = $titleTextInfo['content'];
        $titleFontBox     = imagettfbbox($titleFontSize, 0, $titleFont, $title);//文字水平居中实质
        $titleHeight      = $titleTextInfo['height'] + $rowSpacing;

        //合同内容处理
        $fontSize         = 12;
        $font             = './simsun.ttc';
        $textInfo         = autowrap($fontSize, 0, $font, $string, $contentWidth);
        $value            = $textInfo['content'];
        $fontBox          = imagettfbbox($fontSize, 0, $font, $value);//文字水平居中实质
        $height           = $textInfo['height'];

        //初始化画板
        $targetHeight     = $height + $titleHeight + $top;
        $target           = imagecreatetruecolor($targetWidth, $targetHeight);
        $white            = imagecolorallocate($target, 255, 255, 255);
        imagefill($target, 0, 0, $white);
        $fontColor        = imagecolorallocate ($target, 0, 0, 0);//字的RGB颜色

        //往画板写入标题
        $titleWidth   = ($targetWidth - $titleFontBox[2] ) / 2;
        imagettftext($target, $titleFontSize, 0, ceil($titleWidth), $top, $fontColor, $titleFont, $title);

        //往画板写入合同内容
        $w = ($targetWidth - $fontBox[2]) / 2;
        imagettftext($target, $fontSize, 0, ceil($w), $top + $titleHeight, $fontColor, $font, $value);

        //印章处理
        $zhang   = $data['signet'];
        $signet  = $this->dealWchatQcode($zhang);
        !$signet && $this->apiReturn(201, '', '印章不存在');
        $zhang   = $signet['filename'];
        $zhang_w = 100;
        $imgInfo = resizeImage($zhang, 100);
        $zhang   = (is_https() ? 'https://api.' : 'http://api.') . config('url_domain_root') . '/' . $imgInfo['path'];
        $temp    = @imagecreatefrompng($zhang);
        imagecopy($target, $temp, 90, $targetHeight - $imgInfo['height'] - 60, 0, 0, $zhang_w - 1, $imgInfo['height']);

        imagejpeg ($target, './' . $img, 75);

        imagedestroy ($target);
        unlink($signet['filename']);
//        $this->apiReturn(201, (is_https() ? 'https://api.' : 'http://api.') . config('url_domain_root') . '/' . $img);
        $this->apiReturn(200, $this->upFile($img));
    }

    /**
     * 报价单详情
     * */
    public function quotationDetail(){
        (!isset($this->data['id']) || empty($this->data['id'])) && $this->apiReturn(201, '', '报价单ID非法');

        $id   = $this->data['id'] + 0;

        $data = Db::name('consumer_car_quotation')->where(['id' => $id])->find();
        !$data && $this->apiReturn(201, '', '报价单数据不存在');
        $user = model('SystemUser')->getUserById($data['create_user_id']);
        $data['user'] = ['username' => $user['realName'], 'phone' => $user['phoneNumber']];
        $data['carName'] = Db::name('car_cars')->where(['carId' => $data['carId']])->field('carName')->find()['carName'];
        $data['buycarStyle'] = $data['type'] == 1 ? '全款' : '按揭';
        unset($data['carId'], $data['create_user_id']);
        $this->apiReturn(200, $data);
    }


    /**
     * 资源订单详情
     * */
    public function consumerDetail(){
        (!isset($this->data['id']) || empty($this->data['id'])) && $this->apiReturn(201, '', '参数非法');

        $orderId = $this->data['id'] + 0;
        $data    = model('ConsumerOrder')->getOrderDetailByOrderId($orderId);
        $this->apiReturn(200, $data);
    }

}