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

class AccountController extends _ApiController
{
    public function behaviors()
    {
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

    public function beforeAction($action)
    {
        return parent::beforeAction($action); // TODO: Change the autogenerated stub
    }

    public function actionSetTestsResultsForPartners()
    {
        if (!empty($this->user) && $this->user->type == 'partner') {
            $data = json_decode(Yii::$app->request->post('measure_data'), true);
            $user_id = Yii::$app->request->post('user_id');
            if (count($data)) {
                $i = 1;
                foreach ($data as $v) {
                    $measure = BioMeasure::findOne(['id_measure' => $v['measure_id']]);
                    if($i == 1){
                        $tests_name = BioMeasure::findOne(['id_measure' => $measure->id_parent]);
                    }
                    $i++;
                    if($measure){
                        BioUserMeasure::setValue($user_id, $v);
                    }
                }

                $notice = new BioUserNotice();
                $notice->user_id = $user_id;
                $notice->read = 0;
                $notice->notice_type_id = 4;
                $notice->c_time = new \yii\db\Expression('NOW()');
                $notice->extra_data = json_encode(['partner_id' => $this->user->id, 'tests_name' => !empty($tests_name) ? $tests_name->name : '']);
                $notice->save();

                $connection = BioDoctorPacientConnection::findDoctorByPacient($user_id);
                if($connection){
                    $notice = new BioUserNotice();
                    $notice->user_id = $connection->doctor_id;
                    $notice->read = 0;
                    $notice->notice_type_id = 4;
                    $notice->c_time = new \yii\db\Expression('NOW()');
                    $notice->extra_data = json_encode(['partner_id' => $this->user->id, 'pacient_id' => $user_id, 'tests_name' => !empty($tests_name) ? $tests_name->name : '']);
                    $notice->save();
                }

                return [
                    'success' => true,
                ];
            } else {
                return [
                    'success' => false,
                ];
            }
        } else {
            return [
                'success' => false,
            ];
        }
    }

    public function actionAnketa()
    {
        if (!empty($this->user)){
            $pacient = BioUserPacient::findByUserId($this->user->id);
            $questionOptions = [
                'user_id' => $this->user->id,
                'male' => BioUserPacient::getPacientMale($pacient),
                'age' => BioUserPacient::getPacientAge($pacient, 'months')
            ];

            /* отображать вопросы смешанно , или строго раздельно группы от вопросов*/
            $MIXED =  !empty(Yii::$app->request->post('mixed')) ? Yii::$app->request->post('mixed') : false;
            $id_parent = !empty(Yii::$app->request->post('id_parent')) ? Yii::$app->request->post('id_parent') : 0;
            $data = [];

            $mGroups = new BioMeasure();
            /* получим блоки вопросов */
            $groups = $mGroups->groupGroups($id_parent, $questionOptions);

            $questions = array();
            /* сэкономим ресурсы сервера */

            if (!$groups && !$MIXED) {
                $mQuestions = new BioMeasure();
                /* получим вопросы */
                $questions = $mQuestions->groupGuestions($id_parent, $questionOptions);
            }

            if ($MIXED) {
                $data['anketa_groups'] = '';
                if ($groups) {
                    $data['anketa_groups'] = $this->dataAnketaQuestionGroups($groups, $id_parent);
                }
                $data['anketa_questions'] = '';
                if ($questions) {
                    $data['anketa_questions'] = $this->dataAnketaQuestions($questions, $questionOptions, $id_parent);
                }
            } else {
                if ($groups) {
                    /* отображать как горуппы вопросов */
                    return [
                        'success' => true,
                        'result' => $this->dataAnketaQuestionGroups($groups, $id_parent),
                    ];
                } elseif ($questions) {
                    /* отображать как вопросы */
                    return [
                        'success' => true,
                        'result' => $this->dataAnketaQuestions($questions, $questionOptions, $id_parent),
                    ];
                }

            }

            if ($groups || $questions) {
                return [
                    'success' => true,
                    'result' => $data,
                ];
            } else {
                return [
                    'success' => false,
                    'result' => $data,
                ];
            }
        } else {
            return [
                'success' => false,
            ];
        }
    }

    /* контроллер отображающий вопросы как ГРУППЫ ВОПРОСОВ */
    public function dataAnketaQuestionGroups($groups, $id_parent = 0)
    {
        /* можно ли отправлять на расчет */
        $canSend = true;

        $measure = new BioMeasure();
        foreach ($groups as $index => $group) {
            $groups[$index]['answered'] = $measure->groupQuestionCountAnswered($group['id_measure'], Yii::$app->user->getId());
            $groups[$index]['answered']['proc'] = round(
                $groups[$index]['answered']['answered'] / $groups[$index]['answered']['need'] * 100
            );
            if ($groups[$index]['answered']['proc'] != 100) $canSend = false;
        }

        /* TODO  пофиксить 98% заполненности при 100% (блоки поле имеют скрытое), а пока костыль  - всегда отправить можно */
        $canSend = true;

        return array(
            'groups' => $groups,
            'canSend' => $canSend
        );
    }

    /* контроллер отображающий вопросы как СПИСОК ВОПРОСОВ */
    public function dataAnketaQuestions($questions, $questionOptions, $id_measure = 0)
    {
        if (!$id_measure)
            return [
                'success' => false,
            ];

        $measure = new BioMeasure();
        $group = $measure->findMeasureById($id_measure);
        $next_group = $measure->findNextOfMeasure($group, $questionOptions);
        $prev_group = $measure->findPrevOfMeasure($group, $questionOptions);


        return [
            'questions' => $questions,
            'group' => $group,
            'next_group' => $next_group,
            'prev_group' => $prev_group
        ];
    }

    // пока эту штуку не делаю
    /* THIS ACTION IS ON TESTING MODE */
    public function actionGetResult()
    {
        $originalBlackDir = BioUser::getBlackPath($this->user['path_key']) . BioFileHelper::$DIRECTORY_SEPARATOR . 'original';

        /* to renew always (FIXME at future) */
        BioFileHelper::deleteAllFiles($originalBlackDir);

        $originalBlackJson = BioFileHelper::fileGetContents($originalBlackDir);

        if (!$originalBlackJson) {

            $allUM = BioUserMeasure::findAll(['user_id' => Yii::$app->user->getId()]);

            /* по шаблону заполним данные с базы данных */
            $data = BlackResult::applyUMData($allUM);

            if (isset($_GET['debug']) && $_GET['debug'] == 'toServer') {
                print_r_pre( BlackResult::getCurlAddress() , 'Server URL');
                print_r_pre( $data, 'Data to server:');
                die();
            }

            $originalBlackJson = BlackResult::curl($data);

            if (isset($_GET['debug']) && $_GET['debug'] == 'fromServer') {
                print_r_pre( BlackResult::getCurlAddress() , 'Server URL');
                print_r_pre( json_decode($originalBlackJson, true), 'Data from server:');
                die();
            }

            if ( ! $originalBlackJson ) debug('Server is not available, please try later...');


            BioFileHelper::filePutContents(
                $originalBlackJson,
                $originalBlackDir
            );
            /* результат расчетов с ящика */

        }

        $originalBlack = json_decode($originalBlackJson, true);

        /* информация о болячках */
        $risksPrepares = BlackResult::preparedRisks($originalBlack);

        /* рекомендуемые мероприятия */
        $actionsPrepared = BlackResult::preparedActions($originalBlack);

        /* названия полей рисков */
        $risksFieldsNames = BlackResult::getRiskFieldsNames();

        /* названия полей рисков */
        $сlassifiedRisksFieldsNames = BlackResult::getClassifiedRiskFieldsNames();

        return $this->render('get_result', [
            'risksPrepared' => $risksPrepares,
            'actionsPrepared' => $actionsPrepared,
            'risksFieldsNames' => $risksFieldsNames,
            'сlassifiedRisksFieldsNames' => $сlassifiedRisksFieldsNames
        ]);
    }
}