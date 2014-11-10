<?php

/**
 * Group Admin Ajax requests here
 * Takes in a controller of type AdminController
 * which already have most required 'global' variables defined
 * 
 */
class AdminAjaxController {

	/**
	 *
	 * @var AdminController
	 */
	protected $controller;

	/**
	 *
	 * @var Site
	 */
	protected $site;

	/**
	 *
	 * @var DB
	 */
	protected $fdb;

	public function __construct(AdminController $controller) {
		$this->controller = $controller;
		$this->site = $controller->getSite();
		$this->fdb = $this->controller->getDB();
	}

	public static function call($method, AdminController $controller) {
		$self = new self($controller);
		$action = $self->getPrivateAction($method);
		return $self->$action();
	}

	private function ajaxCreateRunUnit() {
		if (is_ajax_request()):
			$fdb = $this->fdb;
			$run = $this->controller->run;

			$unit_factory = new RunUnitFactory();
			$unit = $unit_factory->make($fdb, null, array('type' => $_GET['type'], 'position' => $_POST['position']));
			$unit->create($_POST);

			if ($unit->valid):
				$unit->addToRun($run->id, $_POST['position']);
				alert('<strong>Success.</strong> ' . ucfirst($unit->type) . ' unit was created.', 'alert-success');
				echo $unit->displayForRun($this->site->renderAlerts());
				exit;
			endif;
		endif;

		bad_request_header();
		$alert_msg = "'<strong>Sorry.</strong> '";
		if (isset($unit)) {
			$alert_msg .= implode($unit->errors);
		}
		alert($alert_msg, 'alert-danger');
		echo $this->site->renderAlerts();
	}

	private function ajaxGetUnit() {
		$run = $this->controller->run;
		$fdb = $this->fdb;

		if (is_ajax_request()) :
			if (isset($_GET['run_unit_id'])):
				if (isset($_GET['special']))
					$special = $_GET['special'];
				else
					$special = false;

				$unit_info = $run->getUnitAdmin($_GET['run_unit_id'], $special);
				$unit_factory = new RunUnitFactory();
				$unit = $unit_factory->make($fdb, null, $unit_info);

				echo $unit->displayForRun();
				exit;
			endif;
		endif;

		bad_request_header();
		$alert_msg = "<strong>Sorry, missing unit.</strong> ";
		if (isset($unit))
			$alert_msg .= implode($unit->errors);
		alert($alert_msg, 'alert-danger');
		echo $this->site->renderAlerts();
	}

	private function ajaxRemind() {
		// @todo: 
		// because sending an out-of-order reminder email means all kinds of problems for logging etc, 
		// I probably need to rethink run_session ordering - atm by most recent unit, but this does not allow for a quick out-of-order email like this one. should probably be using a current_unit field in the run_session 
		// (ordering by highest position is not possible because of loops)

		$run = $this->controller->run;
		// find the last email unit
		$email = $run->getReminder($_GET['session'], $_GET['run_session_id']);
		if ($email->exec() !== false):
			alert('<strong>Something went wrong with the reminder.</strong> in run ' . $run->name, 'alert-danger');
			bad_request_header();
		endif;

		if (is_ajax_request()):
			echo $this->site->renderAlerts();
			exit;
		else:
			redirect_to("admin/run/" . $run->name . "/user_overview");
		endif;
	}

	private function ajaxRemoveRunUnitFromRun() {
		$run = $this->controller->run;
		$fdb = $this->fdb;

		if (is_ajax_request()):
			if (isset($_POST['run_unit_id'])):
				if (isset($_GET['special']))
					$special = $_GET['special'];
				else
					$special = false;

				$unit_info = $run->getUnitAdmin($_POST['run_unit_id'], $special);

				$unit_factory = new RunUnitFactory();
				$unit = $unit_factory->make($fdb, null, $unit_info);

				if ($unit->removeFromRun()):
					alert('<strong>Success.</strong> Unit with ID ' . h($_POST['run_unit_id']) . ' was deleted.', 'alert-success');
					echo $this->site->renderAlerts();
					exit;
				endif;
			endif;
		endif;

		bad_request_header();
		$alert_msg = '<strong>Sorry, could not remove unit.</strong> ';
		if (isset($unit))
			$alert_msg .= implode($unit->errors);
		alert($alert_msg, 'alert-danger');

		echo $this->site->renderAlerts();
	}

	private function ajaxReorder() {
		$run = $this->controller->run;
		if (is_ajax_request()):
			if (isset($_POST['position'])):
				$unit = $run->reorder($_POST['position']);
				exit;
			endif;
		endif;

		bad_request_header();
		$alert_msg = "'<strong>Sorry.</strong> '";
		if (isset($unit))
			$alert_msg .= implode($unit->errors);
		alert($alert_msg, 'alert-danger');

		echo $this->site->renderAlerts();
	}

	private function ajaxRunCronToggle() {
		$run = $this->controller->run;
		if (is_ajax_request()):
			if (isset($_POST['on'])):
				if (!$run->toggleCron((bool) $_POST['on']))
					echo 'Error!';
			endif;
		endif;
	}

	private function ajaxRunExport() {
		$run = $this->controller->run;
		$site = $this->site;
		if (is_ajax_request() && ($units = $site->request->arr('units')) && ($name = $site->request->str('name')) && preg_match('/^[a-z0-9_\s]+$/i', $name)) {
			if (!($saved = $run->exportUnits($units, $name))) {
				bad_request_header();
				echo $site->renderAlerts();
			} else {
				echo basename($saved);
			}
		} elseif (($file = $site->request->str('f')) && file_exists(Config::get('run_exports_dir') . '/' . $file)) {
			// sending file for download
			download_file(Config::get('run_exports_dir') . '/' . $file, true);
		} else {
			bad_request_header();
		}
	}

	private function ajaxRunImport() {
		$run = $this->controller->run;
		$site = $this->site;

		if (is_ajax_request()) {
			// If only showing dialog then show it and exit
			$dialog_only = $site->request->bool('dialog');
			if ($dialog_only) {
				// Read on exported runs from configured directory
				$dir = Config::get('run_exports_dir');
				if (!($exports = (array) get_run_dir_contents($dir))) {
					$exports = array();
				}

				Template::load('run_import_dialog', array('exports' => $exports));
				exit;
			}

			// Else do actual import of specified units
			$json_string = $site->request->str('string');
			$start_position = $site->request->int('position', 1);

			if (!$json_string) {
				bad_request_header();
				exit(1);
			}

			if (!($imports = $run->importUnits($json_string, $start_position))) {
				bad_request_header();
				echo $site->renderAlerts();
			} else {
				json_header();
				echo json_encode($imports);
				exit(0);
			}
		} else {
			bad_request_header();
		}
	}

	private function ajaxRunLockedToggle() {
		$run = $this->controller->run;
		if(is_ajax_request()):
			if(isset($_POST['on'])):
				if(!$run->toggleLocked((bool)$_POST['on']))
					echo 'Error!';
				$this->site->renderAlerts();
			endif;
		endif;
	}

	private function ajaxRunPublicToggle() {
		$run = $this->controller->run;
		if(is_ajax_request()):
			if(isset($_GET['public'])):
				if(!$run->togglePublic((int)$_GET['public']))
					echo 'Error!';
			endif;
		endif;
	}

	private function ajaxSaveRunUnit() {
		$run = $this->controller->run;
		$fdb = $this->fdb;

		if(is_ajax_request()):

			$unit_factory = new RunUnitFactory();
			if(isset($_POST['run_unit_id'])):
				if(isset($_POST['special']))
					$special = $_POST['special'];
				else $special = false;

				$unit_info = $run->getUnitAdmin($_POST['run_unit_id'], $special);

				$unit = $unit_factory->make($fdb,null,$unit_info);

				$unit->create($_POST);
				if($unit->valid):
						if(isset($_POST['unit_id'])):
							alert('<strong>Success.</strong> '.ucfirst($unit->type).' unit was updated.','alert-success');
						endif;
						echo $unit->displayForRun($this->site->renderAlerts());
						exit;
				endif;
			endif;
		endif;
		bad_request_header();
		$alert_msg = "<strong>Sorry.</strong> Something went wrong while saving. Please contact formr devs, if this problem persists.";
		if(isset($unit)) $alert_msg .= implode($unit->errors);
		alert($alert_msg,'alert-danger');

		echo $this->site->renderAlerts();
	}

	private function ajaxSaveSettings() {
		$run = $this->controller->run;

		if(is_ajax_request()):
			$saved = $run->saveSettings($_POST);
			if($saved):
				echo '';
				exit;
			else:
				bad_request_header();
				alert('<strong>Error.</strong> '.implode($run->errors,"<br>"),'alert-danger');
				echo $this->site->renderAlerts();
			endif;
		endif;
	}

	private function ajaxSendToPosition() {
		$run = $this->controller->run;
		$fdb = $this->fdb;

		$run_session = new RunSession($fdb, $run->id, null, $_GET['session']);
		$new_position = $_POST['new_position'];
		$_POST = array();

		if(!$run_session->forceTo($new_position)):
			alert('<strong>Something went wrong with the position change.</strong> in run '.$run->name, 'alert-danger');
			bad_request_header();
		endif;

		if(is_ajax_request()):
			echo $this->site->renderAlerts();
			exit;
		else:
			redirect_to("admin/run/".$run->name."/user_overview");
		endif;
	}

	private function ajaxTestUnit() {
		$run = new Run($this->fdb, $this->controller->run->name);

		if(is_ajax_request()):
			if(isset($_GET['run_unit_id'])):
				if(isset($_GET['special']))
					$special = $_GET['special'];
				else $special = false;

				$unit = $run->getUnitAdmin($_GET['run_unit_id'], $special);
				$unit_factory = new RunUnitFactory();
				$unit = $unit_factory->make($this->db, null, $unit);

				$unit->test();
				echo $this->site->renderAlerts();
				exit;
			endif;
		endif;

		bad_request_header();
		$alert_msg = "'<strong>Sorry.</strong> '";
		if(isset($unit)) $alert_msg .= implode($unit->errors);
		alert($alert_msg,'alert-danger');

		echo $this->site->renderAlerts();
	}

	protected function getPrivateAction($name) {
		$parts = array_filter(explode('_', $name));
		$action = array_shift($parts);
		$class = __CLASS__;
		foreach ($parts as $part) {
			$action .= ucwords(strtolower($part));
		}
		if (!method_exists($this, $action)) {
			throw new Exception("Action '$name' is not found in $class.");
		}
		return $action;
	}

}
