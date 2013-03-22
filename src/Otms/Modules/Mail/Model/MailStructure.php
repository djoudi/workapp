<?php

/**
 * This file is part of the Workapp project.
 *
 * Mail Module
 *
 * (c) Dmitry Samotoy <dmitry.samotoy@gmail.com>
 *
 */

namespace Otms\Modules\Mail\Model;

use Engine\Modules\Model;
use Otms\Modules\Task;
use Otms\System\Model\Settings;

/**
 * Model\MailStructure class
 *
 * Класс-модель для разбора структуры письма
 *
 * @author Dmitry Samotoy <dmitry.samotoy@gmail.com>
 */

class MailStructure extends Model {
	/**
	 * Поток IMAP, полученный из imap_open()
	 * 
	 * @var int
	 */
	public $connect;
	
	/**
	 * Номер сообщения для imap_fetchstructure()
	 * 
	 * @var int
	 */
	public $mes_num;
	
	/**
	 * UIDL
	 * 
	 * @var string
	 */
	public $uidl;
	
	/**
	 * MAILTO:
	 * 
	 * @var string
	 */
	public $to;
	
	/**
	 * @var object
	 */
	public $header;
	
	/**
	 * @var array
	 */
	private $body_part;
	
	/**
	 * @var array
	 */
	private $attach;
	
	/**
	 * @var string
	 */
	private $charset = null;
	
	/**
	 * Флаг, если входящее письмо является задачей
	 * 
	 * @var boolean
	 */
	public $emailTask = false;
	
	/**
	 * Сортировка - создать задачу из письма
	 * 
	 * @var boolean
	 */
	public $mailInTask = false;
	
	/**
	 * !!! Содержит что-то для создания новой задачи - предположительно текст
	 * @var array
	 */
	public $task = array();
	
	/**
	 * Текст - действие при появлении задачи из другой системы (новая задача, правка и т.д.)
	 * 
	 * @var string
	 */
	public $textMailAction = null;
	
	/**
	 * Если письмо из нашей системы - лога не пишем!
	 * 
	 * @var boolean
	 */
	public $notLog = true;
	
	/**
	 * Получить заголовки
	 * 
	 * $this->header = imap_headerinfo
	 */
	function getHeader() {
		$this->header = imap_headerinfo($this->connect, $this->mes_num);
	}
	
	/**
	 * Функция разбивает сообщение на части (HTML, PLAIN, attach)
	 * 
	 * @param int $part_section
	 * @param object $part
	 * @param array $section
	 * Результат:
	 *    $this->body_part
	 *    $this->attach
	 */
	function getPartMail($part_section, $part, $section = array()) {
		if (!empty($part->parts)) {
			$section[] = $part_section + 1;
			for ($p = 0; $p < count($part->parts); $p++) {
				$this->getPartMail($p, $part->parts[$p], $section);
			}
		} else {
			$section_string = null;
			$section_string = implode($section, ".");
			if ($section_string != null) {
				$section_string .= "." . ($part_section + 1);
			} else {
				$section_string .= $part_section + 1;
			}
			
			if ($part->subtype == 'PLAIN') {
				if ( (isset($part->disposition)) and (strtolower($part->disposition) == "attachment") ) {
					$filename = $this->getAttachName($part);
					$md5 = $this->saveAttach($part, $section_string, $filename);
					$this->attach[$md5] = $filename;
				} else {
					$this->body_part[count($this->body_part)]["text"] = $this->getMailText($part, $section_string);
					$this->body_part[count($this->body_part)-1]["type"] = "text";
				}
			} elseif ($part->subtype == "HTML") {
				if ( (isset($part->disposition)) and (strtolower($part->disposition) == "attachment")  ) {
					$filename = $this->getAttachName($part);
					$md5 = $this->saveAttach($part, $section_string, $filename);
					$this->attach[$md5] = $filename;
				} else {
					$this->body_part[count($this->body_part)]["text"] = $this->getMailText($part, $section_string);
					$this->body_part[count($this->body_part)-1]["type"] = "html";
				}
			} else {
				$filename = $this->getAttachName($part);
				$md5 = $this->saveAttach($part, $section_string, $filename);
				$this->attach[$md5] = $filename;
			}
		}
	}
	
	/**
	 * Разобрать сообщение
	 * 
	 * @return array $mail
	 *    $mail["uid"] - UIDL
	 *    $mail["body"]
	 *    $mail["attach"]
	 *    $mail["subject"]
	 *    $mail["date"]
	 *    $mail["personal"]
	 *    $mail["mailbox"]
	 *    $mail["host"]
	 *    $mail["to"]
	 */
	function fetchMail() {
		$this->body_part = array();
		
		$this->body_array = array();
		$this->attach = array();
		$this->section = 0;
		
		$mail["uid"] = $this->uidl;

		$st = imap_fetchstructure($this->connect, $this->mes_num);
		$this->st = $st;

		if (!empty($st->parts)) {
			for ($p = 0; $p < count($st->parts); $p++) {
				$part = $st->parts[$p];

				$this->getPartMail($p, $part);
			}
		} else {
			if ($st->subtype == 'PLAIN') {
				$this->body_part[count($this->body_part)]["text"] = $this->getMailText($st, 1);
				$this->body_part[count($this->body_part)-1]["type"] = "text";
			} elseif ($st->subtype == "HTML") {
				$this->body_part[count($this->body_part)]["text"] = $this->getMailText($st, 1);
				$this->body_part[count($this->body_part)-1]["type"] = "html";
			} else {
				$filename = $this->getAttachName($st);
				$md5 = $this->saveAttach($st, 1, $filename);
				$this->attach[$md5] = $filename;
			}
		}

		$mail["body"] = $this->body_part;
		$mail["attach"] = $this->attach;
		
		$mail["subject"] = null;
		if (isset($this->header->subject)) {
			$elements = imap_mime_header_decode($this->header->subject);
			foreach ($elements as $element) {
				$charset = $element->charset;
	
				if ( (strtolower($charset) != "default") and (strtolower($charset) != "x-unknown") ) {
					$mail["subject"] .= iconv($charset, "UTF-8", $element->text);
				} else {
					if ($this->charset != null) {
						$mail["subject"] .= iconv($this->charset, "UTF-8", $element->text);
					} else {
						$mail["subject"] .= $element->text;
					}
				}
			}
		}
		if ($mail["subject"] == "") {
			$mail["subject"] = "Без темы";
		}

		$mail["date"] = date("Y-m-d H:i:s", $this->header->udate);

		$mail["personal"] = '0';
		
		foreach($this->header->from as $from) {
			if (isset($from->personal)) {
				$elements = imap_mime_header_decode($from->personal);
				foreach($elements as $element) {
					$charset = $element->charset;
	
					if ($charset != "default") {
						$mail["personal"] = iconv($charset, "UTF-8", $element->text);
					} else {
						$mail["personal"] = $element->text;
					}
				}
			}
	
			$mail["mailbox"] = $from->mailbox;
			$mail["host"] = $from->host;
		}

		$mail["to"] = $this->to;
		
		if ($json = json_decode(base64_decode($mail["subject"]))) {
			$this->mailAction($mail);
		} else {
			$this->action($mail);
		}

		return $mail;
	}
	
	/**
	 * Действие - сортировка
	 * 
	 * @param array $mail
	 * Результат:
	 *    $this->mailInTask
	 *    $this->task
	 *    $this->emailTask
	 */
	function action($mail) {
		$startdate["startdate_global"] = date("Y-m-d"); $startdate["starttime_global"] = date("H:i:s");
		$startdate["startdate_noiter"] = date("Y-m-d"); $startdate["starttime_noiter"] = date("H:i:s");
		$startdate["startdate_iter"] = date("Y-m-d"); $startdate["starttime_iter"] = date("H:i:s");
						
		$mailClass = new Mail();
		$sorts = $mailClass->getSorts();

		foreach($sorts as $part) {
			$k = 0;
			foreach($part as $parted) {
				if ($parted["type"] == "to") {
					if ($parted["val"] == $mail["to"]) {
						if ($parted["action"] == "remove") {
							$this->emailTask = true;
						} else if ($parted["action"] == "task") {
							$sort = $mailClass->getSortByTo($parted["val"]);
							$sort += $startdate;
							$sort["task"] = "1";
							$k++;
						}
					}
				}
				
				if ($parted["type"] == "from") {
					if ($parted["val"] == $mail["mailbox"] . "@" . $mail["host"]) {
						if ($parted["action"] == "remove") {
							$this->emailTask = true;
						} else if ($parted["action"] == "task") {
							$sort = $mailClass->getSortByFrom($parted["val"]);
							$sort += $startdate;
							$sort["task"] = "1";
							$k++;
						}
					}
				}
				
				if ($parted["type"] == "subject") {
					if (mb_strpos($mail["subject"], $parted["val"]) !== false) {
						if ($parted["action"] == "remove") {
							$this->emailTask = true;
						} else if ($parted["action"] == "task") {
							$sort = $mailClass->getSortBySubject($parted["val"]);
							$sort += $startdate;
							$sort["task"] = "1";
							$k++;
						}
					}
				}
			}
			if ($k == count($part)) {
				$this->mailInTask = true;
				$this->task = $sort;
			}
		} 
	}
	
	/**
	 * Действие - задача из другой системы
	 * 
	 * @param array $mail
	 * Результат:
	 *    $this->notLog
	 *    $this->textMailAction
	 */
	function mailAction($mail) {
		$ttmail = new Task\Model\Mail();
		$ttmail->uid = $this->uid;
		
		$settings = new Settings();
		$otms_mail = $settings->getMailbox();
		
		$json = json_decode(base64_decode($mail["subject"]));
	
		if ( ($json->name == "OTMS") and (isset($json->method)) ) {
			$this->emailTask = true;

	    	if ($otms_mail["email"] != $mail["mailbox"] . "@" . $mail["host"]) {
	    		if ($json->method == "addtask") {
	    			$this->textMailAction = "New task(another OTMS)";
			    	foreach($mail["body"] as $part) {
			    		$part = json_decode(base64_decode($part["text"]), true);

			    		$tid = $ttmail->addTask($json->tid, $part);
			    	}
			    	
	    			foreach($mail["attach"] as $key=>$part) {
						if ($part != "") {
							$sql = "INSERT INTO mail_attach (tid, md5, filename) VALUES (:tid, :md5, :filename)";
		        
					        $res = $this->registry['db']->prepare($sql);
							$param = array(":tid" => $tid, ":md5" => $key, ":filename" => $part);
							$res->execute($param);
						}
					}
	    		} elseif ($json->method == "edittask") {
	    			$this->textMailAction = "Edit task(another OTMS)";
	    			foreach($mail["body"] as $part) {
			    		$part = json_decode(base64_decode($part["text"]), true);

			    		$tid = $ttmail->editTask($json->tid, $part);
			    	}
			    	
			    	if ( (isset($mail["attach"])) and (count($mail["attach"]) > 0) ) {
			    		$sql = "DELETE FROM mail_attach WHERE tid = :tid";
		        
					    $res = $this->registry['db']->prepare($sql);
						$param = array(":mid" => $tid);
						$res->execute($param);
			    	}
			    	
	    			foreach($mail["attach"] as $key=>$part) {
						if ($part != "") {
							$sql = "INSERT INTO mail_attach (tid, md5, filename) VALUES (:tid, :md5, :filename)";
		        
					        $res = $this->registry['db']->prepare($sql);
							$param = array(":tid" => $tid, ":md5" => $key, ":filename" => $part);
							$res->execute($param);
						}
					}
	    		} elseif ($json->method == "closetask") {
	    			$this->textMailAction = "Task closed(another OTMS)";
	    			
	    			$ttmail->closeTask($json->tid);
	    		} elseif ($json->method == "comment") {
	    			$this->textMailAction = "Comment to task(another OTMS)";
	    			
	    			foreach($mail["body"] as $part) {
	    				$part = json_decode(base64_decode($part["text"]), true);
	    				
	    				if ($json->rc) {
	    					$tdid = $ttmail->addCommentAnswer($json->tid, $part, $part["status"]);
	    				} else {
	    					$tdid = $ttmail->addComment($json->tid, $part, $part["status"]);
	    				}
	    			}
	    			
    				foreach($mail["attach"] as $key=>$part) {
						if ($part != "") {
							$sql = "INSERT INTO mail_attach (tdid, md5, filename) VALUES (:tdid, :md5, :filename)";
		        
					        $res = $this->registry['db']->prepare($sql);
							$param = array(":tdid" => $tdid, ":md5" => $key, ":filename" => $part);
							$res->execute($param);
						}
					}

	    		}
	    	} else {
				$this->notLog = false;
			}
		}
	}

	/**
	 * Получить имя аттача
	 * 
	 * @param object $part
	 * @return string
	 */
	function getAttachName($part) {
		$attach = null;
		
		if ( (isset($part->ifparameters)) and ($part->ifparameters)) {
			foreach($part->parameters as $parted) {
				$elements = imap_mime_header_decode($parted->value);
				
				foreach($elements as $element) {
					$charset = $element->charset;
	
					if (strtolower($charset) != "default") {
						$attach .= iconv($charset, "UTF-8", $element->text);
					} else {
						if ($this->charset != null) {
							$attach .= iconv($this->charset, "UTF-8", $element->text);
						} else {
							$attach .= $element->text;
						}
					}
				}
			}
		} elseif ( (isset($part->ifdparameters)) and ($part->ifdparameters)) {
			foreach($part->dparameters as $parted) {
				$elements = imap_mime_header_decode($parted->value);
				
				foreach($elements as $element) {
					$charset = $element->charset;
	
					if (strtolower($charset) != "default") {
						$attach .= iconv($charset, "UTF-8", $element->text);
					} else {
						if ($this->charset != null) {
							$attach .= iconv($this->charset, "UTF-8", $element->text);
						} else {
							$attach .= $element->text;
						}
					}
				}
			}
		}

		return $attach;
	}
	
	/**
	 * Сохранить аттач
	 * 
	 * @param object $part
	 * @param int $section
	 * @param string $filename
	 */
	function saveAttach($part, $section, $filename) {
		switch($part->encoding) {
			case 0: $data = imap_fetchbody($this->connect, $this->mes_num, $section); break;
			case 1: $data = imap_fetchbody($this->connect, $this->mes_num, $section); break;
			case 2: $data = imap_fetchbody($this->connect, $this->mes_num, $section); break;
			case 3: $data = base64_decode(imap_fetchbody($this->connect, $this->mes_num, $section)); break;
			case 4: $data = quoted_printable_decode(imap_fetchbody($this->connect, $this->mes_num, $section)); break;
			case 5: $data = imap_fetchbody($this->connect, $this->mes_num, $section); break;
		}

		$md5 = md5($this->uidl . $filename);
		$filename = $this->registry["rootPublic"] . $this->registry["path"]["attaches"] . $md5;

		$fp = fopen($filename, "wb+");
		fwrite($fp, $data);
		fclose($fp);
		
		return $md5;
	}

	/**
	 * Получить текст сообщения
	 * 
	 * @param object $part
	 * @param int $p - секция
	 * @return string
	 */
	function getMailText($part, $p) {
		switch($part->encoding) {
			case 0: $body = imap_fetchbody($this->connect, $this->mes_num, $p); break;
			case 1: $body = imap_fetchbody($this->connect, $this->mes_num, $p); break;
			case 2: $body = imap_fetchbody($this->connect, $this->mes_num, $p); break;
			case 3: $body = base64_decode(imap_fetchbody($this->connect, $this->mes_num, $p)); break;
			case 4: $body = quoted_printable_decode(imap_fetchbody($this->connect, $this->mes_num, $p)); break;
			case 5: $body = imap_fetchbody($this->connect, $this->mes_num, $p); break;
		}

		foreach($part->parameters as $parted) {
			if (strtolower($parted->attribute) == "charset") {
				if (strtolower($parted->value) != "x-unknown") {
					$this->charset = $parted->value;
					
					$body = iconv($parted->value, "UTF-8", $body);
				}
			}
		}
		
		if (count((array)$part->parameters) == 0) {
			$body = iconv("CP1251", "UTF-8", $body);
		}

		return $body;
	}
}
?>