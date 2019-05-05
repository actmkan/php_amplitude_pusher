<?php
/**
 * Created by PhpStorm.
 * User: marc
 * Date: 22/11/2018
 * Time: 3:54 PM
 */

class Amplitude
{
    private $DB,$api_url,$api_url_batch;
    public $api_key,$stack,$userProperties,$visitor_idx,$page_type,$custom_utm,$custom_utm_v2;

    public function __construct()
    {

        $this->api_url = "https://api.amplitude.com/httpapi";
        $this->api_url_batch = "https://api.amplitude.com/batch";
        $this->api_key = "";//API KEY
        $this->page_type = $this->getPageType();
        $this->DB = "";//DB Connect

        $this->visitor_idx = $this->get_visitor();

        $this->stack = [];

        if(isset($_SESSION['member_idx'])){
            $member = $this->DB->getUserProperties($_SESSION['member_idx']);
            $member['VisitorIdx'] = $this->visitor_idx;
            $member['Parity'] = $this->visitor_idx%2;
            $this->userProperties = $member;
        }else{
            $this->userProperties = [
                'ID'=>-1,
                'VisitorIdx'=>$this->visitor_idx,
                'Parity' => $this->visitor_idx%2,
            ];
        }
    }

    private function getPageType(){

        $CI = &get_instance();
        $page_type = "none";


        $page_map = [
            'home'=>[
                'index'=>'홈',
            ],
        ];
        if(isset($page_map[$CI->router->class][$CI->router->method])){
            $page_type = $page_map[$CI->router->class][$CI->router->method];
        }

        return $page_type;
    }

    public function convertDataTag($event_name, $object){
        return " data-amplitude-event='".$event_name."' data-amplitude-object='".json_encode($object)."' '";
    }

    public function addStack($event_name, $object){
        array_push($this->stack, (object)['event'=>$event_name, 'object'=>$object]);
    }

    public function sendApi($event_name, $object, $type='single'){

        if($type==='single'){
            $api_url = $this->api_url;
            $postData = http_build_query([
                'api_key'=>$this->api_key,
                'event'=>json_encode([
                    [
                        'device_id'=>$_COOKIE['amplitude_did'],
                        'event_type'=>$event_name,
                        'event_properties'=>$object,
                        'user_properties'=>$this->userProperties
                    ]
                ])

            ]);
        }elseif ($type==='batch'){
            $api_url = $this->api_url_batch;
            $postData = json_encode([
                'api_key'=>$this->api_key,
                'events'=>$object
            ]);
        }else{
            return false;
        }


        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $api_url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);


        $res = curl_exec($ch);

        $json_post_data = str_replace("'", "\'", $postData);
        $json_user_properties = str_replace("'", "\'", json_encode($this->userProperties));
        $date_now = date('Y-m-d H:i:s');
        $res_replace = str_replace("'", "\'", $res);

        curl_close($ch);
        return $res;

    }

    private function get_visitor(){
        //visitor check
        return 0;
    }

    private function get_custom_utm($version=1){

        $custom_utm = (object)[
            'utm_source'=>'(direct)',
            'utm_medium'=>'(none)',
            'utm_campaign'=>'(none)',
        ];


        switch ($version){
            case 1:
                $cookie_name = (object)[
                    'source' => isset($_COOKIE['custom_utm_source'])?$_COOKIE['custom_utm_source']:null,
                    'medium' => isset($_COOKIE['custom_utm_medium'])?$_COOKIE['custom_utm_medium']:null,
                    'campaign' => isset($_COOKIE['custom_utm_campaign'])?$_COOKIE['custom_utm_campaign']:null,
                ];
                break;
            default:
                $cookie_name = (object)[
                    'source' => isset($_COOKIE['custom_utm_v'.$version.'_source'])?$_COOKIE['custom_utm_v'.$version.'_source']:null,
                    'medium' => isset($_COOKIE['custom_utm_v'.$version.'_medium'])?$_COOKIE['custom_utm_v'.$version.'_medium']:null,
                    'campaign' => isset($_COOKIE['custom_utm_v'.$version.'_campaign'])?$_COOKIE['custom_utm_v'.$version.'_campaign']:null,
                ];
                break;
        }


        if(isset($_GET['utm_source'], $_GET['utm_medium'], $_GET['utm_campaign'])){

            $custom_utm = $this->set_utm_cookie($_GET['utm_source'], $_GET['utm_medium'], $_GET['utm_campaign'], $version);

        }else if(!isset($_SERVER['HTTP_REFERER']) && $version!==2){

            $custom_utm = $this->set_utm_cookie('(direct)', '(none)', '(none)', $version);

        }else if(!isset($_SERVER['HTTP_REFERER']) && !isset($cookie_name->source,$cookie_name->medium,$cookie_name->campaign) && $version===2) {

            $custom_utm = $this->set_utm_cookie('(direct)', '(none)', '(none)', $version);

        }else if(isset($cookie_name->source,$cookie_name->medium,$cookie_name->campaign) && $version===2) {

            $custom_utm = $this->set_utm_cookie($cookie_name->source,$cookie_name->medium, $cookie_name->campaign, $version);

        }else if(isset($_SERVER['HTTP_REFERER']) && !preg_match("/https:\/\/".$_SERVER['HTTP_HOST']."/", $_SERVER['HTTP_REFERER'])){
            if(preg_match("/nid\.naver\.com/", $_SERVER['HTTP_REFERER'])
                || preg_match("/pay\.naver\.com.*\/payments\/\d*./", $_SERVER['HTTP_REFERER'])
                || preg_match("/\.facebook\.com.*oauth/", $_SERVER['HTTP_REFERER'])
                || preg_match("/kauth\.kakao\.com/", $_SERVER['HTTP_REFERER'])
                || preg_match("/\.nicepay\.co\.kr/", $_SERVER['HTTP_REFERER'])) {
                if (isset($cookie_name->source, $cookie_name->medium, $cookie_name->campaign)) {

                    $custom_utm = $this->set_utm_cookie($cookie_name->source, $cookie_name->medium, $cookie_name->campaign, $version);

                }
            }else {

                if (preg_match("/FB/", $_SERVER['HTTP_USER_AGENT'])) {
                    $custom_utm_source = 'facebook';
                    $custom_utm_medium = 'webview';
                    $custom_utm_campaign = 'webview';
                } else if (preg_match("/KAKAOTALK/", $_SERVER['HTTP_USER_AGENT'])) {
                    $custom_utm_source = 'kakaotalk';
                    $custom_utm_medium = 'webview';
                    $custom_utm_campaign = 'webview';
                } else if (preg_match("/KAKAOSTORY/", $_SERVER['HTTP_USER_AGENT'])) {
                    $custom_utm_source = 'kakaostory';
                    $custom_utm_medium = 'webview';
                    $custom_utm_campaign = 'webview';
                } else if (preg_match("/Instagram/", $_SERVER['HTTP_USER_AGENT'])) {
                    $custom_utm_source = 'instagram';
                    $custom_utm_medium = 'webview';
                    $custom_utm_campaign = 'webview';
                } else if (preg_match("/BAND/", $_SERVER['HTTP_USER_AGENT'])) {
                    $custom_utm_source = 'band';
                    $custom_utm_medium = 'webview';
                    $custom_utm_campaign = 'webview';
                } else {
                    $custom_utm_source = parse_url($_SERVER['HTTP_REFERER'], PHP_URL_HOST);
                    $custom_utm_medium = '(none)';
                    $custom_utm_campaign = '(none)';
                }

                $custom_utm = $this->set_utm_cookie($custom_utm_source, $custom_utm_medium, $custom_utm_campaign, $version);

            }
        }else if(isset($cookie_name->source,$cookie_name->medium,$cookie_name->campaign)){

            $custom_utm = $this->set_utm_cookie($cookie_name->source,$cookie_name->medium, $cookie_name->campaign, $version);

        }

        return $custom_utm;
    }

    private function set_utm_cookie($source, $medium, $campaign, $version){

        switch ($version){
            case 1:
                $cookie_name = (object)[
                    'source' => 'custom_utm_source',
                    'medium' => 'custom_utm_medium',
                    'campaign' => 'custom_utm_campaign',
                ];
                break;
            default:
                $cookie_name = (object)[
                    'source' => 'custom_utm_v'.$version.'_source',
                    'medium' => 'custom_utm_v'.$version.'_medium',
                    'campaign' => 'custom_utm_v'.$version.'_campaign',
                ];
                break;
        }

        setcookie ($cookie_name->source, $source, time() + (3600 * 24), '/');
        setcookie ($cookie_name->medium, $medium, time() + (3600 * 24), '/');
        setcookie ($cookie_name->campaign, $campaign, time() + (3600 * 24), '/');

        return (object)[
            'utm_source'=>$source,
            'utm_medium'=>$medium,
            'utm_campaign'=>$campaign,
        ];
    }


}
