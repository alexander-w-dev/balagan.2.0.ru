<?php

namespace app\modules\api\controllers;

use yii\base\Theme;
use Yii;
use app\modules\api\models\db\BioUser;
use app\modules\api\models\db\BioUserDoctor;
use app\modules\api\models\db\BioUserPacient;
use app\modules\api\models\db\BioDistrict;
use app\modules\api\models\db\BioMeasure;
use app\modules\api\models\db\BioUserMeasure;
use app\modules\api\models\db\BioUserNotice;
use app\modules\api\models\db\BioDoctorPacientConnection;
use app\modules\api\models\db\BioClinicList;
use app\modules\api\models\db\BioRecordToDoctor;

class RegistrationController extends _ApiController {

    public function behaviors() {
        $behaviors = parent::behaviors();
        $behaviors['corsFilter'] = [
            'class' => \yii\filters\Cors::className(),
            'cors' => [
                // restrict access to
                'Origin' => ['*'],
                'Access-Control-Request-Method' => ['POST', 'PUT'],
                // Allow only POST and PUT methods
                'Access-Control-Request-Headers' => ['X-Wsse'],
                // Allow only headers 'X-Wsse'
                'Access-Control-Allow-Credentials' => true,
                // Allow OPTIONS caching
                'Access-Control-Max-Age' => 3600,
                // Allow the X-Pagination-Current-Page header to be exposed to the browser.
                'Access-Control-Expose-Headers' => ['X-Pagination-Current-Page'],
            ],
        ];
        \Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;
        return $behaviors;
    }

    public function beforeAction($action) {
        return parent::beforeAction($action); // TODO: Change the autogenerated stub
    }

    public function actionUserinfo() {
        Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;
        $this->layout = false;
        $uid = Yii::$app->request->post('uid');
        if (!$uid) return false;
        $user = BioUser::getUserInfoById($uid);
        return $res;
    }

    public function actionTableindex() {
        $sql = 'SHOW INDEX FROM bio_user_doctor';
        $tables = Yii::$app->db
                ->createCommand($sql)
                ->queryAll();
        foreach ($tables as $t) {
            $this->pvd($t);
        }
    }

    public function actionTable() {
        $sql = 'SELECT * FROM bio_user';
        $tables = Yii::$app->db
                ->createCommand($sql)
                ->queryAll();
        foreach ($tables as $t) {
            $this->pvd($t);
        }
    }

    public function actionQuery() {
        $sql = 'SELECT s.*
              FROM bio_doctor_specializations s
              RIGHT JOIN
                bio_user_doctor d ON d.specialization = s.id
              RIGHT JOIN
                bio_doctor_schedule sch ON sch.doctor_id = d.user_id
              GROUP BY s.name
              ORDER BY s.name';
        $tables = Yii::$app->db
                ->createCommand($sql)
                ->queryAll();
        foreach ($tables as $t) {
            $this->pvd($t);
        }
    }

    public function actionManage() {
        $sql = "
                ALTER TABLE bio_user_doctor DROP COLUMN doctor_id;
                
                ";
        $result = Yii::$app->db
                ->createCommand($sql)
                ->queryAll();
        echo json_encode($result);
        die();
    }

    public function actionTables() {
        $sql = 'SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES';
        $tables = Yii::$app->db
                ->createCommand($sql)
                ->queryAll();
        foreach ($tables as $t) {
            $this->pvd($t['TABLE_NAME']);
        }
    }

    public function actionRecord() {
        $sql = 'UPDATE bio_record_to_doctor SET pacient_id = ' . $_POST['user_id'] . ' WHERE record_id = ' . $_POST['r_id'];
        $ret = Yii::$app->db
                ->createCommand($sql)
                ->queryAll();
        echo json_encode($ret);
        die();
    }

    public function actionIndex() {
        Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;
        $this->layout = false;
        $sql = "SELECT s.*, u.id as doctor_id, CONCAT_WS(\", \",s.name,CONCAT_WS(\" \", u.surname, u.name, u.patronymic )) as d_name
              FROM bio_doctor_specializations s
              RIGHT JOIN
                bio_user_doctor d ON d.specialization = s.id
              RIGHT JOIN
                bio_doctor_schedule sch ON sch.doctor_id = d.user_id
              LEFT JOIN
                bio_user u ON u.id = d.user_id ";
        $bindValues = [];
        if (isset($_REQUEST['action']) && $_REQUEST['action'] == 'search') {
            if (isset($_REQUEST['q']) && $_REQUEST['q']) {
                //@todo make PDO
                $qArray = explode(" ", $_REQUEST['q']);
                $search = [];
                $i = 1;
                foreach ($qArray as $q) {
                    $bindValues[":q$i"] = $q;
                    $search[] = " (LOWER(u.name) LIKE LOWER('%:q$i%') OR LOWER(u.patronymic) LIKE LOWER('%:q$i%') OR LOWER(u.surname) LIKE LOWER('%:q$i%')) ";
                    $i++;
                }
                if (sizeof($search)) {
                    $sql .= " WHERE " . implode(" OR ", $search);
                }
            }
            $sql .= "
                  GROUP BY d_name
                  ORDER BY s.name
                  ";
        } else {
            $sql .= "GROUP BY s.name
              ORDER BY s.name
              ";
        }
        $specs = Yii::$app->db
                ->createCommand($sql)
                ->bindValues($bindValues)
                ->queryAll();
        return ['specialists' => $specs];
    }
    
    public function actionSearch(){
        Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;
        $this->layout = false;
        if (isset($_REQUEST['q'])){
            $q = $_REQUEST['q']?$_REQUEST['q']:false;
        } else {
            $q = false;
        }
        $sql = "( SELECT 'specialist' as type, u.id as id, CONCAT_WS(\", \",s.name,CONCAT_WS(\" \", u.surname, u.name, u.patronymic )) as value
                FROM bio_user u
                LEFT JOIN
                    bio_user_doctor d ON d.user_id = u.id
                LEFT JOIN
                    bio_doctor_specializations s ON s.id = d.specialization
                LEFT JOIN
                    bio_doctor_schedule sc ON sc.doctor_id = d.user_id
                WHERE
                    sc.reception_date <= sc.start_time
                AND
                    sc.start_time < sc.end_time
                ";
        $i = 1;
        $bindValues = [];
        if ($q){
            $qArray = explode(" ", $q);
            $search = [];
            foreach ($qArray as $q) {
                $bindValues[":q$i"] = "%$q%";
                $search[] = " (LOWER(u.name) LIKE LOWER(:q$i) OR LOWER(u.patronymic) LIKE LOWER(:q$i) OR LOWER(u.surname) LIKE LOWER(:q$i)) ";
                $i++;
            }
            if (sizeof($search)) {
                $sql .= " AND (" . implode(" OR ", $search) . " ) ";
            }
        }
        $sql .= "
                  GROUP BY value )";
        $sql .= " UNION ALL ";
        
        $sql .= " ( SELECT 'specialists' as type, s.id as id, s.name as value
                FROM bio_doctor_specializations s 
                LEFT JOIN
                    bio_user_doctor d ON d.specialization = s.id
                LEFT JOIN
                    bio_doctor_schedule sc ON sc.doctor_id = d.user_id
                WHERE
                    sc.reception_date <= sc.start_time
                AND
                    sc.start_time < sc.end_time";
        if ($q){
            $qArray = explode(" ", $q);
            $search = [];
            foreach ($qArray as $q) {
                $bindValues[":q$i"] ="%$q%";
                $search[] = " (LOWER(s.name) LIKE LOWER(:q$i)) ";
                $i++;
            }
            if (sizeof($search)) {
                $sql .= " AND ( " . implode(" OR ", $search) . " ) ";
            }
        }
        
        $sql .= " 
                  GROUP BY value )
                  ORDER BY type DESC";
        $ret = Yii::$app->db
                ->createCommand($sql)
                ->bindValues($bindValues)
                ->queryAll();
        return $ret;
    }

    public function actionSpecialists() {
        Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;
        $this->layout = false;
        $sid = Yii::$app->request->get('spec_id');
        $sid = (int)$sid?(int)$sid:1;
        $clinicId = (int)Yii::$app->request->get('clinic');
        $area = Yii::$app->request->get('area');
        $date = Yii::$app->request->get('date');
        $by = Yii::$app->request->get('by');
        $sort = Yii::$app->request->get('sort');
        $bindValues = [];
        $sql = "SELECT u.*, us.*, sp.name as spec_name, s.clinic_id, min(s.price) as price, c.coordinates, c.clinic_area, c.clinic_name, c.clinic_adress,
                    count(DISTINCT r.id) as reviews, AVG(r.stars) as stars
              FROM bio_user u 
              LEFT JOIN
                bio_doctor_schedule s ON s.doctor_id = u.id
              LEFT JOIN
                bio_user_doctor us ON us.user_id = u.id
              LEFT JOIN
                bio_user_reviews r ON r.doctor_id = us.user_id";
        $sql .= " LEFT JOIN bio_clinic_list c ON c.clinic_id = s.clinic_id ";
        $sql .= "
              RIGHT JOIN
                bio_doctor_specializations sp ON sp.id = us.specialization
              WHERE u.type = 'doctor' and s.price is not null and us.specialization = $sid
              ";
        if ($clinicId) {
            $sql .= " AND s.clinic_id = " . $clinicId;
        }
        if ($date) {
            $bindValues[':date'] = date('Y-m-d', strtotime($_GET['date']));
            $sql .= " AND s.reception_date = :date ";
        }
        if ($area) {
            $bindValues[':area'] = $area;
            $sql .= " AND c.clinic_area = :area ";
        }
        $sql .= "
                  GROUP BY u.id";
        $by = ($by && $by == 'name') ? "u.name" : "s.price";
        $sort = ($sort && $sort == 'desc') ? "DESC" : "ASC";
        $sql .= " ORDER BY $by $sort";
        $doctors = Yii::$app->db
                ->createCommand($sql)
                ->bindValues($bindValues)
                ->queryAll();
        $sql = "SELECT * FROM bio_clinic_list";
        $clinics = Yii::$app->db
                ->createCommand($sql)
                ->queryAll();
        $bindValues = [];
        $specialization = Yii::$app->db
                ->createCommand("SELECT id, name, description FROM bio_doctor_specializations WHERE id = $sid LIMIT 1")
                ->queryOne();
        $sql = "SELECT DISTINCT(clinic_area) FROM bio_clinic_list ORDER BY clinic_area";
        $areas = Yii::$app->db
                ->createCommand($sql)
                ->queryAll();
        return ['specialization' => $specialization, 'doctors' => $doctors, 'clinics' => $clinics, 'areas' => $areas];
    }

    public function actionSpecialist($id = 0) {
        Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;
        $this->layout = false;
        $uid = Yii::$app->request->get('user_id',0);
        $cid = Yii::$app->request->get('cid',0); 
        $cid = (int)$cid;
        $date = Yii::$app->request->get('date');
        $id = (int) $id;
        $bindValues = [];
        $sql = "SELECT u.*, d.*, sp.name as spec_name, sp.id as s_id,
                count(DISTINCT r.id) as reviews, AVG(r.stars) as stars
              FROM bio_user u
              LEFT JOIN
                bio_user_doctor d ON d.user_id = u.id
              LEFT JOIN
                bio_user_reviews r ON r.doctor_id = d.user_id
              RIGHT JOIN
                bio_doctor_specializations sp ON sp.id = d.specialization
              WHERE 
                u.type = 'doctor' 
              AND 
                u.id = $id limit 1";
        $doctor = Yii::$app->db
                ->createCommand($sql)
                ->queryAll();
        $doctor = $doctor[0];
        $spec = [];
        $sql = "SELECT * FROM bio_doctor_specializations WHERE `id` = {$doctor['s_id']}";
        $spec = Yii::$app->db
                ->createCommand($sql)
                ->queryOne();
        $bindValues=[];
        if ($date) {
            $date = date('Y-m-d', strtotime($date));
        } else {
            $date = date('Y-m-d', time());
        }
        $bindValues =[':date' => $date];
        $sql = "SELECT s.*, c.clinic_name, r.record_id, r.start_time as s_time,
                IF (r.pacient_id = {$uid},1,0) as recorded,
                IF (r.pacient_id IS NOT NULL OR r.pacient_id <> {$uid},1,0) as occupied
                FROM bio_doctor_schedule s
                LEFT JOIN
                    bio_clinic_list c ON c.clinic_id = s.clinic_id
                RIGHT JOIN
                    bio_record_to_doctor r ON s.schedule_id = r.schedule_id
                WHERE 
                    s.doctor_id = $id
                AND
                    s.reception_date = :date
                AND
                    s.reception_date < s.start_time
                AND
                    DATE_ADD(s.reception_date, INTERVAL 1 DAY) > s.end_time";
        if ($cid)
            $sql .= " AND c.clinic_id = $cid";
        $sql .= " ORDER BY s.reception_date ASC";
        $schedule = Yii::$app->db
                ->createCommand($sql)
                ->bindValues($bindValues)
                ->queryAll();
        $sql = "SELECT reception_date FROM bio_doctor_schedule WHERE doctor_id = $id ";
        if ($cid)
            $sql .= " AND clinic_id = $cid";
        $sql .= " GROUP BY reception_date";
        $schedules = Yii::$app->db
                ->createCommand($sql)
                ->queryAll();
        $sql = "SELECT *, IF(clinic_id = $cid,1,0) as choosed FROM bio_clinic_list WHERE clinic_id in (SELECT DISTINCT(clinic_id) as id FROM bio_doctor_schedule WHERE doctor_id = $id) ORDER BY choosed DESC";
        $clinics = Yii::$app->db
                ->createCommand($sql)
                ->queryAll();
        $sql = "SELECT r.user_id, r.stars, r.text, r.date, CONCAT_WS(\" \", u.surname, u.name, u.patronymic ) as user_name
                FROM bio_user_reviews r
                LEFT JOIN
                    bio_user u ON u.id = r.user_id
                WHERE
                    r.doctor_id = $id
                ";
        $reviews = Yii::$app->db
                ->createCommand($sql)
                ->queryAll();
        $data = [
            'doctor' => $doctor,
            'spec' => $spec,
            'schedule' => $schedule,
            'schedules' => $schedules,
            'reviews' => $reviews,
            'clinics' => $clinics
        ];
        return $data;
    }

    public function actionSpeclist() {
        Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;
        $this->layout = false;
        $sql = "SELECT s.*
              FROM bio_doctor_specializations s
              ";
        $specs = Yii::$app->db
                ->createCommand($sql)
                ->queryAll();
        return ['specs' => $specs];
    }
    
    public function actionTerms(){
        Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;
        $this->layout = false;
        $letter = Yii::$app->request->post('letter');
        $q = Yii::$app->request->post('q');
        $sql = 'select * from bio_terms';
        $sqlAr = [];
        $bindValues = [];
        if ($letter && sizeof($letter) == 1){
            $bindValues[':letter'] = $letter;
            $sqlAr[] = ' UCASE(LEFT(name , 1)) = UCASE(:letter) ';
        }
        if ($q && strlen($q) > 1){
            $bindValues[':q'] = "%$q%";
            $sqlAr[] = ' LOWER(name) LIKE LOWER(:q) ';
        }
        if (sizeof($sqlAr)){
            $sql .= " where " . implode(" AND ", $sqlAr);
        }
        $terms = Yii::$app->db
                ->createCommand($sql)
                ->bindValues($bindValues)
                ->queryAll();
        $sql = "select distinct(UCASE(LEFT(name , 1))) as letter FROM bio_terms GROUP BY letter";
        $letters  = Yii::$app->db
                ->createCommand($sql)
                ->queryAll();
        return ['terms' => $terms, 'letters' => $letters, 'bv' => $bindValues];
    }
    
    public function actionFaq(){
        Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;
        $this->layout = false;
        $sql = "SELECT * FROM bio_faq ORDER BY id DESC";
        $return = Yii::$app->db
                ->createCommand($sql)
                ->queryAll();
        return $return;
    }
    
    public function actionReview(){
        Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;
        $this->layout = false;
        $user = Yii::$app->request->post('user');
        $doctor = Yii::$app->request->post('doctor');
        $star = Yii::$app->request->post('star');
        $text = Yii::$app->request->post('text');
        if (!$user || !$doctor) return false;
        $sql = 'SELECT * FROM bio_user WHERE id = ' . (int)$user;
        $uInfo = Yii::$app->db
                ->createCommand($sql)
                ->queryOne();
        if (!$uInfo) return false;
        $sql = 'SELECT * FROM bio_user WHERE id = ' . (int)$doctor;
        $dInfo = Yii::$app->db
                ->createCommand($sql)
                ->queryOne();
        if (!$dInfo) return false;
        $reviewInserted = Yii::$app->db
                ->createCommand()
                ->insert('bio_user_reviews',[
                    'user_id' => $user,
                    'doctor_id' => $doctor,
                    'stars' => $star,
                    'text' => $text
                ])
                ->execute();
        if (!$reviewInserted) return false;
        $date = date("Y-m-d",time());
        $mailText = "
            Новый отзыв\r\n
            Пользователь: {$uInfo['name']} {$uInfo['patronymic']} {$uInfo['surname']} \r\n
            Email: {$uInfo['email']}\r\n
            Доктор: {$dInfo['name']} {$dInfo['patronymic']} {$dInfo['surname']} \r\n
            Дата: {$date}\r\n
            Оценка: {$star} \r\n
            Комментарий: {$text} \r\n
        ";
        $sent = $this->sendMail($mailText,'Новый отзыв');
        return $sent;
    }
    
    function sendMail($text, $subject = 'Информационное сообщение', $to = 'panov@webmedved.ru'){
        if (!$text) return FALSE;
        $headers = 'From: info@biogenom.ru' . "\r\n" .
                    'Reply-To: info@biogenom.ru' . "\r\n" .
                    'X-Mailer: PHP/' . phpversion();
        $phpMail = mail($to,$subject,$text,$headers);
        return $phpMail;
    }
    
    public function actionTestmail(){
        Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;
        $this->layout = false;
        $sql = 'SELECT * FROM bio_user WHERE id = ' . 63;
        $uInfo = Yii::$app->db
                ->createCommand($sql)
                ->queryOne();
        $date = date("Y-m-d",time());
        $mailText = "
            Новый отзыв\r\n
            Пользователь: {$uInfo['name']} {$uInfo['patronymic']} {$uInfo['surname']} \r\n
            Email: {$uInfo['email']}\r\n
            Дата: {$date}\r\n
            Оценка: {1} \r\n
            Комментарий: fsdghdfshgdfkjglodf \r\n
        ";
        $headers = 'From: info@biogenom.ru' . "\r\n" .
                    'Reply-To: info@biogenom.ru' . "\r\n" .
                    'X-Mailer: PHP/' . phpversion();
        $phpMail = mail('basilelshin@gmail.com','simple subject',$mailText,$headers);
        return ['phpMail' => $phpMail];
    }

    function pvd($data) {
        echo "<pre>";
        var_dump($data);
        echo "</pre>";
    }

}