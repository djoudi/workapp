<?php

/**
 * This file is part of the Workapp project.
 *
 * Mail Module
 *
 * (c) Dmitry Samotoy <dmitry.samotoy@gmail.com>
 *
 */

namespace Otms\Modules\Mail\Controller\Mail;

use Otms\Modules\Mail\Controller\Mail;
use Otms\Modules\Mail\Model;

/**
 * Controller\Mail\Folder class
 *
 * @author Dmitry Samotoy <dmitry.samotoy@gmail.com>
 */
class Folder extends Mail {

	function index() {
		$mailClass = new Model\Mail();
		
		$this->view->setLeftContent($this->view->render("left_mail", array("folders" => $this->folders, "enableCheck" => $this->enableCheck)));

		if (isset($_POST["edit_submit"])) {
			
			$this->view->setTitle("Edit");
			
			$err = array();
			$str = htmlspecialchars($_POST["folder"]);
			$strlen = mb_strlen($_POST["folder"]);
			if ( ($strlen < 1) or ($strlen > 64) ) { $err[] = "Folder name must be from 1 to 64 characters"; }
			
			if (count($err) == 0) {
				$mailClass->editFolder($_GET["id"], $str);
				
				$this->view->refresh(array("timer" => "1", "url" => "mail/folder/"));
			} else {
				$this->view->mail_folder(array("err" => $err, "folders" => $this->folders));
			}
		
		} elseif (isset($_POST["submit"])) {
			
			$this->view->setTitle("New folder");

			$err = array();
			$str = htmlspecialchars($_POST["folder"]);
			$strlen = mb_strlen($_POST["folder"]);
			if ( ($strlen < 1) or ($strlen > 64) ) { $err[] = "Folder name must be from 1 to 64 characters"; }
			
			if (count($err) == 0) {
				$mailClass->addFolder($str);
				
				$this->view->refresh(array("timer" => "1", "url" => "mail/folder/"));
			} else {
				$this->view->mail_folder(array("err" => $err, "folders" => $this->folders));
			}
		} else {
			if (isset($_GET["id"])) {
				
				$this->view->setTitle("Edit");
				
				foreach($this->folders as $part) {
					if ($part["id"] == $_GET["id"]) {
						$folder = $part;
					}
				}
				$this->view->mail_editfolder(array("folder" => $folder));
			} else {
				
				$this->view->setTitle("New folder");
				
				$this->view->mail_folder(array("folders" => $this->folders));
			}
		}
		
		$this->view->showPage();
	}
}
?>