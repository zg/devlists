<?php
require_once('secret.php'); # define('SECRETKEY',CAPTCHA_KEY);
date_default_timezone_set('America/New_York');

function relativeTime($time){
    $d[0] = array(1,"second");
    $d[1] = array(60,"minute");
    $d[2] = array(3600,"hour");
    $d[3] = array(86400,"day");
    $d[4] = array(604800,"week");
    $d[5] = array(2592000,"month");
    $d[6] = array(31104000,"year");

    $w = array();
    $return = "";
    $now = time();
    $diff = ($now-$time);
    $secondsLeft = $diff;

    if(60*60*24*7 < $secondsLeft)
        return date('M j, Y');

    for($i=6;$i>-1;$i--)
    {
         $w[$i] = intval($secondsLeft/$d[$i][0]);
         $secondsLeft -= ($w[$i]*$d[$i][0]);
         if($w[$i]!=0)
            $return.= abs($w[$i]) . " " . $d[$i][1] . (($w[$i]>1)?'s':'') ." ";
    }

    $return .= ($diff>0)?"ago":"left";
    return $return;
}

$uri = explode('/',substr($_SERVER['REQUEST_URI'],1));

class View {
    public function render_template($keys,$vals,$template){
        return str_replace($keys,$vals,file_get_contents($template));
    }

    public function render_base($vals){
        global $uri;
        if($uri[0] == "new" || $uri[0] == "create"){
            $vals[] = ' class="active"';
            $vals[] = '';
            $vals[] = '';
        } elseif($uri[0] == "lists" || $uri[0] == "recent" || $uri[0] == "all"){
            $vals[] = '';
            $vals[] = ' class="active"';
            $vals[] = '';
        } elseif($uri[0] == "about"){
            $vals[] = '';
            $vals[] = '';
            $vals[] = ' class="active"';
        } else {
            $vals[] = '';
            $vals[] = '';
            $vals[] = '';
        }
        return $this->render_template(array("<%TITLE%>","<%CONTENT%>","<%CREATEACTIVE%>","<%RECENTACTIVE%>","<%ABOUTACTIVE%>"),$vals,"templates/base.html");
    }

    public function render_list($id,$title,$contents,$private){
        return $this->render_base(array($title,trim(str_replace(array("<%LISTID%>","<%LISTTITLE%>","<%LISTCONTENTS%>"),array($id,$title,$contents),file_get_contents("templates/view_".($private ? 'private' : 'public').".html")))));
    }

    public function render_all_lists($recent_lists){
        return $this->render_base(array("Recent Public Lists",trim(str_replace(array("<%RECENTLISTS%>"),$recent_lists,file_get_contents("templates/view_all.html")))));
    }

    public function render_new_list($title,$input_title,$textarea_contents){
        return $this->render_base(array($title,trim(str_replace(array("<%TITLE%>","<%INPUTTITLE%>","<%TEXTAREACONTENTS%>"),array($title,$input_title,$textarea_contents),file_get_contents("templates/new.html")))));
    }

    public function render_page($title,$template_name){
        return $this->render_base(array($title,file_get_contents("templates/$template_name.html")));
    }
}

$db = new MongoClient;
$view = new View;

if(count($_POST)){
    $url = "https://www.google.com/recaptcha/api/siteverify";
    $data = array (
        'secret' => SECRETKEY,
        'response' => $_POST['g-recaptcha-response'],
        'remoteip' => $_SERVER['REMOTE_ADDR']
    );
    $options = array (
        'http' => array(
            'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
            'method'  => 'POST',
            'content' => http_build_query($data)
        )
    );
    $context = stream_context_create($options);
    $json = file_get_contents($url, false, $context);
    $result = json_decode($json);
    if($result->success && isset($_POST['title']) && strlen($_POST['title']) < 255 && isset($_POST['contents']) && strlen($_POST['contents']) < 1048576 && isset($_POST['type'])){
        $sector = ($_POST['type'] == 'private' ? 'private' : 'public');

        $data = array (
            "title" => htmlentities($_POST['title']),
            "contents" => htmlentities($_POST['contents']),
            "created_at" => microtime(true)
        );

        if($sector == "private")
            $data['url'] = md5(base64_encode($data['title'].$data['contents'].$data['created_at']));
        else
            $data['hash'] = md5(htmlentities($_POST['title']).htmlentities($_POST['contents']));

        if($sector == "public") {
            $cursor = $db->devlists->public->find(array('hash'=>$data['hash']));
            if(0 < $cursor->count()) {
                $doc = $cursor->getNext();
                header('Location: /list/'.(string)$doc['_id']);
                die();
            }
        }
        
        $db->devlists->$sector->insert($data);
        header('Location: /list/'.($data['private'] ? 'private/'.$data['url'] : $data['_id']));
    }
}

switch($uri[0]){
    case 'list':
        if(isset($uri[1]) && ctype_xdigit($uri[1]) && $uri[1] != "private"){
            try {
                $list_data = $db->devlists->public->findOne(array('_id'=>new MongoId($uri[1])));
                if(is_null($list_data)){
                    echo $view->render_page('List not found','list_not_found');
                } else {
                    if(isset($uri[2]) && ($uri[2] == "raw" || $uri[2] == "raw.txt"))
                        die(html_entity_decode($list_data['contents']));
                    else
                        echo $view->render_list($list_data['_id'],$list_data['title'],$list_data['contents'],false);
                }
            } catch(MongoException $me){
                echo $view->render_page('List not found','list_not_found');
            }
        } elseif(isset($uri[1]) && $uri[1] == "private" && isset($uri[2]) && strlen($uri[2]) == 32 && ctype_xdigit($uri[2])){
            try {
                $list_data = $db->devlists->private->findOne(array('url'=>$uri[2]));
                if(is_null($list_data)){
                    echo $view->render_page('Private list not found','list_not_found');
                } else {
                    echo $view->render_list($list_data['_id'],$list_data['title'],$list_data['contents'],true);
                }
            } catch(MongoException $me){
                echo $view->render_page('Private list not found','list_not_found');
            }
        } else {
            echo $view->render_page('List not found','list_not_found');
        }
        break;
    case 'fork':
        if(isset($uri[1]) && ctype_xdigit($uri[1])){
            try {
                $list_data = $db->devlists->public->findOne(array('_id'=>new MongoId($uri[1])));
                if(is_null($list_data)){
                    echo $view->render_page('List not found','list_not_found');
                } else {
                   echo $view->render_new_list('Create a new List <small>(fork of #'.$list_data['_id'].')</small>',$list_data['title'],$list_data['contents']);
                }
            } catch(MongoException $me){
                echo $view->render_page('List not found','list_not_found');
            }
        }
        break;
    case 'new':
    case 'create':
        echo $view->render_new_list('Create a new List','My List',"Item 1\r\nItem 2\r\nItem 3");
        break;
    case 'lists':
    case 'all':
    case 'recent':
        $recent_lists = "";
        $cursor = $db->devlists->public->find(array(),array('title'=>1,'created_at'=>1));
        $cursor->limit(15);
        $cursor->sort(array('created_at'=>-1));
        foreach($cursor as $id => $list){
            $created_at = relativeTime(explode('.',$list['created_at'])[0]);
            $recent_lists .= "<tr><td><a href=\"/list/$id\">{$list['title']}</a></td><td>$created_at</td></tr>";
        }
        echo $view->render_all_lists($recent_lists);
        break;
    default:
    case '':
        echo $view->render_page('Home','home');
        break;
    case 'about':
        echo $view->render_page('About DevLists','about');
        break;
    default:
        echo $view->render_page('Page not found','page_not_found');
        break;
}
