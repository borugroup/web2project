<?php /* $Id$ $URL$ */
if (!defined('W2P_BASE_DIR')) {
	die('You should not access this file directly.');
}

//Lets require the main classes needed
include_once (W2P_BASE_DIR. '/modules/projectdesigner/config.php');
require_once ($AppUI->getSystemClass('libmail'));
require_once ($AppUI->getSystemClass('w2p'));
require_once ($AppUI->getModuleClass('companies'));
require_once ($AppUI->getModuleClass('projects'));
require_once ($AppUI->getModuleClass('tasks'));

/**
 * CProjectDesignerOptions Class
 */
class CProjectDesignerOptions extends CW2pObject {
	var $pd_option_id = null;
	var $pd_option_user = null;
	var $pd_option_view_project = null;
	var $pd_option_view_gantt = null;
	var $pd_option_view_tasks = null;
	var $pd_option_view_actions = null;
	var $pd_option_view_addtasks = null;
	var $pd_option_view_files = null;

	function CProjectDesignerOptions() {
		$this->CW2pObject('project_designer_options', 'pd_option_id');
	}

	function store() {
		$q = new DBQuery;
		$q->addTable('project_designer_options');
		$q->addReplace('pd_option_user', $this->pd_option_user);
		$q->addReplace('pd_option_view_project', $this->pd_option_view_project);
		$q->addReplace('pd_option_view_gantt', $this->pd_option_view_gantt);
		$q->addReplace('pd_option_view_tasks', $this->pd_option_view_tasks);
		$q->addReplace('pd_option_view_actions', $this->pd_option_view_actions);
		$q->addReplace('pd_option_view_addtasks', $this->pd_option_view_addtasks);
		$q->addReplace('pd_option_view_files', $this->pd_option_view_files);
		$q->addWhere('pd_option_user = ' . (int)$this->pd_option_user);
		$q->exec();
	}
}

/** Retrieve tasks with first task_end_dates within given project
 * @param int Project_id
 * @param int SQL-limit to limit the number of returned tasks
 * @return array List of criticalTasks
 */
function getCriticalTasksInverted($project_id = null, $limit = 1) {

	if (!$project_id) {
		$result = array();
		$result[0]['task_end_date'] = '0000-00-00 00:00:00';
		return $result;
	} else {
		$q = new DBQuery;
		$q->addTable('tasks');
		$q->addWhere('task_project = ' . (int)$project_id  . ' AND !isnull( task_end_date ) AND task_end_date !=  "0000-00-00 00:00:00"');
		$q->addOrder('task_start_date ASC');
		$q->setLimit($limit);

		return $q->loadList();
	}
}

function taskstyle_pd($task) {
	$now = new CDate();
	$start_date = intval($task['task_start_date']) ? new CDate($task['task_start_date']) : null;
	$end_date = intval($task['task_end_date']) ? new CDate($task['task_end_date']) : null;

	if ($start_date && !$end_date) {
		$end_date = $start_date;
		$end_date->addSeconds($task['task_duration'] * $task['task_duration_type'] * SEC_HOUR);
	} else
		if (!$start_date) {
			return '';
		}

	$style = 'class=';
	if ($task['task_percent_complete'] == 0) {
		$style .= (($now->before($start_date)) ? '"task_future"' : '"task_notstarted"');
	} else
		if ($task['task_percent_complete'] == 100) {
			$t = new CTask();
			$t->load($task['task_id']);
			$actual_end_date = new CDate(get_actual_end_date_pd($t->task_id, $t));
			$style .= (($actual_end_date->after($end_date)) ? '"task_late"' : '"task_done"');
		} else {
			$style .= (($now->after($end_date)) ? '"task_overdue"' : '"task_started"');
		}
		return $style;
}

function get_actual_end_date_pd($task_id, $task) {
	global $AppUI;
	$q = new DBQuery;
	$mods = $AppUI->getActiveModules();

	if (!empty($mods['history']) && !getDenyRead('history')) {
		$q->addQuery('MAX(history_date) as actual_end_date');
		$q->addTable('history');
		$q->addWhere('history_table=\'tasks\' AND history_item=' . $task_id);
	} else {
		$q->addQuery('MAX(task_log_date) AS actual_end_date');
		$q->addTable('task_log');
		$q->addWhere('task_log_task = ' . (int)$task_id);
	}

	$task_log_end_date = $q->loadResult();

	$edate = $task_log_end_date;

	$edate = ($edate > $task->task_end_date || $task->task_percent_complete == 100) ? $edate : $task->task_end_date;

	return $edate;
}

//This kludgy function echos children tasks as threads on project designer (_pd)

function showtask_pd(&$a, $level = 0, $is_opened = true, $today_view = false) {
	global $AppUI, $w2Pconfig, $done, $query_string, $durnTypes, $userAlloc, $showEditCheckbox;
	global $task_access, $task_priority, $PROJDESIGN_CONFIG, $m, $expanded;

	$types = w2Pgetsysval('TaskType');

	$now = new CDate();
	$tf = $AppUI->getPref('TIMEFORMAT');
	$df = $AppUI->getPref('SHDATEFORMAT');
	$fdf = $df . ' ' . $tf;
	$perms = &$AppUI->acl();
	$show_all_assignees = $w2Pconfig['show_all_task_assignees'] ? true : false;

	$done[] = $a['task_id'];

	$start_date = intval($a['task_start_date']) ? new CDate($a['task_start_date']) : null;
	$end_date = intval($a['task_end_date']) ? new CDate($a['task_end_date']) : null;
	$last_update = isset($a['last_update']) && intval($a['last_update']) ? new CDate($a['last_update']) : null;

	// prepare coloured highlight of task time information
	$sign = 1;
	$style = '';
	if ($start_date) {
		if (!$end_date) {
			/*
			** end date calc has been moved to calcEndByStartAndDuration()-function
			** called from array_csort and tasks.php 
			** perhaps this fallback if-clause could be deleted in the future, 
			** didn't want to remove it shortly before the 2.0.2

			*/
			$end_date = new CDate('0000-00-00 00:00:00');
		}

		if ($now->after($start_date) && $a['task_percent_complete'] == 0) {
			$style = 'background-color:#ffeebb';
		} else
			if ($now->after($start_date) && $a['task_percent_complete'] < 100) {
				$style = 'background-color:#e6eedd';
			}

		if ($now->after($end_date)) {
			$sign = -1;
			$style = 'background-color:#cc6666;color:#ffffff';
		}
		if ($a['task_percent_complete'] == 100) {
			$style = 'background-color:#aaddaa; color:#00000';
		}

		$days = $now->dateDiff($end_date) * $sign;
	}

	if ($expanded) {
		$s = "\n<tr id=\"project_" . $a['task_project'] . '_level>' . $level . '<task_' . $a['task_id'] . "_\" onmouseover=\"highlight_tds(this, true, " . $a['task_id'] . ")\" onmouseout=\"highlight_tds(this, false, " . $a['task_id'] . ")\" onclick=\"select_box('selected_task', '" . $a['task_id'] . "', 'project_" . $a['task_project'] . '_level>' . $level . '<task_' . $a['task_id'] . "_','frm_tasks')\">"; // edit icon
	} else {
		$s = "\n<tr id=\"project_" . $a['task_project'] . '_level>' . $level . '<task_' . $a['task_id'] . "_\" onmouseover=\"highlight_tds(this, true, " . $a['task_id'] . ")\" onmouseout=\"highlight_tds(this, false, " . $a['task_id'] . ")\" onclick=\"select_box('selected_task', '" . $a['task_id'] . "', 'project_" . $a['task_project'] . '_level>' . $level . '<task_' . $a['task_id'] . "_','frm_tasks')\" " . ($level ? 'style="display:none"' : '') . '>'; // edit icon
	}
	$s .= "\n\t<td>";
	//$canEdit = !getDenyEdit( 'tasks', $a['task_id'] );
	$canEdit = true;
	//$canViewLog = $perms->checkModuleItem('task_log', 'view', $a['task_id']);
	$canViewLog = true;
	if ($canEdit) {
		$s .= w2PtoolTip('edit tasks panel', 'click to edit this task') . "\n\t\t<a href=\"?m=tasks&a=addedit&task_id={$a['task_id']}\">" . "\n\t\t\t" . '<img src="' . w2PfindImage('icons/pencil.gif') . '" border="0" width="12" height="12" />' . "\n\t\t</a>" . w2PendTip();
	}
	$s .= "\n\t</td>";
	// pinned
	/*        $pin_prefix = $a['task_pinned']?'':'un';
	$s .= "\n\t<td>";
	$s .=  w2PtoolTip('edit tasks panel',$AppUI->_( $pin_prefix . 'pin task' ))."\n\t\t<a href=\"?m=tasks&pin=" . ($a['task_pinned']?0:1) . "&task_id={$a['task_id']}\">"
	. "\n\t\t\t".''.w2PfindImage('icons/' . $pin_prefix . 'pin.gif') border="0" width="12" height="12">'
	. "\n\t\t</a>".w2PendTip();
	$s .= "\n\t</td>";*/
	// New Log
	/*        if ($a['task_log_problem']>0) {
	$s .= '<td align="center" valign="middle"><a href="?m=tasks&a=view&task_id='.$a['task_id'].'&tab=0&problem=1">';
	$s .= dPshowImage( './images/icons/dialog-warning5.png', 16, 16, 'Problem', 'Problem!' );
	$s .='</a></td>';
	} else if ($canViewLog) {
	$s .= "\n\t<td><a href=\"?m=tasks&a=view&task_id=" . $a['task_id'] . '&tab=1">' . $AppUI->_('Log') . '</a></td>';
	} else {
	$s .= "\n\t<td></td>";
	}*/
	// percent complete
	$s .= "\n\t<td align=\"right\">" . intval($a['task_percent_complete']) . '%</td>';
	// priority
	$s .= "\n\t<td align='center' nowrap='nowrap'>";
	if ($a['task_priority'] < 0) {
		$s .= "\n\t\t<img src=\"" . w2PfindImage('icons/priority-' . -$a['task_priority'] . '.gif') . '" width=13 height=16>';
	} else
		if ($a['task_priority'] > 0) {
			$s .= "\n\t\t<img src=\"" . w2PfindImage('icons/priority+' . $a['task_priority'] . '.gif') . '" width=13 height=16>';
		}
	$s .= $a['file_count'] > 0 ? '<img src="' . w2PfindImage('clip.png') . '" alt="F">' : '';
	$s .= '</td>';
	// access
	$s .= "\n\t" . '<td nowrap="nowrap">';
	$s .= substr($task_access[$a['task_access']], 0, 3);
	$s .= '</td>';
	// type
	$s .= "\n\t" . '<td nowrap="nowrap">';
	$s .= substr($types[$a['task_type']], 0, 3);
	$s .= '</td>';
	// type
	$s .= "\n\t" . '<td nowrap="nowrap">';
	$s .= $a['queue_id'] ? 'Yes' : '';
	$s .= '</td>';
	// inactive
	$s .= "\n\t" . '<td nowrap="nowrap">';
	$s .= $a['task_status'] == '-1' ? 'Yes' : '';
	$s .= '</td>';
	// add log
	$s .= "\n\t" . '<td align="center" nowrap="nowrap">';
	if ($a['task_dynamic'] != 1) {
		$s .= w2PtoolTip('tasks', 'add work log to this task') . "\n\t\t<a href=\"?m=tasks&a=view&tab=1&project_id={$a['task_project']}&task_id={$a['task_id']}\">" . "\n\t\t\t" . '<img src="' . w2PfindImage('add.png', $m) . '" border="0" width="16" height="16" />' . "\n\t\t</a>" . w2PendTip();
	}
	$s .= '</td>';
	// dots
	if ($today_view) {
		$s .= '<td>';
	} else {
		$s .= '<td width="20%">';
	}
	for ($y = 0; $y < $level; $y++) {
		if ($y + 1 == $level) {
			$s .= '<img src="' . w2PfindImage('corner-dots.gif', $m) . '" width="16" height="12" border="0" />';
		} else {
			$s .= '<img src="' . w2PfindImage('shim.gif', $m) . '" width="16" height="12"  border="0" />';
		}
	}
	// name link
	if ($a['task_description']) {
		$s .= w2PtoolTip('Task Description', $a['task_description'], true);
	}
	$open_link = '<a href="javascript: void(0);"><img onclick="expand_collapse(\'project_' . $a['task_project'] . '_level>' . $level . '<task_' . $a['task_id'] . '_\', \'tblProjects\',\'\',' . ($level + 1) . ');" id="project_' . $a['task_project'] . '_level>' . $level . '<task_' . $a['task_id'] . '__collapse" src="' . w2PfindImage('icons/collapse.gif', $m) . '" border="0" align="center" ' . (!$expanded ? 'style="display:none"' : '') . ' /><img onclick="expand_collapse(\'project_' . $a['task_project'] . '_level>' . $level . '<task_' . $a['task_id'] . '_\', \'tblProjects\',\'\',' . ($level + 1) . ');" id="project_' . $a['task_project'] . '_level>' . $level . '<task_' . $a['task_id'] . '__expand" src="' . w2PfindImage('icons/expand.gif', $m) . '" border="0" align="center" ' . ($expanded ? 'style="display:none"' : '') . ' /></a>';
	$taskObj = new CTask;
	$taskObj->load($a['task_id']);
	if (count($taskObj->getChildren())) {
		$is_parent = true;
	} else {
		$is_parent = false;
	}
	if ($a['task_milestone'] > 0) {
		$s .= '&nbsp;<a href="./index.php?m=tasks&a=view&task_id=' . $a['task_id'] . '" ><b>' . $a['task_name'] . '</b></a> <img src="' . w2PfindImage('icons/milestone.gif', $m) . '" border="0" /></td>';
	} else
		if ($a['task_dynamic'] == '1' || $is_parent) {
			if (!$today_view) {
				$s .= $open_link;
			}
			if ($a['task_dynamic'] == '1') {
				$s .= '&nbsp;<a href="./index.php?m=tasks&a=view&task_id=' . $a['task_id'] . '" ><b><i>' . $a['task_name'] . '</i></b></a></td>';
			} else {
				$s .= '&nbsp;<a href="./index.php?m=tasks&a=view&task_id=' . $a['task_id'] . '" >' . $a['task_name'] . '</a></td>';
			}
		} else {
			$s .= '&nbsp;<a href="./index.php?m=tasks&a=view&task_id=' . $a['task_id'] . '" >' . $a['task_name'] . '</a></td>';
		}
		if ($a['task_description']) {
			$s .= w2PendTip();
		}
	if ($today_view) { // Show the project name
		$s .= '<td>';
		$s .= '<a href="./index.php?m=projects&a=view&project_id=' . $a['task_project'] . '">';
		$s .= '<span style="padding:2px;background-color:#' . $a['project_color_identifier'] . ';color:' . bestColor($a['project_color_identifier']) . '">' . $a['project_name'] . '</span>';
		$s .= '</a></td>';
	}
	// task description
	if ($PROJDESIGN_CONFIG['show_task_descriptions']) {
		$s .= '<td align="justified">' . $a['task_description'] . '</td>';
	}
	// task owner
	$s .= '<td align="center">' . '<a href="?m=admin&a=viewuser&user_id=' . $a['user_id'] . '">' . $a['contact_first_name'] . ' ' . $a['contact_last_name'] . '</a></td>';
	if (!$today_view) {
		$s .= '<td id="ignore_td_' . $a['task_id'] . '" nowrap="nowrap" align="center" style="' . $style . '">' . ($start_date ? $start_date->format($df . ' ' . $tf) : '-') . '</td>';
		//		        $s .= '<td nowrap="nowrap" align="center" style="'.$style.'">'.($start_date ? $start_date->format( $tf ) : '-').'</td>';
	}
	// duration or milestone
	$s .= '<td id="ignore_td_' . $a['task_id'] . '" align="center" nowrap="nowrap" style="' . $style . '">';
	$s .= $a['task_duration'] . ' ' . $AppUI->_($durnTypes[$a['task_duration_type']]);
	$s .= '</td>';
	$s .= '<td id="ignore_td_' . $a['task_id'] . '" nowrap="nowrap" align="center" style="' . $style . '">' . ($end_date ? $end_date->format($df . ' ' . $tf) : '-') . '</td>';
	if (isset($a['task_assigned_users']) && ($assigned_users = $a['task_assigned_users'])) {
		$a_u_tmp_array = array();
		if ($show_all_assignees) {
			$s .= '<td align="center">';
			foreach ($assigned_users as $val) {
				//$a_u_tmp_array[] = "<A href='mailto:".$val['user_email']."'>".$val['user_username']."</A>";
				$aInfo = '<a href="?m=admin&a=viewuser&user_id=' . $val['user_id'] . '"';
				$aInfo .= 'title="' . $AppUI->_('Extent of Assignment') . ':' . $userAlloc[$val['user_id']]['charge'] . '%; ' . $AppUI->_('Free Capacity') . ':' . $userAlloc[$val['user_id']]['freeCapacity'] . '%' . '">';
				$aInfo .= $val['contact_first_name'] . ' ' . $val['contact_last_name'] . " (" . $val['perc_assignment'] . "%)</a>";
				$a_u_tmp_array[] = $aInfo;
			}
			$s .= join(', ', $a_u_tmp_array);
			$s .= '</td>';
		} else {
			$s .= '<td align="center" nowrap="nowrap">';
			$s .= '<a href="?m=admin&a=viewuser&user_id=' . $assigned_users[0]['user_id'] . '"';
			$s .= 'title="' . $AppUI->_('Extent of Assignment') . ':' . $userAlloc[$assigned_users[0]['user_id']]['charge'] . '%; ' . $AppUI->_('Free Capacity') . ':' . $userAlloc[$assigned_users[0]['user_id']]['freeCapacity'] . '%' . '">';
			$s .= $assigned_users[0]['contact_first_name'] . ' ' . $assigned_users[0]['contact_last_name'] . ' (' . $assigned_users[0]['perc_assignment'] . '%)</a>';
			if ($a['assignee_count'] > 1) {
				$id = $a['task_id'];
				$s .= " <a href=\"javascript: void(0);\"  onclick=\"toggle_users('users_$id');\" title=\"" . join(', ', $a_u_tmp_array) . "\">(+" . ($a['assignee_count'] - 1) . ")</a>";

				$s .= '<span style="display: none" id="users_' . $id . '">';

				$a_u_tmp_array[] = $assigned_users[0]['user_username'];
				for ($i = 1, $i_cmp = count($assigned_users); $i < $i_cmp; $i++) {
					$a_u_tmp_array[] = $assigned_users[$i]['user_username'];
					$s .= '<br /><a href="?m=admin&a=viewuser&user_id=';
					$s .= $assigned_users[$i]['user_id'] . '" title="' . $AppUI->_('Extent of Assignment') . ':' . $userAlloc[$assigned_users[$i]['user_id']]['charge'] . '%; ' . $AppUI->_('Free Capacity') . ':' . $userAlloc[$assigned_users[$i]['user_id']]['freeCapacity'] . '%' . '">';
					$s .= $assigned_users[$i]['contact_first_name'] . ' ' . $assigned_users[$i]['contact_last_name'] . ' (' . $assigned_users[$i]['perc_assignment'] . '%)</a>';
				}
				$s .= '</span>';
			}
			$s .= '</td>';
		}
	} elseif (!$today_view) {
		// No users asigned to task
		$s .= '<td align="center">-</td>';
	}

	// Assignment checkbox
	if ($showEditCheckbox) {
		$s .= "\n\t" . '<td align="center"><input type="checkbox" onclick="select_box(\'selected_task\', ' . $a['task_id'] . ',\'project_' . $a['task_project'] . '_level>' . $level . '<task_' . $a['task_id'] . '_\',\'frm_tasks\')" onfocus="is_check=true;" onblur="is_check=false;" id="selected_task_' . $a['task_id'] . '" name="selected_task[' . $a['task_id'] . ']" value="' . $a['task_id'] . '"/></td>';
	}
	$s .= '</tr>';
	echo $s;
}

function findchild_pd(&$tarr, $parent, $level = 0) {
	global $projects;
	global $tasks_opened;

	$level = $level + 1;
	$n = count($tarr);

	for ($x = 0; $x < $n; $x++) {
		if ($tarr[$x]['task_parent'] == $parent && $tarr[$x]['task_parent'] != $tarr[$x]['task_id']) {
			$is_opened = in_array($tarr[$x]['task_id'], $tasks_opened);
			showtask_pd($tarr[$x], $level, $is_opened);
			if ($is_opened || !$tarr[$x]['task_dynamic']) {
				findchild_pd($tarr, $tarr[$x]['task_id'], $level);
			}
		}
	}
}

function get_dependencies_pd($task_id) {
	// Pull tasks dependencies
	$q = new DBQuery;
	$q->addTable('tasks', 't');
	$q->addTable('task_dependencies', 'td');
	$q->addQuery('t.task_id, t.task_name');
	$q->addWhere('td.dependencies_task_id = ' . (int)$task_id);
	$q->addWhere('t.task_id = td.dependencies_req_task_id');
	$taskDep = $q->loadHashList();
}

function showtask_pr(&$a, $level = 0, $is_opened = true, $today_view = false) {
	global $AppUI, $w2Pconfig, $done, $query_string, $durnTypes, $userAlloc, $showEditCheckbox;
	global $task_access, $task_priority;

	$types = w2Pgetsysval('TaskType');

	$now = new CDate();
	$tf = $AppUI->getPref('TIMEFORMAT');
	$df = $AppUI->getPref('SHDATEFORMAT');
	$fdf = $df . " " . $tf;
	$perms = &$AppUI->acl();
	$show_all_assignees = $w2Pconfig['show_all_task_assignees'] ? true : false;

	$done[] = $a['task_id'];

	$start_date = intval($a['task_start_date']) ? new CDate($a['task_start_date']) : null;
	$end_date = intval($a['task_end_date']) ? new CDate($a['task_end_date']) : null;
	$last_update = isset($a['last_update']) && intval($a['last_update']) ? new CDate($a['last_update']) : null;

	// prepare coloured highlight of task time information
	$sign = 1;
	$style = '';
	if ($start_date) {
		if (!$end_date) {
			/*
			** end date calc has been moved to calcEndByStartAndDuration()-function
			** called from array_csort and tasks.php 
			** perhaps this fallback if-clause could be deleted in the future, 
			** didn't want to remove it shortly before the 2.0.2

			*/
			$end_date = new CDate('0000-00-00 00:00:00');
		}

		$days = $now->dateDiff($end_date) * $sign;
	}

	$s = "\n<tr>";

	// dots
	if ($today_view) {
		$s .= '<td nowrap="nowrap">';
	} else {
		$s .= '<td nowrap width="20%">';
	}
	for ($y = 0; $y < $level; $y++) {
		if ($y + 1 == $level) {
			$s .= '<img src="' . w2PfindImage('corner-dots.gif', $m) . '" width="16" height="12" border="0" />';
		} else {
			$s .= '<img src="' . w2PfindImage('shim.gif', $m) . '" width="16" height="12"  border="0" />';
		}
	}
	// name link
	$alt = strlen($a['task_description']) > 80 ? substr($a["task_description"], 0, 80) . '...' : $a['task_description'];
	// instead of the statement below
	$alt = str_replace("\"", "&quot;", $alt);
	$alt = str_replace("\r", ' ', $alt);
	$alt = str_replace("\n", ' ', $alt);

	$open_link = $is_opened ? "<!--<a href='index.php$query_string&close_task_id=" . $a["task_id"] . "'>--><img src='" . w2PfindImage('icons/collapse.gif', $m) . "' border='0' align='center' /><!--</a>-->" : "<!--<a href='index.php$query_string&open_task_id=" . $a["task_id"] . "'>--><img src='images/icons/expand.gif' border='0' /><!--</a>-->";
	if ($a['task_milestone'] > 0) {
		$s .= '&nbsp;<!--<a href="./index.php?m=tasks&a=view&task_id=' . $a["task_id"] . '" title="' . $alt . '">--><b>' . $a["task_name"] . '</b><!--</a>--> <img src="' . w2PfindImage('icons/milestone.gif', $m) . '" border="0" /></td>';
	} else
		if ($a['task_dynamic'] == '1') {
			if (!$today_view) {
				$s .= $open_link;
			}
			$s .= $a['task_name'];
		} else {
			$s .= $a['task_name'];
		}
		// percent complete
		$s .= "\n\t" . '<td align="right">' . intval($a['task_percent_complete']) . '%</td>';
	if ($today_view) { // Show the project name
		$s .= '<td>';
		$s .= '<a href="./index.php?m=projects&a=view&project_id=' . $a['task_project'] . '">';
		$s .= '<span style="padding:2px;background-color:#' . $a['project_color_identifier'] . ';color:' . bestColor($a['project_color_identifier']) . '">' . $a['project_name'] . '</span>';
		$s .= '</a></td>';
	}
	if (!$today_view) {
		$s .= '<td nowrap="nowrap" align="center" style="' . $style . '">' . ($start_date ? $start_date->format($df . ' ' . $tf) : '-') . '</td>';
	}
	$s .= '</td>';
	$s .= '<td nowrap="nowrap" align="center" style="' . $style . '">' . ($end_date ? $end_date->format($df . ' ' . $tf) : '-') . '</td>';
	$s .= '</td>';
	$s .= '<td nowrap="nowrap" align="center" style="' . $style . '">' . ($last_update ? $last_update->format($df . ' ' . $tf) : '-') . '</td>';
	echo $s;
}

function findchild_pr(&$tarr, $parent, $level = 0) {
	global $projects;
	global $tasks_opened;

	$level = $level + 1;
	$n = count($tarr);

	for ($x = 0; $x < $n; $x++) {
		if ($tarr[$x]['task_parent'] == $parent && $tarr[$x]['task_parent'] != $tarr[$x]['task_id']) {
			$is_opened = in_array($tarr[$x]['task_id'], $tasks_opened);
			showtask_pr($tarr[$x], $level, $is_opened);
			if ($is_opened || !$tarr[$x]['task_dynamic']) {
				findchild_pr($tarr, $tarr[$x]['task_id'], $level);
			}
		}
	}
}
?>