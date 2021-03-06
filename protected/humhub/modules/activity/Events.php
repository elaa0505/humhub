<?php

/**
 * @link https://www.humhub.org/
 * @copyright Copyright (c) 2017 HumHub GmbH & Co. KG
 * @license https://www.humhub.com/licences
 */

namespace humhub\modules\activity;

use humhub\modules\activity\components\MailSummary;
use humhub\modules\activity\jobs\SendMailSummary;
use humhub\modules\activity\models\Activity;
use Yii;
use yii\base\BaseObject;
use yii\base\Event;
use yii\db\ActiveRecord;
use yii\db\IntegrityException;

/**
 * Events provides callbacks to handle events.
 *
 * @author luke
 */
class Events extends BaseObject
{

    /**
     * Handles cron hourly run event to send mail summaries to the users
     *
     * @param \yii\base\ActionEvent $event
     */
    public static function onCronHourlyRun($event)
    {
        Yii::$app->queue->push(new SendMailSummary(['interval' => MailSummary::INTERVAL_HOURLY]));
    }

    /**
     * Handles cron daily run event to send mail summaries to the users
     *
     * @param \yii\base\ActionEvent $event
     */
    public static function onCronDailyRun($event)
    {
        Yii::$app->queue->push(new SendMailSummary(['interval' => MailSummary::INTERVAL_DAILY]));
        if (date('w') == Yii::$app->getModule('activity')->weeklySummaryDay) {
            Yii::$app->queue->push(new SendMailSummary(['interval' => MailSummary::INTERVAL_WEEKLY]));
        }
    }

    /**
     * On delete of some active record, check if there are related activities and delete them.
     *
     * @param Event $event
     */
    public static function onActiveRecordDelete(Event $event)
    {
        if (!($event->sender instanceof ActiveRecord)) {
            throw new \LogicException('The handler can be applied only to the \yii\db\ActiveRecord.');
        }

        /** @var \yii\db\ActiveRecord $activeRecordModel */
        $activeRecordModel = $event->sender;
        $pk = $activeRecordModel->getPrimaryKey();

        // Check if primary key exists and is not array (multiple pk)
        if ($pk !== null && !is_array($pk)) {
            $modelsActivity = Activity::find()->where([
                'object_id' => $pk,
                'object_model' => $activeRecordModel->className()
            ])->each();
            foreach ($modelsActivity as $activity) {
                $activity->delete();
            }
        }
    }

    /**
     * Callback to validate module database records.
     *
     * @param Event $event
     */
    public static function onIntegrityCheck($event)
    {
        $integrityController = $event->sender;
        $integrityController->showTestHeadline('Activity Module (' . Activity::find()->count() . ' entries)');

        // Loop over all comments
        foreach (Activity::find()->each() as $a) {
            /** @var Activity $a */

            // Check for object_model / object_id
            if ($a->object_model != '' && $a->object_id != '') {
                try {
                    $source = $a->getSource();
                } catch (IntegrityException $ex) {
                    if ($integrityController->showFix('Deleting activity id ' . $a->id . ' without existing target! (' . $a->object_model . ')')) {
                        $a->delete();
                    }
                }
            }

            // Check for moduleId is set
            if ($a->module == '') {
                if ($integrityController->showFix('Deleting activity id ' . $a->id . ' without module_id!')) {
                    $a->delete();
                }
            }

            // Check Activity class exists
            if (!class_exists($a->class)) {
                if ($integrityController->showFix('Deleting activity id ' . $a->id . ' class not exists! (' . $a->class . ')')) {
                    $a->delete();
                }
            }
        }
    }

}
