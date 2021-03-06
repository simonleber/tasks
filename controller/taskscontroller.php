<?php

/**
* ownCloud - Tasks
*
* @author Raimund Schlüßler
* @copyright 2013 Raimund Schlüßler raimund.schluessler@googlemail.com
*
* This library is free software; you can redistribute it and/or
* modify it under the terms of the GNU AFFERO GENERAL PUBLIC LICENSE
* License as published by the Free Software Foundation; either
* version 3 of the License, or any later version.
*
* This library is distributed in the hope that it will be useful,
* but WITHOUT ANY WARRANTY; without even the implied warranty of
* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
* GNU AFFERO GENERAL PUBLIC LICENSE for more details.
*
* You should have received a copy of the GNU Affero General Public
* License along with this library.  If not, see <http://www.gnu.org/licenses/>.
*
*/

namespace OCA\Tasks\Controller;

use \OCP\IRequest;

use \OCP\AppFramework\Controller;
use \OCP\AppFramework\Http\JSONResponse;
use \OCA\Tasks\Controller\Helper;

class TasksController extends Controller {

	private $userId;

	public function __construct($appName, IRequest $request, $userId){
		parent::__construct($appName, $request);
		$this->userId = $userId;
	}

	/**
	 * @NoAdminRequired
	 */
	public function getTasks($listID = 'all', $type = 'all'){
		
		$user_timezone = \OC_Calendar_App::getTimezone();
		if ($listID == 'all'){
			$calendars = \OC_Calendar_Calendar::allCalendars($this->userId, true);
		} else {
			$calendar = \OC_Calendar_App::getCalendar($listID, true, false);
			$calendars = array($calendar);
		}

		$tasks = array();
		$lists = array();
		foreach( $calendars as $calendar ) {
			$calendar_entries = \OC_Calendar_Object::all($calendar['id']);
			$tasks_selected = array();
			foreach( $calendar_entries as $task ) {
				if($task['objecttype']!='VTODO') {
					continue;
				}
				if(is_null($task['summary'])) {
					continue;
				}
				$vtodo = Helper::parseVTODO($task['calendardata']);
				try {
					$task_data = Helper::arrayForJSON($task['id'], $vtodo, $user_timezone, $calendar['id']);
					switch($type){
						case 'all':
							$tasks[] = $task_data;
							break;
						case 'init':
							if (!$task_data['completed']){
								$tasks[] = $task_data;
							} else {
								$tasks_selected[] = $task_data;
							}
							break;
						case 'completed':
							if ($task_data['completed']){
								$tasks[] = $task_data;
							}
							break;
						case 'uncompleted':
							if (!$task_data['completed']){
								$tasks[] = $task_data;
							}
							break;
					}
				} catch(\Exception $e) {
					\OCP\Util::writeLog('tasks', $e->getMessage(), \OCP\Util::ERROR);
				}
			}
			$nrCompleted = 0;
			$notLoaded = 0;
			usort($tasks_selected, array($this, 'sort_completed'));
			foreach( $tasks_selected as $task_selected){
				$nrCompleted++;
				if ($nrCompleted > 5){
					$notLoaded++;
					continue;
				}
				$tasks[] = $task_selected;
			}
			$lists[] = array(
				'id' 		=> $calendar['id'],
				'notLoaded' => $notLoaded
				);
		}
		$result = array(
			'data' => array(
				'tasks' => $tasks,
				'lists' => $lists
				)
			);
		$response = new JSONResponse();
		$response->setData($result);
		return $response;
	}

	/**
	 * @NoAdminRequired
	 */
	public function getTask($taskID){
		$object = \OC_Calendar_App::getEventObject($taskID);
		$user_timezone = \OC_Calendar_App::getTimezone();
		$task = array();
		if($object['objecttype']=='VTODO' && !is_null($object['summary'])) {
			$vtodo = Helper::parseVTODO($object['calendardata']);
			try {
				$task_data = Helper::arrayForJSON($object['id'], $vtodo, $user_timezone, $object['calendarid']);
				$task[] = $task_data;
			} catch(\Exception $e) {
				\OCP\Util::writeLog('tasks', $e->getMessage(), \OCP\Util::ERROR);
			}	
		}
		$result = array(
			'data' => array(
				'tasks' => $task
				)
			);
		$response = new JSONResponse();
		$response->setData($result);
		return $response;
	}

	private static function sort_completed($a, $b){
		$t1 = \DateTime::createFromFormat('Ymd\THis', $a['completed_date']);
		$t2 = \DateTime::createFromFormat('Ymd\THis', $b['completed_date']);
		if ($t1 == $t2) {
			return 0;
		}
		return $t1 < $t2 ? 1 : -1;
	}

	/**
	 * @param boolean $isStarred
	 */
	private function setStarred($isStarred){
		$taskId = (int) $this->params('taskID');
		try {
			$vcalendar = \OC_Calendar_App::getVCalendar($taskId);
			$vtodo = $vcalendar->VTODO;
			if($isStarred){
				$vtodo->PRIORITY = 1;
			}else{
				$vtodo->__unset('PRIORITY');
			}
			\OC_Calendar_Object::edit($taskId, $vcalendar->serialize());
		} catch(\Exception $e) {
			// throw new BusinessLayerException($e->getMessage());
		}
	}

	/**
	 * @NoAdminRequired
	 */
	public function starTask(){
		$response = new JSONResponse();
		try {
			$this->setStarred(true);
			return $response;
		} catch(\Exception $e) {
			return $response;
			// return $this->renderJSON(array(), $e->getMessage());
		}
	}

	/**
	 * @NoAdminRequired
	 */
	public function unstarTask(){
		$response = new JSONResponse();
		try {
			$this->setStarred(false);
			return $response;
		} catch(\Exception $e) {
			return $response;
			// return $this->renderJSON(array(), $e->getMessage());
		}
	}

	private function setPercentComplete($percent_complete){
		$taskId = (int) $this->params('taskID');
		try {
			$vcalendar = \OC_Calendar_App::getVCalendar($taskId);
			$vtodo = $vcalendar->VTODO;
			if (!empty($percent_complete)) {
				$vtodo->{'PERCENT-COMPLETE'} = $percent_complete;
			}else{
				$vtodo->__unset('PERCENT-COMPLETE');
			}
			if ($percent_complete == 100) {
				$vtodo->STATUS = 'COMPLETED';
				$vtodo->COMPLETED = new \DateTime('now', new \DateTimeZone('UTC'));
			} elseif ($percent_complete != 0) {
				$vtodo->STATUS = 'IN-PROCESS';
				unset($vtodo->COMPLETED);
			} else{
				$vtodo->STATUS = 'NEEDS-ACTION';
				unset($vtodo->COMPLETED);
			}
			\OC_Calendar_Object::edit($taskId, $vcalendar->serialize());
		} catch(\Exception $e) {
			// throw new BusinessLayerException($e->getMessage());
		}
	}

	/**
	 * @NoAdminRequired
	 */
	public function percentComplete(){
		$response = new JSONResponse();
		try{
			$percent_complete = $this->params('complete');
			$this->setPercentComplete( $percent_complete );
			return $response;
		} catch(\Exception $e) {
			return $response;
			// return $this->renderJSON(array(), $e->getMessage());
		}
	}


	/**
	 * @NoAdminRequired
	 */
	public function completeTask(){
		$response = new JSONResponse();
		try {
			$this->setPercentComplete(100);
			return $response;
		} catch(\Exception $e) {
			return $response;
			// return $this->renderJSON(array(), $e->getMessage());
		}
	}

	/**
	 * @NoAdminRequired
	 */
	public function uncompleteTask(){
		$response = new JSONResponse();
		try {
			$this->setPercentComplete(0);
			return $response;
		} catch(\Exception $e) {
			return $response;
			// return $this->renderJSON(array(), $e->getMessage());
		}
	}

	/**
	 * @NoAdminRequired
	 */
	public function addTask(){
		$taskName = $this->params('name');
		$calendarId = $this->params('calendarID');
		$starred = $this->params('starred');
		$due = $this->params('due');
		$start = $this->params('start');
		$response = new JSONResponse();
		$user_timezone = \OC_Calendar_App::getTimezone();
		$request = array(
				'summary'			=> $taskName,
				'categories'		=> null,
				'priority'			=> $starred,
				'location' 			=> null,
				'due'				=> $due,
				'start'				=> $start,
				'description'		=> null
			);
		$vcalendar = Helper::createVCalendarFromRequest($request);
		$taskId = \OC_Calendar_Object::add($calendarId, $vcalendar->serialize());

		$task = Helper::arrayForJSON($taskId, $vcalendar->VTODO, $user_timezone, $calendarId);

		$task['tmpID'] = $this->params('tmpID');
		$result = array(
			'data' => array(
				'task' => $task
				)
		);
		$response->setData($result);
		return $response;
	}

	/**
	 * @NoAdminRequired
	 */
	public function deleteTask(){
		$response = new JSONResponse();
		$taskId = $this->params('taskID');
		\OC_Calendar_Object::delete($taskId);
		return $response;
	}

	/**
	 * @NoAdminRequired
	 */
	public function setTaskName(){
		$taskId = (int) $this->params('taskID');
		$taskName = $this->params('name');
		$response = new JSONResponse();
		try {
			$vcalendar = \OC_Calendar_App::getVCalendar($taskId);
			$vtodo = $vcalendar->VTODO;
			$vtodo->SUMMARY = $taskName;
			\OC_Calendar_Object::edit($taskId, $vcalendar->serialize());
		} catch(\Exception $e) {
			// throw new BusinessLayerException($e->getMessage());
		}
		return $response;
	}

	/**
	 * @NoAdminRequired
	 */
	public function setTaskCalendar(){
		$taskId = $this->params('taskID');
		$calendarId = $this->params('calendarID');
		$response = new JSONResponse();
		try {
			$data = \OC_Calendar_App::getEventObject($taskId);
			if ($data['calendarid'] != $calendarId) {
				\OC_Calendar_Object::moveToCalendar($taskId, $calendarId);
			}
		} catch(\Exception $e) {
			// throw new BusinessLayerException($e->getMessage());
		}
		return $response;
	}

	/**
	 * @NoAdminRequired
	 */
	public function setTaskNote(){
		$taskId = $this->params('taskID');
		$note = $this->params('note');
		$response = new JSONResponse();
		try {
			$vcalendar = \OC_Calendar_App::getVCalendar($taskId);
			$vtodo = $vcalendar->VTODO;
			$vtodo->DESCRIPTION = $note;
			\OC_Calendar_Object::edit($taskId, $vcalendar->serialize());
		} catch(\Exception $e) {
			// throw new BusinessLayerException($e->getMessage());
		}
		return $response;
	}

	/**
	 * @NoAdminRequired
	 */
	public function setDueDate(){
		$taskId = $this->params('taskID');
		$due = $this->params('due');
		$response = new JSONResponse();
		try{
			$vcalendar = \OC_Calendar_App::getVCalendar($taskId);
			$vtodo = $vcalendar->VTODO;
			if ($due != false) {
				$timezone = \OC_Calendar_App::getTimezone();
				$timezone = new \DateTimeZone($timezone);

				$due = new \DateTime('@'.$due);
				$due->setTimezone($timezone);
				$vtodo->DUE = $due;
			} else {
				unset($vtodo->DUE);
			}
			\OC_Calendar_Object::edit($taskId, $vcalendar->serialize());
		} catch (\Exception $e) {

		}
		return $response;
	}

	/**
	 * @NoAdminRequired
	 */
	public function setStartDate(){
		$taskId = $this->params('taskID');
		$start = $this->params('start');
		$response = new JSONResponse();

		try{
			$vcalendar = \OC_Calendar_App::getVCalendar($taskId);
			$vtodo = $vcalendar->VTODO;
			if ($start != false) {
				$timezone = \OC_Calendar_App::getTimezone();
				$timezone = new \DateTimeZone($timezone);

				$start = new \DateTime('@'.$start);
				$start->setTimezone($timezone);
				$vtodo->DTSTART = $start;
			} else {
				unset($vtodo->DTSTART);
			}
			\OC_Calendar_Object::edit($taskId, $vcalendar->serialize());
		} catch (\Exception $e) {

		}
		return $response;
	}

	/**
	 * @NoAdminRequired
	 */
	public function setReminderDate(){
		$taskId = $this->params('taskID');
		$type = $this->params('type');
		$action = $this->params('action');
		$response = new JSONResponse();

		$types = array('DATE-TIME','DURATION');

		$vcalendar = \OC_Calendar_App::getVCalendar($taskId);
		$vtodo = $vcalendar->VTODO;
		$valarm = $vtodo->VALARM;

		if ($type == false){
			unset($vtodo->VALARM);
			$vtodo->__get('LAST-MODIFIED')->setValue(new \DateTime('now', new \DateTimeZone('UTC')));
			$vtodo->DTSTAMP = new \DateTime('now', new \DateTimeZone('UTC'));
			\OC_Calendar_Object::edit($taskId, $vcalendar->serialize());
		}
		elseif (in_array($type,$types)) {
			try{
				if($valarm == null) {
					$valarm = $vcalendar->createComponent('VALARM');
					$valarm->ACTION = $action;
					$valarm->DESCRIPTION = 'Default Event Notification';
					$vtodo->add($valarm);
				} else {
					unset($valarm->TRIGGER);
				}
				$tv = '';
				$related = null;
				if ($type == 'DATE-TIME') {
					$date = new \DateTime('@'.$this->params('date'));
					$tv = $date->format('Ymd\THis\Z');
				} elseif ($type == 'DURATION') {
					$invert = $this->params('invert');
					$related= $this->params('related');
					$week 	= (int)$this->params('week');
					$day 	= (int)$this->params('day');
					$hour 	= (int)$this->params('hour');
					$minute = (int)$this->params('minute');
					$second = (int)$this->params('second');

					// Create duration string
					if($week || $day || $hour || $minute || $second) {
						if ($invert){
							$tv.='-';
						}
						$tv.='P';
						if ($week){
							$tv.=$week.'W';
						}
						if ($day){
							$tv.=$day.'D';
						}
						$tv.='T';
						if ($hour){
							$tv.=$hour.'H';
						}
						if ($minute){
							$tv.=$minute.'M';
						}
						if ($second){
							$tv.=$second.'S';
						}
					}else{
						$tv = 'PT0S';
					}
				}
				if($related == 'END'){
					$valarm->add('TRIGGER', $tv, array('VALUE' => $type, 'RELATED' => $related));
				} else {
					$valarm->add('TRIGGER', $tv, array('VALUE' => $type));
				}
				$vtodo->__get('LAST-MODIFIED')->setValue(new \DateTime('now', new \DateTimeZone('UTC')));
				$vtodo->DTSTAMP = new \DateTime('now', new \DateTimeZone('UTC'));
				\OC_Calendar_Object::edit($taskId, $vcalendar->serialize());
			} catch (\Exception $e) {

			}
		}
		return $response;
	}

	/**
	 * @NoAdminRequired
	 */
	public function addCategory(){
		$taskId = $this->params('taskID');
		$category = $this->params('category');
		$response = new JSONResponse();
		try {
			$vcalendar = \OC_Calendar_App::getVCalendar($taskId);
			$vtodo = $vcalendar->VTODO;
			// fetch categories from TODO
			$categories = $vtodo->CATEGORIES;
			if ($categories){
				$taskcategories = $categories->getParts();
			}
			// add category
			if (!in_array($category, $taskcategories)){
				$taskcategories[] = $category;
				$vtodo->CATEGORIES = $taskcategories;
				\OC_Calendar_Object::edit($taskId, $vcalendar->serialize());
			}
		} catch(\Exception $e) {
			// throw new BusinessLayerException($e->getMessage());
		}
		return $response;
	}

	/**
	 * @NoAdminRequired
	 */
	public function removeCategory(){
		$taskId = $this->params('taskID');
		$category = $this->params('category');
		$response = new JSONResponse();
		try {
			$vcalendar = \OC_Calendar_App::getVCalendar($taskId);
			$vtodo = $vcalendar->VTODO;
			// fetch categories from TODO
			$categories = $vtodo->CATEGORIES;
			if ($categories){
				$taskcategories = $categories->getParts();
			}
			// remove category
			$key = array_search($category, $taskcategories);
			if ($key !== null && $key !== false){
				unset($taskcategories[$key]);
				if(count($taskcategories)){
					$vtodo->CATEGORIES = $taskcategories;
				} else{
					$vtodo->__unset('CATEGORIES');
				}
				\OC_Calendar_Object::edit($taskId, $vcalendar->serialize());
			}
		} catch(\Exception $e) {
			// throw new BusinessLayerException($e->getMessage());
		}
		return $response;
	}

	/**
	 * @NoAdminRequired
	 */
	public function setLocation(){
		$taskId = $this->params('taskID');
		$location = $this->params('location');
		$response = new JSONResponse();
		try {
			$vcalendar = \OC_Calendar_App::getVCalendar($taskId);
			$vtodo = $vcalendar->VTODO;
			$vtodo->LOCATION = $location;
			\OC_Calendar_Object::edit($taskId, $vcalendar->serialize());
		} catch(\Exception $e) {
			// throw new BusinessLayerException($e->getMessage());
		}
		return $response;
	}

	/**
	 * @NoAdminRequired
	 */
	public function addComment(){
		$taskId = $this->params('taskID');
		$comment = $this->params('comment');
		$response = new JSONResponse();
		try {
			$vcalendar = \OC_Calendar_App::getVCalendar($taskId);
			$vtodo = $vcalendar->VTODO;

			if($vtodo->COMMENT == "") {
				// if this is the first comment set the id to 0
				$commentId = 0;
			} else {
				// Determine new commentId by looping through all comments
				$commentIds = array();
				foreach($vtodo->COMMENT as $com) {
					$commentIds[] = (int)$com['X-OC-ID']->getValue();
				}
				$commentId = 1+max($commentIds);
			}

			$now = 	new \DateTime();
			$vtodo->add('COMMENT',$comment,
				array(
					'X-OC-ID' => $commentId,
					'X-OC-USERID' => $this->userId,
					'X-OC-DATE-TIME' => $now->format('Ymd\THis\Z')
					)
				);
			\OC_Calendar_Object::edit($taskId, $vcalendar->serialize());
			$user_timezone = \OC_Calendar_App::getTimezone();
			$now->setTimezone(new \DateTimeZone($user_timezone));
			$comment = array(
				'taskID' => $taskId,
				'id' => $commentId,
				'tmpID' => $this->params('tmpID'),
				'name' => \OCP\User::getDisplayName(),
				'userID' => $this->userId,
				'comment' => $comment,
				'time' => $now->format('Ymd\THis')
				);
			$result = array(
				'data' => array(
					'comment' => $comment
					)
				);
			$response->setData($result);
		} catch(\Exception $e) {
			// throw new BusinessLayerException($e->getMessage());
		}
		return $response;
	}

	/**
	 * @NoAdminRequired
	 */
	public function deleteComment(){
		$taskId = $this->params('taskID');
		$commentId = $this->params('commentID');
		$response = new JSONResponse();
		try {
			$vcalendar = \OC_Calendar_App::getVCalendar($taskId);
			$vtodo = $vcalendar->VTODO;
			$commentIndex = $this->getCommentById($vtodo,$commentId);
			$comment = $vtodo->children[$commentIndex];
			if($comment['X-OC-USERID']->getValue() == $this->userId){
				unset($vtodo->children[$commentIndex]);
				\OC_Calendar_Object::edit($taskId, $vcalendar->serialize());
			}else{
				throw new \Exception('Not allowed.');
			}
		} catch(\Exception $e) {
			// throw new BusinessLayerException($e->getMessage());
		}
		return $response;
	}

	/**
	 * @NoAdminRequired
	 */
	public function getCommentById($vtodo,$commentId) {
		$idx = 0;
		foreach ($vtodo->children as $i => &$property) {
			if ( $property->name == 'COMMENT' && $property['X-OC-ID']->getValue() == $commentId ) {
				return $idx;
			}
			$idx += 1;
		}
		throw new \Exception('Commment not found.');
	}
}
