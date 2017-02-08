<?php

namespace Controller;

class Admin extends \Controller {

	protected $_userId;

	public function __construct() {
		$this->_userId = $this->_requireAdmin();
		\Base::instance()->set("menuitem", "admin");
	}

	/**
	 * @param \Base $f3
	 */
	public function index($f3) {
		$f3->set("title", $f3->get("dict.administration"));
		$f3->set("menuitem", "admin");

		if($f3->get("POST.action") == "clearcache") {
			$cache = \Cache::instance();

			// Clear configured cache
			$cache->reset();

			// Clear filesystem cache (thumbnails, etc.)
			$cache->load("folder=tmp/cache/");
			$cache->reset();

			// Reset cache configuration
			$cache->load($f3->get("CACHE"));

			$f3->set("success", "Cache cleared successfully.");
		}

		$db = $f3->get("db.instance");

		// Gather some stats
		$result = $db->exec("SELECT COUNT(id) AS `count` FROM user WHERE deleted_date IS NULL AND role != 'group'");
		$f3->set("count_user", $result[0]["count"]);
		$result = $db->exec("SELECT COUNT(id) AS `count` FROM issue WHERE deleted_date IS NULL");
		$f3->set("count_issue", $result[0]["count"]);
		$result = $db->exec("SELECT COUNT(id) AS `count` FROM issue_update");
		$f3->set("count_issue_update", $result[0]["count"]);
		$result = $db->exec("SELECT COUNT(id) AS `count` FROM issue_comment");
		$f3->set("count_issue_comment", $result[0]["count"]);
		$result = $db->exec("SELECT value as version FROM config WHERE attribute = 'version'");
		$f3->set("version", $result[0]["version"]);

		if($f3->get("CACHE") == "apc") {
			$f3->set("apc_stats", apc_cache_info("user", true));
		}

		$this->_render("admin/index.html");
	}

	/**
	 * @param \Base $f3
	 */
	public function config($f3) {
		$status = new \Model\Issue\Status;
		$f3->set("issue_statuses", $status->find());

		$f3->set("title", $f3->get("dict.configuration"));
		$this->_render("admin/config.html");
	}

	/**
	 * @param \Base $f3
	 * @throws \Exception
	 */
	public function config_post_saveattribute($f3) {
		$attribute = str_replace("-", ".", $f3->get("POST.attribute"));
		$value = $f3->get("POST.value");
		$response = array("error" => null);

		if(!$attribute) {
			$response["error"] = "No attribute specified.";
			$this->_printJson($response);
			return;
		}

		$config = new \Model\Config;
		$config->load(array("attribute = ?", $attribute));

		$config->attribute = $attribute;
		switch($attribute) {
			case "site-name":
				if(trim($value)) {
					$config->value = $value;
					$config->save();
				} else {
					$response["error"] = "Site name cannot be empty.";
				}
				break;
			case "site-timezone":
				if(in_array($value, timezone_identifiers_list())) {
					$config->value = $value;
					$config->save();
				} else {
					$response["error"] = "Timezone is invalid.";
				}
				break;
			default:
				$config->value = $value;
				$config->save();
		}

		if(!$response["error"]) {
			$response["attribute"] = $attribute;
			$response["value"] = $value;
		}

		$this->_printJson($response);
	}

	/**
	 * @param \Base $f3
	 */
	public function plugins($f3) {
		$f3->set("title", $f3->get("dict.plugins"));
		$this->_render("admin/plugins.html");
	}

	/**
	 * @param \Base $f3
	 * @param array $params
	 */
	public function plugin_single($f3, $params) {
		$this->_userId = $this->_requireAdmin(5);
		$plugins = $f3->get("plugins");
		if($plugin = $plugins[$params["id"]]) {
			$f3->set("plugin", $plugin);
			if($f3->get("AJAX")) {
				$plugin->_admin();
			} else {
				$f3->set("title", $plugin->_package());
				$this->_render("admin/plugins/single.html");
			}
		} else {
			$f3->error(404);
		}
	}

	/**
	 * @param \Base $f3
	 */
	public function users($f3) {
		$f3->set("title", $f3->get("dict.users"));

		$users = new \Model\User();
		$f3->set("users", $users->find("deleted_date IS NULL AND role != 'group'"));
		$f3->set("select_users", $users->find("deleted_date IS NULL AND role != 'group'", array("order" => "name ASC")));

		$this->_render("admin/users.html");
	}

	/**
	 * @param \Base $f3
	 */
	public function deleted_users($f3) {
		$f3->set("title", $f3->get("dict.users"));

		$users = new \Model\User();
		$f3->set("users", $users->find("deleted_date IS NOT NULL AND role != 'group'"));

		$this->_render("admin/users/deleted.html");
	}

	/**
	 * @param \Base $f3
	 * @param array $params
	 * @throws \Exception
	 */
	public function user_edit($f3, $params) {
		$f3->set("title", $f3->get("dict.edit_user"));

		$user = new \Model\User();
		$user->load($params["id"]);

		if($user->id) {
			if($user->rank > $f3->get("user.rank")) {
				$f3->error(403, "You are not authorized to edit this user.");
				return;
			}
			$f3->set("this_user", $user);
			$this->_render("admin/users/edit.html");
		} else {
			$f3->error(404, "User does not exist.");
		}

	}

	/**
	 * @param \Base $f3
	 */
	public function user_new($f3) {
		$f3->set("title", $f3->get("dict.new_user"));
		$f3->set("rand_color", sprintf("#%02X%02X%02X", mt_rand(0, 0xFF), mt_rand(0, 0xFF), mt_rand(0, 0xFF)));
		$this->_render("admin/users/edit.html");
	}

	/**
	 * @param \Base $f3
	 * @throws \Exception
	 */
	public function user_save($f3) {
		$security = \Helper\Security::instance();
		$user = new \Model\User;

		// Load current user if set, otherwise validate fields for new user
		if($user_id = $f3->get("POST.user_id")) {
			$f3->set("title", $f3->get("dict.edit_user"));
			$user->load($user_id);
			$f3->set("this_user", $user);
		} else {
			$f3->set("title", $f3->get("dict.new_user"));

			// Verify a password is being set
			if(!$f3->get("POST.password")) {
				$f3->set("error", "User already exists with this username");
				$this->_render("admin/users/edit.html");
				return;
			}

			// Check for existing users with same info
			$user->load(array("username = ?", $f3->get("POST.username")));
			if($user->id) {
				$f3->set("error", "User already exists with this username");
				$this->_render("admin/users/edit.html");
				return;
			}

			$user->load(array("email = ?", $f3->get("POST.email")));
			if($user->id) {
				$f3->set("error", "User already exists with this email address");
				$this->_render("admin/users/edit.html");
				return;
			}

			// Set new user fields
			$user->api_key = $security->salt_sha1();
			$user->created_date = $this->now();
		}

		// Validate password if being set
		if($f3->get("POST.password")) {
			if($f3->get("POST.password") != $f3->get("POST.password_confirm")) {
				$f3->set("error", "Passwords do not match");
				$this->_render("admin/users/edit.html");
				return;
			}
			$min = $f3->get("security.min_pass_len");
			if(strlen($f3->get("POST.password")) < $min) {
				$f3->set("error", "Passwords must be at least {$min} characters");
				$this->_render("admin/users/edit.html");
				return;
			}

			// Check if giving user temporary or permanent password
			if($f3->get("POST.temporary_password")) {
				$user->salt = null;
				$user->password = $security->hash($f3->get("POST.password"), "");
			} else {
				$user->salt = $security->salt();
				$user->password = $security->hash($f3->get("POST.password"), $user->salt);
			}
		}

		// Validate fields
		$error = null;
		if(!$f3->get("POST.name")) {
			$error = "Please enter a name.";
		}
		if(!preg_match("/#?[0-9a-f]{3,6}/i", $f3->get("POST.task_color"))) {
			$error = "Please enter a valid hex color.";
		}
		if(!preg_match("/[0-9a-z_-]+/i", $f3->get("POST.username"))) {
			$error = "Usernames can only contain letters, numbers, hyphens, and underscores.";
		}
		if(!filter_var($f3->get("POST.email"), FILTER_VALIDATE_EMAIL)) {
			$error = "Please enter a valid email address";
		}
		if($error) {
			$f3->set("error", $error);
			$this->_render("admin/users/edit.html");
			return;
		}

		// Set basic fields
		$user->username = $f3->get("POST.username");
		$user->email = $f3->get("POST.email");
		$user->name = $f3->get("POST.name");
		if($user->id != $f3->get("user.id")) {
			// Don't allow user to change own rank
			$user->rank = $f3->get("POST.rank");
		}
		$user->role = $user->rank < \Model\User::RANK_ADMIN ? 'user' : 'admin';
		$user->task_color = ltrim($f3->get("POST.task_color"), "#");

		// Save user
		$user->save();
		$f3->reroute("/admin/users#" . $user->id);
	}

	/**
	 * @param \Base $f3
	 * @param array $params
	 * @throws \Exception
	 */
	public function user_delete($f3, $params) {
		$user = new \Model\User();
		$user->load($params["id"]);
		if(!$user->id) {
			$f3->reroute("/admin/users");
			return;
		}

		// Reassign issues if requested
		if($f3->get("POST.reassign")) {
			switch ($f3->get("POST.reassign")) {
				case "unassign":
					$user->reassignIssues(null);
					break;
				case "to-user":
					$user->reassignIssues($f3->get("POST.reassign-to"));
					break;
			}
		}

		$user->delete();
		if($f3->get("AJAX")) {
			$this->_printJson(array("deleted" => 1));
		} else {
			$f3->reroute("/admin/users");
		}
	}

	/**
	 * @param \Base $f3
	 * @param array $params
	 * @throws \Exception
	 */
	public function user_undelete($f3, $params) {
		$user = new \Model\User();
		$user->load($params["id"]);
		if(!$user->id) {
			$f3->reroute("/admin/users");
			return;
		}

		$user->deleted_date = null;
		$user->save();

		if($f3->get("AJAX")) {
			$this->_printJson(array("deleted" => 1));
		} else {
			$f3->reroute("/admin/users");
		}
	}

	/**
	 * @param \Base $f3
	 */
	public function groups($f3) {
		$f3->set("title", $f3->get("dict.groups"));

		$group = new \Model\User();
		$groups = $group->find("deleted_date IS NULL AND role = 'group'");

		$group_array = array();
		$db = $f3->get("db.instance");
		foreach($groups as $g) {
			$db->exec("SELECT g.id FROM user_group g JOIN user u ON g.user_id = u.id WHERE g.group_id = ? AND u.deleted_date IS NULL", $g["id"]);
			$count = $db->count();
			$group_array[] = array(
				"id" => $g["id"],
				"name" => $g["name"],
				"task_color" => $g["task_color"],
				"count" => $count
			);
		}
		$f3->set("groups", $group_array);

		$this->_render("admin/groups.html");
	}

	/**
	 * @param \Base $f3
	 */
	public function group_new($f3) {
		$f3->set("title", $f3->get("dict.groups"));

		if($f3->get("POST")) {
			$group = new \Model\User();
			$group->name = $f3->get("POST.name");
			$group->username = \Web::instance()->slug($group->name);
			$group->role = "group";
			$group->task_color = sprintf("%02X%02X%02X", mt_rand(0, 0xFF), mt_rand(0, 0xFF), mt_rand(0, 0xFF));
			$group->created_date = $this->now();
			$group->save();
			$f3->reroute("/admin/groups");
		} else {
			$f3->error(405);
		}
	}

	/**
	 * @param \Base $f3
	 * @param array $params
	 * @throws \Exception
	 */
	public function group_edit($f3, $params) {
		$f3->set("title", $f3->get("dict.groups"));

		$group = new \Model\User();
		$group->load(array("id = ? AND deleted_date IS NULL AND role = 'group'", $params["id"]));
		$f3->set("group", $group);

		$members = new \Model\Custom("user_group_user");
		$f3->set("members", $members->find(array("group_id = ? AND deleted_date IS NULL", $group->id)));

		$users = new \Model\User();
		$f3->set("users", $users->find("deleted_date IS NULL AND role != 'group'", array("order" => "name ASC")));

		$this->_render("admin/groups/edit.html");
	}

	/**
	 * @param \Base $f3
	 * @param array $params
	 * @throws \Exception
	 */
	public function group_delete($f3, $params) {
		$group = new \Model\User();
		$group->load($params["id"]);
		$group->delete();
		if($f3->get("AJAX")) {
			$this->_printJson(array("deleted" => 1) + $group->cast());
		} else {
			$f3->reroute("/admin/groups");
		}
	}

	/**
	 * @param \Base $f3
	 * @throws \Exception
	 */
	public function group_ajax($f3) {
		if(!$f3->get("AJAX")) {
			$f3->error(400);
		}

		$group = new \Model\User();
		$group->load(array("id = ? AND deleted_date IS NULL AND role = 'group'", $f3->get("POST.group_id")));

		if(!$group->id) {
			$f3->error(404);
			return;
		}

		switch($f3->get('POST.action')) {
			case "add_member":
				foreach($f3->get("POST.user") as $user_id) {
					$user_group = new \Model\User\Group();
					$user_group->load(array("user_id = ? AND group_id = ?", $user_id, $f3->get("POST.group_id")));
					if(!$user_group->id) {
						$user_group->group_id = $f3->get("POST.group_id");
						$user_group->user_id = $user_id;
						$user_group->save();
					} else {
						// user already in group
					}
				}
				break;
			case "remove_member":
				$user_group = new \Model\User\Group();
				$user_group->load(array("user_id = ? AND group_id = ?", $f3->get("POST.user_id"), $f3->get("POST.group_id")));
				$user_group->delete();
				$this->_printJson(array("deleted" => 1));
				break;
			case "change_title":
				$group->name = trim($f3->get("POST.name"));
				$group->username = \Web::instance()->slug($group->name);
				$group->save();
				$this->_printJson(array("changed" => 1));
				break;
			case "change_task_color":
				$group->task_color = ltrim($f3->get("POST.value"), '#');
				$group->save();
				$this->_printJson(array("changed" => 1));
				break;
			case "change_api_visibility":
				$group->api_visible = (int)!!$f3->get("POST.value");
				$group->save();
				$this->_printJson(array("changed" => 1));
				break;
		}
	}

	/**
	 * @param \Base $f3
	 * @param array $params
	 * @throws \Exception
	 */
	public function group_setmanager($f3, $params) {
		$db = $f3->get("db.instance");

		$group = new \Model\User();
		$group->load(array("id = ? AND deleted_date IS NULL AND role = 'group'", $params["id"]));

		if(!$group->id) {
			$f3->error(404);
			return;
		}

		// Remove Manager status from all members and set manager status on specified user
		$db->exec("UPDATE user_group SET manager = 0 WHERE group_id = ?", $group->id);
		$db->exec("UPDATE user_group SET manager = 1 WHERE id = ?", $params["user_group_id"]);

		$f3->reroute("/admin/groups/" . $group->id);
	}

	/**
	 * @param \Base $f3
	 */
	public function sprints($f3) {
		$f3->set("title", $f3->get("dict.sprints"));

		$sprints = new \Model\Sprint();
		$f3->set("sprints", $sprints->find());

		$this->_render("admin/sprints.html");
	}

	/**
	 * @param \Base $f3
	 */
	public function sprint_new($f3) {
		$f3->set("title", $f3->get("dict.sprints"));

		if($post = $f3->get("POST")) {
			if(empty($post["start_date"]) || empty($post["end_date"])) {
				$f3->set("error", "Start and end date are required");
				$this->_render("admin/sprints/new.html");
				return;
			}

			$start = strtotime($post["start_date"]);
			$end = strtotime($post["end_date"]);

			if(!$start || !$end) {
				$f3->set("error", "Please enter a valid start and end date");
				$this->_render("admin/sprints/new.html");
				return;
			}

			if($end <= $start) {
				$f3->set("error", "End date must be after start date");
				$this->_render("admin/sprints/new.html");
				return;
			}

			$sprint = new \Model\Sprint();
			$sprint->name = trim($post["name"]);
			$sprint->start_date = date("Y-m-d", $start);
			$sprint->end_date = date("Y-m-d", $end);
			$sprint->save();
			$f3->reroute("/admin/sprints");
			return;
		}

		$this->_render("admin/sprints/new.html");
	}

	/**
	 * @param \Base $f3
	 * @param array $params
	 * @throws \Exception
	 */
	public function sprint_edit($f3, $params) {
		$f3->set("title", $f3->get("dict.sprints"));

		$sprint = new \Model\Sprint;
		$sprint->load($params["id"]);
		if(!$sprint->id) {
			$f3->error(404);
			return;
		}

		if($post = $f3->get("POST")) {
			if(empty($post["start_date"]) || empty($post["end_date"])) {
				$f3->set("error", "Start and end date are required");
				$this->_render("admin/sprints/edit.html");
				return;
			}

			$start = strtotime($post["start_date"]);
			$end = strtotime($post["end_date"]);

			if($end <= $start) {
				$f3->set("error", "End date must be after start date");
				$this->_render("admin/sprints/edit.html");
				return;
			}

			$sprint->name = trim($post["name"]);
			$sprint->start_date = date("Y-m-d", $start);
			$sprint->end_date = date("Y-m-d", $end);
			$sprint->save();

			$f3->reroute("/admin/sprints");
			return;
		}
		$f3->set("sprint", $sprint);

		$this->_render("admin/sprints/edit.html");
	}

}
